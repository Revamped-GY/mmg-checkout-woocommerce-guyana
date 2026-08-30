<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MMGWC_META_MERCHANT_TXN_ID', '_mmg_merchant_transaction_id' );
define( 'MMGWC_META_TXN_ID', '_mmg_transaction_id' );
define( 'MMGWC_META_PROCESSED_TXN_ID', '_mmg_processed_transaction_id' );
define( 'MMGWC_META_MODE', '_mmg_mode' );
define( 'MMGWC_META_EXPECTED_AMOUNT', '_mmg_expected_amount_gyd' );
define( 'MMGWC_META_EXPECTED_CURRENCY', '_mmg_expected_currency' );
define( 'MMGWC_META_EXPECTED_MERCHANT_ID', '_mmg_expected_merchant_id' );
define( 'MMGWC_META_EXPECTED_ORDER_TOTAL', '_mmg_expected_order_total' );
define( 'MMGWC_META_EXPECTED_ORDER_CURRENCY', '_mmg_expected_order_currency' );

final class WC_Order {
	private $id;
	private $payment_method;
	private $total;
	private $currency;
	private $needs_payment;
	private $meta;

	public function __construct( int $id, array $meta, bool $needs_payment = true ) {
		$this->id = $id;
		$this->payment_method = 'mmg_checkout';
		$this->total = '500.00';
		$this->currency = 'GYD';
		$this->needs_payment = $needs_payment;
		$this->meta = $meta;
	}

	public function get_id(): int { return $this->id; }
	public function get_payment_method(): string { return $this->payment_method; }
	public function get_total(): string { return $this->total; }
	public function get_currency(): string { return $this->currency; }
	public function needs_payment(): bool { return $this->needs_payment; }
	public function get_meta( string $key ) { return $this->meta[ $key ] ?? ''; }

	public function set_payment_method( string $payment_method ): void { $this->payment_method = $payment_method; }
	public function set_total( string $total ): void { $this->total = $total; }
	public function set_currency( string $currency ): void { $this->currency = $currency; }
	public function set_meta( string $key, string $value ): void { $this->meta[ $key ] = $value; }
}

$orders_by_meta = array();
$test_options = array();

function wp_parse_url( string $url ) {
	return parse_url( $url );
}

function wc_get_orders( array $args ): array {
	global $orders_by_meta;
	$key = (string) ( $args['meta_key'] ?? '' ) . '|' . (string) ( $args['meta_value'] ?? '' );
	return $orders_by_meta[ $key ] ?? array();
}

function add_option( string $key, $value, $deprecated = '', $autoload = null ): bool {
	global $test_options;
	if ( array_key_exists( $key, $test_options ) ) {
		return false;
	}
	$test_options[ $key ] = $value;
	return true;
}

function get_option( string $key, $default = false ) {
	global $test_options;
	return $test_options[ $key ] ?? $default;
}

function delete_option( string $key ): bool {
	global $test_options;
	if ( ! array_key_exists( $key, $test_options ) ) {
		return false;
	}
	unset( $test_options[ $key ] );
	return true;
}

require dirname( __DIR__ ) . '/includes/class-mmgwc-payment-verifier.php';

$failures = 0;
$tests = 0;

function check( bool $condition, string $message ): void {
	global $failures, $tests;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function check_code( array $result, string $expected, string $message ): void {
	check( isset( $result['code'] ) && $result['code'] === $expected, $message . ' (got ' . (string) ( $result['code'] ?? 'missing' ) . ')' );
}

function base_meta(): array {
	return array(
		MMGWC_META_MERCHANT_TXN_ID => '42-1788120000-0123456789abcdef0123456789abcdef',
		MMGWC_META_MODE => 'live',
		MMGWC_META_EXPECTED_AMOUNT => '500.00',
		MMGWC_META_EXPECTED_CURRENCY => 'GYD',
		MMGWC_META_EXPECTED_MERCHANT_ID => '9991161',
		MMGWC_META_EXPECTED_ORDER_TOTAL => '500.00',
		MMGWC_META_EXPECTED_ORDER_CURRENCY => 'GYD',
	);
}

function base_config(): array {
	return array(
		'mode' => 'live',
		'mwallet_base_url' => 'https://mwallet.example.test/mwallet/v1',
		'api_key' => 'api-key',
		'wss_mid' => '9991161',
		'wss_mkey' => 'merchant-key',
		'wss_msecret' => 'merchant-secret',
		'password' => 'password',
		'merchant_id' => '9991161',
	);
}

function base_response(): array {
	return array(
		'merchantTransactionId' => '42-1788120000-0123456789abcdef0123456789abcdef',
		'transactionId' => '20373216965979',
		'resultCode' => '0',
		'resultMessage' => 'Transaction Successful',
		'htmlResponse' => '<script>must not be stored</script>',
		'_mmgwc_decrypted_mode' => 'live',
	);
}

function base_lookup(): array {
	return array(
		'amount' => '500',
		'currency' => 'GYD',
		'transactionStatus' => 'successful',
		'transactionReference' => '20373216965979',
		'transactionReceipt' => '20373216965979',
		'executionId' => '20373216965979',
		'creditParty' => array( array( 'key' => 'accountid', 'value' => '9991161' ) ),
		'debitParty' => array( array( 'key' => 'accountid', 'value' => '6983238' ) ),
		'metadata' => array( array( 'key' => 'merchant', 'value' => '32254' ) ),
	);
}

$order = new WC_Order( 42, base_meta() );
$valid = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), base_lookup(), base_config() );
check( $valid['valid'] === true, 'A fully matching authenticated transaction is accepted.' );
check( $valid['idempotent'] === false, 'A new payment is not labelled idempotent.' );

$response = base_response();
$response['merchantTransactionId'] = '42-1788120000-forged';
$result = MMGWC_Payment_Verifier::verify_callback( $order, $response, base_lookup(), base_config() );
check_code( $result, 'merchant_transaction_mismatch', 'A forged merchant transaction ID is rejected.' );

$lookup = base_lookup();
$lookup['amount'] = '499.99';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, base_config() );
check_code( $result, 'amount_mismatch', 'An amount mismatch is rejected.' );

$lookup = base_lookup();
$lookup['currency'] = 'USD';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, base_config() );
check_code( $result, 'currency_mismatch', 'A currency mismatch is rejected.' );

$lookup = base_lookup();
$lookup['creditParty'][0]['value'] = '1112222';
$lookup['metadata'][0]['value'] = '9991161';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, base_config() );
check_code( $result, 'merchant_mismatch', 'A different credited merchant is rejected even if optional metadata names the expected merchant.' );

$lookup = base_lookup();
$lookup['transactionReference'] = '20373216965970';
$lookup['transactionReceipt'] = '20373216965970';
$lookup['executionId'] = '20373216965970';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, base_config() );
check_code( $result, 'lookup_transaction_mismatch', 'A different provider transaction ID is rejected.' );

$lookup = base_lookup();
$lookup['transactionStatus'] = 'pending';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, base_config() );
check_code( $result, 'lookup_not_paid', 'A pending lookup is rejected.' );

$response = base_response();
$response['_mmgwc_decrypted_mode'] = 'sandbox';
$result = MMGWC_Payment_Verifier::verify_callback( $order, $response, base_lookup(), base_config() );
check_code( $result, 'mode_mismatch', 'A callback decrypted with the wrong credential mode is rejected.' );

$changed_order = new WC_Order( 42, base_meta() );
$changed_order->set_total( '600.00' );
$result = MMGWC_Payment_Verifier::verify_callback( $changed_order, base_response(), base_lookup(), base_config() );
check_code( $result, 'order_changed', 'An order total changed after initiation is rejected.' );

$legacy_meta = base_meta();
unset( $legacy_meta[ MMGWC_META_EXPECTED_AMOUNT ] );
$legacy_order = new WC_Order( 42, $legacy_meta );
$result = MMGWC_Payment_Verifier::verify_callback( $legacy_order, base_response(), base_lookup(), base_config() );
check_code( $result, 'amount_mismatch', 'An order without an immutable amount snapshot fails closed.' );

$orders_by_meta[ MMGWC_META_PROCESSED_TXN_ID . '|20373216965979' ] = array( 99 );
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), base_lookup(), base_config() );
check_code( $result, 'transaction_reused', 'A provider transaction ID used by another order is rejected.' );
$orders_by_meta = array();

$idempotent_meta = base_meta();
$idempotent_meta[ MMGWC_META_PROCESSED_TXN_ID ] = '20373216965979';
$idempotent_meta[ MMGWC_META_TXN_ID ] = '20373216965979';
$paid_order = new WC_Order( 42, $idempotent_meta, false );
$result = MMGWC_Payment_Verifier::verify_callback( $paid_order, base_response(), base_lookup(), base_config() );
check( $result['valid'] === true && $result['idempotent'] === true, 'A repeated callback for the same verified order is idempotent.' );

$callback_record = MMGWC_Payment_Verifier::callback_record( base_response() );
check( ! array_key_exists( 'htmlResponse', $callback_record ), 'Provider HTML is excluded from retained callback metadata.' );
$lookup_record = MMGWC_Payment_Verifier::lookup_record( base_lookup() );
check( ! array_key_exists( 'debitParty', $lookup_record ) && ! array_key_exists( 'metadata', $lookup_record ), 'Customer wallet and provider metadata are excluded from retained lookup metadata.' );

$missing = base_config();
$missing['api_key'] = '';
check( in_array( 'api_key', MMGWC_Payment_Verifier::missing_api_fields( $missing ), true ), 'Missing lookup credentials keep the gateway fail closed.' );

check( MMGWC_Payment_Verifier::acquire_order_lock( 42 ) === true, 'The first verifier acquires the order lock.' );
check( MMGWC_Payment_Verifier::acquire_order_lock( 42 ) === false, 'A concurrent verifier cannot acquire the same order lock.' );
MMGWC_Payment_Verifier::release_order_lock( 42 );
check( MMGWC_Payment_Verifier::acquire_order_lock( 42 ) === true, 'The verifier lock can be acquired after release.' );
MMGWC_Payment_Verifier::release_order_lock( 42 );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} payment verifier tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} payment verifier tests passed.\n" );
