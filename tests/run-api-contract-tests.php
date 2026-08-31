<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	private $code;
	private $message;

	public function __construct( string $code, string $message ) {
		$this->code = $code;
		$this->message = $message;
	}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

final class MMGWC_Logger {
	public static function warning( string $message, array $context = array() ): void {}
}

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url ) { return parse_url( $url ); }
function apply_filters( string $hook, $value, ...$args ) { return $value; }

require dirname( __DIR__ ) . '/includes/class-mmgwc-api.php';

$tests = 0;
$failures = 0;

function api_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

api_check( MMGWC_API::normalise_customer_account( '698-3238' ) === '6983238', 'A formatted local MMG phone number is normalised.' );
api_check( MMGWC_API::normalise_customer_account( '+592 698 3238' ) === '6983238', 'A 592-prefixed MMG phone number is normalised.' );
api_check( MMGWC_API::normalise_customer_account( '123456' ) === '', 'A short account number is rejected.' );
api_check( MMGWC_API::normalise_customer_account( '59269832380' ) === '', 'An overlong account number is rejected.' );
api_check( MMGWC_API::normalise_customer_account( '6983238<script>' ) === '', 'Non-phone characters are rejected.' );

$reference = MMGWC_API::initiated_reference(
	array(
		'objectReference' => '20373216452995',
		'executionId' => '20373216452995',
	)
);
api_check( $reference === '20373216452995', 'Matching MMG initiation references are accepted.' );

$reference = MMGWC_API::initiated_reference(
	array(
		'objectReference' => '20373216452995',
		'executionId' => '20373216452996',
	)
);
api_check( is_wp_error( $reference ) && $reference->get_error_code() === 'mmgwc_ambiguous_initiated_reference', 'Different MMG initiation references fail closed.' );

$reference = MMGWC_API::initiated_reference( array( 'executionId' => 'not-numeric' ) );
api_check( is_wp_error( $reference ) && $reference->get_error_code() === 'mmgwc_invalid_initiated_reference', 'A non-numeric MMG reference is rejected.' );

api_check(
	MMGWC_API::is_valid_base_url( 'https://mwallet.mmgtest.net/olive/publisher/v1', array( 'mode' => 'sandbox' ) ),
	'The published MMG Sandbox API base is allowed.'
);
api_check(
	MMGWC_API::is_valid_base_url( 'https://payments.mymmg.gy/publisher/v1', array( 'mode' => 'live' ) ),
	'An MMG-controlled Live subdomain is allowed.'
);
api_check(
	! MMGWC_API::is_valid_base_url( 'https://merchant.example.test/collect', array( 'mode' => 'live' ) ),
	'An arbitrary HTTPS host cannot receive merchant API credentials.'
);
api_check(
	! MMGWC_API::is_valid_base_url( 'https://user:pass@mwallet.mmgtest.net/olive/publisher/v1', array( 'mode' => 'sandbox' ) ),
	'Embedded URL credentials are rejected.'
);
api_check(
	! MMGWC_API::is_valid_base_url( 'https://mwallet.mmgtest.net:8443/olive/publisher/v1', array( 'mode' => 'sandbox' ) ),
	'A non-standard API port is rejected.'
);

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} MMG API contract tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} MMG API contract tests passed.\n" );
