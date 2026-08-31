<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MMGWC_META_INITIATED_REFERENCE', '_mmg_initiated_reference' );
define( 'MMGWC_META_INITIATED_STATUS', '_mmg_initiated_status' );
define( 'MMGWC_META_INITIATED_CORRELATION', '_mmg_initiated_correlation' );
define( 'MMGWC_META_INITIATED_EXPIRES_AT', '_mmg_initiated_expires_at' );
define( 'MMGWC_META_INITIATED_LAST_CHECK', '_mmg_initiated_last_check' );
define( 'MMGWC_META_INITIATED_ATTEMPTS', '_mmg_initiated_attempts' );
define( 'MMGWC_META_INITIATED_CUSTOMER_HINT', '_mmg_initiated_customer_hint' );
define( 'MMGWC_META_EXPECTED_AMOUNT', '_mmg_expected_amount' );
define( 'MMGWC_META_EXPECTED_CURRENCY', '_mmg_expected_currency' );
define( 'MMGWC_META_EXPECTED_MERCHANT_ID', '_mmg_expected_merchant_id' );
define( 'MMGWC_META_EXPECTED_ORDER_TOTAL', '_mmg_expected_order_total' );
define( 'MMGWC_META_EXPECTED_ORDER_CURRENCY', '_mmg_expected_order_currency' );
define( 'MMGWC_META_MODE', '_mmg_mode' );

$test_options = array();
$scheduled_events = array();
$checkout_notices = array();

final class WP_Error {
	private $code;
	private $message;

	public function __construct( string $code, string $message, $data = null ) {
		$this->code = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

class WC_Payment_Gateway {
	public $id = '';
	public $method_title = '';
	public $method_description = '';
	public $has_fields = false;
	public $supports = array();
	public $enabled = '';
	public $title = '';
	public $description = '';
	public $icon = '';

	public function get_return_url( WC_Order $order ): string {
		return 'https://example.test/order/' . $order->get_id();
	}
}

final class Initiated_Start_Test_Runtime {
	public static $order;

	public static function reset( WC_Order $order ): void {
		global $test_options, $scheduled_events, $checkout_notices;

		self::$order = $order;
		$test_options = array();
		$scheduled_events = array();
		$checkout_notices = array();
		MMGWC_API::reset();
		MMGWC_Atomic_Option::reset();
		MMGWC_Payment_Context::reset( $order );
		MMGWC_Payment_Verifier::reset();
	}
}

final class WC_Order {
	private $id;
	private $status = 'pending';
	private $paid = false;
	private $payment_method = 'mmg_initiated';
	private $meta = array();
	private $after_first_save;
	private $fail_on_save_call = 0;

	public $save_calls = 0;
	public $status_writes = array();
	public $notes = array();

	public function __construct( int $id ) {
		$this->id = $id;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function needs_payment(): bool {
		return ! $this->paid && in_array( $this->status, array( 'pending', 'failed' ), true );
	}

	public function is_paid(): bool {
		return $this->paid;
	}

	public function set_paid( bool $paid ): void {
		$this->paid = $paid;
	}

	public function get_payment_method(): string {
		return $this->payment_method;
	}

	public function set_payment_method( string $payment_method ): void {
		$this->payment_method = $payment_method;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function set_status( string $status, string $note = '', bool $manual_update = false ): void {
		$this->status = preg_replace( '/^wc-/', '', $status );
		if ( $note !== '' ) {
			$this->notes[] = $note;
		}
	}

	public function update_status( string $status, string $note = '', bool $manual_update = false ): void {
		$this->set_status( $status );
		$this->save();
		if ( $note !== '' ) {
			$this->notes[] = $note;
		}
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
		if ( $key === MMGWC_META_INITIATED_STATUS ) {
			$this->status_writes[] = (string) $value;
		}
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	public function save(): int {
		$this->save_calls++;
		if ( $this->save_calls === $this->fail_on_save_call ) {
			throw new RuntimeException( 'Simulated WooCommerce save failure.' );
		}
		if ( $this->save_calls === 1 && is_callable( $this->after_first_save ) ) {
			call_user_func( $this->after_first_save, $this );
		}
		return $this->id;
	}

	public function add_order_note( string $note ): void {
		$this->notes[] = $note;
	}

	public function get_total(): string {
		return '1250.00';
	}

	public function get_currency(): string {
		return 'GYD';
	}

	public function mutate_after_first_save( callable $mutation ): void {
		$this->after_first_save = $mutation;
	}

	public function fail_on_save( int $save_call ): void {
		$this->fail_on_save_call = $save_call;
	}
}

final class MMGWC_Settings {
	public static function get( string $key, $default = false ) {
		if ( in_array( $key, array( 'initiated_enabled', 'initiated_authorised' ), true ) ) {
			return 'yes';
		}
		return $default;
	}

	public static function get_mode(): string {
		return 'sandbox';
	}

	public static function get_config( string $mode ): array {
		return array(
			'mode' => $mode,
			'mwallet_base_url' => 'https://mmg.example.test',
			'api_key' => 'api-key',
			'wss_mid' => 'merchant-msisdn',
			'wss_mkey' => 'merchant-key',
			'wss_msecret' => 'merchant-secret',
			'password' => 'password',
			'credit_account_id' => 'merchant-account',
		);
	}
}

final class MMGWC_Payment_Verifier {
	public static $lock_acquisitions = 0;
	public static $lock_releases = 0;

	public static function reset(): void {
		self::$lock_acquisitions = 0;
		self::$lock_releases = 0;
	}

	public static function missing_api_fields( array $config ): array {
		return array();
	}

	public static function acquire_order_lock( int $order_id ): bool {
		self::$lock_acquisitions++;
		return true;
	}

	public static function release_order_lock( int $order_id ): void {
		self::$lock_releases++;
	}
}

final class MMGWC_Payment_Context {
	public static $fresh_order_calls = 0;
	public static $prepare_calls = 0;
	public static $prepare_persist_arguments = array();
	private static $order;

	public static function reset( WC_Order $order ): void {
		self::$fresh_order_calls = 0;
		self::$prepare_calls = 0;
		self::$prepare_persist_arguments = array();
		self::$order = $order;
	}

	public static function fresh_order( int $order_id ) {
		self::$fresh_order_calls++;
		return self::$order instanceof WC_Order && self::$order->get_id() === $order_id ? self::$order : false;
	}

	public static function prepare( WC_Order $order, array $config, string $expected_merchant_id, bool $persist = true ): array {
		self::$prepare_calls++;
		self::$prepare_persist_arguments[] = $persist;
		$snapshot = array(
			'mode' => (string) $config['mode'],
			'expected_amount' => '1250.00',
			'expected_currency' => 'GYD',
			'expected_merchant_id' => $expected_merchant_id,
			'expected_order_total' => '1250.00',
			'expected_order_currency' => 'GYD',
		);
		$order->update_meta_data( MMGWC_META_MODE, $snapshot['mode'] );
		$order->update_meta_data( MMGWC_META_EXPECTED_AMOUNT, $snapshot['expected_amount'] );
		$order->update_meta_data( MMGWC_META_EXPECTED_CURRENCY, $snapshot['expected_currency'] );
		$order->update_meta_data( MMGWC_META_EXPECTED_MERCHANT_ID, $snapshot['expected_merchant_id'] );
		$order->update_meta_data( MMGWC_META_EXPECTED_ORDER_TOTAL, $snapshot['expected_order_total'] );
		$order->update_meta_data( MMGWC_META_EXPECTED_ORDER_CURRENCY, $snapshot['expected_order_currency'] );
		if ( $persist ) {
			$order->save();
		}

		return array(
			'amount_decimal' => '1250.00',
			'amount_request' => '1250',
			'snapshot' => $snapshot,
		);
	}
}

final class MMGWC_API {
	public static $initiate_calls = 0;
	public static $save_calls_at_dispatch = array();

	public static function reset(): void {
		self::$initiate_calls = 0;
		self::$save_calls_at_dispatch = array();
	}

	public static function normalise_customer_account( string $customer_account ): string {
		$digits = preg_replace( '/\D+/', '', $customer_account );
		return is_string( $digits ) && preg_match( '/^\d{7}$/', $digits ) === 1 ? $digits : '';
	}

	public static function initiate_payment( array $config, string $customer_account, string $amount, string $correlation_id = '' ): array {
		self::$initiate_calls++;
		self::$save_calls_at_dispatch[] = Initiated_Start_Test_Runtime::$order->save_calls;
		return array(
			'objectReference' => '20373216452995',
			'executionId' => '20373216452995',
			'status' => 'pending',
			'expiryTime' => gmdate( 'c', time() + 600 ),
		);
	}

	public static function initiated_reference( array $response ) {
		$object_reference = trim( (string) ( $response['objectReference'] ?? '' ) );
		$execution_id = trim( (string) ( $response['executionId'] ?? '' ) );
		if ( $object_reference !== '' && $execution_id !== '' && ! hash_equals( $object_reference, $execution_id ) ) {
			return new WP_Error( 'mmgwc_ambiguous_initiated_reference', 'The response references differ.' );
		}
		$reference = $execution_id !== '' ? $execution_id : $object_reference;
		return preg_match( '/^\d{1,64}$/', $reference ) === 1
			? $reference
			: new WP_Error( 'mmgwc_invalid_initiated_reference', 'The response reference is invalid.' );
	}
}

final class MMGWC_Atomic_Option {
	private static $locks = array();
	private static $reservations = array();

	public static function reset(): void {
		self::$locks = array();
		self::$reservations = array();
	}

	public static function acquire_lock( string $key, int $ttl ): string {
		if ( isset( self::$locks[ $key ] ) ) {
			return '';
		}
		self::$locks[ $key ] = 'owned-' . $key;
		return self::$locks[ $key ];
	}

	public static function release_lock( string $key, string $value ): void {
		if ( ( self::$locks[ $key ] ?? '' ) === $value ) {
			unset( self::$locks[ $key ] );
		}
	}

	public static function reserve( string $key, string $value ): bool {
		if ( ! isset( self::$reservations[ $key ] ) ) {
			self::$reservations[ $key ] = $value;
		}
		return self::$reservations[ $key ] === $value;
	}

	public static function reserved_value( string $key ): ?string {
		return self::$reservations[ $key ] ?? null;
	}

	public static function delete_if_owned( string $key, string $value ): void {
		if ( ( self::$reservations[ $key ] ?? '' ) === $value ) {
			unset( self::$reservations[ $key ] );
		}
	}
}

final class MMGWC_Logger {
	public static function warning( string $message, array $context = array() ): void {}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function apply_filters( string $hook, $value ) {
	return $value;
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function wp_generate_uuid4(): string {
	return '12345678-1234-4123-8123-123456789abc';
}

function get_option( string $key, $default = false ) {
	global $test_options;
	return array_key_exists( $key, $test_options ) ? $test_options[ $key ] : $default;
}

function update_option( string $key, $value, $autoload = null ): bool {
	global $test_options;
	$test_options[ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	global $test_options;
	unset( $test_options[ $key ] );
	return true;
}

function wp_next_scheduled( string $hook, array $args = array() ) {
	return false;
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	global $scheduled_events;
	$scheduled_events[] = compact( 'timestamp', 'hook', 'args' );
	return true;
}

function wp_clear_scheduled_hook( string $hook, array $args = array() ): void {}

function wc_get_order( int $order_id ) {
	$order = Initiated_Start_Test_Runtime::$order;
	return $order instanceof WC_Order && $order->get_id() === $order_id ? $order : false;
}

function wc_add_notice( string $message, string $type = 'success' ): void {
	global $checkout_notices;
	$checkout_notices[] = compact( 'message', 'type' );
}

function wc_clean( $value ) {
	return is_string( $value ) ? trim( $value ) : $value;
}

function wp_unslash( $value ) {
	return $value;
}

require dirname( __DIR__ ) . '/includes/class-mmgwc-initiated-payments.php';
require dirname( __DIR__ ) . '/includes/class-wc-gateway-mmgwc-initiated.php';

$tests = 0;
$failures = 0;
$next_order_id = 7000;

function initiated_start_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function invoke_initiated_start( WC_Order $order ): array {
	try {
		return array(
			'result' => MMGWC_Initiated_Payments::start( $order, '698-3238' ),
			'throwable' => null,
		);
	} catch ( Throwable $throwable ) {
		return array(
			'result' => null,
			'throwable' => $throwable,
		);
	}
}

function new_initiated_start_order(): WC_Order {
	global $next_order_id;
	$order = new WC_Order( $next_order_id++ );
	Initiated_Start_Test_Runtime::reset( $order );
	return $order;
}

function assert_pre_dispatch_failure( string $case, WC_Order $order, array $outcome ): void {
	$result = $outcome['result'];
	initiated_start_check( MMGWC_API::$initiate_calls === 0, $case . ' does not dispatch an MMG API request.' );
	initiated_start_check( ! is_array( $result ), $case . ' fails closed instead of reporting a successful initiation.' );
	initiated_start_check(
		! is_wp_error( $result ) || $result->get_error_code() !== 'mmgwc_initiation_uncertain',
		$case . ' is not reported as initiation_uncertain before an API dispatch.'
	);
	initiated_start_check(
		! in_array( 'initiation_uncertain', $order->status_writes, true ),
		$case . ' never persists initiation_uncertain before an API dispatch.'
	);
}

$order = new_initiated_start_order();
$outcome = invoke_initiated_start( $order );
initiated_start_check( $outcome['throwable'] === null, 'A valid initiation does not throw.' );
initiated_start_check( is_array( $outcome['result'] ), 'A valid initiation returns a result.' );
initiated_start_check( MMGWC_Payment_Context::$prepare_calls === 1, 'A valid initiation prepares the payment context once.' );
initiated_start_check( MMGWC_Payment_Context::$prepare_persist_arguments === array( false ), 'Preparation defers persistence so its snapshot and initiation marker share one save.' );
initiated_start_check( MMGWC_API::$initiate_calls === 1, 'A valid durable state dispatches exactly one MMG API request.' );
initiated_start_check( MMGWC_API::$save_calls_at_dispatch === array( 1 ), 'Exactly one preparation save completes before the MMG API dispatch.' );
initiated_start_check( MMGWC_Payment_Context::$fresh_order_calls >= 2, 'The order is reloaded after its preparation save and before dispatch.' );
initiated_start_check( ! in_array( 'initiation_uncertain', $order->status_writes, true ), 'A valid initiation never writes initiation_uncertain.' );

$order = new_initiated_start_order();
$order->fail_on_save( 1 );
$outcome = invoke_initiated_start( $order );
assert_pre_dispatch_failure( 'A failed preparation save', $order, $outcome );

$mutations = array(
	'an order paid after preparation' => static function ( WC_Order $saved_order ): void {
		$saved_order->set_paid( true );
	},
	'a payment method changed after preparation' => static function ( WC_Order $saved_order ): void {
		$saved_order->set_payment_method( 'cod' );
	},
	'an order status changed after preparation' => static function ( WC_Order $saved_order ): void {
		$saved_order->set_status( 'cancelled' );
	},
	'an initiation status changed after preparation' => static function ( WC_Order $saved_order ): void {
		$saved_order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'review' );
	},
	'a correlation changed after preparation' => static function ( WC_Order $saved_order ): void {
		$saved_order->update_meta_data( MMGWC_META_INITIATED_CORRELATION, '87654321-4321-4321-8321-cba987654321' );
	},
	'a payment snapshot changed after preparation' => static function ( WC_Order $saved_order ): void {
		$saved_order->update_meta_data( MMGWC_META_EXPECTED_AMOUNT, '1250.01' );
	},
);

foreach ( $mutations as $case => $mutation ) {
	$order = new_initiated_start_order();
	$order->mutate_after_first_save( $mutation );
	$outcome = invoke_initiated_start( $order );
	assert_pre_dispatch_failure( ucfirst( $case ), $order, $outcome );
	initiated_start_check( MMGWC_Payment_Context::$fresh_order_calls >= 2, ucfirst( $case ) . ' is detected by the post-save reload.' );
}

$gateway = new WC_Gateway_MMGWC_Initiated();

$order = new_initiated_start_order();
$order->set_status( 'on-hold' );
$outcome = $gateway->process_payment( $order->get_id() );
initiated_start_check( $outcome['result'] === 'failure', 'An unpaid non-payable order without initiated evidence fails instead of showing a false waiting screen.' );
initiated_start_check( MMGWC_API::$initiate_calls === 0, 'A non-payable order without initiated evidence never dispatches an MMG request.' );
initiated_start_check( count( $checkout_notices ) === 1 && $checkout_notices[0]['type'] === 'error', 'The missing-request failure gives the customer one clear error notice.' );

$order = new_initiated_start_order();
$order->set_status( 'on-hold' );
$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'pending' );
$order->update_meta_data( MMGWC_META_INITIATED_REFERENCE, '20373216452995' );
$outcome = $gateway->process_payment( $order->get_id() );
initiated_start_check( $outcome['result'] === 'success', 'An on-hold order with a durable MMG request resumes successfully.' );
initiated_start_check( MMGWC_API::$initiate_calls === 0, 'Resuming an on-hold request never dispatches another MMG request.' );
initiated_start_check( MMGWC_Atomic_Option::reserved_value( 'mmgwc_initiated_pending_' . $order->get_id() ) === (string) $order->get_id(), 'Resuming an on-hold request restores its durable reconciliation record.' );
initiated_start_check( count( $scheduled_events ) === 1, 'Resuming an on-hold request schedules reconciliation.' );

$order = new_initiated_start_order();
$order->set_status( 'cancelled' );
$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'pending' );
$order->update_meta_data( MMGWC_META_INITIATED_REFERENCE, '20373216452996' );
$outcome = $gateway->process_payment( $order->get_id() );
initiated_start_check( $outcome['result'] === 'success', 'A closed order with an existing MMG request reaches the closed-request guidance.' );
initiated_start_check( MMGWC_API::$initiate_calls === 0, 'A closed order never dispatches another MMG request.' );
initiated_start_check( count( $scheduled_events ) === 1, 'A closed order restores reconciliation for a possible late MMG settlement.' );

$order = new_initiated_start_order();
$order->set_status( 'on-hold' );
$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'initiation_uncertain' );
$outcome = $gateway->process_payment( $order->get_id() );
initiated_start_check( $outcome['result'] === 'success', 'An uncertain existing request reaches its manual-review guidance.' );
initiated_start_check( MMGWC_API::$initiate_calls === 0, 'An uncertain existing request is never sent again.' );
initiated_start_check( count( $scheduled_events ) === 0, 'An uncertain request without a lookup reference is not scheduled blindly.' );

$order = new_initiated_start_order();
$order->set_status( 'processing' );
$order->set_paid( true );
$outcome = $gateway->process_payment( $order->get_id() );
initiated_start_check( $outcome['result'] === 'success', 'A paid order remains idempotently successful.' );
initiated_start_check( MMGWC_API::$initiate_calls === 0 && count( $scheduled_events ) === 0, 'A paid order neither dispatches nor schedules an MMG request.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} initiated start tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} initiated start tests passed.\n" );
