<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MMGWC_SETTINGS_OPTION_KEY', 'woocommerce_mmg_checkout_settings' );

class WP_Error {}
class WooCommerce {}
final class MMGWC_Settings { public static function get( string $key, $default = '' ) { return $default; } }
final class MMGWC_Logger { public static function error( string $message ): void {} }
final class MMGWC_Atomic_Option {
	private static $locks = array();
	public static function acquire_lock( string $key, int $ttl ): string {
		if ( isset( self::$locks[ $key ] ) ) {
			return '';
		}
		self::$locks[ $key ] = 'owned';
		return 'owned';
	}
	public static function release_lock( string $key, string $owner ): void {
		if ( ( self::$locks[ $key ] ?? '' ) === $owner ) {
			unset( self::$locks[ $key ] );
		}
	}
}

class WC_Order_Item_Fee {
	public function set_name( string $value ): void {}
	public function set_amount( float $value ): void {}
	public function set_total( float $value ): void {}
}

class QR_Test_Order {
	private $id;
	public $paid = false;
	private $status = 'pending';
	public function __construct( int $id ) { $this->id = $id; }
	public function get_id(): int { return $this->id; }
	public function is_paid(): bool { return $this->paid; }
	public function add_item( $item ): void {}
	public function calculate_totals(): void {}
	public function update_meta_data( string $key, $value ): void {}
	public function set_payment_method( string $value ): void {}
	public function set_payment_method_title( string $value ): void {}
	public function get_status(): string { return $this->status; }
	public function set_status( string $status ): void { $this->status = $status; }
	public function save(): void {}
	public function delete( bool $force ): void {}
}

$GLOBALS['qr_test_transients'] = array();
$GLOBALS['qr_test_orders'] = array();

function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function get_current_user_id(): int { return 0; }
function is_admin(): bool { return false; }
function user_can( int $user_id, string $capability ): bool { return false; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function get_option( string $key, $default = false ) { return $default; }
function get_transient( string $key ) { return $GLOBALS['qr_test_transients'][ $key ] ?? false; }
function set_transient( string $key, $value, int $ttl ): bool { $GLOBALS['qr_test_transients'][ $key ] = $value; return true; }
function wc_create_order( array $args = array() ) {
	$id = count( $GLOBALS['qr_test_orders'] ) + 1;
	$order = new QR_Test_Order( $id );
	$GLOBALS['qr_test_orders'][ $id ] = $order;
	return $order;
}
function wc_get_order( int $id ) { return $GLOBALS['qr_test_orders'][ $id ] ?? false; }

require dirname( __DIR__ ) . '/includes/class-mmgwc-qr-payments.php';

$tests = 0;
$failures = 0;
function qr_order_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$reusable_token = str_repeat( 'a', 64 );
$reusable = array(
	'type' => 'amount',
	'label' => 'Reusable payment',
	'amount' => '1000',
	'one_time' => 'no',
	'order_id' => 0,
);
$GLOBALS['qr_test_transients'][ 'mmgwc_qr_tpl_' . $reusable_token ] = $reusable;

$first = MMGWC_QR_Payments::maybe_create_checkout_order_for_token( $reusable_token, $reusable, 600 );
qr_order_check( $first instanceof QR_Test_Order, 'A reusable QR creates its first payable order.' );
qr_order_check( count( $GLOBALS['qr_test_orders'] ) === 1, 'Only one order exists after the first request.' );
$second = MMGWC_QR_Payments::maybe_create_checkout_order_for_token( $reusable_token, $reusable, 600 );
qr_order_check( $second === $first, 'Repeated requests reuse the active unpaid order.' );
qr_order_check( count( $GLOBALS['qr_test_orders'] ) === 1, 'Repeated requests cannot flood the order store.' );

$first->paid = true;
$third = MMGWC_QR_Payments::maybe_create_checkout_order_for_token( $reusable_token, $reusable, 600 );
qr_order_check( $third instanceof QR_Test_Order && $third !== $first, 'A reusable QR creates its next order only after the previous order is paid.' );
qr_order_check( count( $GLOBALS['qr_test_orders'] ) === 2, 'Exactly one replacement order is created after payment.' );

$one_time_token = str_repeat( 'b', 64 );
$one_time = array(
	'type' => 'amount',
	'label' => 'One-time payment',
	'amount' => '500',
	'one_time' => 'yes',
	'order_id' => 0,
);
$GLOBALS['qr_test_transients'][ 'mmgwc_qr_tpl_' . $one_time_token ] = $one_time;
$one_time_order = MMGWC_QR_Payments::maybe_create_checkout_order_for_token( $one_time_token, $one_time, 600 );
$one_time_order->paid = true;
$one_time_again = MMGWC_QR_Payments::maybe_create_checkout_order_for_token( $one_time_token, $one_time, 600 );
qr_order_check( $one_time_again === $one_time_order, 'A paid one-time QR cannot create a second order.' );
qr_order_check( count( $GLOBALS['qr_test_orders'] ) === 3, 'The one-time QR remains bound to exactly one order.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} QR order-idempotency tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} QR order-idempotency tests passed.\n" );
