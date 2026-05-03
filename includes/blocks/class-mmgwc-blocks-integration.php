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
		return isset( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
	}

	public function get_payment_method_script_handles() {
		$handle = 'mmg-checkout-woocommerce-blocks';
		wp_register_script(
			$handle,
			MMGWC_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			MMGWC_VERSION,
			true
		);
		return array( $handle );
	}

	public function get_payment_method_data() {
		$title = $this->settings['title'] ?? 'MMG';
		$description = $this->settings['description'] ?? 'You will be redirected to MMG to complete your payment.';
		$icon_url = apply_filters( 'mmgwc_gateway_icon_url', defined( 'MMGWC_GATEWAY_ICON_URL' ) ? MMGWC_GATEWAY_ICON_URL : '' );

		return array(
			'title' => $title,
			'description' => $description,
			'icon_url' => $icon_url,
			'supports' => array( 'features' => array( 'products', 'pay_for_order' ) ),
		);
	}
}
