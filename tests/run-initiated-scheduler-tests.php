<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MMGWC_META_INITIATED_STATUS', '_mmg_initiated_status' );

$scheduled_action = null;
$pending_actions = array();
$wp_unscheduled_hook = '';
$as_unscheduled = array();
$wp_pending = false;
$wp_scheduled = array();
$test_options = array();

final class WC_Order {
	private $status;
	private $paid;

	public function __construct( string $status, bool $paid = false ) {
		$this->status = $status;
		$this->paid = $paid;
	}

	public function get_status(): string { return $this->status; }
	public function is_paid(): bool { return $this->paid; }
	public function get_meta( string $key ) { return $key === MMGWC_META_INITIATED_STATUS ? 'pending' : ''; }
}

final class MMGWC_Atomic_Option {
	private static $locks = array();
	private static $reservations = array();
	private static $force_busy = false;

	public static function force_busy( bool $busy ): void {
		self::$force_busy = $busy;
	}

	public static function acquire_lock( string $key, int $ttl ): string {
		if ( self::$force_busy || isset( self::$locks[ $key ] ) ) {
			return '';
		}
		self::$locks[ $key ] = 'owned';
		return 'owned';
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

	public static function keys_with_prefix( string $prefix, int $limit = 20, int $offset = 0 ): ?array {
		$keys = array_values( array_filter( array_keys( self::$reservations ), static function( string $key ) use ( $prefix ): bool {
			return strpos( $key, $prefix ) === 0;
		} ) );
		return array_slice( $keys, $offset, $limit );
	}
}

function as_get_scheduled_actions( array $args, string $format ): array {
	global $pending_actions;
	return $pending_actions;
}

function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ) {
	global $scheduled_action, $pending_actions;
	$scheduled_action = compact( 'timestamp', 'hook', 'args', 'group' );
	$pending_actions = array( 101 );
	return 101;
}

function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	global $as_unscheduled;
	$as_unscheduled[] = compact( 'hook', 'args', 'group' );
}

function wp_next_scheduled( string $hook, array $args = array() ) {
	global $wp_pending;
	return $wp_pending ? time() + 20 : false;
}
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	global $wp_scheduled;
	$wp_scheduled[] = compact( 'timestamp', 'hook', 'args' );
	return true;
}
function wp_clear_scheduled_hook( string $hook, array $args = array() ): void {}
function wp_unschedule_hook( string $hook ): void {
	global $wp_unscheduled_hook;
	$wp_unscheduled_hook = $hook;
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

require dirname( __DIR__ ) . '/includes/class-mmgwc-initiated-payments.php';

$method = new ReflectionMethod( MMGWC_Initiated_Payments::class, 'schedule_check' );
$method->setAccessible( true );
$pending_state_method = new ReflectionMethod( MMGWC_Initiated_Payments::class, 'pending_state' );
$pending_state_method->setAccessible( true );
$tests = 0;
$failures = 0;

function scheduler_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$pending_actions = array();
$scheduled_action = null;
$method->invoke( null, 42, 20, true );
scheduler_check( is_array( $scheduled_action ), 'An in-progress Action Scheduler callback queues its successor when no pending copy exists.' );
scheduler_check( $scheduled_action['hook'] === 'mmgwc_check_initiated_payment' && $scheduled_action['args'] === array( 42 ), 'The successor keeps the exact hook and order ID.' );

$pending_actions = array( 101 );
$scheduled_action = null;
$method->invoke( null, 42, 20, true );
scheduler_check( $scheduled_action === null, 'An in-progress callback does not duplicate an existing pending successor.' );

$pending_actions = array( 102 );
$scheduled_action = null;
$method->invoke( null, 42, 20, false );
scheduler_check( $scheduled_action === null, 'A browser status request does not duplicate a future scheduled check.' );

$pending_actions = array();
$scheduled_action = null;
$method->invoke( null, 42, 20, false );
scheduler_check( is_array( $scheduled_action ), 'A missing background check is scheduled.' );

$pending_actions = array();
$wp_scheduled = array();
MMGWC_Atomic_Option::force_busy( true );
$method->invoke( null, 43, 20, true );
MMGWC_Atomic_Option::force_busy( false );
scheduler_check( count( $wp_scheduled ) === 1 && $wp_scheduled[0]['args'] === array( 43 ), 'A busy scheduling lock creates a WP-Cron safety successor.' );

$wp_pending = true;
$pending_actions = array();
$scheduled_action = null;
$method->invoke( null, 42, 20, false );
scheduler_check( $scheduled_action === null, 'An existing WP-Cron fallback prevents a duplicate Action Scheduler job.' );
$wp_pending = false;

$closed_state = $pending_state_method->invoke( null, new WC_Order( 'refunded' ) );
scheduler_check( $closed_state['state'] === 'review' && strpos( $closed_state['message'], 'Do not approve' ) !== false, 'A closed order stops telling the customer to approve a pending MMG request.' );

MMGWC_Initiated_Payments::deactivate();
scheduler_check( $wp_unscheduled_hook === 'mmgwc_check_initiated_payment', 'Deactivation removes every WP-Cron event for the initiated hook.' );
$last_unscheduled = end( $as_unscheduled );
scheduler_check( is_array( $last_unscheduled ) && $last_unscheduled['hook'] === 'mmgwc_check_initiated_payment' && $last_unscheduled['args'] === array() && $last_unscheduled['group'] === '', 'Deactivation cancels every pending Action Scheduler job for the initiated hook.' );

$scheduled_action = null;
$wp_scheduled = array();
$method->invoke( null, 44, 20, true );
scheduler_check( $scheduled_action === null && $wp_scheduled === array(), 'A running request cannot schedule more reconciliation after deactivation.' );

MMGWC_Initiated_Payments::activate();
$pending_actions = array();
$method->invoke( null, 44, 20, false );
scheduler_check( is_array( $scheduled_action ), 'Activation allows reconciliation scheduling again.' );

for ( $order_id = 1000; $order_id < 1125; $order_id++ ) {
	MMGWC_Atomic_Option::reserve( 'mmgwc_initiated_pending_' . $order_id, (string) $order_id );
}
MMGWC_Initiated_Payments::recover_pending_orders();
scheduler_check( (int) get_option( 'mmgwc_initiated_pending_recovery_cursor', 0 ) === 100, 'Recovery advances beyond the first hundred durable initiated records.' );
MMGWC_Initiated_Payments::recover_pending_orders();
scheduler_check( (int) get_option( 'mmgwc_initiated_pending_recovery_cursor', -1 ) === 0, 'Recovery wraps after reaching the final durable initiated record page.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} initiated scheduler tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} initiated scheduler tests passed.\n" );
