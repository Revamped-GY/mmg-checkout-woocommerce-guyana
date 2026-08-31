<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Diagnostics {
	private const CAP = 'mmgwc_access_diagnostics';
	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_mmgwc_refresh_fx', array( __CLASS__, 'handle_refresh_fx' ) );
	}

	public static function handle_refresh_fx(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'mmgwc_refresh_fx', 'mmgwc_nonce' );

		$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '';
		$currency = strtoupper( trim( (string) $currency ) );

		// Only accept ISO-4217-looking codes. Anything else is dropped before it can reach the HTTP call.
		if ( $currency !== '' && ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-diagnostics&fx_refresh=invalid' ) );
			exit;
		}

		if ( $currency !== '' && $currency !== 'GYD' ) {
			try {
				MMGWC_FX::get_rate_to_gyd( $currency, true );
			} catch ( Exception $e ) {
				MMGWC_Logger::error( 'FX refresh failed', array( 'currency' => $currency ) );
				wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-diagnostics&fx_refresh=failed' ) );
				exit;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-diagnostics&fx_refresh=ok' ) );
		exit;
	}

	public static function register_menu(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}
		// Under WooCommerce menu.
		add_submenu_page(
			'woocommerce',
			'MMG Diagnostics',
			'MMG Diagnostics',
			self::CAP,
			'mmgwc-diagnostics',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'Unknown';
		$wp_version = get_bloginfo( 'version' );
		$php_version = PHP_VERSION;

		$permalink_structure = (string) get_option( 'permalink_structure', '' );
		$has_openssl = extension_loaded( 'openssl' );
		$mode = MMGWC_Settings::get_mode();
		$debug = MMGWC_Settings::is_debug_enabled() ? 'Enabled' : 'Disabled';

		$callback = function_exists( 'WC' ) ? WC()->api_request_url( MMGWC_WC_API_ENDPOINT ) : home_url( '/?wc-api=' . MMGWC_WC_API_ENDPOINT );
		$callback_pretty = home_url( '/wc-api/' . MMGWC_WC_API_ENDPOINT . '/' );

		$logs_url = admin_url( 'admin.php?page=wc-status&tab=logs' );
		$store_currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$store_currency = strtoupper( trim( $store_currency ) );
		$conversion_enabled = (string) MMGWC_Settings::get( 'currency_conversion', 'no' ) === 'yes';

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Diagnostics' );
		}
		echo '<h1>MMG Checkout Diagnostics</h1>';
		echo '<p>This page helps you confirm your environment and MMG settings quickly.</p>';

		echo '<h2>Environment</h2>';
		echo '<table class="widefat striped" style="max-width: 980px">';
		echo '<tbody>';
		echo '<tr><th style="width:260px">Plugin version</th><td>' . esc_html( MMGWC_VERSION ) . '</td></tr>';
		echo '<tr><th>WordPress</th><td>' . esc_html( $wp_version ) . '</td></tr>';
		echo '<tr><th>WooCommerce</th><td>' . esc_html( $wc_version ) . '</td></tr>';
		echo '<tr><th>PHP</th><td>' . esc_html( $php_version ) . '</td></tr>';
		echo '<tr><th>OpenSSL</th><td>' . ( $has_openssl ? 'Available' : '<span style="color:#b32d2e">Missing</span>' ) . '</td></tr>';
		echo '<tr><th>Permalinks</th><td>' . ( $permalink_structure !== '' ? 'Pretty permalinks enabled' : '<span style="color:#b32d2e">Plain permalinks</span> (still works, but pretty permalinks are recommended)' ) . '</td></tr>';
		echo '<tr><th>Mode</th><td>' . esc_html( ucfirst( $mode ) ) . '</td></tr>';
		echo '<tr><th>Debug logging</th><td>' . esc_html( $debug ) . ' (Logs: <a href="' . esc_url( $logs_url ) . '">WooCommerce → Status → Logs</a>, source: <code>' . esc_html( MMGWC_LOG_SOURCE ) . '</code>)</td></tr>';
		echo '</tbody>';
		echo '</table>';

		echo '<h2>Callback URLs</h2>';
		echo '<p>Use either callback URL depending on what MMG accepts. The plugin supports both formats.</p>';
		echo '<ul>';
		echo '<li><strong>Recommended</strong>: <code>' . esc_html( $callback ) . '</code></li>';
		echo '<li>Pretty format: <code>' . esc_html( $callback_pretty ) . '</code></li>';
		echo '</ul>';

		echo '<h2>Credential checks</h2>';
		$active = MMGWC_Settings::get_config( $mode );
		$missing = array();
		foreach ( array( 'checkout_url', 'merchant_id', 'client_id', 'merchant_name', 'secret_key', 'public_key', 'private_key' ) as $k ) {
			if ( empty( $active[ $k ] ) ) {
				$missing[] = $k;
			}
		}

		if ( empty( $missing ) ) {
			echo '<p><span style="color:#046b2c">Active mode credentials look complete.</span></p>';
		} else {
			echo '<p><span style="color:#b32d2e">Active mode is missing:</span> <code>' . esc_html( implode( ', ', $missing ) ) . '</code></p>';
			echo '<p>Go to <strong>MMG Checkout → Settings</strong> and complete the missing fields.</p>';
		}

		echo '<h2>Currency conversion</h2>';
		echo '<table class="widefat striped" style="max-width: 980px"><tbody>';
		echo '<tr><th style="width:260px">Store currency</th><td>' . esc_html( $store_currency !== '' ? $store_currency : 'Unknown' ) . '</td></tr>';
		echo '<tr><th>Conversion enabled</th><td>' . ( $conversion_enabled ? '<span style="color:#046b2c">Yes</span>' : '<span style="color:#b32d2e">No</span>' ) . '</td></tr>';

		if ( $store_currency !== '' && $store_currency !== 'GYD' ) {
			$rate_row = 'Not available';
			$meta_row = '';
			try {
				$info = MMGWC_FX::get_rate_to_gyd( $store_currency, false );
				$rate_row = '1 ' . esc_html( $store_currency ) . ' = ' . esc_html( (string) $info['rate'] ) . ' GYD';
				$meta_row = ( $info['date'] !== '' ? 'Date: ' . esc_html( (string) $info['date'] ) . '. ' : '' ) . 'Source: ' . esc_html( (string) $info['source'] ) . ( ! empty( $info['cached'] ) ? ' (cached)' : '' );
			} catch ( Exception $e ) {
				$rate_row = '<span style="color:#b32d2e">Could not fetch rate</span> (' . esc_html( $e->getMessage() ) . ')';
			}
			echo '<tr><th>Latest rate</th><td>' . $rate_row . '<br><span class="description">' . $meta_row . '</span></td></tr>';
			echo '<tr><th>Rounding</th><td>Converted amount is rounded to the nearest <strong>100 GYD</strong> before sending to MMG.</td></tr>';
		} else {
			echo '<tr><th>Latest rate</th><td>Not needed (store currency is GYD).</td></tr>';
		}
		echo '</tbody></table>';

		if ( $store_currency !== '' && $store_currency !== 'GYD' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
			echo '<input type="hidden" name="action" value="mmgwc_refresh_fx">';
			echo '<input type="hidden" name="currency" value="' . esc_attr( $store_currency ) . '">';
			wp_nonce_field( 'mmgwc_refresh_fx', 'mmgwc_nonce' );
			echo '<p><button type="submit" class="button">Refresh exchange rate</button></p>';
			echo '</form>';
		}

		echo '<h2>Optional: Store credentials in wp-config.php</h2>';
		echo '<p>If you prefer not to store secrets in the database, you can define constants in <code>wp-config.php</code>. Any constant you define will override the gateway settings.</p>';
		echo '<pre style="max-width: 980px; white-space: pre-wrap;">';
		echo esc_html(
			"// General\n" .
			"define('MMGWC_MODE', 'sandbox'); // or 'live'\n" .
			"define('MMGWC_DEBUG', false);\n\n" .
			"// Sandbox\n" .
			"define('MMGWC_SANDBOX_CHECKOUT_URL', 'https://mmgpg.mmgtest.net/mmg-pg/web/payments');\n" .
			"define('MMGWC_SANDBOX_MERCHANT_ID', '...');\n" .
			"define('MMGWC_SANDBOX_CLIENT_ID', '...');\n" .
			"define('MMGWC_SANDBOX_MERCHANT_NAME', '...');\n" .
			"define('MMGWC_SANDBOX_SECRET_KEY', '...');\n" .
			"define('MMGWC_SANDBOX_PUBLIC_KEY', " . '"' . "-----BEGIN PUBLIC KEY-----\\n...\\n-----END PUBLIC KEY-----" . '"' . ");\n" .
			"define('MMGWC_SANDBOX_PRIVATE_KEY', " . '"' . "-----BEGIN PRIVATE KEY-----\\n...\\n-----END PRIVATE KEY-----" . '"' . ");\n\n" .
			"// Live\n" .
			"define('MMGWC_LIVE_CHECKOUT_URL', 'https://mmgpg.mymmg.gy/mmg-pg/web/payments');\n" .
			"define('MMGWC_LIVE_MERCHANT_ID', '...');\n" .
			"define('MMGWC_LIVE_CLIENT_ID', '...');\n" .
			"define('MMGWC_LIVE_MERCHANT_NAME', '...');\n" .
			"define('MMGWC_LIVE_SECRET_KEY', '...');\n" .
			"define('MMGWC_LIVE_PUBLIC_KEY', " . '"' . "-----BEGIN PUBLIC KEY-----\\n...\\n-----END PUBLIC KEY-----" . '"' . ");\n" .
			"define('MMGWC_LIVE_PRIVATE_KEY', " . '"' . "-----BEGIN PRIVATE KEY-----\\n...\\n-----END PRIVATE KEY-----" . '"' . ");\n\n" .
			"// Sandbox API verification and optional approval requests\n" .
			"define('MMGWC_SANDBOX_MWALLET_BASE_URL', 'https://mwallet.mmgtest.net/olive/publisher/v1');\n" .
			"define('MMGWC_SANDBOX_API_KEY', '...');\n" .
			"define('MMGWC_SANDBOX_WSS_MID', '...');\n" .
			"define('MMGWC_SANDBOX_WSS_MKEY', '...');\n" .
			"define('MMGWC_SANDBOX_WSS_MSECRET', '...');\n" .
			"define('MMGWC_SANDBOX_API_PASSWORD', '...');\n" .
			"define('MMGWC_SANDBOX_API_CREDIT_ACCOUNT_ID', '...');\n\n" .
			"// Live API values must be issued by MMG\n" .
			"define('MMGWC_LIVE_MWALLET_BASE_URL', '...');\n" .
			"define('MMGWC_LIVE_API_KEY', '...');\n" .
			"define('MMGWC_LIVE_WSS_MID', '...');\n" .
			"define('MMGWC_LIVE_WSS_MKEY', '...');\n" .
			"define('MMGWC_LIVE_WSS_MSECRET', '...');\n" .
			"define('MMGWC_LIVE_API_PASSWORD', '...');\n" .
			"define('MMGWC_LIVE_API_CREDIT_ACCOUNT_ID', '...');\n"
		);
		echo '</pre>';

		echo '</div>';
	}
}
