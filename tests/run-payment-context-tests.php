<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MMGWC_META_MERCHANT_TXN_ID', '_mmg_merchant_transaction_id' );
define( 'MMGWC_META_MODE', '_mmg_mode' );
define( 'MMGWC_META_ORIGINAL_CURRENCY', '_mmg_original_currency' );
define( 'MMGWC_META_ORIGINAL_TOTAL', '_mmg_original_total' );
define( 'MMGWC_META_MMG_AMOUNT_GYD', '_mmg_amount_gyd' );

$checkout_pay_page = false;
$test_order = null;
$currency_conversion = 'no';

final class WC_Order {
	private $currency;
	private $total;
	private $meta;
	private $payment_method;
	private $id;

	public function __construct( string $currency, float $total = 500.0, array $meta = array(), string $payment_method = 'mmg_checkout', int $id = 42 ) {
		$this->currency = $currency;
		$this->total = $total;
		$this->meta = $meta;
		$this->payment_method = $payment_method;
		$this->id = $id;
	}

	public function get_currency(): string { return $this->currency; }
	public function get_total(): float { return $this->total; }
	public function get_meta( string $key ) { return $this->meta[ $key ] ?? ''; }
	public function get_payment_method(): string { return $this->payment_method; }
	public function get_id(): int { return $this->id; }
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
function wp_parse_str( string $input, array &$result ): void { parse_str( $input, $result ); }
function wc_format_decimal( $value, int $decimals = 2 ): string { return number_format( (float) $value, $decimals, '.', '' ); }
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

$legacy_transaction_id = '42-1700000000';
$legacy_meta = array(
	MMGWC_META_MERCHANT_TXN_ID => $legacy_transaction_id,
	MMGWC_META_MODE => 'sandbox',
	'_mmg_initiated_at' => 1700000000,
	'_mmgwc_last_checkout_url' => 'https://mmgpg.mmgtest.net/mmg-pg/web/payments?token=opaque&merchantId=9991037&X-Client-ID=client',
	'_mmgwc_last_checkout_url_at' => 1700000001,
	'_mmgwc_last_checkout_url_mode' => 'sandbox',
);
$legacy_gyd_order = new WC_Order( 'GYD', 500.0, $legacy_meta );
$legacy_gyd_snapshot = MMGWC_Payment_Context::legacy_hosted_snapshot( $legacy_gyd_order, $legacy_transaction_id );
context_check( is_array( $legacy_gyd_snapshot ), 'A valid pre-2.16.0 GYD checkout snapshot is reconstructed.' );
context_check( ( $legacy_gyd_snapshot['expected_amount'] ?? '' ) === '500.00', 'A legacy GYD snapshot uses the order total that the old checkout charged.' );
context_check( ( $legacy_gyd_snapshot['expected_merchant_id'] ?? '' ) === '9991037', 'A legacy snapshot recovers the exact merchant ID from the stored checkout URL.' );
context_check( ( $legacy_gyd_snapshot['expected_order_currency'] ?? '' ) === 'GYD', 'A legacy GYD snapshot retains the order currency.' );

$converted_meta = $legacy_meta;
$converted_meta[ MMGWC_META_ORIGINAL_CURRENCY ] = 'USD';
$converted_meta[ MMGWC_META_ORIGINAL_TOTAL ] = '2.50';
$converted_meta[ MMGWC_META_MMG_AMOUNT_GYD ] = '500';
$legacy_converted_order = new WC_Order( 'USD', 2.5, $converted_meta );
$legacy_converted_snapshot = MMGWC_Payment_Context::legacy_hosted_snapshot( $legacy_converted_order, $legacy_transaction_id );
context_check( ( $legacy_converted_snapshot['expected_amount'] ?? '' ) === '500.00', 'A converted legacy checkout uses its stored GYD amount.' );
context_check( ( $legacy_converted_snapshot['expected_order_total'] ?? '' ) === '2.50' && ( $legacy_converted_snapshot['expected_order_currency'] ?? '' ) === 'USD', 'A converted legacy checkout uses its stored original total and currency.' );
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( new WC_Order( 'USD', 2.75, $converted_meta ), $legacy_transaction_id ) === null, 'A converted legacy checkout with a changed order total fails closed.' );
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( new WC_Order( 'EUR', 2.5, $converted_meta ), $legacy_transaction_id ) === null, 'A converted legacy checkout with a changed order currency fails closed.' );

$invalid_meta = $legacy_meta;
$invalid_meta['_mmgwc_last_checkout_url'] = 'https://mmgpg.mmgtest.net/mmg-pg/web/payments?token=opaque';
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( new WC_Order( 'GYD', 500.0, $invalid_meta ), $legacy_transaction_id ) === null, 'A legacy checkout without a stored merchant ID fails closed.' );

$invalid_meta = $legacy_meta;
$invalid_meta['_mmgwc_last_checkout_url_mode'] = 'live';
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( new WC_Order( 'GYD', 500.0, $invalid_meta ), $legacy_transaction_id ) === null, 'A legacy checkout with conflicting credential modes fails closed.' );
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( $legacy_gyd_order, '42-forged' ) === null, 'A legacy checkout requires an exact merchant transaction ID match.' );
$new_transaction_id = $legacy_transaction_id . '-0123456789abcdef0123456789abcdef';
$new_transaction_meta = $legacy_meta;
$new_transaction_meta[ MMGWC_META_MERCHANT_TXN_ID ] = $new_transaction_id;
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( new WC_Order( 'GYD', 500.0, $new_transaction_meta ), $new_transaction_id ) === null, 'A missing current-version reservation cannot be downgraded to the legacy path.' );
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( new WC_Order( 'USD', 2.5, $legacy_meta ), $legacy_transaction_id ) === null, 'A non-GYD legacy checkout without stored conversion evidence fails closed.' );
context_check( MMGWC_Payment_Context::legacy_hosted_snapshot( new WC_Order( 'GYD', 500.0, $legacy_meta, 'cod' ), $legacy_transaction_id ) === null, 'A legacy snapshot is not reconstructed for another payment method.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} payment context tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} payment context tests passed.\n" );
