<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
	abstract class AbstractPaymentMethodType {
		protected $settings = array();
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	class WC_Payment_Gateway {
		public $enabled = 'no';
		public $settings = array();

		public function is_available() {
			return $this->enabled === 'yes';
		}

		public function get_option( $key, $default = '' ) {
			return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default;
		}
	}

	final class MMGWC_Settings {
		public static $config = array();

		public static function get_mode(): string {
			return 'live';
		}

		public static function get( string $key, $default = '' ) {
			global $test_options;
			$settings = $test_options['woocommerce_mmg_checkout_settings'] ?? array();
			return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
		}

		public static function get_config( string $mode ): array {
			return self::$config;
		}
	}

	final class MMGWC_Payment_Context {
		public static $currency_available = true;

		public static function missing_hosted_fields( array $config ): array {
			$missing = array();
			foreach ( array( 'checkout_url', 'merchant_id', 'client_id', 'merchant_name', 'secret_key', 'public_key', 'private_key' ) as $key ) {
				if ( ! isset( $config[ $key ] ) || trim( (string) $config[ $key ] ) === '' ) {
					$missing[] = $key;
				}
			}
			return $missing;
		}

		public static function can_process_currency( string $currency ): bool {
			return self::$currency_available;
		}

		public static function current_checkout_currency(): string {
			return 'GYD';
		}
	}

	final class MMGWC_Payment_Verifier {
		public static function missing_api_fields( array $config ): array {
			throw new \RuntimeException( 'Hosted availability must not inspect Merchant Initiated API fields.' );
		}
	}

	$test_options = array(
		'woocommerce_mmg_checkout_settings' => array(
			'enabled' => 'yes',
		),
	);

	function get_option( string $key, $default = false ) {
		global $test_options;
		return array_key_exists( $key, $test_options ) ? $test_options[ $key ] : $default;
	}

	require dirname( __DIR__ ) . '/includes/class-wc-gateway-mmgwc.php';
	require dirname( __DIR__ ) . '/includes/blocks/class-mmgwc-blocks-integration.php';

	$tests = 0;
	$failures = 0;

	function availability_check( bool $condition, string $message ): void {
		global $tests, $failures;
		$tests++;
		if ( ! $condition ) {
			$failures++;
			fwrite( STDERR, "FAIL: {$message}\n" );
		}
	}

	MMGWC_Settings::$config = array(
		'mode' => 'live',
		'checkout_url' => 'https://mmgpg.mymmg.gy/mmg-pg/web/payments',
		'merchant_id' => '9991161',
		'client_id' => 'client-id',
		'merchant_name' => 'Example Store',
		'secret_key' => 'hosted-secret',
		'public_key' => 'public-key',
		'private_key' => 'private-key',
		'mwallet_base_url' => '',
		'api_key' => '',
		'wss_mid' => '',
		'wss_mkey' => '',
		'wss_msecret' => '',
		'password' => '',
	);

	$gateway = ( new \ReflectionClass( 'WC_Gateway_MMGWC' ) )->newInstanceWithoutConstructor();
	$gateway->enabled = 'yes';
	$gateway->settings = array( 'enabled' => 'yes', 'mode' => 'live' );
	availability_check( $gateway->is_available() === true, 'Classic hosted checkout remains available without Merchant Initiated API credentials.' );

	$blocks = new MMGWC_Blocks_Integration();
	$blocks->initialize();
	availability_check( $blocks->is_active() === true, 'Blocks hosted checkout remains active without Merchant Initiated API credentials.' );

	MMGWC_Settings::$config['private_key'] = '';
	availability_check( $gateway->is_available() === false, 'Classic hosted checkout remains unavailable when a documented Merchant Checkout credential is missing.' );
	availability_check( $blocks->is_active() === false, 'Blocks hosted checkout remains inactive when a documented Merchant Checkout credential is missing.' );

	MMGWC_Settings::$config['private_key'] = 'private-key';
	MMGWC_Payment_Context::$currency_available = false;
	availability_check( $gateway->is_available() === false, 'Classic hosted checkout respects the currency gate.' );
	availability_check( $blocks->is_active() === false, 'Blocks hosted checkout respects the currency gate.' );

	if ( $failures > 0 ) {
		fwrite( STDERR, "{$failures} of {$tests} hosted availability tests failed.\n" );
		exit( 1 );
	}

	fwrite( STDOUT, "All {$tests} hosted availability tests passed.\n" );
}
