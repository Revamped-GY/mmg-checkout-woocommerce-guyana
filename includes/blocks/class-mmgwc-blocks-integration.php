<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class MMGWC_Blocks_Integration extends AbstractPaymentMethodType {
	protected $name = 'mmg_checkout';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_mmg_checkout_settings', array() );
	}

	public function is_active() {
		if ( ! isset( $this->settings['enabled'] ) || $this->settings['enabled'] !== 'yes' ) {
			return false;
		}
		$mode = MMGWC_Settings::get_mode();
		$config = MMGWC_Settings::get_config( $mode );
		return empty( MMGWC_Payment_Verifier::missing_api_fields( $config ) )
			&& empty( MMGWC_Payment_Context::missing_hosted_fields( $config ) )
			&& MMGWC_Payment_Context::can_process_currency( MMGWC_Payment_Context::current_checkout_currency() );
	}

	public function get_payment_method_script_handles() {
		$handle = 'mmg-checkout-woocommerce-blocks';
		wp_register_script(
			'mmgwc-checkout-guide',
			MMGWC_PLUGIN_URL . 'assets/js/checkout-guide.js',
			array(),
			MMGWC_VERSION,
			true
		);
		wp_enqueue_style( 'mmgwc-gateway-frontend', MMGWC_PLUGIN_URL . 'assets/css/gateway.css', array(), MMGWC_VERSION );
		wp_register_script(
			$handle,
			MMGWC_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n', 'mmgwc-checkout-guide' ),
			MMGWC_VERSION,
			true
		);
		return array( $handle );
	}

	public function get_payment_method_data() {
		$title = $this->settings['title'] ?? 'MMG';
		$description = $this->settings['description'] ?? 'You will be redirected to MMG to complete your payment.';
		$icon_url = apply_filters( 'mmgwc_gateway_icon_url', defined( 'MMGWC_GATEWAY_ICON_URL' ) ? MMGWC_GATEWAY_ICON_URL : '' );
		$content_html = WC_Gateway_MMGWC::get_checkout_content_html(
			(string) $description,
			( $this->settings['checkout_guide_enabled'] ?? 'yes' ) === 'yes'
		);

		return array(
			'title' => $title,
			'description' => $description,
			'content_html' => $content_html,
			'icon_url' => $icon_url,
			'currency_available' => MMGWC_Payment_Context::can_process_currency( MMGWC_Payment_Context::current_checkout_currency() ),
			'supports' => array( 'features' => array( 'products', 'pay_for_order' ) ),
		);
	}
}
