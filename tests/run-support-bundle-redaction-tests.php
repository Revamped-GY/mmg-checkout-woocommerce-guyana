<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/admin/class-mmgwc-support-bundle.php';

$tests = 0;
$failures = 0;

function support_bundle_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$redact = new ReflectionMethod( MMGWC_Support_Bundle::class, 'redact_text' );
$redact->setAccessible( true );

$private_key = "-----BEGIN PRIVATE KEY-----\nprivate-material-123\n-----END PRIVATE KEY-----";
$input = implode(
	"\n",
	array(
		'https://example.test/?token=query-token-123&access_token=query-access-123&safe=1',
		'https://example.test/wc-api/mmg-checkout/path-token-123',
		'Authorization: Bearer bearer-token-123',
		'x-api-key: header-api-key-123',
		'x-wss-mkey=merchant-key-123',
		'x-wss-msecret: merchant-secret-123',
		'api_password=plain-password-123',
		'"refresh_token": "quoted refresh token 123"',
		'"private_key": "inline-private-key-123"',
		'x-wss-mid: 6991234',
		$private_key,
	)
);

$output = $redact->invoke( null, $input );
support_bundle_check( is_string( $output ), 'Redaction returns text.' );
foreach (
	array(
		'query-token-123',
		'query-access-123',
		'path-token-123',
		'bearer-token-123',
		'header-api-key-123',
		'merchant-key-123',
		'merchant-secret-123',
		'plain-password-123',
		'quoted refresh token 123',
		'inline-private-key-123',
		'private-material-123',
	) as $secret
) {
	support_bundle_check( strpos( $output, $secret ) === false, 'A support bundle cannot contain the tested secret value.' );
}
support_bundle_check( strpos( $output, 'x-wss-mid: 6991234' ) !== false, 'A non-secret merchant identifier remains available for diagnostics.' );
support_bundle_check( substr_count( $output, '[redacted]' ) >= 9, 'Credential values are replaced with an explicit redaction marker.' );
support_bundle_check( strpos( $output, '[redacted-pem]' ) !== false, 'Multiline PEM key material is removed.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} support-bundle redaction tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} support-bundle redaction tests passed.\n" );
