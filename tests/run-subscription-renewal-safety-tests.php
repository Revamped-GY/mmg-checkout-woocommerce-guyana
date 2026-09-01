<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MMGWC_META_SUBSCRIPTION_RENEWAL', '_mmg_subscription_renewal' );
define( 'MMGWC_META_SUBSCRIPTION_ID', '_mmg_subscription_id' );
define( 'MMGWC_META_SUBSCRIPTION_RENEWAL_EXPIRES_AT', '_mmg_subscription_renewal_expires_at' );

function absint( $value ): int {
	return abs( (int) $value );
}

final class Renewal_Test_DB {
	public $prefix = 'wp_';
	public function prepare( string $query, ...$args ): string {
		return $query;
	}
	public function get_row( string $query, string $format ) {
		return $GLOBALS['renewal_test_subscription'] ?? null;
	}
}

final class Renewal_Test_Order {
	private $id;
	private $meta;

	public function __construct( int $id, array $meta ) {
		$this->id = $id;
		$this->meta = $meta;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}
}

$GLOBALS['wpdb'] = new Renewal_Test_DB();

require dirname( __DIR__ ) . '/includes/class-mmgwc-subscriptions.php';

$tests = 0;
$failures = 0;
function renewal_safety_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$owns = new ReflectionMethod( MMGWC_Subscriptions::class, 'customer_owns_subscription' );
$owns->setAccessible( true );
renewal_safety_check( $owns->invoke( null, array( 'user_id' => 27 ), 27 ) === true, 'The owning signed-in customer can renew the subscription.' );
renewal_safety_check( $owns->invoke( null, array( 'user_id' => 27 ), 28 ) === false, 'A different customer cannot renew the subscription.' );
renewal_safety_check( $owns->invoke( null, array( 'user_id' => 0 ), 27 ) === false, 'A guest subscription cannot be modified through the signed-in customer action.' );
renewal_safety_check( $owns->invoke( null, array( 'user_id' => 27 ), 0 ) === false, 'An unauthenticated request cannot renew a subscription.' );

$active = new ReflectionMethod( MMGWC_Subscriptions::class, 'is_active_renewal_order' );
$active->setAccessible( true );
$GLOBALS['renewal_test_subscription'] = array( 'id' => 31, 'renewal_order_id' => 404 );
$valid_meta = array(
	MMGWC_META_SUBSCRIPTION_RENEWAL => 'yes',
	MMGWC_META_SUBSCRIPTION_ID => 31,
	MMGWC_META_SUBSCRIPTION_RENEWAL_EXPIRES_AT => time() + 300,
);
renewal_safety_check( $active->invoke( null, new Renewal_Test_Order( 404, $valid_meta ) ) === true, 'The current unexpired renewal order remains payable.' );
renewal_safety_check( $active->invoke( null, new Renewal_Test_Order( 405, $valid_meta ) ) === false, 'A replaced renewal order is no longer payable.' );
$expired_meta = $valid_meta;
$expired_meta[ MMGWC_META_SUBSCRIPTION_RENEWAL_EXPIRES_AT ] = time() - 1;
renewal_safety_check( $active->invoke( null, new Renewal_Test_Order( 404, $expired_meta ) ) === false, 'An expired renewal order is no longer payable.' );
$unrelated_meta = $valid_meta;
$unrelated_meta[ MMGWC_META_SUBSCRIPTION_RENEWAL ] = 'no';
renewal_safety_check( $active->invoke( null, new Renewal_Test_Order( 404, $unrelated_meta ) ) === false, 'An unrelated order cannot pass the renewal guard.' );

$subscriptions_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mmgwc-subscriptions.php' );
$account_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mmgwc-myaccount.php' );
$renewal_creation_source = '';
if ( is_string( $subscriptions_source ) ) {
	$renewal_creation_start = strpos( $subscriptions_source, 'private static function get_or_create_renewal_order' );
	$renewal_creation_end = strpos( $subscriptions_source, 'private static function reusable_renewal_order_id', $renewal_creation_start === false ? 0 : $renewal_creation_start );
	if ( $renewal_creation_start !== false && $renewal_creation_end !== false ) {
		$renewal_creation_source = substr( $subscriptions_source, $renewal_creation_start, $renewal_creation_end - $renewal_creation_start );
	}
}
renewal_safety_check( is_string( $subscriptions_source ) && strpos( $subscriptions_source, 'MMGWC_Atomic_Option::acquire_lock' ) !== false, 'Renewal creation uses an atomic owned lock.' );
renewal_safety_check( is_string( $subscriptions_source ) && strpos( $subscriptions_source, 'MMGWC_Atomic_Option::release_lock' ) !== false, 'Renewal creation releases only its owned lock.' );
renewal_safety_check( $renewal_creation_source !== '' && strpos( $renewal_creation_source, 'get_transient( $lock_key )' ) === false && strpos( $renewal_creation_source, 'set_transient( $lock_key' ) === false, 'Renewal creation no longer uses a race-prone transient lock.' );
renewal_safety_check( is_string( $subscriptions_source ) && strpos( $subscriptions_source, "update_status( 'cancelled', 'Renewal link replaced" ) !== false, 'A replaced unpaid renewal order is cancelled.' );
renewal_safety_check( is_string( $account_source ) && strpos( $account_source, '$render_action_form( \'renew\'' ) !== false, 'My Account renders renewal as a POST action.' );
renewal_safety_check( is_string( $account_source ) && strpos( $account_source, 'customer_get_renewal_payment_url' ) !== false, 'The renewal action enforces customer ownership before creating an order.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} subscription renewal safety tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} subscription renewal safety tests passed.\n" );
