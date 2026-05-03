<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Self-hosted plugin updater for MMG Checkout for WooCommerce.
 *
 * Primary update channel: GitHub Releases. The updater fetches
 * https://api.github.com/repos/<owner>/<repo>/releases/latest
 * locates the .zip asset attached to the release, optionally verifies a
 * .zip.sha256 sidecar attached to the same release, and feeds that into the
 * standard WordPress plugin update flow.
 *
 * Optional fallback: a self-hosted JSON manifest. If the GitHub call fails
 * (network error, rate limit, repo unavailable) and `MMGWC_UPDATE_JSON_URL`
 * is defined, the updater reads the legacy JSON manifest as a fallback so
 * sites still receive updates while the primary channel is unreachable.
 *
 * Safety features:
 *   - HTTPS required for both API call and download asset.
 *   - Download host must be on github.com or objects.githubusercontent.com
 *     by default for the GitHub channel, or match the manifest host for the
 *     JSON channel. Stores can extend either via `mmgwc_update_allowed_hosts`.
 *   - Pre-releases and drafts are ignored.
 *   - Version downgrades are rejected.
 *   - Tag must match a sane semantic version pattern.
 *   - If the release ships a .zip.sha256 sidecar, the package is hashed
 *     before install and aborted if the hash does not match.
 */
class MMGWC_Updater {

    /** @var string Default repository (owner/name). Override with the `mmgwc_github_repo` filter or the MMGWC_GITHUB_REPO constant. */
    private const DEFAULT_REPO = 'revamped-gy/mmg-checkout-woocommerce';

    /** @var string */
    private static $plugin_file = '';

    /** @var string */
    private static $plugin_basename = '';

    /** @var string */
    private static $slug = 'mmg-checkout-woocommerce';

    /** @var string */
    private static $cache_key = 'mmgwc_update_info_v2';

    /** @var int */
    private static $cache_ttl = 6 * HOUR_IN_SECONDS;

    /** @var string|null */
    private static $expected_sha256 = null;

    /**
     * Initialise the updater.
     *
     * @param string $plugin_file Absolute path to main plugin file.
     */
    public static function init( $plugin_file ) {
        if ( empty( $plugin_file ) ) {
            return;
        }

        self::$plugin_file     = $plugin_file;
        self::$plugin_basename = plugin_basename( $plugin_file );

        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_cache' ), 10, 2 );
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'capture_expected_hash' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( __CLASS__, 'verify_downloaded_package' ), 10, 3 );
    }

    /**
     * Resolve the GitHub repository the updater reads from.
     *
     * Order of precedence: `mmgwc_github_repo` filter, `MMGWC_GITHUB_REPO`
     * constant, then DEFAULT_REPO. Returns an empty string if the resolved
     * value is not a valid "owner/name" pattern.
     */
    private static function get_repo(): string {
        $default = defined( 'MMGWC_GITHUB_REPO' ) && is_string( MMGWC_GITHUB_REPO ) && MMGWC_GITHUB_REPO !== ''
            ? (string) MMGWC_GITHUB_REPO
            : self::DEFAULT_REPO;
        /** Filter the GitHub repository (owner/name) the updater reads from. */
        $repo = (string) apply_filters( 'mmgwc_github_repo', $default );
        if ( ! preg_match( '/^[A-Za-z0-9._-]{1,100}\/[A-Za-z0-9._-]{1,100}$/', $repo ) ) {
            return '';
        }
        return $repo;
    }

    /**
     * Fetch the latest release info, with caching.
     * Tries GitHub first, then falls back to the legacy JSON manifest if defined.
     *
     * @param bool $force Force refresh.
     * @return object|false
     */
    private static function get_remote_info( $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::$cache_key );
            if ( is_object( $cached ) ) {
                return $cached;
            }
        }

        $info = self::fetch_from_github();

        if ( ! $info && defined( 'MMGWC_UPDATE_JSON_URL' ) && MMGWC_UPDATE_JSON_URL !== '' ) {
            $info = self::fetch_from_json_manifest( (string) MMGWC_UPDATE_JSON_URL );
        }

        if ( $info ) {
            set_site_transient( self::$cache_key, $info, self::$cache_ttl );
        }

        return $info;
    }

    // ---------------------------------------------------------------------
    // GitHub Releases channel
    // ---------------------------------------------------------------------

    /**
     * Fetch latest release from GitHub.
     *
     * @return object|false
     */
    private static function fetch_from_github() {
        $repo = self::get_repo();
        if ( $repo === '' ) {
            return false;
        }

        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';

        $args = array(
            'timeout' => 12,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'MMG-Checkout-Updater (+' . home_url( '/' ) . ')',
            ),
        );

        /** Optional GitHub token. Useful for higher rate limits or private forks. */
        $token = (string) apply_filters( 'mmgwc_github_token', '' );
        if ( $token !== '' ) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( $body === '' || strlen( $body ) > 1024 * 1024 ) {
            return false;
        }

        $data = json_decode( $body );
        if ( ! is_object( $data ) ) {
            return false;
        }

        if ( ! empty( $data->draft ) || ! empty( $data->prerelease ) ) {
            return false;
        }
        if ( empty( $data->tag_name ) ) {
            return false;
        }

        $version = ltrim( (string) $data->tag_name, 'vV' );
        if ( ! preg_match( '/^\d+\.\d+\.\d+([.\-+][A-Za-z0-9.\-]+)?$/', $version ) ) {
            return false;
        }

        // Locate the .zip asset and the optional .zip.sha256 sidecar.
        $download_url = '';
        $sha256_url   = '';
        if ( ! empty( $data->assets ) && is_array( $data->assets ) ) {
            foreach ( $data->assets as $asset ) {
                if ( ! is_object( $asset ) || empty( $asset->browser_download_url ) ) {
                    continue;
                }
                $name = isset( $asset->name ) ? (string) $asset->name : '';
                if ( $download_url === '' && preg_match( '/\.zip$/i', $name ) ) {
                    $download_url = (string) $asset->browser_download_url;
                } elseif ( $sha256_url === '' && preg_match( '/\.zip\.sha256$/i', $name ) ) {
                    $sha256_url = (string) $asset->browser_download_url;
                }
            }
        }

        if ( $download_url === '' ) {
            return false;
        }

        // Require HTTPS and a sane host for the download.
        if ( strtolower( (string) wp_parse_url( $download_url, PHP_URL_SCHEME ) ) !== 'https' ) {
            return false;
        }
        $dl_host = strtolower( (string) wp_parse_url( $download_url, PHP_URL_HOST ) );
        if ( ! self::is_allowed_download_host( $dl_host, array( 'github.com', 'objects.githubusercontent.com' ) ) ) {
            return false;
        }

        // Pull the SHA-256 sidecar if present.
        $package_sha256 = '';
        if ( $sha256_url !== '' ) {
            $sha_response = wp_remote_get( $sha256_url, array( 'timeout' => 8 ) );
            if ( ! is_wp_error( $sha_response ) && 200 === (int) wp_remote_retrieve_response_code( $sha_response ) ) {
                $sha_body = (string) wp_remote_retrieve_body( $sha_response );
                if ( preg_match( '/([a-fA-F0-9]{64})/', $sha_body, $m ) ) {
                    $package_sha256 = strtolower( $m[1] );
                }
            }
        }

        $last_updated = '';
        if ( ! empty( $data->published_at ) ) {
            $ts = strtotime( (string) $data->published_at );
            if ( $ts ) {
                $last_updated = gmdate( 'Y-m-d', $ts );
            }
        }

        return (object) array(
            'name'           => 'MMG Checkout for WooCommerce',
            'slug'           => self::$slug,
            'version'        => $version,
            'download_url'   => esc_url_raw( $download_url ),
            'package_sha256' => $package_sha256,
            'homepage'       => isset( $data->html_url ) ? esc_url_raw( (string) $data->html_url ) : 'https://revamped.gy/mmg-woocommerce-plugin-guyana',
            'last_updated'   => $last_updated,
            'tested'         => self::get_local_readme_field( 'Tested up to' ),
            'requires'       => self::get_local_readme_field( 'Requires at least' ),
            'requires_php'   => self::get_local_readme_field( 'Requires PHP' ),
            'sections'       => (object) array(
                'description'  => '',
                'installation' => '',
                'changelog'    => self::format_release_changelog( $data ),
            ),
        );
    }

    /**
     * Build the HTML used in the "View version details" changelog section
     * from a GitHub release object.
     */
    private static function format_release_changelog( $release ): string {
        $tag  = isset( $release->tag_name ) ? (string) $release->tag_name : '';
        $body = isset( $release->body ) ? (string) $release->body : '';

        $heading = $tag !== '' ? '<h4>' . esc_html( ltrim( $tag, 'vV' ) ) . '</h4>' : '';

        if ( $body === '' ) {
            return $heading . '<p>See the project release page for full notes.</p>';
        }

        return $heading . wpautop( wp_kses_post( $body ) );
    }

    // ---------------------------------------------------------------------
    // Legacy JSON manifest channel (fallback only)
    // ---------------------------------------------------------------------

    /**
     * Read a self-hosted JSON manifest as a fallback. Same shape as the
     * pre-2.14.21 manifest at `revamped.gy/updates/mmg-checkout.json`.
     *
     * @return object|false
     */
    private static function fetch_from_json_manifest( string $json_url ) {
        $json_url = esc_url_raw( $json_url );
        if ( $json_url === '' ) {
            return false;
        }
        if ( strtolower( (string) wp_parse_url( $json_url, PHP_URL_SCHEME ) ) !== 'https' ) {
            return false;
        }

        $response = wp_remote_get( $json_url, array(
            'timeout' => 12,
            'headers' => array(
                'Accept'     => 'application/json',
                'User-Agent' => 'MMG-Checkout-Updater (+' . home_url( '/' ) . ')',
            ),
        ) );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( $body === '' || strlen( $body ) > 256 * 1024 ) {
            return false;
        }

        $data = json_decode( $body );
        if ( ! is_object( $data ) ) {
            return false;
        }
        if ( empty( $data->version ) || empty( $data->download_url ) ) {
            return false;
        }

        $version      = (string) $data->version;
        $download_url = esc_url_raw( (string) $data->download_url );
        if ( $download_url === '' ) {
            return false;
        }
        if ( strtolower( (string) wp_parse_url( $download_url, PHP_URL_SCHEME ) ) !== 'https' ) {
            return false;
        }

        $json_host = strtolower( (string) wp_parse_url( $json_url, PHP_URL_HOST ) );
        $dl_host   = strtolower( (string) wp_parse_url( $download_url, PHP_URL_HOST ) );
        if ( ! self::is_allowed_download_host( $dl_host, array( $json_host ) ) ) {
            return false;
        }

        $package_sha256 = '';
        if ( ! empty( $data->package_sha256 ) && is_string( $data->package_sha256 ) ) {
            $cleaned = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $data->package_sha256 ) );
            if ( strlen( $cleaned ) === 64 ) {
                $package_sha256 = $cleaned;
            }
        }

        $sections = isset( $data->sections ) && is_object( $data->sections ) ? $data->sections : (object) array();

        return (object) array(
            'name'           => isset( $data->name ) ? (string) $data->name : 'MMG Checkout for WooCommerce',
            'slug'           => self::$slug,
            'version'        => $version,
            'download_url'   => $download_url,
            'package_sha256' => $package_sha256,
            'homepage'       => ! empty( $data->homepage ) ? esc_url_raw( (string) $data->homepage ) : 'https://revamped.gy/mmg-woocommerce-plugin-guyana',
            'last_updated'   => ! empty( $data->last_updated ) ? (string) $data->last_updated : '',
            'tested'         => ! empty( $data->tested ) ? (string) $data->tested : self::get_local_readme_field( 'Tested up to' ),
            'requires'       => ! empty( $data->requires ) ? (string) $data->requires : self::get_local_readme_field( 'Requires at least' ),
            'requires_php'   => ! empty( $data->requires_php ) ? (string) $data->requires_php : self::get_local_readme_field( 'Requires PHP' ),
            'sections'       => (object) array(
                'description'  => isset( $sections->description ) ? (string) $sections->description : '',
                'installation' => isset( $sections->installation ) ? (string) $sections->installation : '',
                'changelog'    => isset( $sections->changelog ) ? (string) $sections->changelog : '',
            ),
        );
    }

    // ---------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------

    /**
     * Whether the given lowercase host is acceptable for the package download.
     *
     * @param string   $host     Lowercase host string.
     * @param string[] $defaults Hosts always allowed for this channel.
     */
    private static function is_allowed_download_host( string $host, array $defaults ): bool {
        if ( $host === '' ) {
            return false;
        }
        /** Filter: array of additional lowercase hosts allowed for package downloads. */
        $allowed = (array) apply_filters( 'mmgwc_update_allowed_hosts', $defaults );
        $allowed = array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $allowed ) ) ) );
        return in_array( $host, $allowed, true );
    }

    /**
     * Read a top-of-readme.txt header field from the locally installed plugin.
     */
    private static function get_local_readme_field( string $field ): string {
        if ( self::$plugin_file === '' ) {
            return '';
        }
        $readme = dirname( self::$plugin_file ) . '/readme.txt';
        if ( ! is_readable( $readme ) ) {
            return '';
        }
        $head = (string) @file_get_contents( $readme, false, null, 0, 4096 );
        if ( $head === '' ) {
            return '';
        }
        if ( preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/im', $head, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // WP integration: update transient + plugin info modal + integrity check
    // ---------------------------------------------------------------------

    /**
     * Inject update info into the WordPress update transient.
     *
     * @param object $transient
     * @return object
     */
    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
            return $transient;
        }

        if ( empty( $transient->checked[ self::$plugin_basename ] ) ) {
            return $transient;
        }

        $current_version = (string) $transient->checked[ self::$plugin_basename ];
        $remote          = self::get_remote_info();

        if ( ! $remote ) {
            return $transient;
        }

        $remote_version = (string) $remote->version;

        $item = (object) array(
            'slug'         => self::$slug,
            'plugin'       => self::$plugin_basename,
            'new_version'  => $remote_version,
            'url'          => ! empty( $remote->homepage ) ? esc_url_raw( $remote->homepage ) : '',
            'package'      => esc_url_raw( $remote->download_url ),
            'tested'       => ! empty( $remote->tested ) ? (string) $remote->tested : '',
            'requires'     => ! empty( $remote->requires ) ? (string) $remote->requires : '',
            'requires_php' => ! empty( $remote->requires_php ) ? (string) $remote->requires_php : '',
        );

        if ( version_compare( $remote_version, $current_version, '>' ) ) {
            $transient->response[ self::$plugin_basename ] = $item;
        } else {
            $transient->no_update[ self::$plugin_basename ] = $item;
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View version details" modal.
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public static function plugins_api( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( empty( $args->slug ) || self::$slug !== $args->slug ) {
            return $result;
        }

        $remote = self::get_remote_info( false );
        if ( ! $remote ) {
            return $result;
        }

        $info                 = new stdClass();
        $info->name           = ! empty( $remote->name ) ? (string) $remote->name : 'MMG Checkout for WooCommerce';
        $info->slug           = self::$slug;
        $info->version        = (string) $remote->version;
        $info->author         = 'Revamped GY';
        $info->author_profile = 'https://revamped.gy';
        $info->homepage       = ! empty( $remote->homepage ) ? esc_url_raw( $remote->homepage ) : 'https://revamped.gy/mmg-woocommerce-plugin-guyana';
        $info->requires       = ! empty( $remote->requires ) ? (string) $remote->requires : '';
        $info->tested         = ! empty( $remote->tested ) ? (string) $remote->tested : '';
        $info->requires_php   = ! empty( $remote->requires_php ) ? (string) $remote->requires_php : '';
        $info->download_link  = esc_url_raw( $remote->download_url );

        $sections = array();
        foreach ( array( 'description', 'installation', 'changelog' ) as $k ) {
            if ( ! empty( $remote->sections->$k ) ) {
                $sections[ $k ] = (string) $remote->sections->$k;
            }
        }
        $info->sections = $sections;

        if ( ! empty( $remote->last_updated ) ) {
            $info->last_updated = (string) $remote->last_updated;
        }

        return $info;
    }

    /**
     * Stash the expected SHA-256 (if known) before WordPress downloads the ZIP.
     */
    public static function capture_expected_hash( $reply, $package, $upgrader ) {
        if ( ! is_string( $package ) || $package === '' ) {
            return $reply;
        }
        $remote = self::get_remote_info( false );
        if ( ! $remote ) {
            return $reply;
        }
        if ( empty( $remote->download_url ) || (string) $remote->download_url !== $package ) {
            return $reply;
        }
        if ( ! empty( $remote->package_sha256 ) ) {
            self::$expected_sha256 = (string) $remote->package_sha256;
        }
        return $reply;
    }

    /**
     * Verify that the downloaded package matches the declared SHA-256.
     * If no hash is available, the download proceeds unchanged.
     */
    public static function verify_downloaded_package( $response, $hook_extra, $result ) {
        if ( empty( self::$expected_sha256 ) ) {
            return $response;
        }
        if ( is_wp_error( $response ) || empty( $result['source'] ) ) {
            return $response;
        }
        $local_file = isset( $result['local_source'] ) ? (string) $result['local_source'] : '';
        if ( $local_file === '' || ! is_file( $local_file ) ) {
            self::$expected_sha256 = null;
            return $response;
        }
        $actual   = @hash_file( 'sha256', $local_file );
        $expected = self::$expected_sha256;
        self::$expected_sha256 = null;
        if ( ! is_string( $actual ) || ! hash_equals( strtolower( $expected ), strtolower( $actual ) ) ) {
            return new WP_Error( 'mmgwc_update_hash_mismatch', 'MMG Checkout update package hash did not match the expected value. Update aborted.' );
        }
        return $response;
    }

    /**
     * Purge cached update info after an update completes.
     *
     * @param object $upgrader
     * @param array  $hook_extra
     * @return void
     */
    public static function purge_cache( $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
            return;
        }
        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return;
        }
        delete_site_transient( self::$cache_key );
    }
}
