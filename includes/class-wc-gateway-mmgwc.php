<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_MMGWC extends WC_Payment_Gateway {
	public function __construct() {
		$this->id = 'mmg_checkout';
		$this->method_title = 'MMG Checkout';
		$this->method_description = 'Accept payments via MMG Merchant Checkout. Settings: WP Admin → MMG Checkout → Settings.';
		$this->has_fields = false;

		$this->supports = array( 'products', 'pay_for_order' );

		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_migrate_checkout_urls();


		// Advanced: allow custom order status mapping on payment complete.
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'filter_payment_complete_order_status' ), 10, 3 );
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled = $this->get_option( 'enabled' );

		// Frontend presentation.
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'filter_gateway_title' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Encrypt protected keys before they hit the database on save.
		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'filter_sanitize_sensitive_fields' ) );

		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_display_mmg_meta' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'frontend_display_mmg_meta' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_display_mmg_meta' ), 10, 4 );
	}

	/**
	 * Gateway icon URL (filterable).
	 */
	public static function get_gateway_icon_url() {
		$default = defined( 'MMGWC_GATEWAY_ICON_URL' ) ? MMGWC_GATEWAY_ICON_URL : '';
		return apply_filters( 'mmgwc_gateway_icon_url', $default );
	}

	/**
	 * Enqueue lightweight frontend styles for checkout label alignment.
	 */
	public static function enqueue_frontend_assets() {
		if ( is_admin() ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			return;
		}
		wp_enqueue_style( 'mmgwc-gateway-frontend', MMGWC_PLUGIN_URL . 'assets/css/gateway.css', array(), MMGWC_VERSION );
	}

	/**
	 * Inject MMG icon neatly next to the gateway label (Classic checkout and pay-for-order screens).
	 */
	public static function filter_gateway_title( $title, $gateway_id ) {
		if ( (string) $gateway_id !== 'mmg_checkout' ) {
			return $title;
		}
		if ( is_admin() ) {
			return $title;
		}
		if ( ! function_exists( 'is_checkout' ) ) {
			return $title;
		}
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			return $title;
		}
		// Avoid double wrapping.
		if ( is_string( $title ) && strpos( $title, 'mmgwc-gateway-label' ) !== false ) {
			return $title;
		}
		$icon_url = self::get_gateway_icon_url();
		if ( empty( $icon_url ) ) {
			return $title;
		}
		$img = '<img class="mmgwc-gateway-icon" src="' . esc_url( $icon_url ) . '" alt="" loading="lazy" decoding="async" />';
		$out = '<span class="mmgwc-gateway-label">' . $img . '<span class="mmgwc-gateway-text">' . wp_kses_post( $title ) . '</span></span>';
		return $out;
	}

	/**
	 * Allow optional wp-config.php constant overrides.
	 *
	 * MMGWC_Settings::get handles decryption transparently for protected keys,
	 * so callers always see plaintext.
	 */
	public function get_option( $key, $empty_value = null ) {
		$default = parent::get_option( $key, $empty_value );
		return MMGWC_Settings::get( (string) $key, $default );
	}

	/**
	 * Encrypt any sensitive fields before WooCommerce persists them.
	 */
	public function filter_sanitize_sensitive_fields( $sanitized ) {
		if ( ! is_array( $sanitized ) ) {
			return $sanitized;
		}
		if ( class_exists( 'MMGWC_Secure_Store' ) ) {
			foreach ( MMGWC_Secure_Store::protected_keys() as $k ) {
				if ( array_key_exists( $k, $sanitized ) && is_string( $sanitized[ $k ] ) && $sanitized[ $k ] !== '' ) {
					$sanitized[ $k ] = MMGWC_Secure_Store::encrypt( $sanitized[ $k ] );
				}
			}
		}
		return $sanitized;
	}

	/**
	 * Persist gateway settings with autoload=no so the PEM blobs don't load on every request.
	 * WooCommerce's default stores the option with autoload=yes, which is wasteful for
	 * large credential fields. We intercept the same save path used by process_admin_options().
	 */
	public function process_admin_options() {
		$result = parent::process_admin_options();
		$option_key = $this->get_option_key();
		$value = get_option( $option_key );
		if ( is_array( $value ) ) {
			// Re-save with autoload=no.
			delete_option( $option_key );
			add_option( $option_key, $value, '', false );
		}
		return $result;
	}

	public function init_form_fields() {
		$callback = esc_url( WC()->api_request_url( MMGWC_WC_API_ENDPOINT ) );

		// Order status options for advanced mapping.
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$status_options = array( '' => 'WooCommerce default' );
		$status_options_all = array();
		foreach ( $wc_statuses as $key => $label ) {
			$slug = str_replace( 'wc-', '', (string) $key );
			$status_options[ $slug ] = $label;
			$status_options_all[ $slug ] = $label;
		}
		if ( empty( $status_options_all ) ) {
			$status_options_all = array( 'pending' => 'Pending payment', 'processing' => 'Processing', 'completed' => 'Completed', 'on-hold' => 'On hold', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed' );
			$status_options = array_merge( array( '' => 'WooCommerce default' ), $status_options_all );
		}

		$this->form_fields = array(
			'enabled' => array(
				'title' => 'Enable/Disable',
				'label' => 'Enable MMG Checkout',
				'type' => 'checkbox',
				'default' => 'no',
			),
			'title' => array(
				'title' => 'Title',
				'type' => 'text',
				'description' => 'This controls the payment method title customers see during checkout.',
				'default' => 'MMG',
				'desc_tip' => true,
			),
			'description' => array(
				'title' => 'Description',
				'type' => 'textarea',
				'description' => 'This controls the payment method description customers see during checkout.',
				'default' => 'You will be redirected to MMG to complete your payment.',
			),
			'mode' => array(
				'title' => 'Mode',
				'type' => 'select',
				'description' => 'Choose Sandbox for testing, then switch to Live when ready.',
				'default' => 'sandbox',
				'options' => array(
					'sandbox' => 'Sandbox',
					'live' => 'Live',
				),
			),
			'debug' => array(
				'title' => 'Debug log',
				'label' => 'Enable logging (WooCommerce → Status → Logs)',
				'type' => 'checkbox',
				'default' => 'no',
			),

			'callback_info' => array(
				'title' => 'Callback URL',
				'type' => 'title',
				'description' => 'Copy the MMG Response/Callback URL from <strong>MMG Checkout → Diagnostics</strong> and include it when you request your MMG credential package (UAT or Live) from MMG Merchant Services. MMG will return an encrypted <code>token</code> to this URL: <code>' . esc_html( $callback ) . '</code>',
			),

			'sandbox_section' => array(
				'title' => 'Sandbox credentials',
				'type' => 'title',
				'description' => 'Use these settings while Mode is Sandbox.',
			),
			'sandbox_checkout_url' => array(
				'title' => 'Sandbox Checkout URL',
				'type' => 'text',
				'default' => 'https://mmgpg.mmgtest.net/mmg-pg/web/payments',
				'description' => 'Base URL for the sandbox checkout page.',
			),
			'sandbox_merchant_id' => array(
				'title' => 'Sandbox Merchant ID',
				'type' => 'text',
			),
			'sandbox_client_id' => array(
				'title' => 'Sandbox Client ID',
				'type' => 'text',
			),
			'sandbox_merchant_name' => array(
				'title' => 'Sandbox Merchant Name',
				'type' => 'text',
			),
			'sandbox_secret_key' => array(
				'title' => 'Sandbox Secret Key',
				'type' => 'password',
			),
			'sandbox_public_key' => array(
				'title' => 'Sandbox Public Key (PEM)',
				'type' => 'textarea',
				'css' => 'min-height:140px;',
				'description' => 'The MMG public key in PEM format (used to encrypt the payment request token).',
			),
			'sandbox_private_key' => array(
				'title' => 'Sandbox Private Key (PEM)',
				'type' => 'textarea',
				'css' => 'min-height:140px;',
				'description' => 'The private key in PEM format (used to decrypt MMG response token). Keep this safe.',
			),

			'live_section' => array(
				'title' => 'Live credentials',
				'type' => 'title',
				'description' => 'Use these settings while Mode is Live.',
			),
			'live_checkout_url' => array(
				'title' => 'Live Checkout URL',
				'type' => 'text',
				'default' => 'https://mmgpg.mymmg.gy/mmg-pg/web/payments',
				'description' => 'Base URL for the live checkout page.',
			),
			'live_merchant_id' => array(
				'title' => 'Live Merchant ID',
				'type' => 'text',
			),
			'live_client_id' => array(
				'title' => 'Live Client ID',
				'type' => 'text',
			),
			'live_merchant_name' => array(
				'title' => 'Live Merchant Name',
				'type' => 'text',
			),
			'live_secret_key' => array(
				'title' => 'Live Secret Key',
				'type' => 'password',
			),
			'live_public_key' => array(
				'title' => 'Live Public Key (PEM)',
				'type' => 'textarea',
				'css' => 'min-height:140px;',
			),
			'live_private_key' => array(
				'title' => 'Live Private Key (PEM)',
				'type' => 'textarea',
				'css' => 'min-height:140px;',
			),

			'api_section' => array(
				'title' => 'Optional: Merchant Initiated API (for lookups)',
				'type' => 'title',
				'description' => 'If provided, the plugin can call the MMG Transaction Lookup API and store extra details. If you leave these blank, checkout still works.',
			),
			'api_mwallet_base_url' => array(
				'title' => 'MWallet Base URL',
				'type' => 'text',
				'default' => 'https://mwallet.mmgtest.net/mwallet/v1',
			),
			'api_key' => array(
				'title' => 'x-api-key',
				'type' => 'text',
			),
			'api_wss_mid' => array(
				'title' => 'x-wss-mid (Merchant MSISDN)',
				'type' => 'text',
			),
			'api_wss_mkey' => array(
				'title' => 'x-wss-mkey',
				'type' => 'text',
			),
			'api_wss_msecret' => array(
				'title' => 'x-wss-msecret',
				'type' => 'text',
			),
			'api_password' => array(
				'title' => 'API Password',
				'type' => 'password',
				'description' => 'Used to obtain a resource token (login endpoint).',
			),

			'currency_section' => array(
				'title' => 'Currency conversion',
				'type' => 'title',
				'description' => 'MMG settles in <strong>GYD</strong>. If your store uses another currency, enable automatic conversion to GYD. The converted value is rounded to the nearest 100 GYD to keep the amount neat.',
			),
			'currency_conversion' => array(
				'title' => 'Enable conversion to GYD',
				'label' => 'Auto convert non-GYD orders to GYD for MMG checkout',
				'type' => 'checkbox',
				'default' => 'no',
				'description' => 'When enabled, the plugin will fetch the latest exchange rate and charge the converted amount in GYD via MMG. The original order total stays in your store currency for reporting.',
			),
			'fx_cache_hours' => array(
				'title' => 'Rate cache',
				'type' => 'select',
				'default' => '12',
				'description' => 'How often the exchange rate should be refreshed automatically. You can also refresh manually on the Diagnostics page.',
				'desc_tip' => true,
				'options' => array(
					'1' => '1 hour',
					'6' => '6 hours',
					'12' => '12 hours',
					'24' => '24 hours',
				),
			),

			'advanced_section' => array(
				'title' => 'Advanced',
				'type' => 'title',
				'description' => 'Advanced options for order status mapping, log retention and support tooling.',
			),
			'status_success_virtual' => array(
				'title' => 'Success status (virtual orders)',
				'type' => 'select',
				'default' => '',
				'description' => 'Choose the order status to use after successful payment when the order does not need processing (for example virtual or downloadable products). Leave as WooCommerce default to keep the standard behaviour.',
				'desc_tip' => true,
				'options' => $status_options,
			),
			'status_success_physical' => array(
				'title' => 'Success status (physical orders)',
				'type' => 'select',
				'default' => '',
				'description' => 'Choose the order status to use after successful payment when the order needs processing (for example physical products). Leave as WooCommerce default to keep the standard behaviour.',
				'desc_tip' => true,
				'options' => $status_options,
			),
			'status_cancelled' => array(
				'title' => 'Status when cancelled',
				'type' => 'select',
				'default' => 'cancelled',
				'options' => $status_options_all,
			),
			'status_failed' => array(
				'title' => 'Status when failed',
				'type' => 'select',
				'default' => 'failed',
				'options' => $status_options_all,
			),
			'status_timeout' => array(
				'title' => 'Status when timed out',
				'type' => 'select',
				'default' => 'failed',
				'options' => $status_options_all,
			),
			'log_retention_days' => array(
				'title' => 'Log retention (days)',
				'type' => 'select',
				'default' => '14',
				'description' => 'If Debug is enabled, MMG logs will be automatically rotated to keep this many days. If Debug is disabled, logs are kept for 2 days.',
				'desc_tip' => true,
				'options' => array(
					'7' => '7 days',
					'14' => '14 days',
					'30' => '30 days',
				),
			),
		);
	}

	private function is_debug_enabled(): bool {
		return MMGWC_Settings::is_debug_enabled();
	}

	private function get_mode(): string {
		$mode = (string) $this->get_option( 'mode', 'sandbox' );
		return $mode === 'live' ? 'live' : 'sandbox';
	}

/**
 * Normalise checkout base URL values for known production hosts.
 */
private function normalize_checkout_url( string $url, string $mode ): string {
	$url = trim( $url );
	if ( $url === '' ) {
		return '';
	}

	$mode = $mode === 'live' ? 'live' : 'sandbox';

	// Some older builds used mmgpg.mmg.gy, which can cause "site can’t be reached".
	if ( $mode === 'live' && strpos( $url, 'mmgpg.mmg.gy' ) !== false ) {
		$url = str_replace( 'mmgpg.mmg.gy', 'mmgpg.mymmg.gy', $url );
	}

	return $url;
}

/**
 * Auto-migrate known bad URLs in saved settings.
 */
private function maybe_migrate_checkout_urls(): void {
	$changed = false;

	if ( isset( $this->settings['live_checkout_url'] ) ) {
		$old = (string) $this->settings['live_checkout_url'];
		$new = $this->normalize_checkout_url( $old, 'live' );
		if ( $new !== '' && $new !== $old ) {
			$this->settings['live_checkout_url'] = $new;
			$changed = true;
		}
	}

	if ( $changed ) {
		update_option( $this->get_option_key(), $this->settings );
	}
}


	public function get_config_for_mode( string $mode ): array {
		$mode = $mode === 'live' ? 'live' : 'sandbox';
		$prefix = $mode === 'live' ? 'live_' : 'sandbox_';

		return array(
			'mode' => $mode,
			'checkout_url' => $this->normalize_checkout_url( trim( (string) $this->get_option( $prefix . 'checkout_url', '' ) ), $mode ),
			'merchant_id' => trim( (string) $this->get_option( $prefix . 'merchant_id', '' ) ),
			'client_id' => trim( (string) $this->get_option( $prefix . 'client_id', '' ) ),
			'merchant_name' => trim( (string) $this->get_option( $prefix . 'merchant_name', '' ) ),
			'secret_key' => trim( (string) $this->get_option( $prefix . 'secret_key', '' ) ),
			'public_key' => (string) $this->get_option( $prefix . 'public_key', '' ),
			'private_key' => (string) $this->get_option( $prefix . 'private_key', '' ),

			// Optional API details.
			'mwallet_base_url' => trim( (string) $this->get_option( 'api_mwallet_base_url', '' ) ),
			'api_key' => trim( (string) $this->get_option( 'api_key', '' ) ),
			'wss_mid' => trim( (string) $this->get_option( 'api_wss_mid', '' ) ),
			'wss_mkey' => trim( (string) $this->get_option( 'api_wss_mkey', '' ) ),
			'wss_msecret' => trim( (string) $this->get_option( 'api_wss_msecret', '' ) ),
			'password' => trim( (string) $this->get_option( 'api_password', '' ) ),
		);
	}

	private function get_active_config(): array {
		return $this->get_config_for_mode( $this->get_mode() );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'fail' );
		}

		// If the order is already paid / no longer needs payment, short-circuit safely.
		if ( ! $order->needs_payment() ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		try {
			$redirect = $this->build_mmg_redirect_url( $order );
		} catch ( Exception $e ) {
			MMGWC_Logger::error( 'MMG build redirect failed: ' . $e->getMessage(), array( 'order_id' => $order->get_id() ) );
			wc_add_notice( 'Could not start MMG payment. Please try again.', 'error' );
			return array( 'result' => 'fail' );
		}

		return array(
			'result' => 'success',
			'redirect' => $redirect,
		);
	}

	public function build_mmg_redirect_url( WC_Order $order, bool $force_new = false ): string {
		$config = $this->get_active_config();

		foreach ( array( 'checkout_url', 'merchant_id', 'client_id', 'merchant_name', 'secret_key', 'public_key' ) as $key ) {
			if ( empty( $config[ $key ] ) ) {
				throw new RuntimeException( 'Missing MMG setting: ' . $key );
			}
		}

		// Initiation id handling:
		// - Default is "always fresh": every call to build_mmg_redirect_url generates a new
		//   merchant transaction id and a new MMG session URL. This is the correct behaviour for
		//   MMG because MMG rejects a reused merchantTransactionId with "We are unable to process
		//   your request", which breaks any customer retry.
		// - The only reason we still keep a tiny reuse path at all is the same-second edge case:
		//   if two concurrent AJAX calls to process_payment land inside the same wall-clock second,
		//   our "<order_id>-<unix_seconds>" id would collide. Returning the cached URL for that
		//   single-second overlap avoids sending a duplicate id to MMG.
		// - Any site that explicitly wants a longer reuse window can raise the default via the
		//   mmgwc_checkout_session_reuse_seconds filter. The default is 0.
		$merchant_txn_id = (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID );
		$initiated_at    = (int) $order->get_meta( '_mmg_initiated_at' );
		$now             = time();
		$last_url  = (string) $order->get_meta( '_mmgwc_last_checkout_url' );
		$last_at   = (int) $order->get_meta( '_mmgwc_last_checkout_url_at' );
		$last_mode = (string) $order->get_meta( '_mmgwc_last_checkout_url_mode' );

		$reuse_window_seconds = (int) apply_filters( 'mmgwc_checkout_session_reuse_seconds', 0 );
		if ( $reuse_window_seconds < 0 ) {
			$reuse_window_seconds = 0;
		}

		if ( ! $force_new && $order->needs_payment() && $last_url !== '' && $last_at > 0 && ( $now - $last_at ) <= $reuse_window_seconds && $last_mode === (string) $config['mode'] ) {
			MMGWC_Logger::info(
				'MMG redirect reused',
				array(
					'order_id' => $order->get_id(),
					'mode' => $config['mode'],
				)
			);
			return $last_url;
		}


		$reuse_window = $reuse_window_seconds; // seconds – same window for the merchant_txn_id.

		if ( $force_new || $merchant_txn_id === '' || $initiated_at <= 0 ) {
			$merchant_txn_id = (string) $order->get_id() . '-' . (string) $now;
			$order->update_meta_data( MMGWC_META_MERCHANT_TXN_ID, $merchant_txn_id );
			$order->update_meta_data( '_mmg_initiated_at', $now );
		} elseif ( ( $now - $initiated_at ) > $reuse_window ) {
			$merchant_txn_id = (string) $order->get_id() . '-' . (string) $now;
			$order->update_meta_data( MMGWC_META_MERCHANT_TXN_ID, $merchant_txn_id );
			$order->update_meta_data( '_mmg_initiated_at', $now );
		}

		$order->update_meta_data( '_mmg_mode', $config['mode'] );
		$order->save();

		$total = (float) $order->get_total();
		$order_currency = strtoupper( (string) $order->get_currency() );
		$mmg_total = $total;

		// MMG settles in GYD. If the order currency is not GYD, optionally convert.
		if ( $order_currency !== 'GYD' && $total > 0 ) {
			$enabled = (string) MMGWC_Settings::get( 'currency_conversion', 'no' );
			if ( $enabled !== 'yes' ) {
				throw new RuntimeException( 'This store is using ' . $order_currency . '. MMG requires GYD. Enable Currency conversion in MMG Checkout settings or switch the store currency to GYD.' );
			}

			$rate_info = MMGWC_FX::get_rate_to_gyd( $order_currency, false );
			$conv = MMGWC_FX::convert_and_round_to_gyd( $total, (float) $rate_info['rate'], 100 );
			$mmg_total = (float) $conv['rounded'];

			// Store conversion details for clear reporting.
			$order->update_meta_data( MMGWC_META_ORIGINAL_CURRENCY, $order_currency );
			$order->update_meta_data( MMGWC_META_ORIGINAL_TOTAL, wc_format_decimal( $total, 2 ) );
			$order->update_meta_data( MMGWC_META_FX_RATE, (string) $rate_info['rate'] );
			$order->update_meta_data( MMGWC_META_FX_DATE, (string) $rate_info['date'] );
			$order->update_meta_data( MMGWC_META_MMG_AMOUNT_GYD_RAW, wc_format_decimal( (float) $conv['raw'], 2 ) );
			$order->update_meta_data( MMGWC_META_MMG_AMOUNT_GYD, (string) (int) round( $mmg_total ) );
			$order->update_meta_data( MMGWC_META_MMG_ROUNDING_DELTA, wc_format_decimal( (float) $conv['delta'], 2 ) );
			$order->save();
		} else {
			// Clear any previous conversion metadata.
			$order->delete_meta_data( MMGWC_META_ORIGINAL_CURRENCY );
			$order->delete_meta_data( MMGWC_META_ORIGINAL_TOTAL );
			$order->delete_meta_data( MMGWC_META_FX_RATE );
			$order->delete_meta_data( MMGWC_META_FX_DATE );
			$order->delete_meta_data( MMGWC_META_MMG_AMOUNT_GYD_RAW );
			$order->delete_meta_data( MMGWC_META_MMG_AMOUNT_GYD );
			$order->delete_meta_data( MMGWC_META_MMG_ROUNDING_DELTA );
			$order->save();
		}

		// MMG amount is always in GYD.
		if ( abs( $mmg_total - round( $mmg_total ) ) < 0.00001 ) {
			$amount = (string) (int) round( $mmg_total );
		} else {
			$amount = (string) wc_format_decimal( $mmg_total, 2 );
		}

		$payload = array(
			'secretKey' => $config['secret_key'],
			'amount' => $amount,
			'merchantId' => $config['merchant_id'],
			'merchantName' => $config['merchant_name'],
			'merchantTransactionId' => $merchant_txn_id,
			'productDescription' => sprintf( 'Order #%s', $order->get_order_number() ),
			'requestInitiationTime' => (string) time(),
		);

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$json = mb_convert_encoding( $json, 'ISO-8859-1', 'UTF-8' );
		}

		$cipher = MMGWC_Crypto::encrypt_oaep_sha256( $json, $config['public_key'] );
		$token = MMGWC_Crypto::base64url_encode( $cipher );

		$checkout_url = $config['checkout_url'];
		$checkout_url = add_query_arg(
			array(
				'token' => $token,
				'merchantId' => $config['merchant_id'],
				'X-Client-ID' => $config['client_id'],
			),
			$checkout_url
		);

		// Store for reuse while the customer is still on the MMG page.
		$order->update_meta_data( '_mmgwc_last_checkout_url', $checkout_url );
		$order->update_meta_data( '_mmgwc_last_checkout_url_at', (int) time() );
		$order->update_meta_data( '_mmgwc_last_checkout_url_mode', (string) $config['mode'] );
		$order->save();

		MMGWC_Logger::debug(
			'MMG redirect built',
			array(
				'order_id' => $order->get_id(),
				'merchant_transaction_id' => (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID ),
				'checkout_url' => $checkout_url,
				'mode' => $config['mode'],
			)
		);

		return $checkout_url;
	}

	private function map_result_code( string $code ): array {
		$code = trim( $code );
		switch ( $code ) {
			case '0':
				return array( 'type' => 'success', 'label' => 'Payment successful' );
			case '1':
				return array( 'type' => 'failed', 'label' => 'Agent not registered' );
			case '2':
				return array( 'type' => 'failed', 'label' => 'Payment failed' );
			case '3':
				return array( 'type' => 'error', 'label' => 'Invalid secret key' );
			case '4':
				return array( 'type' => 'error', 'label' => 'Merchant ID mismatch' );
			case '5':
				return array( 'type' => 'error', 'label' => 'Token decryption failed' );
			case '6':
				return array( 'type' => 'cancelled', 'label' => 'Transaction cancelled' );
			case '7':
				return array( 'type' => 'timeout', 'label' => 'Request timed out' );
			default:
				return array( 'type' => 'failed', 'label' => 'Payment not completed' );
		}
	}

	private function add_customer_notice( string $message, string $type = 'error' ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			wc_add_notice( $message, $type );
		}
	}

	public function decrypt_mmg_response( string $token ): array {
		$cipher = MMGWC_Crypto::base64url_decode( $token );

		$active_mode = $this->get_mode();
		$modes_to_try = array( $active_mode, $active_mode === 'live' ? 'sandbox' : 'live' );
		$plain = null;
		$succeeded_mode = '';
		foreach ( $modes_to_try as $mode ) {
			$config = $this->get_config_for_mode( $mode );
			if ( empty( $config['private_key'] ) ) {
				continue;
			}
			try {
				$plain = MMGWC_Crypto::decrypt_oaep_sha256( $cipher, $config['private_key'] );
				$succeeded_mode = $mode;
				break;
			} catch ( Exception $e ) {
				MMGWC_Logger::debug( 'MMG decrypt failed for mode', array( 'mode' => $mode ) );
			}
		}

		if ( ! is_string( $plain ) ) {
			throw new RuntimeException( 'Unable to decrypt token' );
		}

		// Surface a mode mismatch so admins notice if a wrong-env credential was in use.
		if ( $succeeded_mode !== $active_mode ) {
			MMGWC_Logger::warning( 'MMG callback decrypted with non-active mode', array(
				'active_mode'    => $active_mode,
				'succeeded_mode' => $succeeded_mode,
			) );
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$plain = mb_convert_encoding( $plain, 'UTF-8', 'ISO-8859-1' );
		}

		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'MMG response is not JSON' );
		}
		return $data;
	}

	private function clear_checkout_session_meta( WC_Order $order ): void {
		$order->delete_meta_data( '_mmgwc_last_checkout_url' );
		$order->delete_meta_data( '_mmgwc_last_checkout_url_at' );
		$order->delete_meta_data( '_mmgwc_last_checkout_url_mode' );
		$order->delete_meta_data( '_mmg_initiated_at' );
		$order->delete_meta_data( MMGWC_META_MERCHANT_TXN_ID );
		$order->save();
	}

	public function resolve_order_from_mmg_response( array $response ) {
		$merchant_txn_id = $response['merchantTransactionId'] ?? $response['MerchantTransactionId'] ?? $response['merchantTransactionID'] ?? $response['MerchantTransactionID'] ?? null;
		if ( is_string( $merchant_txn_id ) && $merchant_txn_id !== '' ) {
			if ( ctype_digit( $merchant_txn_id ) ) {
				$order = wc_get_order( (int) $merchant_txn_id );
				if ( $order ) {
					return $order;
				}
			}

			// Common case: merchantTransactionId stored as "<order_id>-<timestamp>".
			if ( preg_match( '/^(\d+)[-:_]/', $merchant_txn_id, $m ) ) {
				$maybe_order_id = (int) $m[1];
				if ( $maybe_order_id > 0 ) {
					$order = wc_get_order( $maybe_order_id );
					if ( $order ) {
						return $order;
					}
				}
			}

			// Fallback: search by stored meta, restricted to MMG orders.
			$orders = wc_get_orders(
				array(
					'limit'          => 1,
					'return'         => 'objects',
					'payment_method' => 'mmg_checkout',
					'meta_key'       => MMGWC_META_MERCHANT_TXN_ID,
					'meta_value'     => $merchant_txn_id,
				)
			);
			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		return null;
	}

	public function handle_mmg_response_for_order( WC_Order $order, array $response ): string {
		$txn_id = $response['transactionId'] ?? $response['TransactionId'] ?? $response['transactionReference'] ?? $response['transactionReceipt'] ?? $response['executionId'] ?? '';
		$txn_id = is_string( $txn_id ) ? $txn_id : '';

		$result_code = $response['resultCode'] ?? $response['ResultCode'] ?? $response['transactionStatus'] ?? $response['transactionStatusCode'] ?? '';
		$result_message = $response['resultMessage'] ?? $response['ResultMessage'] ?? $response['message'] ?? '';

		// Idempotency: if already processed and order is paid, do nothing.
		$already_processed = (string) $order->get_meta( MMGWC_META_PROCESSED_TXN_ID );
		$current_txn = (string) $order->get_meta( MMGWC_META_TXN_ID );
		if ( $txn_id !== '' && ( $already_processed === $txn_id || $current_txn === $txn_id ) && ! $order->needs_payment() ) {
			MMGWC_Logger::debug( 'MMG callback duplicate, skipping', array( 'order_id' => $order->get_id(), 'txn_id' => $txn_id ) );
			return $this->get_return_url( $order );
		}

		$order->update_meta_data( MMGWC_META_TXN_ID, $txn_id );
		$order->update_meta_data( MMGWC_META_RESULT_CODE, is_scalar( $result_code ) ? (string) $result_code : '' );
		$order->update_meta_data( MMGWC_META_RESULT_MESSAGE, is_scalar( $result_message ) ? (string) $result_message : '' );
		$order->update_meta_data( MMGWC_META_RAW_RESPONSE, wp_json_encode( $response ) );
		$order->save();

		$rc = is_scalar( $result_code ) ? trim( (string) $result_code ) : '';
		$mapping = $this->map_result_code( $rc );
		$label = $mapping['label'];
		$type = $mapping['type'];
		$rm_text = is_scalar( $result_message ) ? trim( (string) $result_message ) : '';

		// Optional: enrich using Transaction Lookup API.
		if ( $txn_id !== '' ) {
			$lookup_data = MMGWC_API::transaction_lookup( $this->get_active_config(), $txn_id );
			if ( is_array( $lookup_data ) ) {
				$order->update_meta_data( '_mmg_lookup_last', wp_json_encode( $lookup_data ) );
				$order->save();
				MMGWC_Logger::debug( 'MMG transaction lookup stored', array( 'order_id' => $order->get_id(), 'txn_id' => $txn_id ) );
			}
		}

		if ( $type === 'success' ) {
			if ( $order->needs_payment() ) {
				$order->payment_complete( $txn_id );
			}
			$order->update_meta_data( MMGWC_META_PROCESSED_TXN_ID, $txn_id );
			$order->save();
			$this->clear_checkout_session_meta( $order );
			$order->add_order_note( sprintf( 'MMG payment successful. Transaction ID: %s', $txn_id !== '' ? $txn_id : 'N/A' ) );
			return $this->get_return_url( $order );
		}

		$details = $rm_text !== '' ? $rm_text : $label;
		if ( $type === 'cancelled' ) {
			$cancel_status = MMGWC_Settings::get( 'status_cancelled', 'cancelled' );
			$cancel_status = is_string( $cancel_status ) && $cancel_status !== '' ? $cancel_status : 'cancelled';
			$order->update_status( $cancel_status, 'MMG payment cancelled: ' . $details );
			$this->clear_checkout_session_meta( $order );
			$this->add_customer_notice( 'Payment cancelled. You can try again.', 'notice' );
			return $order->get_checkout_payment_url( true );
		}

		if ( $type === 'timeout' ) {
			$timeout_status = MMGWC_Settings::get( 'status_timeout', 'failed' );
			$timeout_status = is_string( $timeout_status ) && $timeout_status !== '' ? $timeout_status : 'failed';
			$order->update_status( $timeout_status, 'MMG payment timed out: ' . $details );
			$this->clear_checkout_session_meta( $order );
			$this->add_customer_notice( 'Payment timed out. Please try again.', 'error' );
			return $order->get_checkout_payment_url( true );
		}

		$failed_status = MMGWC_Settings::get( 'status_failed', 'failed' );
		$failed_status = is_string( $failed_status ) && $failed_status !== '' ? $failed_status : 'failed';
		$order->update_status( $failed_status, sprintf( 'MMG payment not completed. Code: %s. Message: %s', $rc, $rm_text ) );
		$this->clear_checkout_session_meta( $order );
		$this->add_customer_notice( 'Payment failed. Please try again.', 'error' );
		return $order->get_checkout_payment_url( true );
	}


	public static function filter_payment_complete_order_status( $status, $order_id, $order = null ) {
		try {
			if ( $order instanceof WC_Order ) {
				$wc_order = $order;
			} else {
				$wc_order = wc_get_order( $order_id );
			}
			if ( ! $wc_order instanceof WC_Order ) {
				return $status;
			}
			if ( $wc_order->get_payment_method() !== 'mmg_checkout' ) {
				return $status;
			}
			$virtual = MMGWC_Settings::get( 'status_success_virtual', '' );
			$physical = MMGWC_Settings::get( 'status_success_physical', '' );
			$virtual = is_string( $virtual ) ? trim( $virtual ) : '';
			$physical = is_string( $physical ) ? trim( $physical ) : '';
			$needs_processing = method_exists( $wc_order, 'needs_processing' ) ? (bool) $wc_order->needs_processing() : false;
			if ( $needs_processing && $physical !== '' ) {
				return $physical;
			}
			if ( ! $needs_processing && $virtual !== '' ) {
				return $virtual;
			}
		} catch ( Exception $e ) {
			return $status;
		}
		return $status;
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return new WP_Error( 'mmgwc_refunds_not_supported', 'Refunds must be processed in MMG.' );
	}

	public function admin_display_mmg_meta( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$txn_id = $order->get_meta( MMGWC_META_TXN_ID );
		if ( ! $txn_id ) {
			return;
		}
		$merchant_txn = $order->get_meta( MMGWC_META_MERCHANT_TXN_ID );
		$code = $order->get_meta( MMGWC_META_RESULT_CODE );
		$msg = $order->get_meta( MMGWC_META_RESULT_MESSAGE );

		echo '<p><strong>MMG</strong><br>';
		echo 'Merchant Transaction ID: ' . esc_html( (string) $merchant_txn ) . '<br>';
		echo 'Transaction ID: ' . esc_html( (string) $txn_id ) . '<br>';
		echo 'Result: ' . esc_html( (string) $code ) . ' ' . esc_html( (string) $msg );

		$orig_cur = (string) $order->get_meta( MMGWC_META_ORIGINAL_CURRENCY );
		if ( $orig_cur !== '' && strtoupper( $orig_cur ) !== 'GYD' ) {
			$orig_total = (string) $order->get_meta( MMGWC_META_ORIGINAL_TOTAL );
			$rate = (string) $order->get_meta( MMGWC_META_FX_RATE );
			$rate_date = (string) $order->get_meta( MMGWC_META_FX_DATE );
			$raw = (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD_RAW );
			$gyd = (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD );
			echo '<br><br><strong>Currency conversion</strong><br>';
			echo 'Store currency: ' . esc_html( strtoupper( $orig_cur ) ) . '<br>';
			echo 'Store total: ' . esc_html( $orig_total ) . ' ' . esc_html( strtoupper( $orig_cur ) ) . '<br>';
			echo 'Rate used: 1 ' . esc_html( strtoupper( $orig_cur ) ) . ' = ' . esc_html( $rate ) . ' GYD' . ( $rate_date !== '' ? ' (' . esc_html( $rate_date ) . ')' : '' ) . '<br>';
			echo 'Converted: ' . esc_html( $raw ) . ' GYD<br>';
			echo 'MMG charged: ' . esc_html( $gyd ) . ' GYD (rounded to nearest 100)';
		}
		echo '</p>';
	}

	public function frontend_display_mmg_meta( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$txn_id = $order->get_meta( MMGWC_META_TXN_ID );
		if ( ! $txn_id ) {
			return;
		}
		$merchant_txn = $order->get_meta( MMGWC_META_MERCHANT_TXN_ID );
		echo '<section class="woocommerce-mmg-details"><h2>MMG Payment</h2>';
		echo '<p>Merchant Transaction ID: ' . esc_html( (string) $merchant_txn ) . '<br>';
		echo 'Transaction ID: ' . esc_html( (string) $txn_id ) . '</p>';

		$orig_cur = (string) $order->get_meta( MMGWC_META_ORIGINAL_CURRENCY );
		if ( $orig_cur !== '' && strtoupper( $orig_cur ) !== 'GYD' ) {
			$gyd = (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD );
			echo '<p><strong>Charged in GYD:</strong> ' . esc_html( $gyd ) . ' GYD</p>';
		}
		echo '</section>';
	}

	public function email_display_mmg_meta( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$txn_id = $order->get_meta( MMGWC_META_TXN_ID );
		if ( ! $txn_id ) {
			return;
		}

		$orig_cur = (string) $order->get_meta( MMGWC_META_ORIGINAL_CURRENCY );
		$gyd = (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD );
		if ( $plain_text ) {
			echo "\nMMG Transaction ID: " . $txn_id . "\n";
			if ( $orig_cur !== '' && strtoupper( $orig_cur ) !== 'GYD' && $gyd !== '' ) {
				echo "Paid via MMG in GYD: " . $gyd . " GYD\n";
			}
			return;
		}
		echo '<p><strong>MMG Transaction ID:</strong> ' . esc_html( $txn_id ) . '</p>';
		if ( $orig_cur !== '' && strtoupper( $orig_cur ) !== 'GYD' && $gyd !== '' ) {
			echo '<p><strong>Paid via MMG in GYD:</strong> ' . esc_html( $gyd ) . ' GYD</p>';
		}
	}
}