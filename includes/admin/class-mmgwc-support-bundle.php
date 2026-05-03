<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Support_Bundle {
	private const CAP = 'mmgwc_access_support_bundle';
	private const NONCE_ACTION = 'mmgwc_support_bundle';
	private const MAX_LOG_BYTES = 1048576; // 1 MB per log file.

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_mmgwc_support_bundle_download', array( __CLASS__, 'handle_download' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$download_url = admin_url( 'admin-post.php' );
		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Support Bundle' );
		}
		echo '<h1>MMG Checkout Support Bundle</h1>';
		echo '<p>Generate a zipped support bundle you can share with Revamped GY support. Sensitive values are redacted automatically.</p>';

		echo '<form method="post" action="' . esc_url( $download_url ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_support_bundle_download" />';
		wp_nonce_field( self::NONCE_ACTION, '_mmgwc_nonce' );

		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Include</th><td>';
		echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="include_diagnostics" value="1" checked> Diagnostics</label>';
		echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="include_settings" value="1" checked> Settings snapshot (redacted)</label>';
		echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="include_logs" value="1" checked> Recent logs (redacted)</label>';
		echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="include_order" value="1"> A specific order</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Order ID (optional)</th><td>';
		echo '<input type="number" name="order_id" min="1" step="1" class="regular-text" placeholder="e.g. 1234" />';
		echo '<p class="description">If selected, the bundle will include the order summary and MMG metadata for that order.</p>';
		echo '</td></tr>';

		echo '</table>';

		submit_button( 'Download Support Bundle (.zip)' );
		echo '</form>';

		echo '<hr>';
		echo '<h2>Tip</h2>';
		echo '<p>If you are troubleshooting a single payment issue, include the Order ID. Also take a screenshot of the MMG dashboard transaction page for faster support.</p>';
		echo '</div>';
	}

	public static function handle_download(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'mmg-checkout-woocommerce' ) );
		}
		$nonce = isset( $_POST['_mmgwc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mmgwc_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mmg-checkout-woocommerce' ) );
		}

		$include_diagnostics = isset( $_POST['include_diagnostics'] );
		$include_settings    = isset( $_POST['include_settings'] );
		$include_logs        = isset( $_POST['include_logs'] );
		$include_order       = isset( $_POST['include_order'] );
		$order_id            = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive is not available on this server. Please ask your host to enable it.', 'mmg-checkout-woocommerce' ) );
		}

		// Build the bundle in the system temp dir (not wp-content/uploads, which may be publicly served).
		$filename = 'mmg-support-bundle-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 8, false, false ) . '.zip';
		$sys_tmp = sys_get_temp_dir();
		if ( ! is_string( $sys_tmp ) || $sys_tmp === '' || ! is_writable( $sys_tmp ) ) {
			$sys_tmp = get_temp_dir();
		}
		$tmp = rtrim( $sys_tmp, '/\\' ) . DIRECTORY_SEPARATOR . $filename;

		$zip = new ZipArchive();
		$opened = $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			wp_die( esc_html__( 'Could not create the support bundle zip file.', 'mmg-checkout-woocommerce' ) );
		}

		$zip->addFromString( 'README.txt', self::build_readme( $include_diagnostics, $include_settings, $include_logs, $include_order ? $order_id : 0 ) );

		if ( $include_diagnostics ) {
			$zip->addFromString( 'diagnostics.json', wp_json_encode( self::build_diagnostics(), JSON_PRETTY_PRINT ) );
		}

		if ( $include_settings ) {
			$zip->addFromString( 'settings-redacted.json', wp_json_encode( self::redact_settings( MMGWC_Settings::get_all() ), JSON_PRETTY_PRINT ) );
		}

		if ( $include_logs ) {
			$logs = self::collect_logs();
			if ( empty( $logs ) ) {
				$zip->addFromString( 'logs/NO_LOGS_FOUND.txt', "No MMG logs were found.\n\nIf WooCommerce logs are enabled, check: WooCommerce → Status → Logs.\n" );
			} else {
				foreach ( $logs as $log_name => $log_content ) {
					$zip->addFromString( 'logs/' . $log_name, $log_content );
				}
			}
		}

		if ( $include_order && $order_id > 0 ) {
			$order_data = self::build_order_summary( $order_id );
			if ( $order_data === null ) {
				$zip->addFromString( 'order/ORDER_NOT_FOUND.txt', 'Order not found for ID: ' . absint( $order_id ) . "\n" );
			} else {
				$zip->addFromString( 'order/order-' . absint( $order_id ) . '.json', wp_json_encode( $order_data, JSON_PRETTY_PRINT ) );
			}
		}

		$zip->close();

		// Stream download.
		if ( function_exists( 'ob_get_level' ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		readfile( $tmp );

		@unlink( $tmp );
		exit;
	}

	private static function build_readme( bool $diag, bool $settings, bool $logs, int $order_id ): string {
		$lines = array();
		$lines[] = 'MMG Checkout Support Bundle';
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = 'Plugin: MMG Checkout for WooCommerce';
		$lines[] = 'Plugin Version: ' . ( defined( 'MMGWC_VERSION' ) ? MMGWC_VERSION : 'Unknown' );
		$lines[] = '';
		$lines[] = 'Included:';
		$lines[] = '- Diagnostics: ' . ( $diag ? 'Yes' : 'No' );
		$lines[] = '- Settings snapshot (redacted): ' . ( $settings ? 'Yes' : 'No' );
		$lines[] = '- Logs (redacted): ' . ( $logs ? 'Yes' : 'No' );
		$lines[] = '- Order: ' . ( $order_id > 0 ? 'Yes (Order ID ' . $order_id . ')' : 'No' );
		$lines[] = '';
		$lines[] = 'Security:';
		$lines[] = '- Secrets and tokens are redacted automatically.';
		$lines[] = '- Do not share private key files via email or public links.';
		$lines[] = '';
		$lines[] = 'Support workflow:';
		$lines[] = '1) Share this zip with support';
		$lines[] = '2) Share the WooCommerce Order ID';
		$lines[] = '3) Share the MMG Transaction ID (if available)';
		return implode( "\n", $lines ) . "\n";
	}

	private static function build_diagnostics(): array {
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'Unknown';
		$wp_version = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'Unknown';
		$php_version = PHP_VERSION;

		$permalink_structure = (string) get_option( 'permalink_structure', '' );
		$has_openssl = extension_loaded( 'openssl' );
		$mode = MMGWC_Settings::get_mode();
		$debug = MMGWC_Settings::is_debug_enabled();

		$callback_api = function_exists( 'WC' ) ? WC()->api_request_url( MMGWC_WC_API_ENDPOINT ) : home_url( '/?wc-api=' . MMGWC_WC_API_ENDPOINT );
		$callback_pretty = home_url( '/wc-api/' . MMGWC_WC_API_ENDPOINT . '/' );

		$store_currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$store_currency = strtoupper( trim( $store_currency ) );

		$conversion_enabled = (string) MMGWC_Settings::get( 'currency_conversion', 'no' ) === 'yes';
		$fx_rate = null;
		$fx_date = null;
		$fx_source = null;
		$fx_fetched_at = null;
		$fx_cached = null;

		if ( class_exists( 'MMGWC_FX' ) && $store_currency !== '' && strtoupper( $store_currency ) !== 'GYD' ) {
			try {
				$fx = MMGWC_FX::get_rate_to_gyd( $store_currency, false );
				if ( is_array( $fx ) ) {
					$fx_rate = $fx['rate'] ?? null;
					$fx_date = $fx['date'] ?? null;
					$fx_source = $fx['source'] ?? null;
					$fx_fetched_at = $fx['fetched_at'] ?? null;
					$fx_cached = $fx['cached'] ?? null;
				}
			} catch ( Exception $e ) {
				// Ignore FX errors for diagnostics bundle generation.
			}
		}

		return array(
			'plugin_version' => defined( 'MMGWC_PLUGIN_VERSION' ) ? MMGWC_PLUGIN_VERSION : null,
			'wp_version' => $wp_version,
			'wc_version' => $wc_version,
			'php_version' => $php_version,
			'permalink_structure' => $permalink_structure,
			'openssl_loaded' => $has_openssl,
			'mode' => $mode,
			'debug_enabled' => $debug,
			'callback_api_url' => $callback_api,
			'callback_pretty_url' => $callback_pretty,
			'store_currency' => $store_currency,
			'currency_conversion_enabled' => $conversion_enabled,
			'fx_rate_to_gyd' => $fx_rate,
			'fx_rate_date' => $fx_date,
			'fx_source' => $fx_source,
			'fx_fetched_at' => $fx_fetched_at,
			'fx_cached' => $fx_cached,
		);
	}

	private static function redact_settings( array $settings ): array {
		// Allow-list: keys we intentionally include as-is in the support bundle.
		// Anything else is either partially redacted (known IDs) or fully redacted by default.
		$safe_keys = array(
			'enabled', 'mode', 'debug', 'title', 'description',
			'sandbox_checkout_url', 'live_checkout_url',
			'sandbox_merchant_name', 'live_merchant_name',
			'api_mwallet_base_url',
			'currency_conversion', 'fx_cache_hours',
			'status_success_virtual', 'status_success_physical',
			'status_cancelled', 'status_failed', 'status_timeout',
			'log_retention_days',
			'qr_checkout_fallback',
		);

		$partial_keys = array(
			'sandbox_merchant_id', 'sandbox_client_id',
			'live_merchant_id', 'live_client_id',
			'api_wss_mid',
		);

		$out = array();
		foreach ( $settings as $k => $v ) {
			$key = is_string( $k ) ? strtolower( $k ) : (string) $k;

			if ( in_array( $key, $safe_keys, true ) ) {
				$out[ $k ] = $v;
				continue;
			}
			if ( in_array( $key, $partial_keys, true ) && is_string( $v ) ) {
				$out[ $k ] = self::partial_redact( $v );
				continue;
			}
			// Default: fully redact. This is safer than a deny-list because any future key
			// that holds a secret (webhook_secret, bearer_token, etc.) is redacted by default.
			$out[ $k ] = '[redacted]';
		}
		return $out;
	}

	private static function partial_redact( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		$len = strlen( $value );
		if ( $len <= 6 ) {
			return str_repeat( '*', $len );
		}
		return substr( $value, 0, 2 ) . str_repeat( '*', max( 0, $len - 4 ) ) . substr( $value, -2 );
	}

	/**
	 * @return array<string, string> filename => content
	 */
	private static function collect_logs(): array {
		$dir = '';
		$paths = array();

		// Try the default WooCommerce log dir.
		$upload = wp_upload_dir();
		if ( is_array( $upload ) && isset( $upload['basedir'] ) ) {
			$candidate = trailingslashit( $upload['basedir'] ) . 'wc-logs/';
			if ( is_dir( $candidate ) ) {
				$dir = $candidate;
			}
		}

		if ( $dir === '' && defined( 'WC_LOG_DIR' ) ) {
			if ( is_string( WC_LOG_DIR ) && is_dir( WC_LOG_DIR ) ) {
				$dir = WC_LOG_DIR;
			}
		}

		if ( $dir !== '' ) {
			$glob = glob( $dir . '*' . MMGWC_LOG_SOURCE . '*.log' );
			if ( is_array( $glob ) ) {
				$paths = $glob;
			}
		}

		// If we can directly resolve the expected path for our handle, include it too.
		$maybe = self::resolve_log_path_for_handle( MMGWC_LOG_SOURCE );
		if ( is_string( $maybe ) && $maybe !== '' && file_exists( $maybe ) ) {
			$paths[] = $maybe;
		}

		$paths = array_values( array_unique( array_filter( $paths ) ) );
		if ( empty( $paths ) ) {
			return array();
		}

		usort( $paths, function( $a, $b ) {
			return ( filemtime( $b ) ?: 0 ) <=> ( filemtime( $a ) ?: 0 );
		} );

		$paths = array_slice( $paths, 0, 3 ); // up to 3 newest logs.
		$out = array();
		foreach ( $paths as $p ) {
			$name = basename( $p );
			$content = self::read_tail( $p, self::MAX_LOG_BYTES );
			$content = self::redact_text( $content );
			$out[ $name ] = $content;
		}
		return $out;
	}

	private static function resolve_log_path_for_handle( string $handle ): string {
		if ( class_exists( 'WC_Log_Handler_File' ) ) {
			if ( is_callable( array( 'WC_Log_Handler_File', 'get_log_file_path' ) ) ) {
				$path = WC_Log_Handler_File::get_log_file_path( $handle );
				return is_string( $path ) ? $path : '';
			}
			$handler = new WC_Log_Handler_File();
			if ( is_callable( array( $handler, 'get_log_file_path' ) ) ) {
				$path = $handler->get_log_file_path( $handle );
				return is_string( $path ) ? $path : '';
			}
		}
		return '';
	}

	private static function read_tail( string $path, int $max_bytes ): string {
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$size = filesize( $path );
		if ( ! is_int( $size ) || $size <= 0 ) {
			$raw = @file_get_contents( $path );
			return is_string( $raw ) ? $raw : '';
		}
		if ( $size <= $max_bytes ) {
			$raw = @file_get_contents( $path );
			return is_string( $raw ) ? $raw : '';
		}
		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return '';
		}
		@fseek( $fp, -1 * $max_bytes, SEEK_END );
		$data = @fread( $fp, $max_bytes );
		@fclose( $fp );
		return is_string( $data ) ? $data : '';
	}

	private static function redact_text( string $text ): string {
		// Reuse the logger redaction patterns and add a few extra.
		$text = preg_replace( '/token=([A-Za-z0-9\-_\.]+)/', 'token=[redacted]', $text );
		$text = preg_replace( '/mmg-checkout\/[A-Za-z0-9\-_\.]+/', 'mmg-checkout/[redacted]', $text );
		$text = preg_replace( '/(api_key\s*[:=]\s*)([^\s\"]+)/i', '$1[redacted]', $text );
		$text = preg_replace( '/(secret_key\s*[:=]\s*)([^\s\"]+)/i', '$1[redacted]', $text );
		$text = preg_replace( '/(wss_msecret\s*[:=]\s*)([^\s\"]+)/i', '$1[redacted]', $text );
		return is_string( $text ) ? $text : '';
	}

	private static function build_order_summary( int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$items[] = array(
				'item_id' => $item_id,
				'product_id' => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name' => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total' => $item->get_total(),
				'subtotal' => $item->get_subtotal(),
			);
		}

		$meta = array(
			'merchant_transaction_id' => (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID ),
			'transaction_id' => (string) $order->get_meta( MMGWC_META_TXN_ID ),
			'result_code' => (string) $order->get_meta( MMGWC_META_RESULT_CODE ),
			'result_message' => (string) $order->get_meta( MMGWC_META_RESULT_MESSAGE ),
			'last_verified_at' => (string) $order->get_meta( MMGWC_META_LAST_VERIFIED_AT ),
			'processed_transaction_id' => (string) $order->get_meta( MMGWC_META_PROCESSED_TXN_ID ),
			'payment_request' => (string) $order->get_meta( MMGWC_META_PAYMENT_REQUEST ),
			'payment_request_expires_at' => (string) $order->get_meta( MMGWC_META_PR_EXPIRES_AT ),
			// FX metadata.
			'original_currency' => (string) $order->get_meta( MMGWC_META_ORIGINAL_CURRENCY ),
			'original_total' => (string) $order->get_meta( MMGWC_META_ORIGINAL_TOTAL ),
			'fx_rate_to_gyd' => (string) $order->get_meta( MMGWC_META_FX_RATE ),
			'fx_date' => (string) $order->get_meta( MMGWC_META_FX_DATE ),
			'amount_gyd_raw' => (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD_RAW ),
			'amount_gyd_rounded' => (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD ),
			'rounding_delta' => (string) $order->get_meta( MMGWC_META_MMG_ROUNDING_DELTA ),
		);

		return array(
			'order_id' => $order->get_id(),
			'status' => $order->get_status(),
			'currency' => $order->get_currency(),
			'total' => $order->get_total(),
			'created' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'billing_email' => $order->get_billing_email(),
			'billing_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'items' => $items,
			'mmg_meta' => $meta,
		);
	}
}
