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

$GLOBALS['mmgwc_api_responses'] = array();
$GLOBALS['mmgwc_api_requests'] = array();
$GLOBALS['mmgwc_api_transients'] = array();

final class MMGWC_Logger {
	public static function warning( string $message, array $context = array() ): void {}
}

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url ) { return parse_url( $url ); }
function apply_filters( string $hook, $value, ...$args ) { return $value; }
function wp_generate_uuid4(): string { return '12345678-1234-4123-8123-123456789abc'; }
function wp_json_encode( $value ): string { return (string) json_encode( $value ); }
function add_query_arg( array $args, string $url ): string { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args ); }
function get_transient( string $key ) { return $GLOBALS['mmgwc_api_transients'][ $key ] ?? false; }
function set_transient( string $key, $value, int $ttl ): bool { $GLOBALS['mmgwc_api_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['mmgwc_api_transients'][ $key ] ); return true; }
function wp_safe_remote_post( string $url, array $args ) {
	$GLOBALS['mmgwc_api_requests'][] = array( 'method' => 'POST', 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['mmgwc_api_responses'] );
}
function wp_safe_remote_get( string $url, array $args ) {
	$GLOBALS['mmgwc_api_requests'][] = array( 'method' => 'GET', 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['mmgwc_api_responses'] );
}
function wp_remote_retrieve_response_code( $response ): int { return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ): string { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }

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

function api_response( int $code, array $body = array() ): array {
	return array( 'code' => $code, 'body' => (string) json_encode( $body ) );
}

function api_reset_remote( array $responses ): void {
	$GLOBALS['mmgwc_api_responses'] = $responses;
	$GLOBALS['mmgwc_api_requests'] = array();
	$GLOBALS['mmgwc_api_transients'] = array();
}

function initiated_api_config(): array {
	return array(
		'mode' => 'sandbox',
		'mwallet_base_url' => 'https://mwallet.mmgtest.net/olive/publisher/v1',
		'api_key' => 'test-api-key',
		'wss_mid' => '6991234',
		'wss_mkey' => 'test-merchant-key',
		'wss_msecret' => 'test-merchant-secret',
		'password' => 'test-password',
		'credit_account_id' => '6991234',
		'wss_token' => 'stale-token',
	);
}

function invoke_initiated_api( array $config = array() ) {
	return MMGWC_API::initiate_payment(
		empty( $config ) ? initiated_api_config() : $config,
		'6983238',
		'500.00',
		'12345678-1234-4123-8123-123456789abc'
	);
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

$login_config = initiated_api_config();
unset( $login_config['wss_token'] );
api_reset_remote(
	array(
		api_response( 200, array( 'statusCode' => '315', 'message' => 'USER_ACCOUNT_LOCKED' ) ),
	)
);
$locked = invoke_initiated_api( $login_config );
api_check( is_wp_error( $locked ) && $locked->get_error_code() === 'mmgwc_initiated_account_locked', 'A provider account lock is reported before any payment request is sent.' );
api_check( count( $GLOBALS['mmgwc_api_requests'] ) === 1, 'A locked account performs only the token request.' );

api_reset_remote(
	array(
		api_response( 422, array( 'statusCode' => '102', 'message' => 'INVALID_CREDENTIALS' ) ),
	)
);
$invalid_login = invoke_initiated_api( $login_config );
api_check( is_wp_error( $invalid_login ) && $invalid_login->get_error_code() === 'mmgwc_initiated_invalid_credentials', 'Invalid API credentials are distinguished from a locked account.' );
api_check( count( $GLOBALS['mmgwc_api_requests'] ) === 1, 'Rejected credentials do not dispatch a payment request.' );

api_reset_remote( array( api_response( 404 ) ) );
$missing_login_route = invoke_initiated_api( $login_config );
api_check( is_wp_error( $missing_login_route ) && $missing_login_route->get_error_code() === 'mmgwc_initiated_authentication_failed', 'An unexplained login 404 is not misreported as invalid credentials.' );

$pending = array(
	'status' => 'pending',
	'objectReference' => '20373216452995',
	'executionId' => '20373216452995',
);
api_reset_remote(
	array(
		api_response( 401 ),
		api_response( 200, array( 'access_token' => 'fresh-token', 'expires_in' => 120 ) ),
		api_response( 200, $pending ),
	)
);
$refreshed = invoke_initiated_api();
api_check( is_array( $refreshed ) && $refreshed['status'] === 'pending', 'A stale token is refreshed after 401 and the initiated request is retried once.' );
api_check( count( $GLOBALS['mmgwc_api_requests'] ) === 3, 'The stale-token flow performs one rejected payment request, one login and one final payment request.' );
api_check( $GLOBALS['mmgwc_api_requests'][0]['args']['headers']['x-wss-token'] === 'stale-token', 'The first request uses the supplied stale token.' );
api_check( $GLOBALS['mmgwc_api_requests'][2]['args']['headers']['x-wss-token'] === 'fresh-token', 'The second request uses the refreshed token.' );

api_reset_remote(
	array(
		api_response( 401 ),
		api_response( 200, array( 'access_token' => 'fresh-token', 'expires_in' => 120 ) ),
		api_response( 401 ),
	)
);
$rejected = invoke_initiated_api();
api_check( is_wp_error( $rejected ) && $rejected->get_error_code() === 'mmgwc_initiated_rejected', 'A final 401 is a definite rejection that can restore a retryable order.' );

api_reset_remote( array( new WP_Error( 'http_request_failed', 'Connection ended.' ) ) );
$transport = invoke_initiated_api();
api_check( is_wp_error( $transport ) && $transport->get_error_code() === 'mmgwc_initiated_transport_uncertain', 'A transport failure after dispatch remains uncertain.' );

api_reset_remote( array( api_response( 500 ) ) );
$server_error = invoke_initiated_api();
api_check( is_wp_error( $server_error ) && $server_error->get_error_code() === 'mmgwc_initiated_server_uncertain', 'A server error after dispatch remains uncertain.' );

api_reset_remote( array( api_response( 409 ) ) );
$conflict = invoke_initiated_api();
api_check( is_wp_error( $conflict ) && $conflict->get_error_code() === 'mmgwc_initiated_server_uncertain', 'An undocumented 409 remains uncertain because it may represent a duplicate request.' );

api_reset_remote( array( api_response( 429 ) ) );
$rate_limited = invoke_initiated_api();
api_check( is_wp_error( $rate_limited ) && $rate_limited->get_error_code() === 'mmgwc_initiated_server_uncertain', 'A rate-limited non-idempotent request remains uncertain because MMG may have accepted it before returning 429.' );

api_reset_remote( array( array( 'code' => 200, 'body' => '{not-json' ) ) );
$invalid_success = invoke_initiated_api();
api_check( is_wp_error( $invalid_success ) && $invalid_success->get_error_code() === 'mmgwc_initiated_response_uncertain', 'An invalid 2xx response remains uncertain after dispatch.' );

api_reset_remote( array( api_response( 400 ) ) );
$bad_request = invoke_initiated_api();
api_check( is_wp_error( $bad_request ) && $bad_request->get_error_code() === 'mmgwc_initiated_rejected', 'A documented validation-style 400 is treated as not accepted.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} MMG API contract tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} MMG API contract tests passed.\n" );
