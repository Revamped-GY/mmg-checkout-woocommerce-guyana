<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout compatibility helpers.
 *
 * Some themes and checkout customisations omit the WooCommerce checkout nonce field.
 * That can cause the checkout request to fail with a blank "-1" response.
 *
 * This class injects the missing nonce field on the checkout page when needed.
 */
final class MMGWC_Checkout_Compat {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		// Only relevant when WooCommerce is active and the frontend is rendering checkout.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}

		$handle = 'mmgwc-checkout-compat';
		$src    = MMGWC_PLUGIN_URL . 'assets/js/checkout-compat.js';

		wp_register_script( $handle, $src, array(), MMGWC_VERSION, true );
		wp_enqueue_script( $handle );

		wp_localize_script(
			$handle,
			'MMGWCCheckoutCompat',
			array(
				'nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
			)
		);
	}
}
