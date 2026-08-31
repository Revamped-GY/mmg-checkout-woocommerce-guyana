<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_MMGWC extends WC_Payment_Gateway {
	private const HOSTED_SESSION_PREFIX = 'mmgwc_checkout_session_';
	private const HOSTED_CALLBACK_PREFIX = 'mmgwc_hosted_callback_';
	private const HOSTED_CALLBACK_PROCESSING_PREFIX = 'mmgwc_hosted_processing_';
	private const HOSTED_CALLBACK_QUEUE_MARKER = 'mmgwc_hosted_callback_queue_present';
	private const HOSTED_CALLBACK_RECOVERY_CURSOR = 'mmgwc_hosted_callback_recovery_cursor';
	private const HOSTED_CALLBACK_HOOK = 'mmgwc_process_hosted_callback';
	private const HOSTED_CALLBACK_MAX_LOOKUP_ATTEMPTS = 8;
	private const HOSTED_CALLBACK_MAX_PROCESSING_FAILURES = 8;
	private const HOSTED_CALLBACK_MAX_AGE = DAY_IN_SECONDS;
	private const HOSTED_CALLBACK_PROCESSING_LOCK_TTL = 1200;

	public function __construct() {
		$this->id = 'mmg_checkout';
		$this->method_title = 'MMG Checkout';
		$this->method_description = 'Accept payments via MMG Merchant Checkout. Settings: WP Admin → MMG Checkout → Settings.';
		$this->has_fields = true;

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
		wp_enqueue_script( 'mmgwc-checkout-guide', MMGWC_PLUGIN_URL . 'assets/js/checkout-guide.js', array(), MMGWC_VERSION, true );
	}

	/**
	 * Render the hosted-checkout explanation only while MMG is selected.
	 */
	public function payment_fields() {
		echo wp_kses_post(
			self::get_checkout_content_html(
				(string) $this->description,
				(string) $this->get_option( 'checkout_guide_enabled', 'yes' ) === 'yes'
			)
		);
	}

	/**
	 * Build shared Classic Checkout and Checkout Blocks content.
	 */
	public static function get_checkout_content_html( string $description, bool $show_guide ): string {
		$image_url = MMGWC_PLUGIN_URL . 'assets/images/mmg-hosted-checkout-guide.png';
		ob_start();
		if ( trim( $description ) !== '' ) {
			echo '<div class="mmgwc-gateway-description">' . wpautop( wp_kses_post( $description ) ) . '</div>';
		}
		if ( $show_guide ) {
			?>
			<details class="mmgwc-checkout-guide">
				<summary>How to pay on the MMG page</summary>
				<p><strong>Username means the phone number registered to your MMG account.</strong></p>
				<ol>
					<li>Choose <strong>Login</strong>.</li>
					<li>Enter your MMG phone number and password, then select <strong>Continue</strong>.</li>
					<li>Use <strong>Pay with QR</strong> only when you can scan the code with the MMG app on another device.</li>
				</ol>
				<div class="mmgwc-guide-images" aria-label="MMG checkout examples">
					<a class="mmgwc-guide-image mmgwc-guide-image-tabs" href="<?php echo esc_url( $image_url ); ?>" data-mmgwc-lightbox aria-label="Open a larger preview showing the Login and Pay with QR choices">
						<img src="<?php echo esc_url( $image_url ); ?>" alt="MMG checkout with Login and Pay with QR choices" loading="lazy" decoding="async" width="1142" height="508">
						<span>1. Choose Login</span>
					</a>
					<a class="mmgwc-guide-image mmgwc-guide-image-login" href="<?php echo esc_url( $image_url ); ?>" data-mmgwc-lightbox aria-label="Open a larger preview showing the MMG username and password fields">
						<img src="<?php echo esc_url( $image_url ); ?>" alt="MMG Login form with Username and Password fields" loading="lazy" decoding="async" width="1142" height="508">
						<span>2. Enter phone number</span>
					</a>
				</div>
				<p class="mmgwc-guide-retry">If the MMG page stops after switching between Login and QR, return to checkout and start again. The plugin will create a fresh MMG session.</p>
			</details>
			<?php
		}
		return (string) ob_get_clean();
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
	 * Do not offer MMG at checkout unless settlement can be verified through
	 * MMG's authenticated Transaction Lookup API.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$config = $this->get_active_config();
		return empty( MMGWC_Payment_Verifier::missing_api_fields( $config ) )
			&& empty( MMGWC_Payment_Context::missing_hosted_fields( $config ) )
			&& MMGWC_Payment_Context::can_process_currency( MMGWC_Payment_Context::current_checkout_currency() );
	}

	/**
	 * Encrypt any sensitive fields before WooCommerce persists them.
	 */
	public function filter_sanitize_sensitive_fields( $sanitized ) {
		if ( ! is_array( $sanitized ) ) {
			return $sanitized;
		}
		if ( class_exists( 'MMGWC_Secure_Store' ) ) {
			$existing = get_option( $this->get_option_key(), array() );
			$existing = is_array( $existing ) ? $existing : array();
			foreach ( MMGWC_Secure_Store::protected_keys() as $k ) {
				if ( array_key_exists( $k, $sanitized ) && is_string( $sanitized[ $k ] ) && $sanitized[ $k ] !== '' ) {
					$sanitized[ $k ] = MMGWC_Secure_Store::encrypt_preserving_existing(
						$sanitized[ $k ],
						isset( $existing[ $k ] ) && is_string( $existing[ $k ] ) ? $existing[ $k ] : ''
					);
				}
			}
		}
		return $sanitized;
	}

	/**
	 * Keep large credential fields out of the autoloaded option set when the
	 * installed WordPress version supports changing autoload safely.
	 */
	public function process_admin_options() {
		$result = parent::process_admin_options();
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( $this->get_option_key(), false );
		}
		return $result;
	}

	public function init_form_fields() {
		$callback = esc_url( WC()->api_request_url( MMGWC_WC_API_ENDPOINT ) );

		// Order status options for advanced mapping.
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$status_options_paid = array( '' => 'WooCommerce default' );
		$status_options_unpaid = array();
		$protected_unpaid_statuses = array( 'refunded', 'trash', 'auto-draft', 'checkout-draft', 'draft', 'inherit', 'future', 'private', 'publish' );
		foreach ( $wc_statuses as $key => $label ) {
			$slug = str_replace( 'wc-', '', (string) $key );
			if ( in_array( $slug, $paid_statuses, true ) ) {
				$status_options_paid[ $slug ] = $label;
			} elseif ( ! in_array( $slug, $protected_unpaid_statuses, true ) ) {
				$status_options_unpaid[ $slug ] = $label;
			}
		}
		if ( count( $status_options_paid ) === 1 ) {
			$status_options_paid['processing'] = 'Processing';
			$status_options_paid['completed'] = 'Completed';
		}
		if ( empty( $status_options_unpaid ) ) {
			$status_options_unpaid = array( 'pending' => 'Pending payment', 'on-hold' => 'On hold', 'cancelled' => 'Cancelled', 'failed' => 'Failed' );
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
				'default' => 'You will be redirected to MMG. On the MMG page, Username means your MMG phone number.',
			),
			'checkout_guide_enabled' => array(
				'title' => 'Checkout walkthrough',
				'label' => 'Show the short MMG Login and QR guide at checkout',
				'type' => 'checkbox',
				'default' => 'yes',
				'description' => 'Shows compact instructions and clickable image previews only while MMG is selected.',
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

			'sandbox_api_section' => array(
				'title' => 'Sandbox Merchant Initiated API',
				'type' => 'title',
				'description' => 'Required for authenticated Sandbox payment verification and the optional approval request method. Use merchant-specific values issued by MMG. Values shown in public developer examples are not credentials.',
			),
			'sandbox_api_mwallet_base_url' => array(
				'title' => 'MWallet Base URL',
				'type' => 'text',
				'default' => MMGWC_Settings::DEFAULT_SANDBOX_API_BASE,
			),
			'sandbox_api_key' => array(
				'title' => 'x-api-key',
				'type' => 'password',
			),
			'sandbox_api_wss_mid' => array(
				'title' => 'x-wss-mid (Merchant MSISDN)',
				'type' => 'text',
			),
			'sandbox_api_wss_mkey' => array(
				'title' => 'x-wss-mkey',
				'type' => 'password',
			),
			'sandbox_api_wss_msecret' => array(
				'title' => 'x-wss-msecret',
				'type' => 'password',
			),
			'sandbox_api_password' => array(
				'title' => 'API Password',
				'type' => 'password',
				'description' => 'Used to obtain a short-lived resource token.',
			),
			'sandbox_api_credit_account_id' => array(
				'title' => 'Merchant Credit Account ID',
				'type' => 'text',
				'description' => 'Required only for approval requests. Ask MMG whether this equals x-wss-mid or a separate account ID.',
			),

			'live_api_section' => array(
				'title' => 'Live Merchant Initiated API',
				'type' => 'title',
				'description' => 'Required for authenticated Live payment verification and the optional approval request method. MMG does not publish the production base URL. Enter only values issued for this merchant and approved for Live use.',
			),
			'live_api_mwallet_base_url' => array(
				'title' => 'Live MWallet Base URL',
				'type' => 'text',
				'default' => '',
			),
			'live_api_key' => array(
				'title' => 'Live x-api-key',
				'type' => 'password',
			),
			'live_api_wss_mid' => array(
				'title' => 'Live x-wss-mid (Merchant MSISDN)',
				'type' => 'text',
			),
			'live_api_wss_mkey' => array(
				'title' => 'Live x-wss-mkey',
				'type' => 'password',
			),
			'live_api_wss_msecret' => array(
				'title' => 'Live x-wss-msecret',
				'type' => 'password',
			),
			'live_api_password' => array(
				'title' => 'Live API Password',
				'type' => 'password',
			),
			'live_api_credit_account_id' => array(
				'title' => 'Live Merchant Credit Account ID',
				'type' => 'text',
				'description' => 'Use the exact creditParty account ID confirmed by MMG for Live approval requests.',
			),

			'initiated_section' => array(
				'title' => 'Optional: approve in the MMG app',
				'type' => 'title',
				'description' => 'Creates a separate checkout method that sends an MMG approval request to the customer. Keep this disabled until MMG confirms remote WooCommerce use, the production endpoint, the merchant credit account and polling limits.',
			),
			'initiated_enabled' => array(
				'title' => 'Enable approval requests',
				'label' => 'Offer Approve in the MMG app at checkout',
				'type' => 'checkbox',
				'default' => 'no',
			),
			'initiated_authorised' => array(
				'title' => 'MMG authorisation',
				'label' => 'MMG has confirmed this merchant may use approval requests for remote WooCommerce orders',
				'type' => 'checkbox',
				'default' => 'no',
				'description' => 'The public API page describes this service primarily for in-store POS use. Keep this clear unless MMG has approved the intended use.',
			),
			'initiated_title' => array(
				'title' => 'Approval method title',
				'type' => 'text',
				'default' => 'Approve in the MMG app',
			),
			'initiated_description' => array(
				'title' => 'Approval method description',
				'type' => 'textarea',
				'default' => 'Enter the phone number registered to your MMG account. Open the MMG app and approve the payment request before it expires.',
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
				'options' => $status_options_paid,
			),
			'status_success_physical' => array(
				'title' => 'Success status (physical orders)',
				'type' => 'select',
				'default' => '',
				'description' => 'Choose the order status to use after successful payment when the order needs processing (for example physical products). Leave as WooCommerce default to keep the standard behaviour.',
				'desc_tip' => true,
				'options' => $status_options_paid,
			),
			'status_cancelled' => array(
				'title' => 'Status when cancelled',
				'type' => 'select',
				'default' => 'cancelled',
				'options' => $status_options_unpaid,
			),
			'status_failed' => array(
				'title' => 'Status when failed',
				'type' => 'select',
				'default' => 'failed',
				'options' => $status_options_unpaid,
			),
			'status_timeout' => array(
				'title' => 'Status when timed out',
				'type' => 'select',
				'default' => 'failed',
				'options' => $status_options_unpaid,
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
		$config = MMGWC_Settings::get_config( $mode );
		$config['checkout_url'] = $this->normalize_checkout_url( (string) $config['checkout_url'], (string) $config['mode'] );
		return $config;
	}

	private function get_active_config(): array {
		return $this->get_config_for_mode( $this->get_mode() );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
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
		} catch ( Throwable $e ) {
			MMGWC_Logger::error( 'MMG build redirect failed: ' . $e->getMessage(), array( 'order_id' => $order->get_id() ) );
			wc_add_notice( 'Could not start MMG payment. Please try again.', 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result' => 'success',
			'redirect' => $redirect,
		);
	}

	private function new_merchant_transaction_id( WC_Order $order, int $timestamp ): string {
		return (string) $order->get_id() . '-' . (string) $timestamp . '-' . bin2hex( random_bytes( 16 ) );
	}

	private static function hosted_config_fingerprint( array $config ): string {
		$values = array();
		foreach ( array( 'mode', 'checkout_url', 'merchant_id', 'client_id', 'merchant_name', 'secret_key', 'public_key', 'private_key', 'mwallet_base_url', 'api_key', 'wss_mid', 'wss_mkey', 'wss_msecret', 'password' ) as $key ) {
			$values[ $key ] = isset( $config[ $key ] ) && is_scalar( $config[ $key ] ) ? (string) $config[ $key ] : '';
		}
		$encoded = wp_json_encode( $values, JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	public function build_mmg_redirect_url( WC_Order $order, bool $force_new = false ): string {
		return (string) $this->with_locked_mmg_redirect_url(
			$order,
			$force_new,
			static function( string $url, WC_Order $locked_order ) {
				return $url;
			}
		);
	}

	/**
	 * Build and consume a hosted checkout URL while the plugin order lock remains
	 * held. Admin delivery uses this to prevent another checkout request from
	 * paying the order between URL generation and email delivery.
	 */
	public function with_locked_mmg_redirect_url( WC_Order $order, bool $force_new, callable $consumer ) {
		$order_id = (int) $order->get_id();
		if ( ! MMGWC_Payment_Verifier::acquire_order_lock( $order_id ) ) {
			throw new RuntimeException( 'An MMG checkout session is already being created for this order.' );
		}

		try {
			$fresh_order = MMGWC_Payment_Context::fresh_order( $order_id );
			if ( ! $fresh_order instanceof WC_Order || $fresh_order->get_payment_method() !== 'mmg_checkout' ) {
				throw new RuntimeException( 'The MMG order is no longer available.' );
			}
			if ( ! $fresh_order->needs_payment() || $fresh_order->is_paid() ) {
				throw new RuntimeException( 'The order no longer accepts payment.' );
			}
			$url = $this->build_mmg_redirect_url_locked( $fresh_order, $force_new );
			if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
				throw new RuntimeException( 'The MMG order changed while its payment link was being created.' );
			}
			return $consumer( $url, $fresh_order );
		} finally {
			MMGWC_Payment_Verifier::release_order_lock( $order_id );
		}
	}

	private function build_mmg_redirect_url_locked( WC_Order $order, bool $force_new = false ): string {
		$config = $this->get_active_config();

		$missing_api_fields = MMGWC_Payment_Verifier::missing_api_fields( $config );
		if ( ! empty( $missing_api_fields ) ) {
			throw new RuntimeException( 'Missing MMG verification setting: ' . $missing_api_fields[0] );
		}
		$missing_hosted_fields = MMGWC_Payment_Context::missing_hosted_fields( $config );
		if ( ! empty( $missing_hosted_fields ) ) {
			throw new RuntimeException( 'Missing MMG setting: ' . $missing_hosted_fields[0] );
		}
		$public_key = function_exists( 'openssl_pkey_get_public' ) ? @openssl_pkey_get_public( (string) $config['public_key'] ) : false;
		$private_key = function_exists( 'openssl_pkey_get_private' ) ? @openssl_pkey_get_private( (string) $config['private_key'] ) : false;
		if ( $public_key === false || $private_key === false ) {
			throw new RuntimeException( 'The MMG public or private key is not valid key material.' );
		}
		if ( function_exists( 'openssl_pkey_free' ) ) {
			@openssl_pkey_free( $public_key );
			@openssl_pkey_free( $private_key );
		}

		// Reuse a newly created URL briefly so duplicate checkout submissions cannot
		// invalidate each other. A later retry gets a fresh merchant transaction ID
		// because MMG rejects identifiers that have already been submitted. Sites can
		// change the 15-second window with mmgwc_checkout_session_reuse_seconds.
		$now             = time();
		$last_url  = (string) $order->get_meta( '_mmgwc_last_checkout_url' );
		$last_at   = (int) $order->get_meta( '_mmgwc_last_checkout_url_at' );
		$last_mode = (string) $order->get_meta( '_mmgwc_last_checkout_url_mode' );
		$last_merchant_transaction_id = trim( (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID ) );
		$last_session = self::hosted_session_context( $last_merchant_transaction_id );

		$reuse_window_seconds = (int) apply_filters( 'mmgwc_checkout_session_reuse_seconds', 15 );
		if ( $reuse_window_seconds < 0 ) {
			$reuse_window_seconds = 0;
		}

		$reuse_snapshot_matches = is_array( $last_session )
			&& hash_equals( (string) $last_session['merchant_transaction_id'], $last_merchant_transaction_id )
			&& hash_equals( (string) $last_session['expected_order_total'], wc_format_decimal( $order->get_total(), 2 ) )
			&& hash_equals( strtoupper( (string) $last_session['expected_order_currency'] ), strtoupper( (string) $order->get_currency() ) )
			&& hash_equals( (string) $last_session['expected_merchant_id'], (string) $config['merchant_id'] )
			&& isset( $last_session['hosted_config_fingerprint'], $last_session['checkout_url_hash'] )
			&& hash_equals( (string) $last_session['hosted_config_fingerprint'], self::hosted_config_fingerprint( $config ) )
			&& hash_equals( (string) $last_session['checkout_url_hash'], hash( 'sha256', $last_url ) );

		if ( ! $force_new && $order->needs_payment() && $reuse_snapshot_matches && $last_url !== '' && $last_at > 0 && ( $now - $last_at ) <= $reuse_window_seconds && $last_mode === (string) $config['mode'] ) {
			MMGWC_Logger::info(
				'MMG redirect reused',
				array(
					'order_id' => $order->get_id(),
					'mode' => $config['mode'],
				)
			);
			return $last_url;
		}

		$merchant_txn_id = $this->new_merchant_transaction_id( $order, $now );
		$payment_context = MMGWC_Payment_Context::prepare( $order, $config, (string) $config['merchant_id'], false );
		$amount = (string) $payment_context['amount_request'];

		$payload = array(
			'secretKey' => $config['secret_key'],
			'amount' => $amount,
			'merchantId' => $config['merchant_id'],
			'merchantName' => $config['merchant_name'],
			'merchantTransactionId' => $merchant_txn_id,
			'productDescription' => sprintf( 'Order #%s', $order->get_order_number() ),
			'requestInitiationTime' => (string) $now,
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

		$session = array_merge(
			array(
				'version' => 1,
				'order_id' => (int) $order->get_id(),
				'merchant_transaction_id' => $merchant_txn_id,
				'created_at' => $now,
				'hosted_config_fingerprint' => self::hosted_config_fingerprint( $config ),
				'checkout_url_hash' => hash( 'sha256', $checkout_url ),
			),
			(array) $payment_context['snapshot']
		);
		$reservation = $this->reserve_hosted_session_context( $session );

		// Commit the new current session only after its payload and encrypted URL
		// have been created. Earlier session reservations remain independently valid.
		$order->update_meta_data( MMGWC_META_MERCHANT_TXN_ID, $merchant_txn_id );
		$order->update_meta_data( '_mmg_initiated_at', $now );
		$order->update_meta_data( '_mmgwc_last_checkout_url', $checkout_url );
		$order->update_meta_data( '_mmgwc_last_checkout_url_at', $now );
		$order->update_meta_data( '_mmgwc_last_checkout_url_mode', (string) $config['mode'] );
		try {
			$order->save();
		} catch ( Throwable $exception ) {
			$fresh_order = MMGWC_Payment_Context::fresh_order( (int) $order->get_id() );
			$commit_confirmed = $fresh_order instanceof WC_Order
				&& hash_equals( $merchant_txn_id, trim( (string) $fresh_order->get_meta( MMGWC_META_MERCHANT_TXN_ID ) ) )
				&& hash_equals( $checkout_url, (string) $fresh_order->get_meta( '_mmgwc_last_checkout_url' ) )
				&& (int) $fresh_order->get_meta( '_mmgwc_last_checkout_url_at' ) === $now;
			if ( $commit_confirmed ) {
				MMGWC_Logger::warning( 'MMG checkout session save hook failed after the session was committed', array( 'order_id' => (int) $order->get_id() ) );
				$order = $fresh_order;
			} elseif ( $fresh_order instanceof WC_Order ) {
				MMGWC_Atomic_Option::delete_if_owned( $reservation['key'], $reservation['value'] );
				throw $exception;
			} else {
				throw new RuntimeException( 'The MMG payment-link save outcome is uncertain. Do not generate another link until the order can be checked.', 0, $exception );
			}
		}

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
			} catch ( Throwable $e ) {
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
		// Internal evidence used by the verifier. Override any provider-supplied
		// value with the credential mode that actually decrypted the token.
		$data['_mmgwc_decrypted_mode'] = $succeeded_mode;
		return $data;
	}

	private function clear_checkout_session_meta( WC_Order $order, bool $preserve_verification_snapshot = false ): void {
		$order->delete_meta_data( '_mmgwc_last_checkout_url' );
		$order->delete_meta_data( '_mmgwc_last_checkout_url_at' );
		$order->delete_meta_data( '_mmgwc_last_checkout_url_mode' );
		$order->delete_meta_data( '_mmg_initiated_at' );
		if ( ! $preserve_verification_snapshot ) {
			$order->delete_meta_data( MMGWC_META_MERCHANT_TXN_ID );
			$order->delete_meta_data( MMGWC_META_EXPECTED_AMOUNT );
			$order->delete_meta_data( MMGWC_META_EXPECTED_CURRENCY );
			$order->delete_meta_data( MMGWC_META_EXPECTED_MERCHANT_ID );
			$order->delete_meta_data( MMGWC_META_EXPECTED_ORDER_TOTAL );
			$order->delete_meta_data( MMGWC_META_EXPECTED_ORDER_CURRENCY );
		}
		$order->save();
	}

	private static function hosted_session_key( string $merchant_transaction_id ): string {
		return self::HOSTED_SESSION_PREFIX . hash( 'sha256', $merchant_transaction_id );
	}

	private function reserve_hosted_session_context( array $session ): array {
		$merchant_transaction_id = isset( $session['merchant_transaction_id'] ) && is_string( $session['merchant_transaction_id'] )
			? trim( $session['merchant_transaction_id'] )
			: '';
		$value = wp_json_encode( $session, JSON_UNESCAPED_SLASHES );
		if ( $merchant_transaction_id === '' || ! is_string( $value ) || strlen( $value ) > 4096 ) {
			throw new RuntimeException( 'The MMG checkout session context could not be created.' );
		}
		$key = self::hosted_session_key( $merchant_transaction_id );
		if ( ! MMGWC_Atomic_Option::reserve( $key, $value ) ) {
			throw new RuntimeException( 'The MMG checkout session identifier is already in use.' );
		}
		return array( 'key' => $key, 'value' => $value );
	}

	private static function hosted_session_context( string $merchant_transaction_id ): ?array {
		$merchant_transaction_id = trim( $merchant_transaction_id );
		if ( $merchant_transaction_id === '' || strlen( $merchant_transaction_id ) > 191 ) {
			return null;
		}
		$value = MMGWC_Atomic_Option::reserved_value( self::hosted_session_key( $merchant_transaction_id ) );
		if ( ! is_string( $value ) || $value === '' || strlen( $value ) > 4096 ) {
			return null;
		}
		$session = json_decode( $value, true );
		if ( ! is_array( $session ) || (int) ( $session['version'] ?? 0 ) !== 1 || (int) ( $session['order_id'] ?? 0 ) <= 0 ) {
			return null;
		}
		$stored_id = isset( $session['merchant_transaction_id'] ) && is_string( $session['merchant_transaction_id'] ) ? trim( $session['merchant_transaction_id'] ) : '';
		if ( $stored_id === '' || ! hash_equals( $stored_id, $merchant_transaction_id ) ) {
			return null;
		}
		if ( ! in_array( (string) ( $session['mode'] ?? '' ), array( 'sandbox', 'live' ), true ) ) {
			return null;
		}
		foreach ( array( 'expected_amount', 'expected_currency', 'expected_merchant_id', 'expected_order_total', 'expected_order_currency' ) as $key ) {
			if ( ! isset( $session[ $key ] ) || ! is_scalar( $session[ $key ] ) || trim( (string) $session[ $key ] ) === '' ) {
				return null;
			}
		}
		return $session;
	}

	private static function response_merchant_transaction_id( array $response ): string {
		$value = $response['merchantTransactionId'] ?? $response['MerchantTransactionId'] ?? $response['merchantTransactionID'] ?? $response['MerchantTransactionID'] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	public static function init_background_callbacks(): void {
		add_action( self::HOSTED_CALLBACK_HOOK, array( __CLASS__, 'process_queued_callback' ), 10, 1 );
		if ( (string) get_option( self::HOSTED_CALLBACK_QUEUE_MARKER, 'no' ) === 'yes' ) {
			add_action( 'init', array( __CLASS__, 'recover_queued_callbacks' ), 1 );
		}
	}

	public static function recover_queued_callbacks(): void {
		$limit = 50;
		$cursor = max( 0, (int) get_option( self::HOSTED_CALLBACK_RECOVERY_CURSOR, 0 ) );
		$keys = MMGWC_Atomic_Option::keys_with_prefix( self::HOSTED_CALLBACK_PREFIX, $limit, $cursor );
		if ( ! is_array( $keys ) ) {
			return;
		}
		if ( empty( $keys ) && $cursor > 0 ) {
			$cursor = 0;
			$keys = MMGWC_Atomic_Option::keys_with_prefix( self::HOSTED_CALLBACK_PREFIX, $limit, 0 );
			if ( ! is_array( $keys ) ) {
				return;
			}
		}
		foreach ( $keys as $key ) {
			$delay = 5;
			$value = MMGWC_Atomic_Option::reserved_value( $key );
			$data = is_string( $value ) ? json_decode( $value, true ) : null;
			if ( is_array( $data ) && isset( $data['next_attempt_at'] ) ) {
				$delay = max( 5, (int) $data['next_attempt_at'] - time() );
			}
			self::schedule_queued_callback( $key, $delay );
		}
		$next_cursor = count( $keys ) < $limit ? 0 : $cursor + count( $keys );
		update_option( self::HOSTED_CALLBACK_RECOVERY_CURSOR, $next_cursor, false );
		// The marker is intentionally persistent after first use. Clearing it can
		// race with a concurrent enqueue between its marker write and reservation.
	}

	private static function schedule_queued_callback( string $key, int $delay = 10 ): void {
		if ( preg_match( '/^' . preg_quote( self::HOSTED_CALLBACK_PREFIX, '/' ) . '[a-f0-9]{64}$/', $key ) !== 1 ) {
			return;
		}
		$args = array( $key );
		if ( ! wp_next_scheduled( self::HOSTED_CALLBACK_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 5, $delay ), self::HOSTED_CALLBACK_HOOK, $args );
		}
	}

	private static function hosted_callback_retry_delay( int $attempts ): int {
		$delays = array( 30, 60, 120, 300, 600, 1800, 3600, 7200 );
		$index = max( 0, min( count( $delays ) - 1, $attempts - 1 ) );
		return $delays[ $index ];
	}

	private static function maybe_clear_hosted_callback_marker(): void {
		// Kept for call-site clarity. The recovery marker remains until uninstall.
	}

	private static function retain_hosted_callback_for_retry( string $key, string $value, array $data, string $reason ): array {
		$failures = max( 0, (int) ( $data['processing_failures'] ?? 0 ) ) + 1;
		$delay = self::hosted_callback_retry_delay( $failures );
		$data['processing_failures'] = $failures;
		$data['last_error'] = sanitize_key( $reason );
		$data['next_attempt_at'] = time() + $delay;
		$next_value = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$owned_value = $value;
		$retained = false;
		if ( is_string( $next_value ) && strlen( $next_value ) <= 8192 && MMGWC_Atomic_Option::replace_reserved_value( $key, $value, $next_value ) ) {
			$owned_value = $next_value;
			$retained = true;
		} else {
			$current_value = MMGWC_Atomic_Option::reserved_value( $key );
			if ( is_string( $current_value ) ) {
				$owned_value = $current_value;
				$retained = true;
			}
		}
		if ( $retained ) {
			self::schedule_queued_callback( $key, $delay );
		}
		return array(
			'retained' => $retained,
			'failures' => $failures,
			'delay' => $delay,
			'owned_value' => $owned_value,
			'queued_at' => max( 1, (int) ( $data['queued_at'] ?? time() ) ),
		);
	}

	private static function retain_hosted_callback_with_limit( string $key, string $value, array $data, string $reason ): array {
		$retry = self::retain_hosted_callback_for_retry( $key, $value, $data, $reason );
		$exhausted = (int) $retry['failures'] >= self::HOSTED_CALLBACK_MAX_PROCESSING_FAILURES
			|| ( time() - (int) $retry['queued_at'] ) >= self::HOSTED_CALLBACK_MAX_AGE;
		if ( $exhausted && self::mark_hosted_callback_processing_review( (int) ( $data['order_id'] ?? 0 ), is_array( $data['response'] ?? null ) ? $data['response'] : array() ) ) {
			MMGWC_Atomic_Option::delete_if_owned( $key, (string) $retry['owned_value'] );
			self::maybe_clear_hosted_callback_marker();
			$retry['retained'] = false;
			$retry['reviewed'] = true;
		}
		return $retry;
	}

	private static function mark_hosted_callback_processing_review( int $order_id, array $response = array() ): bool {
		if ( $order_id <= 0 || ! MMGWC_Payment_Verifier::acquire_order_lock( $order_id ) ) {
			return false;
		}
		try {
			$order = MMGWC_Payment_Context::fresh_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return false;
			}
			$callback_transaction_id = isset( $response['transactionId'] ) && is_scalar( $response['transactionId'] ) ? trim( (string) $response['transactionId'] ) : '';
			$processed_transaction_id = trim( (string) $order->get_meta( MMGWC_META_PROCESSED_TXN_ID ) );
			if ( $order->is_paid() && $callback_transaction_id !== '' && $processed_transaction_id !== '' && hash_equals( $processed_transaction_id, $callback_transaction_id ) ) {
				return true;
			}
			if ( $order->is_paid() || in_array( (string) $order->get_status(), array( 'refunded', 'trash' ), true ) ) {
				$order->add_order_note( 'A durable MMG callback could not finish processing after the order was already paid or closed. Review the callback with MMG for a possible additional payment.' );
				return true;
			}
			if ( $order->get_payment_method() !== 'mmg_checkout' ) {
				$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:payment_method_changed' );
				$order->save();
				$order->add_order_note( 'A durable MMG payment callback was received after the order payment method changed. Manual verification with MMG is required.' );
				return true;
			}
			$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:callback_processing_failed' );
			$order->save();
			$order->add_order_note( 'The durable MMG payment callback could not be processed after bounded retries. Manual verification with MMG is required. Do not ask the customer to pay again.' );
			return true;
		} catch ( Throwable $exception ) {
			return false;
		} finally {
			MMGWC_Payment_Verifier::release_order_lock( $order_id );
		}
	}

	private function queue_hosted_callback( int $order_id, array $response ): bool {
		$record = MMGWC_Payment_Verifier::callback_record( $response );
		$mode = isset( $response['_mmgwc_decrypted_mode'] ) && is_scalar( $response['_mmgwc_decrypted_mode'] )
			? trim( (string) $response['_mmgwc_decrypted_mode'] )
			: '';
		if ( $order_id <= 0 || empty( $record['merchantTransactionId'] ) || ! in_array( $mode, array( 'sandbox', 'live' ), true ) ) {
			return false;
		}
		$record['_mmgwc_decrypted_mode'] = $mode;
		$fingerprint = wp_json_encode( array( $order_id, $record ), JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $fingerprint ) ) {
			return false;
		}
		$key = self::HOSTED_CALLBACK_PREFIX . hash( 'sha256', $fingerprint );
		$value = wp_json_encode(
			array(
				'order_id' => $order_id,
				'response' => $record,
				'queued_at' => time(),
				'attempts' => 0,
				'processing_failures' => 0,
				'last_error' => '',
				'next_attempt_at' => time() + 10,
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $value ) || strlen( $value ) > 8192 ) {
			return false;
		}
		// Write the recovery marker before the durable record. A crash can leave a
		// harmless marker, but it must never leave an undiscoverable callback.
		update_option( self::HOSTED_CALLBACK_QUEUE_MARKER, 'yes', false );
		if ( (string) get_option( self::HOSTED_CALLBACK_QUEUE_MARKER, 'no' ) !== 'yes' ) {
			return false;
		}
		$reserved = MMGWC_Atomic_Option::reserve( $key, $value );
		if ( ! $reserved && MMGWC_Atomic_Option::reserved_value( $key ) === null ) {
			return false;
		}
		self::schedule_queued_callback( $key );
		return true;
	}

	/**
	 * Retain a decrypted callback when its immutable session is valid but the
	 * WooCommerce order cannot be loaded during the browser request.
	 */
	public function queue_unresolved_mmg_response( array $response ): bool {
		$merchant_transaction_id = self::response_merchant_transaction_id( $response );
		$session = self::hosted_session_context( $merchant_transaction_id );
		return is_array( $session ) && (int) ( $session['order_id'] ?? 0 ) > 0
			? $this->queue_hosted_callback( (int) $session['order_id'], $response )
			: false;
	}

	public static function process_queued_callback( $key ): void {
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( preg_match( '/^' . preg_quote( self::HOSTED_CALLBACK_PREFIX, '/' ) . '[a-f0-9]{64}$/', $key ) !== 1 ) {
			return;
		}

		$processing_key = self::HOSTED_CALLBACK_PROCESSING_PREFIX . hash( 'sha256', $key );
		$processing_owner = MMGWC_Atomic_Option::acquire_lock( $processing_key, self::HOSTED_CALLBACK_PROCESSING_LOCK_TTL );
		if ( $processing_owner === '' ) {
			self::schedule_queued_callback( $key, 30 );
			return;
		}

		try {
			$value = MMGWC_Atomic_Option::reserved_value( $key );
			$data = is_string( $value ) ? json_decode( $value, true ) : null;
			if ( ! is_string( $value ) || ! is_array( $data ) || ! is_array( $data['response'] ?? null ) || (int) ( $data['order_id'] ?? 0 ) <= 0 ) {
				if ( is_string( $value ) ) {
					MMGWC_Atomic_Option::delete_if_owned( $key, $value );
				}
				self::maybe_clear_hosted_callback_marker();
				return;
			}

			$order = MMGWC_Payment_Context::fresh_order( (int) $data['order_id'] );
			if ( ! $order instanceof WC_Order ) {
				$retry = self::retain_hosted_callback_with_limit( $key, $value, $data, 'order_load_failed' );
				MMGWC_Logger::warning(
					! empty( $retry['reviewed'] ) ? 'Queued MMG callback order load failed; callback moved to review' : 'Queued MMG callback order load failed; callback retry recorded',
					array( 'order_id' => (int) $data['order_id'], 'retained' => ! empty( $retry['retained'] ) )
				);
				return;
			}

			$attempts = max( 0, (int) ( $data['attempts'] ?? 0 ) );
			$queued_at = max( 1, (int) ( $data['queued_at'] ?? time() ) );
			$lookup_attempt = $attempts + 1;
			$processing_state = null;
			$gateway = new self();
			$gateway->handle_mmg_response_for_order(
				$order,
				$data['response'],
				$processing_state,
				array(
					'from_queue' => true,
					'lookup_attempt' => $lookup_attempt,
					'lookup_retry_allowed' => $lookup_attempt < self::HOSTED_CALLBACK_MAX_LOOKUP_ATTEMPTS && ( time() - $queued_at ) < self::HOSTED_CALLBACK_MAX_AGE,
				)
			);
			$renewed_processing_owner = MMGWC_Atomic_Option::renew_lock( $processing_key, $processing_owner );
			if ( $renewed_processing_owner === '' ) {
				return;
			}
			$processing_owner = $renewed_processing_owner;

			if ( is_array( $processing_state ) && ! empty( $processing_state['retry'] ) ) {
				$reason = sanitize_key( (string) ( $processing_state['reason'] ?? 'deferred' ) );
				$next_data = $data;
				if ( $reason === 'lookup_failed' ) {
					$next_data['attempts'] = $lookup_attempt;
				}
				self::retain_hosted_callback_with_limit( $key, $value, $next_data, $reason );
				return;
			}

			MMGWC_Atomic_Option::delete_if_owned( $key, $value );
			self::maybe_clear_hosted_callback_marker();
		} catch ( Throwable $exception ) {
			$order_id = (int) ( $data['order_id'] ?? 0 );
			$renewed_processing_owner = MMGWC_Atomic_Option::renew_lock( $processing_key, $processing_owner );
			if ( $renewed_processing_owner !== '' ) {
				$processing_owner = $renewed_processing_owner;
			}
			if ( $renewed_processing_owner !== '' && isset( $value, $data ) && is_string( $value ) && is_array( $data ) ) {
				self::retain_hosted_callback_with_limit( $key, $value, $data, 'processing_exception' );
			}
			MMGWC_Logger::error( 'Queued MMG callback processing failed', array( 'order_id' => $order_id, 'reason' => $exception->getMessage() ) );
		} finally {
			MMGWC_Atomic_Option::release_lock( $processing_key, $processing_owner );
		}
	}

	public function resolve_order_from_mmg_response( array $response ) {
		$merchant_txn_id = self::response_merchant_transaction_id( $response );
		if ( $merchant_txn_id === '' || strlen( $merchant_txn_id ) > 191 ) {
			return null;
		}
		$session = self::hosted_session_context( $merchant_txn_id );
		if ( ! is_array( $session ) ) {
			// Preserve a checkout page created immediately before this version was
			// installed. The fallback still requires one exact current-meta match and
			// converts that legacy snapshot into the new immutable reservation.
			$orders = wc_get_orders(
				array(
					'limit' => 2,
					'return' => 'objects',
					'payment_method' => 'mmg_checkout',
					'meta_key' => MMGWC_META_MERCHANT_TXN_ID,
					'meta_value' => $merchant_txn_id,
				)
			);
			if ( ! is_array( $orders ) || count( $orders ) !== 1 || ! $orders[0] instanceof WC_Order ) {
				return null;
			}
			$legacy_order = $orders[0];
			$session = array(
				'version' => 1,
				'order_id' => (int) $legacy_order->get_id(),
				'merchant_transaction_id' => $merchant_txn_id,
				'created_at' => (int) $legacy_order->get_meta( '_mmg_initiated_at' ),
				'mode' => (string) $legacy_order->get_meta( MMGWC_META_MODE ),
				'expected_amount' => (string) $legacy_order->get_meta( MMGWC_META_EXPECTED_AMOUNT ),
				'expected_currency' => (string) $legacy_order->get_meta( MMGWC_META_EXPECTED_CURRENCY ),
				'expected_merchant_id' => (string) $legacy_order->get_meta( MMGWC_META_EXPECTED_MERCHANT_ID ),
				'expected_order_total' => (string) $legacy_order->get_meta( MMGWC_META_EXPECTED_ORDER_TOTAL ),
				'expected_order_currency' => (string) $legacy_order->get_meta( MMGWC_META_EXPECTED_ORDER_CURRENCY ),
			);
			try {
				$this->reserve_hosted_session_context( $session );
			} catch ( Throwable $exception ) {
				$session = self::hosted_session_context( $merchant_txn_id );
				if ( ! is_array( $session ) ) {
					return null;
				}
			}
		}

		// The exact transaction ID hashes to an immutable reservation that contains
		// its order ID and payment snapshot. No order ID is parsed from callback text.
		$order = wc_get_order( (int) $session['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		return $order;
	}

	private function record_hosted_settlement_after_method_change( WC_Order $order, array $verification, string $fallback_transaction_id ): string {
		$transaction_id = isset( $verification['transaction_id'] ) && is_scalar( $verification['transaction_id'] )
			? trim( (string) $verification['transaction_id'] )
			: trim( $fallback_transaction_id );
		if ( preg_match( '/^\d{1,64}$/', $transaction_id ) === 1 ) {
			$order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
		}
		$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:payment_method_changed_review' );
		$order->save();
		$order->add_order_note( 'MMG settlement was authenticated after the order payment method changed. Transaction ID: ' . $transaction_id . '. The current payment method and order status were preserved for manual review.' );
		$this->add_customer_notice( 'MMG confirmed payment after the order payment method changed. Please do not pay again. The store will review it.', 'notice' );
		return $this->get_return_url( $order );
	}

	public function handle_mmg_response_for_order( WC_Order $order, array $response, &$processing_state = null, array $processing_context = array() ): string {
		$processing_state = array( 'retry' => false, 'reason' => '' );
		$from_queue = ! empty( $processing_context['from_queue'] );
		$order_id = (int) $order->get_id();
		$txn_id = $response['transactionId'] ?? $response['TransactionId'] ?? $response['transactionReference'] ?? $response['transactionReceipt'] ?? $response['executionId'] ?? '';
		$txn_id = is_scalar( $txn_id ) ? trim( (string) $txn_id ) : '';

		$result_code = $response['resultCode'] ?? $response['ResultCode'] ?? $response['transactionStatus'] ?? $response['transactionStatusCode'] ?? '';
		$result_message = $response['resultMessage'] ?? $response['ResultMessage'] ?? $response['message'] ?? '';
		$rc = is_scalar( $result_code ) ? trim( (string) $result_code ) : '';
		$mapping = $this->map_result_code( $rc );
		$label = $mapping['label'];
		$type = $mapping['type'];
		$rm_text = is_scalar( $result_message ) ? trim( (string) $result_message ) : '';

		if ( ! MMGWC_Payment_Verifier::acquire_order_lock( $order_id ) ) {
			$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
			$processing_state = array( 'retry' => $queued, 'reason' => 'order_lock_busy' );
			MMGWC_Logger::info( 'MMG callback handling deferred', array( 'order_id' => $order_id, 'queued' => $queued ) );
			$this->add_customer_notice(
				$queued
					? 'Your MMG payment response was received and is being checked. Please do not pay again.'
					: 'Your MMG payment response needs store review. Please do not pay again.',
				'notice'
			);
			$fresh_order = MMGWC_Payment_Context::fresh_order( $order_id );
			return $fresh_order instanceof WC_Order && $fresh_order->is_paid()
				? $this->get_return_url( $fresh_order )
				: $this->get_return_url( $order );
		}

		try {
			$locked_order = MMGWC_Payment_Context::fresh_order( $order_id );
			if ( ! $locked_order instanceof WC_Order ) {
				$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
				$processing_state = array( 'retry' => $queued, 'reason' => 'order_refresh_failed' );
				$this->add_customer_notice( 'Your MMG payment response is being checked. Please do not pay again.', 'notice' );
				return $this->get_return_url( $order );
			}
			$order = $locked_order;
			$payment_method_changed = $order->get_payment_method() !== 'mmg_checkout';

			$response_merchant_transaction_id = self::response_merchant_transaction_id( $response );
			$session = self::hosted_session_context( $response_merchant_transaction_id );
			$stored_merchant_transaction_id = is_array( $session ) ? (string) $session['merchant_transaction_id'] : '';
			$decrypted_mode = isset( $response['_mmgwc_decrypted_mode'] ) && is_scalar( $response['_mmgwc_decrypted_mode'] ) ? trim( (string) $response['_mmgwc_decrypted_mode'] ) : '';
			$order_mode = is_array( $session ) ? (string) $session['mode'] : '';
			if ( $stored_merchant_transaction_id === '' || $response_merchant_transaction_id === '' || ! hash_equals( $stored_merchant_transaction_id, $response_merchant_transaction_id ) || $decrypted_mode !== $order_mode ) {
				if ( ! $order->is_paid() ) {
					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'failed:callback_correlation' );
					$order->save();
				}
				$order->add_order_note( 'MMG callback was rejected because its order correlation or credential mode did not match.' );
				$this->add_customer_notice( 'The MMG response could not be verified. Please contact the store before trying again.', 'error' );
				return $order->is_paid() || in_array( (string) $order->get_status(), array( 'refunded', 'trash' ), true )
					? $this->get_return_url( $order )
					: $order->get_checkout_payment_url( true );
			}
			$current_merchant_transaction_id = trim( (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID ) );
			$is_current_session = $current_merchant_transaction_id !== '' && hash_equals( $current_merchant_transaction_id, $response_merchant_transaction_id );
			// A late failure, timeout or cancellation must never downgrade an order
			// that another verified callback or trusted workflow already paid.
			if ( ( $order->is_paid() || in_array( (string) $order->get_status(), array( 'refunded', 'trash', 'cancelled', 'failed' ), true ) ) && $type !== 'success' ) {
				if ( $type !== 'success' ) {
					$order->add_order_note( 'A late MMG ' . sanitize_key( $type ) . ' callback was ignored because the order was already paid or closed.' );
				}
				return $this->get_return_url( $order );
			}

			if ( ! $is_current_session && $type !== 'success' ) {
				$order->add_order_note( 'A non-success callback from an earlier MMG checkout session was recorded without changing the current payment session.' );
				$this->add_customer_notice( 'That MMG attempt did not complete. Return to checkout to use the latest payment session.', 'notice' );
				return $order->get_checkout_payment_url( true );
			}

			$order->update_meta_data( MMGWC_META_RESULT_CODE, is_scalar( $result_code ) ? (string) $result_code : '' );
			$order->update_meta_data( MMGWC_META_RESULT_MESSAGE, is_scalar( $result_message ) ? (string) $result_message : '' );
			$order->update_meta_data( MMGWC_META_RAW_RESPONSE, wp_json_encode( MMGWC_Payment_Verifier::callback_record( $response ) ) );
			$order->save();
			if ( $payment_method_changed && $type !== 'success' ) {
				$order->add_order_note( 'An MMG checkout attempt ended with status ' . sanitize_key( $type ) . ' after the order payment method changed. The current payment method and order status were preserved.' );
				$this->add_customer_notice( 'That MMG checkout attempt did not complete. The current order payment method was preserved.', 'notice' );
				return $this->get_return_url( $order );
			}

			if ( $type === 'success' ) {
				$config = $this->get_config_for_mode( $order_mode );
				$verification_snapshot = $session;
				$verification_snapshot['payment_method'] = 'mmg_checkout';
				$lookup_id_valid = preg_match( '/^\d{1,64}$/', $txn_id ) === 1;
				$lookup_data = $lookup_id_valid ? MMGWC_API::transaction_lookup( $config, $txn_id ) : null;
				if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
					$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
					$processing_state = array( 'retry' => $queued, 'reason' => 'order_lock_lost' );
					$this->add_customer_notice( 'Your MMG payment response is being checked. Please do not pay again.', 'notice' );
					return $this->get_return_url( $order );
				}
				$post_lookup_order = MMGWC_Payment_Context::fresh_order( $order_id );
				if ( ! $post_lookup_order instanceof WC_Order ) {
					$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
					$processing_state = array( 'retry' => $queued, 'reason' => 'order_refresh_failed' );
					$this->add_customer_notice( 'The order changed while MMG verification was running. The store must review the payment.', 'notice' );
					return $this->get_return_url( $order );
				}
				$order = $post_lookup_order;
				$payment_method_changed = $order->get_payment_method() !== 'mmg_checkout';
				$order->update_meta_data( MMGWC_META_LAST_VERIFIED_AT, (string) time() );
				if ( is_array( $lookup_data ) ) {
					$order->update_meta_data( '_mmg_lookup_last', wp_json_encode( MMGWC_Payment_Verifier::lookup_record( $lookup_data ) ) );
				}
				$order->save();

				if ( ! is_array( $lookup_data ) && ( $order->is_paid() || in_array( (string) $order->get_status(), array( 'refunded', 'trash' ), true ) ) ) {
					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:lookup_failed_after_order_closed' );
					$order->save();
					$order->add_order_note( 'MMG returned a success callback after the order was already paid or closed, but authenticated lookup was unavailable. Review the possible duplicate payment with MMG.' );
					$this->add_customer_notice( 'This order is already paid or closed. The store is checking an additional MMG response. Please do not pay again.', 'notice' );
					return $this->get_return_url( $order );
				}

				if ( $lookup_id_valid && ! is_array( $lookup_data ) ) {
					$retry_allowed = ! $from_queue || ! empty( $processing_context['lookup_retry_allowed'] );
					if ( $from_queue && ! $retry_allowed ) {
						$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:lookup_retry_exhausted' );
						$order->save();
						$order->add_order_note( 'MMG reported payment success, but authenticated lookup remained unavailable after bounded retries. Manual verification with MMG is required. Do not ask the customer to pay again.' );
						MMGWC_Logger::error( 'MMG payment lookup retry limit reached', array( 'order_id' => $order_id, 'attempts' => (int) ( $processing_context['lookup_attempt'] ?? 0 ) ) );
						$this->add_customer_notice( 'Your MMG payment needs store verification. Please do not pay again.', 'notice' );
						return $this->get_return_url( $order );
					}

					$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
					if ( $queued ) {
						$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'pending:lookup_retry' );
						$order->save();
						if ( ! $from_queue ) {
							$order->add_order_note( 'MMG reported payment success, but authenticated lookup was temporarily unavailable. Verification was queued for retry.' );
						}
						$processing_state = array( 'retry' => true, 'reason' => 'lookup_failed' );
					} else {
						$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:lookup_retry_queue_failed' );
						$order->save();
						$order->add_order_note( 'MMG reported payment success, but authenticated lookup was unavailable and the retry could not be stored. Manual verification with MMG is required.' );
					}
					$this->add_customer_notice( 'Your MMG payment is being verified. Please do not pay again.', 'notice' );
					return $this->get_return_url( $order );
				}

				$verification = is_array( $lookup_data )
					? MMGWC_Payment_Verifier::verify_callback( $order, $response, $lookup_data, $config, $verification_snapshot )
					: array( 'valid' => false, 'code' => 'invalid_transaction_id' );
				if ( ! empty( $verification['valid'] ) || ! empty( $verification['settlement_verified'] ) ) {
					if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
						$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
						$processing_state = array( 'retry' => $queued, 'reason' => 'order_lock_lost' );
						return $this->get_return_url( $order );
					}
					$pre_completion_order = MMGWC_Payment_Context::fresh_order( $order_id );
					if ( ! $pre_completion_order instanceof WC_Order ) {
						$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
						$processing_state = array( 'retry' => $queued, 'reason' => 'order_refresh_failed' );
						return $this->get_return_url( $order );
					}
					$order = $pre_completion_order;
					$payment_method_changed = $order->get_payment_method() !== 'mmg_checkout';
					$verification = MMGWC_Payment_Verifier::verify_callback( $order, $response, $lookup_data, $config, $verification_snapshot );
				}
				if ( $payment_method_changed && ( ! empty( $verification['valid'] ) || ! empty( $verification['settlement_verified'] ) ) ) {
					return $this->record_hosted_settlement_after_method_change( $order, $verification, $txn_id );
				}
				if ( ! empty( $verification['settlement_verified'] ) ) {
					$review_transaction_id = (string) $verification['transaction_id'];
					$order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $review_transaction_id );
					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
					$order->save();
					$order->add_order_note( 'MMG reported an additional settled checkout transaction after the order was already paid or closed. Transaction ID: ' . $review_transaction_id . '. Review the possible duplicate payment with MMG.' );
					$this->add_customer_notice( 'This order was already paid or closed and MMG reported another payment. The store will review it. Please do not pay again.', 'notice' );
					return $this->get_return_url( $order );
				}
				if ( ! empty( $verification['valid'] ) ) {
					$verified_txn_id = (string) $verification['transaction_id'];
					$order->update_meta_data( MMGWC_META_TXN_ID, $verified_txn_id );
					$order->update_meta_data( MMGWC_META_PROCESSED_TXN_ID, $verified_txn_id );
					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified' );
					$order->save();
					if ( ! $order->is_paid() ) {
						if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
							$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
							$processing_state = array( 'retry' => $queued, 'reason' => 'order_lock_lost' );
							return $this->get_return_url( $order );
						}
						$completion_order = MMGWC_Payment_Context::fresh_order( $order_id );
						if ( ! $completion_order instanceof WC_Order ) {
							$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
							$processing_state = array( 'retry' => $queued, 'reason' => 'order_refresh_failed' );
							return $this->get_return_url( $order );
						}
						$completion_method_changed = $completion_order->get_payment_method() !== 'mmg_checkout';
						$completion_verification = MMGWC_Payment_Verifier::verify_callback( $completion_order, $response, $lookup_data, $config, $verification_snapshot );
						if ( $completion_method_changed && ( ! empty( $completion_verification['valid'] ) || ! empty( $completion_verification['settlement_verified'] ) ) ) {
							return $this->record_hosted_settlement_after_method_change( $completion_order, $completion_verification, $verified_txn_id );
						}
						if ( ! empty( $completion_verification['settlement_verified'] ) ) {
							$completion_order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $verified_txn_id );
							$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
							$completion_order->save();
							$completion_order->add_order_note( 'MMG settlement was verified after the order closed. Transaction ID: ' . $verified_txn_id . '. The closed status was preserved.' );
							return $this->get_return_url( $completion_order );
						}
						if ( empty( $completion_verification['valid'] ) ) {
							$reason = sanitize_key( (string) ( $completion_verification['code'] ?? 'order_changed' ) );
							$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:' . $reason );
							$completion_order->save();
							$completion_order->add_order_note( 'MMG settlement could not complete after a final order-state check: ' . $reason . '. Manual review is required.' );
							return $this->get_return_url( $completion_order );
						}
						$order = $completion_order;
						$order->payment_complete( $verified_txn_id );
					}

					$fresh_order = MMGWC_Payment_Context::fresh_order( $order_id );
					if ( ! $fresh_order instanceof WC_Order ) {
						$queued = $from_queue || $this->queue_hosted_callback( $order_id, $response );
						$processing_state = array( 'retry' => $queued, 'reason' => 'order_refresh_failed' );
						return $this->get_return_url( $order );
					}
					$order = $fresh_order;
					if ( $order->is_paid() ) {
						$this->clear_checkout_session_meta( $order, true );
						if ( empty( $verification['idempotent'] ) ) {
							$order->add_order_note( sprintf( 'MMG payment authenticated and verified. Transaction ID: %s', $verified_txn_id ) );
						}
						return $this->get_return_url( $order );
					}

					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'payment_confirmed_review' );
					$order->save();
					$order->add_order_note( 'MMG payment was verified, but WooCommerce did not enter a paid status. Manual order review is required.' );
					$this->add_customer_notice( 'Your MMG payment was confirmed, but the order status needs store review. Please do not pay again.', 'notice' );
					return $this->get_return_url( $order );
				}

				$failure_code = isset( $verification['code'] ) ? sanitize_key( (string) $verification['code'] ) : 'verification_failed';
				$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'failed:' . $failure_code );
				$order->save();
				$order->add_order_note( 'MMG callback reported success but authenticated verification failed: ' . $failure_code . '. The order was not marked as paid.' );
				MMGWC_Logger::error( 'MMG payment verification failed', array( 'order_id' => $order_id, 'reason' => $failure_code ) );
				$this->add_customer_notice( 'Your MMG payment is awaiting verification. Please do not pay again. The store will review the transaction.', 'notice' );
				return $this->get_return_url( $order );
			}

			if ( preg_match( '/^\d{1,64}$/', $txn_id ) === 1 ) {
				$order->update_meta_data( MMGWC_META_TXN_ID, $txn_id );
			}
			$details = $rm_text !== '' ? $rm_text : $label;
			if ( $type === 'cancelled' ) {
				$cancel_status = self::safe_unpaid_status( MMGWC_Settings::get( 'status_cancelled', 'cancelled' ), 'cancelled' );
				$order->update_status( $cancel_status, 'MMG payment cancelled: ' . $details );
				$this->clear_checkout_session_meta( $order );
				$this->add_customer_notice( 'Payment cancelled. You can try again.', 'notice' );
				return $order->get_checkout_payment_url( true );
			}

			if ( $type === 'timeout' ) {
				$timeout_status = self::safe_unpaid_status( MMGWC_Settings::get( 'status_timeout', 'failed' ), 'failed' );
				$order->update_status( $timeout_status, 'MMG payment timed out: ' . $details );
				$this->clear_checkout_session_meta( $order );
				$this->add_customer_notice( 'Payment timed out. Please try again.', 'error' );
				return $order->get_checkout_payment_url( true );
			}

			$failed_status = self::safe_unpaid_status( MMGWC_Settings::get( 'status_failed', 'failed' ), 'failed' );
			$order->update_status( $failed_status, sprintf( 'MMG payment not completed. Code: %s. Message: %s', $rc, $rm_text ) );
			$this->clear_checkout_session_meta( $order );
			$this->add_customer_notice( 'Payment failed. Please try again.', 'error' );
			return $order->get_checkout_payment_url( true );
		} finally {
			MMGWC_Payment_Verifier::release_order_lock( $order_id );
		}
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
			if ( ! in_array( $wc_order->get_payment_method(), array( 'mmg_checkout', 'mmg_initiated' ), true ) ) {
				return $status;
			}
			$virtual = MMGWC_Settings::get( 'status_success_virtual', '' );
			$physical = MMGWC_Settings::get( 'status_success_physical', '' );
			$virtual = is_string( $virtual ) ? trim( $virtual ) : '';
			$physical = is_string( $physical ) ? trim( $physical ) : '';
			$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
			$needs_processing = method_exists( $wc_order, 'needs_processing' ) ? (bool) $wc_order->needs_processing() : false;
			if ( $needs_processing && $physical !== '' && in_array( $physical, $paid_statuses, true ) ) {
				return $physical;
			}
			if ( ! $needs_processing && $virtual !== '' && in_array( $virtual, $paid_statuses, true ) ) {
				return $virtual;
			}
		} catch ( Throwable $e ) {
			return $status;
		}
		return $status;
	}

	private static function safe_unpaid_status( $status, string $fallback ): string {
		return MMGWC_Payment_Context::safe_unpaid_status( $status, $fallback );
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
