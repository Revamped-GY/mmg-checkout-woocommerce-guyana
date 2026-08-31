<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MMGWC_META_MERCHANT_TXN_ID', '_mmg_merchant_transaction_id' );
define( 'MMGWC_META_MODE', '_mmg_mode' );
define( 'MMGWC_META_ORIGINAL_CURRENCY', '_mmg_original_currency' );
define( 'MMGWC_META_ORIGINAL_TOTAL', '_mmg_original_total' );
define( 'MMGWC_META_MMG_AMOUNT_GYD', '_mmg_amount_gyd' );

class WC_Payment_Gateway {}

final class WC_Order {
	private $id;
	private $currency;
	private $total;
	private $meta;
	private $payment_method;

	public function __construct( int $id, string $currency, float $total, array $meta, string $payment_method = 'mmg_checkout' ) {
		$this->id = $id;
		$this->currency = $currency;
		$this->total = $total;
		$this->meta = $meta;
		$this->payment_method = $payment_method;
	}

	public function get_id(): int { return $this->id; }
	public function get_currency(): string { return $this->currency; }
	public function get_total(): float { return $this->total; }
	public function get_meta( string $key ) { return $this->meta[ $key ] ?? ''; }
	public function get_payment_method(): string { return $this->payment_method; }
}

final class MMGWC_Atomic_Option {
	public static $values = array();

	public static function reserve( string $key, string $value ): bool {
		if ( array_key_exists( $key, self::$values ) ) {
			return false;
		}
		self::$values[ $key ] = $value;
		return true;
	}

	public static function reserved_value( string $key ) {
		return self::$values[ $key ] ?? null;
	}
}

final class MMGWC_Settings {
	public static function get_config( string $mode ): array {
		return array(
			'mode' => $mode,
			'checkout_url' => $mode === 'live'
				? 'https://mmgpg.mymmg.gy/mmg-pg/web/payments'
				: 'https://mmgpg.mmgtest.net/mmg-pg/web/payments',
			'merchant_id' => $mode === 'live' ? '8880001' : '9991037',
		);
	}
}

$legacy_orders = array();
$legacy_order_queries = 0;

function wc_get_orders( array $args ): array {
	global $legacy_orders, $legacy_order_queries;
	$legacy_order_queries++;
	$matches = array();
	foreach ( $legacy_orders as $order ) {
		if (
			$order instanceof WC_Order
			&& $order->get_payment_method() === ( $args['payment_method'] ?? '' )
			&& (string) $order->get_meta( (string) ( $args['meta_key'] ?? '' ) ) === (string) ( $args['meta_value'] ?? '' )
		) {
			$matches[] = $order;
		}
	}
	return array_slice( $matches, 0, (int) ( $args['limit'] ?? count( $matches ) ) );
}

function wc_get_order( int $order_id ) {
	global $legacy_orders;
	return $legacy_orders[ $order_id ] ?? false;
}

function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( string $url ) { return parse_url( $url ); }
function wp_parse_str( string $input, array &$result ): void { parse_str( $input, $result ); }
function wc_format_decimal( $value, int $decimals = 2 ): string { return number_format( (float) $value, $decimals, '.', '' ); }

require dirname( __DIR__ ) . '/includes/class-mmgwc-payment-context.php';
require dirname( __DIR__ ) . '/includes/class-wc-gateway-mmgwc.php';

$tests = 0;
$failures = 0;

function legacy_session_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function legacy_checkout_meta( int $order_id, int $initiated_at, string $mode = 'sandbox' ): array {
	return array(
		MMGWC_META_MERCHANT_TXN_ID => (string) $order_id . '-' . (string) $initiated_at,
		MMGWC_META_MODE => $mode,
		'_mmg_initiated_at' => $initiated_at,
		'_mmgwc_last_checkout_url' => 'https://mmgpg.mmgtest.net/mmg-pg/web/payments?token=opaque&merchantId=9991037&X-Client-ID=client',
		'_mmgwc_last_checkout_url_at' => $initiated_at + 1,
		'_mmgwc_last_checkout_url_mode' => $mode,
	);
}

$gateway_reflection = new ReflectionClass( 'WC_Gateway_MMGWC' );
$gateway = $gateway_reflection->newInstanceWithoutConstructor();

$gyd_meta = legacy_checkout_meta( 42, 1700000000 );
$gyd_order = new WC_Order( 42, 'GYD', 500.0, $gyd_meta );
$legacy_orders = array( 42 => $gyd_order );
MMGWC_Atomic_Option::$values = array();
$legacy_order_queries = 0;
$gyd_response = array(
	'merchantTransactionId' => $gyd_meta[ MMGWC_META_MERCHANT_TXN_ID ],
	'_mmgwc_decrypted_mode' => 'sandbox',
);
$resolved = $gateway->resolve_order_from_mmg_response( $gyd_response );
legacy_session_check( $resolved === $gyd_order, 'A pre-2.16.0 GYD checkout resolves through its exact stored transaction ID.' );
legacy_session_check( count( MMGWC_Atomic_Option::$values ) === 1, 'A valid legacy checkout creates one immutable reservation.' );
$reserved = json_decode( (string) reset( MMGWC_Atomic_Option::$values ), true );
legacy_session_check( ( $reserved['expected_amount'] ?? '' ) === '500.00' && ( $reserved['expected_merchant_id'] ?? '' ) === '9991037', 'The reserved GYD snapshot contains the exact amount and merchant destination.' );

$legacy_orders = array();
$resolved_again = $gateway->resolve_order_from_mmg_response( $gyd_response );
legacy_session_check( $resolved_again === null, 'A reservation cannot resolve an order that WooCommerce can no longer load.' );
legacy_session_check( $legacy_order_queries === 1, 'A repeated callback uses the immutable reservation instead of another metadata search.' );

$converted_meta = legacy_checkout_meta( 43, 1700000100 );
$converted_meta[ MMGWC_META_ORIGINAL_CURRENCY ] = 'USD';
$converted_meta[ MMGWC_META_ORIGINAL_TOTAL ] = '2.50';
$converted_meta[ MMGWC_META_MMG_AMOUNT_GYD ] = '500';
$converted_order = new WC_Order( 43, 'USD', 2.5, $converted_meta );
$legacy_orders = array( 43 => $converted_order );
MMGWC_Atomic_Option::$values = array();
$resolved = $gateway->resolve_order_from_mmg_response(
	array(
		'merchantTransactionId' => $converted_meta[ MMGWC_META_MERCHANT_TXN_ID ],
		'_mmgwc_decrypted_mode' => 'sandbox',
	)
);
$reserved = json_decode( (string) reset( MMGWC_Atomic_Option::$values ), true );
legacy_session_check( $resolved === $converted_order, 'A pre-2.16.0 converted checkout resolves without fetching a new exchange rate.' );
legacy_session_check( ( $reserved['expected_amount'] ?? '' ) === '500.00', 'The converted reservation uses the stored GYD amount.' );
legacy_session_check( ( $reserved['expected_order_total'] ?? '' ) === '2.50' && ( $reserved['expected_order_currency'] ?? '' ) === 'USD', 'The converted reservation retains the original order total and currency.' );

$current_id = '44-1700000200-0123456789abcdef0123456789abcdef';
$current_meta = legacy_checkout_meta( 44, 1700000200 );
$current_meta[ MMGWC_META_MERCHANT_TXN_ID ] = $current_id;
$legacy_orders = array( 44 => new WC_Order( 44, 'GYD', 500.0, $current_meta ) );
MMGWC_Atomic_Option::$values = array();
$resolved = $gateway->resolve_order_from_mmg_response(
	array(
		'merchantTransactionId' => $current_id,
		'_mmgwc_decrypted_mode' => 'sandbox',
	)
);
legacy_session_check( $resolved === null && MMGWC_Atomic_Option::$values === array(), 'A missing current-version reservation fails closed instead of using the legacy path.' );

$mode_mismatch_meta = legacy_checkout_meta( 45, 1700000300 );
$legacy_orders = array( 45 => new WC_Order( 45, 'GYD', 500.0, $mode_mismatch_meta ) );
MMGWC_Atomic_Option::$values = array();
$resolved = $gateway->resolve_order_from_mmg_response(
	array(
		'merchantTransactionId' => $mode_mismatch_meta[ MMGWC_META_MERCHANT_TXN_ID ],
		'_mmgwc_decrypted_mode' => 'live',
	)
);
legacy_session_check( $resolved === null && MMGWC_Atomic_Option::$values === array(), 'A legacy callback decrypted with another credential mode fails before reservation.' );

$merchant_mismatch_meta = legacy_checkout_meta( 46, 1700000400 );
$merchant_mismatch_meta['_mmgwc_last_checkout_url'] = 'https://mmgpg.mmgtest.net/mmg-pg/web/payments?token=opaque&merchantId=9999999&X-Client-ID=client';
$legacy_orders = array( 46 => new WC_Order( 46, 'GYD', 500.0, $merchant_mismatch_meta ) );
MMGWC_Atomic_Option::$values = array();
$resolved = $gateway->resolve_order_from_mmg_response(
	array(
		'merchantTransactionId' => $merchant_mismatch_meta[ MMGWC_META_MERCHANT_TXN_ID ],
		'_mmgwc_decrypted_mode' => 'sandbox',
	)
);
legacy_session_check( $resolved === null && MMGWC_Atomic_Option::$values === array(), 'A legacy URL for another merchant fails before reservation.' );

$reserve_method = $gateway_reflection->getMethod( 'reserve_hosted_session_context' );
$reserve_method->setAccessible( true );
MMGWC_Atomic_Option::$values = array();
$invalid_writer_rejected = false;
try {
	$reserve_method->invoke(
		$gateway,
		array(
			'version' => 1,
			'order_id' => 47,
			'merchant_transaction_id' => '47-1700000500',
			'mode' => 'sandbox',
		)
	);
} catch ( Throwable $exception ) {
	$invalid_writer_rejected = true;
}
legacy_session_check( $invalid_writer_rejected && MMGWC_Atomic_Option::$values === array(), 'The reservation writer rejects an incomplete snapshot before persistence.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} hosted legacy session tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} hosted legacy session tests passed.\n" );
