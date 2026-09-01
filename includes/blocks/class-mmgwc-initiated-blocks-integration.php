<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class MMGWC_Initiated_Blocks_Integration extends AbstractPaymentMethodType {
	protected $name = 'mmg_initiated';

	public function initialize() {
		$this->settings = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
	}

	public function is_active() {
		if ( MMGWC_Settings::get( 'initiated_enabled', 'no' ) !== 'yes' ) {
			return false;
		}
		$config = MMGWC_Settings::get_config( MMGWC_Settings::get_mode() );
		return empty( MMGWC_Payment_Verifier::missing_api_fields( $config ) )
			&& trim( (string) ( $config['credit_account_id'] ?? '' ) ) !== ''
			&& MMGWC_Payment_Context::can_process_currency( MMGWC_Payment_Context::current_checkout_currency() );
	}

	public function get_payment_method_script_handles() {
		$handle = 'mmgwc-initiated-blocks';
		wp_enqueue_style( 'mmgwc-gateway-frontend', MMGWC_PLUGIN_URL . 'assets/css/gateway.css', array(), MMGWC_VERSION );
		wp_register_script(
			$handle,
			MMGWC_PLUGIN_URL . 'assets/js/initiated-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			MMGWC_VERSION,
			true
		);
		return array( $handle );
	}

	public function get_payment_method_data() {
		return array(
			'title' => (string) MMGWC_Settings::get( 'initiated_title', 'Approve in the MMG app' ),
			'description' => (string) MMGWC_Settings::get(
				'initiated_description',
				'Enter the phone number registered to your MMG account. Open the MMG app and approve the payment request before it expires.'
			),
			'icon_url' => apply_filters( 'mmgwc_gateway_icon_url', defined( 'MMGWC_GATEWAY_ICON_URL' ) ? MMGWC_GATEWAY_ICON_URL : '' ),
			'currency_available' => MMGWC_Payment_Context::can_process_currency( MMGWC_Payment_Context::current_checkout_currency() ),
			'supports' => array( 'features' => array( 'products', 'pay_for_order' ) ),
		);
	}
}
