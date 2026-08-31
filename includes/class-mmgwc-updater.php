<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Self-hosted plugin updater for MMG Checkout for WooCommerce.
 *
 * Primary update channel: GitHub Releases. The updater fetches
 * https://api.github.com/repos/<owner>/<repo>/releases/latest
 * locates the .zip asset attached to the release, requires and verifies its
 * .zip.sha256 sidecar before installation, and feeds that into the
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
 *   - Every advertised package requires a SHA-256 value and is verified
 *     before install.
 */
class MMGWC_Updater {

    /** @var string Default repository (owner/name). Override with the `mmgwc_github_repo` filter or the MMGWC_GITHUB_REPO constant. */
    private const DEFAULT_REPO = 'Revamped-GY/MMG-Checkout-Woocommerce-Guyana';

    /** @var string */
    private static $plugin_file = '';

    /** @var string */
    private static $plugin_basename = '';

    /** @var string */
    private static $slug = 'mmg-checkout-woocommerce';

    /** @var string */
    private static $cache_key = 'mmgwc_update_info_v3';

    /** @var int */
    private static $cache_ttl = 6 * HOUR_IN_SECONDS;

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
        // Run after other download filters so a supplied local file is still
        // checked before WordPress can unpack this plugin update.
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_verified_package' ), PHP_INT_MAX, 4 );
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

        $args['reject_unsafe_urls'] = true;
        $response = wp_safe_remote_get( $url, $args );
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

        // Require the release workflow's exact ZIP and matching sidecar names.
        // This prevents unrelated assets in the same release being paired.
        $expected_zip_name = 'mmg-checkout-woocommerce-v' . $version . '.zip';
        $download_url = '';
        $sha256_url   = '';
        $ambiguous_assets = false;
        if ( ! empty( $data->assets ) && is_array( $data->assets ) ) {
            foreach ( $data->assets as $asset ) {
                if ( ! is_object( $asset ) || empty( $asset->browser_download_url ) ) {
                    continue;
                }
                $name = isset( $asset->name ) ? (string) $asset->name : '';
                if ( $name === $expected_zip_name ) {
                    if ( $download_url !== '' ) {
                        $ambiguous_assets = true;
                        break;
                    }
                    $download_url = (string) $asset->browser_download_url;
                } elseif ( $name === $expected_zip_name . '.sha256' ) {
                    if ( $sha256_url !== '' ) {
                        $ambiguous_assets = true;
                        break;
                    }
                    $sha256_url = (string) $asset->browser_download_url;
                }
            }
        }

        if ( $ambiguous_assets || $download_url === '' || $sha256_url === '' ) {
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

        // Pull the required SHA-256 sidecar.
        $package_sha256 = '';
        if ( $sha256_url !== '' ) {
            $sha_scheme = strtolower( (string) wp_parse_url( $sha256_url, PHP_URL_SCHEME ) );
            $sha_host = strtolower( (string) wp_parse_url( $sha256_url, PHP_URL_HOST ) );
            if ( $sha_scheme !== 'https' || ! self::is_allowed_download_host( $sha_host, array( 'github.com', 'objects.githubusercontent.com' ) ) ) {
                return false;
            }
            $sha_response = wp_safe_remote_get( $sha256_url, array( 'timeout' => 8, 'reject_unsafe_urls' => true ) );
            if ( ! is_wp_error( $sha_response ) && 200 === (int) wp_remote_retrieve_response_code( $sha_response ) ) {
                $sha_body = (string) wp_remote_retrieve_body( $sha_response );
                if ( strlen( $sha_body ) <= 4096 && preg_match( '/\b([a-fA-F0-9]{64})\b/', $sha_body, $m ) ) {
                    $package_sha256 = strtolower( $m[1] );
                }
            }
        }

        // GitHub releases are built by this repository and must carry the
        // checksum asset. Do not advertise an unverifiable package.
        if ( $package_sha256 === '' ) {
            return false;
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
            'source'         => 'github',
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

        $response = wp_safe_remote_get( $json_url, array(
            'timeout'            => 12,
            'reject_unsafe_urls' => true,
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
        if ( $package_sha256 === '' ) {
            return false;
        }

        $sections = isset( $data->sections ) && is_object( $data->sections ) ? $data->sections : (object) array();

        return (object) array(
            'name'           => isset( $data->name ) ? (string) $data->name : 'MMG Checkout for WooCommerce',
            'slug'           => self::$slug,
            'version'        => $version,
            'download_url'   => $download_url,
            'package_sha256' => $package_sha256,
            'source'         => 'manifest',
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
     * Download this plugin's package to a temporary file and verify it before
     * WordPress is allowed to unpack or install it.
     *
     * Returning the verified local path from upgrader_pre_download makes core
     * use that exact file. Other plugins and packages remain untouched.
     */
    public static function download_verified_package( $reply, $package, $upgrader, $hook_extra ) {
        if ( self::is_explicit_other_plugin_update( $hook_extra ) ) {
            return $reply;
        }

        $remote = self::get_remote_info( false );
        $is_plugin_update = self::is_this_plugin_update( $hook_extra );
        $matches_advertised_package = is_object( $remote )
            && ! empty( $remote->download_url )
            && is_string( $package )
            && (string) $remote->download_url === $package;

        if ( ! $is_plugin_update && ! $matches_advertised_package ) {
            return $reply;
        }

        if ( is_wp_error( $reply ) ) {
            return $reply;
        }
        if ( ! is_object( $remote ) || empty( $remote->download_url ) ) {
            return new WP_Error( 'mmgwc_update_metadata_missing', 'MMG Checkout update metadata is unavailable. Update aborted.' );
        }
        if ( ! is_string( $package ) || $package === '' || (string) $remote->download_url !== $package ) {
            return new WP_Error( 'mmgwc_update_package_changed', 'MMG Checkout update package did not match the verified release metadata. Update aborted.' );
        }

        $expected = isset( $remote->package_sha256 ) ? strtolower( trim( (string) $remote->package_sha256 ) ) : '';
        if ( preg_match( '/^[a-f0-9]{64}$/', $expected ) !== 1 ) {
            return new WP_Error( 'mmgwc_update_hash_missing', 'MMG Checkout update package has no valid SHA-256 checksum. Update aborted.' );
        }

        $local_file = false;
        if ( is_string( $reply ) && $reply !== '' ) {
            $local_file = $reply;
        } elseif ( false === $reply ) {
            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $local_file = download_url( $package, 300 );
        } else {
            return new WP_Error( 'mmgwc_update_download_invalid', 'MMG Checkout update download could not be verified. Update aborted.' );
        }

        if ( is_wp_error( $local_file ) ) {
            return $local_file;
        }

        if ( ! is_string( $local_file ) || $local_file === '' || ! is_file( $local_file ) ) {
            return new WP_Error( 'mmgwc_update_file_missing', 'MMG Checkout update package file is unavailable. Update aborted.' );
        }

        $actual = hash_file( 'sha256', $local_file );
        if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $local_file );
            } else {
                @unlink( $local_file );
            }
            return new WP_Error( 'mmgwc_update_hash_mismatch', 'MMG Checkout update package hash did not match the expected value. Update aborted.' );
        }

        return $local_file;
    }

    /**
     * Identify this plugin in a single or bulk upgrader request.
     *
     * @param mixed $hook_extra Upgrader context supplied by WordPress.
     */
    private static function is_this_plugin_update( $hook_extra ): bool {
        if ( self::$plugin_basename === '' || ! is_array( $hook_extra ) ) {
            return false;
        }

        if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && $hook_extra['plugin'] === self::$plugin_basename ) {
            return true;
        }

        if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            return in_array( self::$plugin_basename, $hook_extra['plugins'], true );
        }

        return false;
    }

    /**
     * Avoid remote metadata work when WordPress explicitly identifies another plugin.
     *
     * @param mixed $hook_extra Upgrader context supplied by WordPress.
     */
    private static function is_explicit_other_plugin_update( $hook_extra ): bool {
        if ( self::$plugin_basename === '' || ! is_array( $hook_extra ) ) {
            return false;
        }

        if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
            return $hook_extra['plugin'] !== self::$plugin_basename;
        }

        if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            return ! in_array( self::$plugin_basename, $hook_extra['plugins'], true );
        }

        return false;
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
