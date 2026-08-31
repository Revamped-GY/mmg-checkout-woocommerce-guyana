<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MMGWC_META_MERCHANT_TXN_ID', '_mmg_merchant_transaction_id' );
define( 'MMGWC_META_TXN_ID', '_mmg_transaction_id' );
define( 'MMGWC_META_PROCESSED_TXN_ID', '_mmg_processed_transaction_id' );
define( 'MMGWC_META_REVIEW_TXN_ID', '_mmg_review_transaction_id' );
define( 'MMGWC_META_MODE', '_mmg_mode' );
define( 'MMGWC_META_EXPECTED_AMOUNT', '_mmg_expected_amount_gyd' );
define( 'MMGWC_META_EXPECTED_CURRENCY', '_mmg_expected_currency' );
define( 'MMGWC_META_EXPECTED_MERCHANT_ID', '_mmg_expected_merchant_id' );
define( 'MMGWC_META_EXPECTED_ORDER_TOTAL', '_mmg_expected_order_total' );
define( 'MMGWC_META_EXPECTED_ORDER_CURRENCY', '_mmg_expected_order_currency' );

final class MMGWC_API {
	public static function is_valid_base_url( string $base, array $config = array() ): bool {
		$parts = parse_url( $base );
		return is_array( $parts ) && ( $parts['scheme'] ?? '' ) === 'https' && ! empty( $parts['host'] );
	}
}

final class WC_Order {
	private $id;
	private $payment_method;
	private $total;
	private $currency;
	private $needs_payment;
	private $paid;
	private $status;
	private $meta;

	public function __construct( int $id, array $meta, bool $needs_payment = true, bool $paid = false ) {
		$this->id = $id;
		$this->payment_method = 'mmg_checkout';
		$this->total = '500.00';
		$this->currency = 'GYD';
		$this->needs_payment = $needs_payment;
		$this->paid = $paid;
		$this->status = $paid ? 'processing' : ( $needs_payment ? 'pending' : 'cancelled' );
		$this->meta = $meta;
	}

	public function get_id(): int { return $this->id; }
	public function get_payment_method(): string { return $this->payment_method; }
	public function get_total(): string { return $this->total; }
	public function get_currency(): string { return $this->currency; }
	public function needs_payment(): bool { return $this->needs_payment; }
	public function is_paid(): bool { return $this->paid; }
	public function get_status(): string { return $this->status; }
	public function get_meta( string $key ) { return $this->meta[ $key ] ?? ''; }

	public function set_payment_method( string $payment_method ): void { $this->payment_method = $payment_method; }
	public function set_total( string $total ): void { $this->total = $total; }
	public function set_currency( string $currency ): void { $this->currency = $currency; }
	public function set_status( string $status ): void { $this->status = $status; }
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

final class MMGWC_Atomic_Option {
	private static $values = array();
	private static $counter = 0;

	public static function reserve( string $key, string $owner ): bool {
		if ( ! array_key_exists( $key, self::$values ) ) {
			self::$values[ $key ] = $owner;
			return true;
		}
		return self::$values[ $key ] === $owner;
	}

	public static function acquire_lock( string $key, int $ttl ): string {
		if ( array_key_exists( $key, self::$values ) ) {
			return '';
		}
		self::$counter++;
		$value = 'lock-' . self::$counter;
		self::$values[ $key ] = $value;
		return $value;
	}

	public static function release_lock( string $key, string $owned_value ): void {
		if ( ( self::$values[ $key ] ?? '' ) === $owned_value ) {
			unset( self::$values[ $key ] );
		}
	}

	public static function renew_lock( string $key, string $owned_value ): string {
		if ( ( self::$values[ $key ] ?? '' ) !== $owned_value ) {
			return '';
		}
		self::$counter++;
		$new_value = 'lock-' . self::$counter;
		self::$values[ $key ] = $new_value;
		return $new_value;
	}
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

function initiated_meta(): array {
	$meta = base_meta();
	$meta[ MMGWC_META_EXPECTED_MERCHANT_ID ] = '8882252';
	return $meta;
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

$newer_meta = base_meta();
$newer_meta[ MMGWC_META_MERCHANT_TXN_ID ] = '42-1788120099-ffffffffffffffffffffffffffffffff';
$newer_meta[ MMGWC_META_MODE ] = 'sandbox';
$newer_order = new WC_Order( 42, $newer_meta );
$earlier_session = array(
	'merchant_transaction_id' => base_meta()[ MMGWC_META_MERCHANT_TXN_ID ],
	'mode' => 'live',
	'expected_amount' => '500.00',
	'expected_currency' => 'GYD',
	'expected_merchant_id' => '9991161',
	'expected_order_total' => '500.00',
	'expected_order_currency' => 'GYD',
);
$valid = MMGWC_Payment_Verifier::verify_callback( $newer_order, base_response(), base_lookup(), base_config(), $earlier_session );
check( $valid['valid'] === true, 'An earlier hosted session remains verifiable after a newer session becomes current.' );

$sandbox_meta = base_meta();
$sandbox_meta[ MMGWC_META_MODE ] = 'sandbox';
$sandbox_order = new WC_Order( 43, $sandbox_meta );
$sandbox_config = base_config();
$sandbox_config['mode'] = 'sandbox';
$sandbox_config['mwallet_base_url'] = 'https://sandbox-mwallet.example.test/mwallet/v1';
$sandbox_response = base_response();
$sandbox_response['_mmgwc_decrypted_mode'] = 'sandbox';
$sandbox_result = MMGWC_Payment_Verifier::verify_callback( $sandbox_order, $sandbox_response, base_lookup(), $sandbox_config );
check( $sandbox_result['valid'] === true, 'The same provider number can be claimed independently in a different verified MMG environment.' );

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
$lookup['creditParty'][0]['value'] = '8882252';
$config = base_config();
$config['credit_account_id'] = '8882252';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, $config );
check_code( $result, 'merchant_mismatch', 'Hosted checkout requires the exact snapshotted merchant even when another configured MMG account received the funds.' );

$lookup = base_lookup();
$lookup['transactionReference'] = '20373216965970';
$lookup['transactionReceipt'] = '20373216965970';
$lookup['executionId'] = '20373216965970';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, base_config() );
check_code( $result, 'lookup_transaction_mismatch', 'A different provider transaction ID is rejected.' );

$lookup = base_lookup();
$lookup['executionId'] = '20373216965971';
$result = MMGWC_Payment_Verifier::verify_callback( $order, base_response(), $lookup, base_config() );
check_code( $result, 'lookup_transaction_ambiguous', 'Conflicting identifiers in one authenticated lookup are rejected.' );

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
$paid_order = new WC_Order( 42, $idempotent_meta, false, true );
$result = MMGWC_Payment_Verifier::verify_callback( $paid_order, base_response(), base_lookup(), base_config() );
check( $result['valid'] === true && $result['idempotent'] === true, 'A repeated callback for the same verified order is idempotent.' );

$initiated_lookup = base_lookup();
$initiated_lookup['transactionReference'] = '20373216965980';
$initiated_lookup['transactionReceipt'] = '20373216965980';
$initiated_lookup['executionId'] = '20373216965980';
$initiated_lookup['creditParty'][0]['value'] = '8882252';
$initiated_config = base_config();
$initiated_config['credit_account_id'] = '8882252';
$initiated_order = new WC_Order( 50, initiated_meta() );
$initiated_order->set_payment_method( 'mmg_initiated' );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $initiated_order, '20373216965980', $initiated_lookup, $initiated_config );
check( $result['valid'] === true, 'A matching initiated payment can atomically claim its transaction.' );

$second_initiated_order = new WC_Order( 51, initiated_meta() );
$second_initiated_order->set_payment_method( 'mmg_initiated' );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $second_initiated_order, '20373216965980', $initiated_lookup, $initiated_config );
check_code( $result, 'transaction_reused', 'A concurrent order cannot claim a transaction already atomically reserved by another order.' );

$cross_rail_lookup = base_lookup();
$cross_rail_lookup['creditParty'][] = array( 'key' => 'accountid', 'value' => '8882252' );
$cross_rail_order = new WC_Order( 56, initiated_meta() );
$cross_rail_order->set_payment_method( 'mmg_initiated' );
$cross_rail_config = base_config();
$cross_rail_config['credit_account_id'] = '8882252';
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $cross_rail_order, '20373216965979', $cross_rail_lookup, $cross_rail_config );
check_code( $result, 'transaction_reused', 'One provider settlement cannot pay both hosted and initiated orders within the same API tenant.' );

$cancelled_initiated_lookup = base_lookup();
$cancelled_initiated_lookup['transactionReference'] = '20373216965981';
$cancelled_initiated_lookup['transactionReceipt'] = '20373216965981';
$cancelled_initiated_lookup['executionId'] = '20373216965981';
$cancelled_initiated_lookup['creditParty'][0]['value'] = '8882252';
$cancelled_initiated_order = new WC_Order( 52, initiated_meta(), false, false );
$cancelled_initiated_order->set_payment_method( 'mmg_initiated' );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $cancelled_initiated_order, '20373216965981', $cancelled_initiated_lookup, $initiated_config );
check( $result['valid'] === true, 'A settled initiated payment can be reconciled after WooCommerce made the order locally non-payable.' );

$refunded_initiated_lookup = base_lookup();
$refunded_initiated_lookup['transactionReference'] = '20373216965982';
$refunded_initiated_lookup['transactionReceipt'] = '20373216965982';
$refunded_initiated_lookup['executionId'] = '20373216965982';
$refunded_initiated_lookup['creditParty'][0]['value'] = '8882252';
$refunded_initiated_order = new WC_Order( 54, initiated_meta(), false, false );
$refunded_initiated_order->set_payment_method( 'mmg_initiated' );
$refunded_initiated_order->set_status( 'refunded' );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $refunded_initiated_order, '20373216965982', $refunded_initiated_lookup, $initiated_config );
check( empty( $result['valid'] ) && ! empty( $result['settlement_verified'] ) && $result['code'] === 'order_already_paid', 'A later MMG settlement on a refunded order is claimed for review without reopening the order.' );

$additional_payment_lookup = base_lookup();
$additional_payment_lookup['transactionReference'] = '20373216965983';
$additional_payment_lookup['transactionReceipt'] = '20373216965983';
$additional_payment_lookup['executionId'] = '20373216965983';
$additional_payment_lookup['creditParty'][0]['value'] = '8882252';
$already_paid_order = new WC_Order( 55, initiated_meta(), false, true );
$already_paid_order->set_payment_method( 'mmg_initiated' );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $already_paid_order, '20373216965983', $additional_payment_lookup, $initiated_config );
check( empty( $result['valid'] ) && ! empty( $result['settlement_verified'] ) && $result['code'] === 'order_already_paid', 'An additional settled transaction on an already paid order is claimed for manual duplicate-payment review.' );

$nonpayable_hosted_lookup = base_lookup();
$nonpayable_hosted_lookup['transactionReference'] = '20373216965984';
$nonpayable_hosted_lookup['transactionReceipt'] = '20373216965984';
$nonpayable_hosted_lookup['executionId'] = '20373216965984';
$nonpayable_hosted_order = new WC_Order( 53, base_meta(), false, false );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $nonpayable_hosted_order, '20373216965984', $nonpayable_hosted_lookup, base_config() );
check( empty( $result['valid'] ) && ! empty( $result['settlement_verified'] ) && $result['code'] === 'order_already_paid', 'A late hosted settlement on a cancelled order is claimed for review without reopening the order.' );

$initiated_on_hold_lookup = base_lookup();
$initiated_on_hold_lookup['transactionReference'] = '20373216965985';
$initiated_on_hold_lookup['transactionReceipt'] = '20373216965985';
$initiated_on_hold_lookup['executionId'] = '20373216965985';
$initiated_on_hold_lookup['creditParty'][0]['value'] = '8882252';
$initiated_on_hold_meta = initiated_meta();
$initiated_on_hold_meta[ MMGWC_META_PROCESSED_TXN_ID ] = '20373216965985';
$initiated_on_hold_order = new WC_Order( 57, $initiated_on_hold_meta, false, false );
$initiated_on_hold_order->set_payment_method( 'mmg_initiated' );
$initiated_on_hold_order->set_status( 'on-hold' );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $initiated_on_hold_order, '20373216965985', $initiated_on_hold_lookup, $initiated_config );
check( ! empty( $result['valid'] ) && ! empty( $result['idempotent'] ), 'A verified initiated transaction can resume WooCommerce completion while the plugin-held order is on hold.' );

$changed_method_lookup = base_lookup();
$changed_method_lookup['transactionReference'] = '20373216965986';
$changed_method_lookup['transactionReceipt'] = '20373216965986';
$changed_method_lookup['executionId'] = '20373216965986';
$changed_method_response = base_response();
$changed_method_response['transactionId'] = '20373216965986';
$changed_method_order = new WC_Order( 58, base_meta(), false, false );
$changed_method_order->set_payment_method( 'bacs' );
$changed_method_order->set_status( 'on-hold' );
$changed_method_snapshot = $earlier_session;
$changed_method_snapshot['payment_method'] = 'mmg_checkout';
$result = MMGWC_Payment_Verifier::verify_callback( $changed_method_order, $changed_method_response, $changed_method_lookup, base_config(), $changed_method_snapshot );
check( empty( $result['valid'] ) && ! empty( $result['settlement_verified'] ), 'A hosted settlement after a change to an on-hold gateway is claimed for review without changing the current order state.' );
$result = MMGWC_Payment_Verifier::verify_callback( $changed_method_order, $changed_method_response, $changed_method_lookup, base_config() );
check_code( $result, 'wrong_payment_method', 'A changed-method hosted order requires its immutable MMG checkout snapshot.' );

$cancelled_replay_lookup = base_lookup();
$cancelled_replay_lookup['transactionReference'] = '20373216965987';
$cancelled_replay_lookup['transactionReceipt'] = '20373216965987';
$cancelled_replay_lookup['executionId'] = '20373216965987';
$cancelled_replay_meta = base_meta();
$cancelled_replay_meta[ MMGWC_META_PROCESSED_TXN_ID ] = '20373216965987';
$cancelled_replay_order = new WC_Order( 59, $cancelled_replay_meta, false, false );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $cancelled_replay_order, '20373216965987', $cancelled_replay_lookup, base_config() );
check( empty( $result['valid'] ) && ! empty( $result['settlement_verified'] ), 'A replayed settlement cannot reopen a cancelled order even when its transaction ID was processed earlier.' );

$changed_initiated_lookup = base_lookup();
$changed_initiated_lookup['transactionReference'] = '20373216965988';
$changed_initiated_lookup['transactionReceipt'] = '20373216965988';
$changed_initiated_lookup['executionId'] = '20373216965988';
$changed_initiated_lookup['creditParty'][0]['value'] = '8882252';
$changed_initiated_order = new WC_Order( 60, initiated_meta() );
$changed_initiated_order->set_payment_method( 'stripe' );
$result = MMGWC_Payment_Verifier::verify_lookup_for_order( $changed_initiated_order, '20373216965988', $changed_initiated_lookup, $initiated_config, array( 'payment_method' => 'mmg_initiated' ) );
check( ! empty( $result['valid'] ), 'An initiated settlement remains authenticatable after a live payment-method change so the caller can preserve the order for review.' );

$callback_record = MMGWC_Payment_Verifier::callback_record( base_response() );
check( ! array_key_exists( 'htmlResponse', $callback_record ), 'Provider HTML is excluded from retained callback metadata.' );
$status_code_record = MMGWC_Payment_Verifier::callback_record( array( 'merchantTransactionId' => '42-test', 'transactionStatusCode' => '0' ) );
check( ( $status_code_record['resultCode'] ?? '' ) === '0', 'A queued callback preserves every result-code field accepted by the live handler.' );
$alias_response = base_response();
unset( $alias_response['resultCode'] );
$alias_response['transactionStatusCode'] = '0';
$alias_result = MMGWC_Payment_Verifier::verify_callback( $order, $alias_response, base_lookup(), base_config() );
check( $alias_result['valid'] === true, 'A direct callback accepts the same success-code aliases retained by the durable queue.' );
$lookup_record = MMGWC_Payment_Verifier::lookup_record( base_lookup() );
check( ! array_key_exists( 'debitParty', $lookup_record ) && ! array_key_exists( 'metadata', $lookup_record ), 'Customer wallet and provider metadata are excluded from retained lookup metadata.' );

$missing = base_config();
$missing['api_key'] = '';
check( in_array( 'api_key', MMGWC_Payment_Verifier::missing_api_fields( $missing ), true ), 'Missing lookup credentials keep the gateway fail closed.' );

check( MMGWC_Payment_Verifier::acquire_order_lock( 42 ) === true, 'The first verifier acquires the order lock.' );
check( MMGWC_Payment_Verifier::acquire_order_lock( 42 ) === false, 'A concurrent verifier cannot acquire the same order lock.' );
check( MMGWC_Payment_Verifier::renew_order_lock( 42 ) === true, 'The verifier renews the lock only while it owns the current value.' );
MMGWC_Payment_Verifier::release_order_lock( 42 );
check( MMGWC_Payment_Verifier::acquire_order_lock( 42 ) === true, 'The verifier lock can be acquired after release.' );
MMGWC_Payment_Verifier::release_order_lock( 42 );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} payment verifier tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} payment verifier tests passed.\n" );
