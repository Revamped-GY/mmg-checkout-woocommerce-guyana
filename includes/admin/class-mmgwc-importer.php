<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Importer {
	private const CAP = 'mmgwc_access_importer';

	// Upload hardening constants.
	private const MAX_ZIP_BYTES        = 5 * 1024 * 1024; // 5 MB
	private const MAX_SINGLE_FILE_BYTES = 1 * 1024 * 1024; // 1 MB per setup.cfg / pem
	private const MAX_ZIP_MEMBERS       = 64;
	private const MAX_ZIP_ENTRY_BYTES   = 512 * 1024;
	private const MAX_UNCOMPRESSED_BYTES = 5 * 1024 * 1024;

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_mmgwc_import_files', array( __CLASS__, 'handle_import' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$notice = '';
		if ( isset( $_GET['mmgwc_imported'] ) ) {
			$type = sanitize_text_field( wp_unslash( $_GET['mmgwc_imported'] ) );
			$msg  = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
			if ( $type === 'success' ) {
				$notice = '<div class="notice notice-success"><p>' . esc_html( $msg ? $msg : 'Import completed.' ) . '</p></div>';
			} elseif ( $type === 'error' ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $msg ? $msg : 'Import failed.' ) . '</p></div>';
			}
		}

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Importer' );
		}
		echo '<h1>MMG Checkout Importer</h1>';
		echo '<p>MMG usually provides a zip package that contains:</p>';
		echo '<ul style="list-style: disc; margin-left: 20px;">';
		echo '<li><code>setup.cfg</code> (contains Merchant Name, Merchant ID, Client ID, Secret Key)</li>';
		echo '<li><code>keys/…public.pem</code> (public key used to encrypt requests)</li>';
		echo '<li><code>keys/…private.pem</code> (private key used to decrypt MMG response tokens)</li>';
		echo '</ul>';
		echo '<p>You can upload the zip and the plugin will locate the files automatically. If you do not have the zip, you can upload <code>setup.cfg</code> and the <code>.pem</code> key files separately.</p>';
		echo '<p><strong>Where to get these files:</strong> Ask MMG support for your Merchant Checkout integration package for Sandbox (UAT) and Live (Production). The zip usually contains the same file structure, only the Merchant ID changes.</p>';

		echo $notice;

		echo '<hr />';
		self::render_import_form( 'sandbox', 'Sandbox (UAT)' );
		echo '<hr />';
		self::render_import_form( 'live', 'Live (Production)' );

		echo '</div>';
	}

	private static function render_import_form( string $mode, string $label ): void {
		$action_url = admin_url( 'admin-post.php' );

		echo '<h2>' . esc_html( $label ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $action_url ) . '" enctype="multipart/form-data" style="max-width: 980px;">';
		wp_nonce_field( 'mmgwc_import_files', 'mmgwc_nonce' );
		echo '<input type="hidden" name="action" value="mmgwc_import_files" />';
		echo '<input type="hidden" name="mmgwc_mode" value="' . esc_attr( $mode ) . '" />';

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="mmgwc_zip_' . esc_attr( $mode ) . '">MMG zip package (optional)</label></th>';
		echo '<td><input type="file" id="mmgwc_zip_' . esc_attr( $mode ) . '" name="mmgwc_zip" accept=".zip" /> <p class="description">Upload the MMG zip package (max ' . esc_html( size_format( self::MAX_ZIP_BYTES ) ) . '). The plugin will auto-detect <code>setup.cfg</code> and key files.</p></td></tr>';

		echo '<tr><th scope="row">Or upload separately</th><td>';
		echo '<p><label for="mmgwc_cfg_' . esc_attr( $mode ) . '">setup.cfg</label><br />';
		echo '<input type="file" id="mmgwc_cfg_' . esc_attr( $mode ) . '" name="mmgwc_cfg" accept=".cfg,.ini,.txt" /></p>';

		echo '<p><label for="mmgwc_public_' . esc_attr( $mode ) . '">Public key (.public.pem)</label><br />';
		echo '<input type="file" id="mmgwc_public_' . esc_attr( $mode ) . '" name="mmgwc_public_pem" accept=".pem" /></p>';

		echo '<p><label for="mmgwc_private_' . esc_attr( $mode ) . '">Private key (.private.pem)</label><br />';
		echo '<input type="file" id="mmgwc_private_' . esc_attr( $mode ) . '" name="mmgwc_private_pem" accept=".pem" /></p>';

		echo '<p class="description">If you upload the zip package, you do not need to upload these separately.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Options</th><td>';
		echo '<label><input type="checkbox" name="mmgwc_set_active_mode" value="1" /> Set Mode to ' . esc_html( $label ) . ' after importing</label>';
		echo '</td></tr>';

		echo '</table>';

		submit_button( 'Import ' . $label, 'primary', 'mmgwc_import_submit' );
		echo '</form>';
	}

	public static function handle_import(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mmg-checkout-woocommerce' ) );
		}

		check_admin_referer( 'mmgwc_import_files', 'mmgwc_nonce' );

		$mode = isset( $_POST['mmgwc_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mmgwc_mode'] ) ) : 'sandbox';
		if ( ! in_array( $mode, array( 'sandbox', 'live' ), true ) ) {
			$mode = 'sandbox';
		}

		$set_active = isset( $_POST['mmgwc_set_active_mode'] );

		try {
			$result = self::import_files_for_mode( $mode );

			if ( $set_active ) {
				MMGWC_Settings::update_partial( array( 'mode' => $mode ) );
			}

			MMGWC_Logger::info( 'MMG credentials imported', array(
				'mode'    => $mode,
				'user_id' => get_current_user_id(),
			) );

			$url = add_query_arg(
				array(
					'page' => 'mmgwc-import',
					'mmgwc_imported' => 'success',
					'message' => rawurlencode( $result ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $url );
			exit;
		} catch ( Exception $e ) {
			// Log the full detail but only expose a short, scrubbed message to the admin.
			MMGWC_Logger::error( 'MMG import failed: ' . $e->getMessage(), array( 'mode' => $mode ) );
			$safe_msg = preg_replace( '/[\r\n]+/', ' ', (string) $e->getMessage() ) ?? 'Import failed.';
			// Trim to avoid leaking long internal paths.
			$safe_msg = mb_substr( $safe_msg, 0, 240 );
			$url = add_query_arg(
				array(
					'page' => 'mmgwc-import',
					'mmgwc_imported' => 'error',
					'message' => rawurlencode( $safe_msg ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $url );
			exit;
		}
	}

	private static function check_upload_ok( array $file, int $max_bytes, string $friendly ): void {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new Exception( $friendly . ' upload could not be read.' );
		}
		$err = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if ( $err !== UPLOAD_ERR_OK ) {
			throw new Exception( $friendly . ' upload failed (code ' . $err . ').' );
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : (int) @filesize( $file['tmp_name'] );
		if ( $size <= 0 ) {
			throw new Exception( $friendly . ' is empty.' );
		}
		if ( $size > $max_bytes ) {
			throw new Exception( $friendly . ' is too large (max ' . size_format( $max_bytes ) . ').' );
		}
	}

	private static function safe_read_upload( string $path, int $max_bytes ): string {
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			throw new Exception( 'Could not read uploaded file.' );
		}
		$buf = '';
		$read = 0;
		while ( ! feof( $fp ) ) {
			$chunk = fread( $fp, 8192 );
			if ( $chunk === false ) { break; }
			$read += strlen( $chunk );
			if ( $read > $max_bytes ) {
				fclose( $fp );
				throw new Exception( 'Uploaded file is too large.' );
			}
			$buf .= $chunk;
		}
		fclose( $fp );
		return $buf;
	}

	private static function import_files_for_mode( string $mode ): string {
		$cfg  = '';
		$pub  = '';
		$priv = '';

		// 1) If zip provided, read from zip.
		if ( isset( $_FILES['mmgwc_zip'] ) && ! empty( $_FILES['mmgwc_zip']['tmp_name'] ) ) {
			self::check_upload_ok( $_FILES['mmgwc_zip'], self::MAX_ZIP_BYTES, 'Zip package' );
			$zip_path = $_FILES['mmgwc_zip']['tmp_name'];

			$cfg  = self::read_zip_first_match( $zip_path, '/(^|\/)setup\.cfg$/i' );
			$pub  = self::read_zip_first_match( $zip_path, '/\.public\.pem$/i' );
			$priv = self::read_zip_first_match( $zip_path, '/\.private\.pem$/i' );

			if ( $cfg === '' || $pub === '' || $priv === '' ) {
				throw new Exception( 'Zip file uploaded, but required files were not found. Make sure it contains setup.cfg and the public/private .pem key files.' );
			}
		} else {
			// 2) Otherwise read separately.
			if ( isset( $_FILES['mmgwc_cfg'] ) && ! empty( $_FILES['mmgwc_cfg']['tmp_name'] ) ) {
				self::check_upload_ok( $_FILES['mmgwc_cfg'], self::MAX_SINGLE_FILE_BYTES, 'setup.cfg' );
				$cfg = self::safe_read_upload( $_FILES['mmgwc_cfg']['tmp_name'], self::MAX_SINGLE_FILE_BYTES );
			}
			if ( isset( $_FILES['mmgwc_public_pem'] ) && ! empty( $_FILES['mmgwc_public_pem']['tmp_name'] ) ) {
				self::check_upload_ok( $_FILES['mmgwc_public_pem'], self::MAX_SINGLE_FILE_BYTES, 'Public key' );
				$pub = self::safe_read_upload( $_FILES['mmgwc_public_pem']['tmp_name'], self::MAX_SINGLE_FILE_BYTES );
			}
			if ( isset( $_FILES['mmgwc_private_pem'] ) && ! empty( $_FILES['mmgwc_private_pem']['tmp_name'] ) ) {
				self::check_upload_ok( $_FILES['mmgwc_private_pem'], self::MAX_SINGLE_FILE_BYTES, 'Private key' );
				$priv = self::safe_read_upload( $_FILES['mmgwc_private_pem']['tmp_name'], self::MAX_SINGLE_FILE_BYTES );
			}

			if ( $cfg === '' || $pub === '' || $priv === '' ) {
				throw new Exception( 'Please upload either the MMG zip package, or upload setup.cfg and both the public and private .pem files.' );
			}
		}

		// Validate key material early so we fail fast with a clear message.
		if ( ! self::looks_like_pem_public( $pub ) ) {
			throw new Exception( 'The uploaded public key does not look like a valid PEM key.' );
		}
		if ( ! self::looks_like_pem_private( $priv ) ) {
			throw new Exception( 'The uploaded private key does not look like a valid PEM key.' );
		}

		$cfg_data = self::parse_setup_cfg( $cfg );

		$merchant_name = $cfg_data['merchant'] ?? '';
		$merchant_id   = $cfg_data['merchant_msisdn'] ?? ( $cfg_data['merchantId'] ?? '' );
		$client_id     = $cfg_data['clientId'] ?? ( $cfg_data['client_id'] ?? '' );
		$secret_key    = $cfg_data['secret_key'] ?? ( $cfg_data['secretKey'] ?? '' );

		if ( $merchant_name === '' || $merchant_id === '' || $client_id === '' || $secret_key === '' ) {
			throw new Exception( 'setup.cfg was found, but required fields are missing. Expected merchant, merchant_msisdn, clientId, and secret_key.' );
		}

		$prefix = $mode === 'live' ? 'live_' : 'sandbox_';

		$changes = array(
			$prefix . 'merchant_name' => sanitize_text_field( $merchant_name ),
			$prefix . 'merchant_id'   => sanitize_text_field( $merchant_id ),
			$prefix . 'client_id'     => sanitize_text_field( $client_id ),
			$prefix . 'secret_key'    => trim( (string) $secret_key ),
			$prefix . 'public_key'    => trim( (string) $pub ),
			$prefix . 'private_key'   => trim( (string) $priv ),
		);

		MMGWC_Settings::update_partial( $changes );

		return sprintf(
			'%s credentials imported for Merchant ID %s. You can review everything under MMG Checkout → Settings.',
			$mode === 'live' ? 'Live (Production)' : 'Sandbox (UAT)',
			$merchant_id
		);
	}

	private static function looks_like_pem_public( string $pem ): bool {
		$pem = trim( $pem );
		return (bool) preg_match( '/-----BEGIN (PUBLIC KEY|RSA PUBLIC KEY|CERTIFICATE)-----/', $pem )
			&& (bool) preg_match( '/-----END (PUBLIC KEY|RSA PUBLIC KEY|CERTIFICATE)-----/', $pem );
	}

	private static function looks_like_pem_private( string $pem ): bool {
		$pem = trim( $pem );
		return (bool) preg_match( '/-----BEGIN (RSA |EC |)PRIVATE KEY-----/', $pem )
			&& (bool) preg_match( '/-----END (RSA |EC |)PRIVATE KEY-----/', $pem );
	}

	/**
	 * Read the first ZIP entry matching $pattern with hardening:
	 *   - Reject zips with too many entries (zip bombs / noise).
	 *   - Reject entries with paths that contain .. segments or absolute paths.
	 *   - Cap individual entry read size, and total uncompressed read size.
	 */
	private static function read_zip_first_match( string $zip_path, string $pattern ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'PHP ZipArchive is not available on this server. Ask your host to enable it, or upload the files separately.' );
		}

		$zip = new ZipArchive();
		$opened = $zip->open( $zip_path );
		if ( $opened !== true ) {
			throw new Exception( 'Unable to open zip file.' );
		}

		if ( $zip->numFiles > self::MAX_ZIP_MEMBERS ) {
			$zip->close();
			throw new Exception( 'Zip file has too many entries (max ' . self::MAX_ZIP_MEMBERS . ').' );
		}

		$match_index = -1;
		$total_uncompressed = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = isset( $stat['name'] ) ? (string) $stat['name'] : '';
			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$total_uncompressed += max( 0, $size );
			if ( $total_uncompressed > self::MAX_UNCOMPRESSED_BYTES ) {
				$zip->close();
				throw new Exception( 'Zip contents exceed the allowed size.' );
			}
			// Reject suspicious paths.
			if ( $name === '' || strpos( $name, '..' ) !== false || strpos( $name, "\0" ) !== false ) {
				continue;
			}
			if ( isset( $name[0] ) && ( $name[0] === '/' || $name[0] === '\\' ) ) {
				continue;
			}
			if ( preg_match( '/^[A-Za-z]:[\\\\\/]/', $name ) ) {
				continue;
			}
			if ( $match_index < 0 && preg_match( $pattern, $name ) ) {
				if ( $size > self::MAX_ZIP_ENTRY_BYTES ) {
					$zip->close();
					throw new Exception( 'A file inside the zip is too large.' );
				}
				$match_index = $i;
			}
		}

		if ( $match_index < 0 ) {
			$zip->close();
			return '';
		}

		$stream = $zip->getStream( $zip->getNameIndex( $match_index ) );
		if ( ! $stream ) {
			$zip->close();
			return '';
		}

		$content = '';
		$read = 0;
		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 8192 );
			if ( $chunk === false ) { break; }
			$read += strlen( $chunk );
			if ( $read > self::MAX_ZIP_ENTRY_BYTES ) {
				fclose( $stream );
				$zip->close();
				throw new Exception( 'A file inside the zip is too large.' );
			}
			$content .= $chunk;
		}
		fclose( $stream );
		$zip->close();

		return (string) $content;
	}

	private static function parse_setup_cfg( string $cfg ): array {
		$cfg = trim( (string) $cfg );
		if ( $cfg === '' ) {
			return array();
		}

		// setup.cfg is INI-like. Prefer parse_ini_string with sections.
		$data = @parse_ini_string( $cfg, true, INI_SCANNER_RAW );
		if ( is_array( $data ) ) {
			if ( isset( $data['DEFAULT'] ) && is_array( $data['DEFAULT'] ) ) {
				return $data['DEFAULT'];
			}
			// Some configs may not use a section.
			return $data;
		}

		return array();
	}
}
