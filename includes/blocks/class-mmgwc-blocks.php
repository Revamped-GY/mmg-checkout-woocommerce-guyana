<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class MMGWC_Blocks {

	private static $initialized = false;

	/**
	 * Initialise Blocks integration early.
	 *
	 * Some WooCommerce versions fire Blocks hooks very early, so we hook directly into
	 * woocommerce_blocks_payment_method_type_registration rather than relying on woocommerce_blocks_loaded.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register' ), 10, 1 );
	}

	/**
	 * Register the MMG payment method with WooCommerce Blocks.
	 *
	 * @param mixed $payment_method_registry Payment method registry instance.
	 */
	public static function register( $payment_method_registry ) {
		if ( ! class_exists( AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once MMGWC_PLUGIN_DIR . 'includes/blocks/class-mmgwc-blocks-integration.php';

		if ( is_object( $payment_method_registry ) && method_exists( $payment_method_registry, 'register' ) ) {
			$payment_method_registry->register( new MMGWC_Blocks_Integration() );
		}
	}
}
