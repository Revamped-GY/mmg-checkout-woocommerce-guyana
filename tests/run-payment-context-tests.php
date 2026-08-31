<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$checkout_pay_page = false;
$test_order = null;
$currency_conversion = 'no';

final class WC_Order {
	private $currency;

	public function __construct( string $currency ) {
		$this->currency = $currency;
	}

	public function get_currency(): string { return $this->currency; }
}

final class MMGWC_Settings {
	public static function get( string $key, $default = '' ) {
		global $currency_conversion;
		return $key === 'currency_conversion' ? $currency_conversion : $default;
	}
}

function is_checkout_pay_page(): bool {
	global $checkout_pay_page;
	return $checkout_pay_page;
}

function get_query_var( string $key ) { return $key === 'order-pay' ? 42 : 0; }
function absint( $value ): int { return abs( (int) $value ); }
function wc_get_order( int $order_id ) {
	global $test_order;
	return $order_id === 42 ? $test_order : false;
}
function get_woocommerce_currency(): string { return 'GYD'; }
function wc_get_is_paid_statuses(): array { return array( 'processing', 'completed' ); }
function wp_parse_url( string $url ) { return parse_url( $url ); }
function apply_filters( string $hook, $value, ...$args ) { return $value; }

require dirname( __DIR__ ) . '/includes/class-mmgwc-payment-context.php';

$tests = 0;
$failures = 0;

function context_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$checkout_pay_page = false;
context_check( MMGWC_Payment_Context::current_checkout_currency() === 'GYD', 'A normal checkout uses the current store currency.' );

$checkout_pay_page = true;
$test_order = new WC_Order( 'usd' );
context_check( MMGWC_Payment_Context::current_checkout_currency() === 'USD', 'A pay-for-order checkout uses the stored order currency.' );
context_check( ! MMGWC_Payment_Context::can_process_currency( 'USD' ), 'A non-GYD order is unavailable when conversion is disabled.' );
$currency_conversion = 'yes';
context_check( MMGWC_Payment_Context::can_process_currency( 'USD' ), 'A non-GYD order is available when conversion is enabled.' );
context_check( MMGWC_Payment_Context::safe_unpaid_status( 'wc-processing', 'failed' ) === 'failed', 'A prefixed paid status cannot be used for a failed payment.' );
context_check( MMGWC_Payment_Context::safe_unpaid_status( 'wc-refunded', 'cancelled' ) === 'cancelled', 'A prefixed refunded status cannot be used for a cancelled payment.' );
context_check( MMGWC_Payment_Context::safe_unpaid_status( 'wc-trash', 'failed' ) === 'failed', 'Trash cannot be used for a failed payment.' );
context_check( MMGWC_Payment_Context::safe_unpaid_status( 'auto-draft', 'cancelled' ) === 'cancelled', 'Internal draft states cannot be used for an unpaid payment result.' );
context_check( MMGWC_Payment_Context::safe_unpaid_status( 'wc-custom-review', 'failed' ) === 'custom-review', 'A custom unpaid status is normalised before WooCommerce receives it.' );

$hosted_config = array(
	'mode' => 'sandbox',
	'checkout_url' => 'https://mmgpg.mmgtest.net/mmg-pg/web/payments',
	'merchant_id' => 'merchant',
	'client_id' => 'client',
	'merchant_name' => 'Store',
	'secret_key' => 'secret',
	'public_key' => 'public',
	'private_key' => 'private',
);
context_check( MMGWC_Payment_Context::missing_hosted_fields( $hosted_config ) === array(), 'A complete hosted credential set is available at checkout.' );
unset( $hosted_config['public_key'] );
context_check( MMGWC_Payment_Context::missing_hosted_fields( $hosted_config ) === array( 'public_key' ), 'Hosted availability reports a field required by redirect creation.' );
$hosted_config['public_key'] = 'public';
$private_key = $hosted_config['private_key'];
unset( $hosted_config['private_key'] );
context_check( MMGWC_Payment_Context::missing_hosted_fields( $hosted_config ) === array( 'private_key' ), 'Hosted availability requires the private key used to authenticate the MMG return.' );
$hosted_config['private_key'] = $private_key;
$hosted_config['checkout_url'] = 'http://mmgpg.mmgtest.net/mmg-pg/web/payments';
context_check( in_array( 'valid checkout_url', MMGWC_Payment_Context::missing_hosted_fields( $hosted_config ), true ), 'Hosted checkout rejects a non-HTTPS payment URL.' );
$hosted_config['checkout_url'] = 'https://payments.example.test/mmg';
context_check( in_array( 'valid checkout_url', MMGWC_Payment_Context::missing_hosted_fields( $hosted_config ), true ), 'Hosted checkout rejects an unapproved payment host.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} payment context tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} payment context tests passed.\n" );
