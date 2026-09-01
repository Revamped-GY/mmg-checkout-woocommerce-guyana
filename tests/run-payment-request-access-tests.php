<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MMGWC_META_PAYMENT_REQUEST', '_mmgwc_payment_request' );
define( 'MMGWC_META_PR_EXPIRES_AT', '_mmgwc_payment_request_expires_at' );

function absint( $value ): int { return abs( (int) $value ); }

class WC_Order {
	private $payment_request;
	private $payment_method;
	private $status;
	private $paid;
	private $expires_at;

	public function __construct( string $payment_request, string $payment_method, string $status, bool $paid, int $expires_at = 0 ) {
		$this->payment_request = $payment_request;
		$this->payment_method = $payment_method;
		$this->status = $status;
		$this->paid = $paid;
		$this->expires_at = $expires_at;
	}

	public function get_meta( string $key ) {
		return $key === MMGWC_META_PAYMENT_REQUEST ? $this->payment_request : $this->expires_at;
	}
	public function get_payment_method(): string { return $this->payment_method; }
	public function get_status(): string { return $this->status; }
	public function is_paid(): bool { return $this->paid; }
}

require dirname( __DIR__ ) . '/includes/admin/class-mmgwc-payment-requests.php';

$tests = 0;
$failures = 0;
function payment_request_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$check = new ReflectionMethod( MMGWC_Payment_Requests::class, 'is_payment_request_order' );
$check->setAccessible( true );

$valid = new WC_Order( 'yes', 'mmg_checkout', 'pending', false, time() + 300 );
payment_request_check( $check->invoke( null, $valid, true ) === true, 'A current payable MMG payment request is accepted.' );
payment_request_check( $check->invoke( null, new WC_Order( 'no', 'mmg_checkout', 'pending', false ), false ) === false, 'An unrelated WooCommerce order cannot expose its pay link.' );
payment_request_check( $check->invoke( null, new WC_Order( 'yes', 'cod', 'pending', false ), false ) === false, 'A payment request using another gateway is rejected.' );
payment_request_check( $check->invoke( null, new WC_Order( 'yes', 'mmg_checkout', 'completed', true ), true ) === false, 'A paid order cannot receive another payment-request email.' );
payment_request_check( $check->invoke( null, new WC_Order( 'yes', 'mmg_checkout', 'cancelled', false ), true ) === false, 'A non-payable order status is rejected.' );
payment_request_check( $check->invoke( null, new WC_Order( 'yes', 'mmg_checkout', 'pending', false, time() - 1 ), true ) === false, 'An expired payment request cannot be emailed.' );
payment_request_check( $check->invoke( null, new stdClass(), false ) === false, 'A non-order object is rejected.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} payment-request access tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} payment-request access tests passed.\n" );
