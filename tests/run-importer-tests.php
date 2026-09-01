<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

final class MMGWC_API {
	public static function is_valid_base_url( string $base, array $config = array() ): bool {
		$host = strtolower( (string) parse_url( $base, PHP_URL_HOST ) );
		$mode = (string) ( $config['mode'] ?? 'sandbox' );
		$allowed = $mode === 'live' ? array( 'mmg.gy', 'mymmg.gy' ) : array( 'mmgtest.net' );
		foreach ( $allowed as $suffix ) {
			if ( $host === $suffix || substr( $host, -( strlen( $suffix ) + 1 ) ) === '.' . $suffix ) {
				return true;
			}
		}
		return false;
	}
}

function sanitize_text_field( $value ): string {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ?? '' );
}

function wp_unslash( $value ) {
	return $value;
}

require dirname( __DIR__ ) . '/includes/admin/class-mmgwc-importer.php';

$tests = 0;
$failures = 0;

function importer_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

function importer_call( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( MMGWC_Importer::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
}

function importer_expect_exception( callable $callback, string $message ): void {
	$thrown = false;
	try {
		$callback();
	} catch ( Exception $exception ) {
		$thrown = true;
	}
	importer_check( $thrown, $message );
}

function importer_environment( string $base_url, bool $include_password = true ): string {
	$values = array(
		array( 'key' => 'x-api-key', 'value' => 'test-api-key', 'enabled' => true ),
		array( 'key' => 'x-wss-mid', 'value' => '6991234', 'enabled' => true ),
		array( 'key' => 'PASSWORD', 'value' => $include_password ? 'test-password' : '', 'enabled' => true ),
		array( 'key' => 'x-wss-msecret', 'value' => 'test-merchant-secret', 'enabled' => true ),
		array( 'key' => 'x-wss-mkey', 'value' => 'test-merchant-key', 'enabled' => true ),
		array( 'key' => 'BASE_URL_MWALLET', 'value' => $base_url, 'enabled' => true ),
		array( 'key' => 'x-wss-token', 'value' => 'runtime-token-must-not-import', 'enabled' => true ),
		array( 'key' => 'TRANSACTION_ID', 'value' => '12345', 'enabled' => true ),
		array( 'key' => 'x-api-key', 'value' => 'disabled-value', 'enabled' => false ),
		array( 'key' => 'UNKNOWN_FIELD', 'value' => 'ignored', 'enabled' => true ),
	);
	return (string) json_encode( array( 'values' => $values ), JSON_UNESCAPED_SLASHES );
}

$sandbox_environment = importer_environment( 'https://mwallet.mmgtest.net/olive/publisher/v1/' );
$parsed = importer_call( 'parse_postman_environment', array( $sandbox_environment ) );
importer_check(
	array_keys( $parsed ) === array( 'api_key', 'wss_mid', 'password', 'wss_msecret', 'wss_mkey', 'mwallet_base_url' ),
	'The Postman environment is parsed in the supplied credential order.'
);
importer_check( ! isset( $parsed['wss_token'] ) && ! isset( $parsed['transaction_id'] ), 'Runtime and unknown Postman values are ignored.' );
importer_check( $parsed['api_key'] === 'test-api-key', 'A disabled duplicate cannot replace an enabled API key.' );

$sandbox = importer_call( 'prepare_import', array( 'sandbox', '', '', '', $sandbox_environment, array() ) );
$expected_sandbox_keys = array(
	'sandbox_api_key',
	'sandbox_api_wss_mid',
	'sandbox_api_password',
	'sandbox_api_wss_msecret',
	'sandbox_api_wss_mkey',
	'sandbox_api_mwallet_base_url',
);
importer_check( array_keys( $sandbox['changes'] ) === $expected_sandbox_keys, 'An environment-only Sandbox import produces every supported setting in JSON order.' );
importer_check( ! isset( $sandbox['changes']['sandbox_api_credit_account_id'] ), 'An API import preserves a separate creditParty.accountid override.' );
importer_check( $sandbox['changes']['sandbox_api_mwallet_base_url'] === 'https://mwallet.mmgtest.net/olive/publisher/v1', 'The imported API base URL is normalised without a trailing slash.' );

$live_environment = importer_environment( 'https://payments.mymmg.gy/olive/publisher/v1' );
$live = importer_call( 'prepare_import', array( 'live', '', '', '', $live_environment, array() ) );
importer_check( isset( $live['changes']['live_api_key'], $live['changes']['live_api_mwallet_base_url'] ), 'The same Postman import path writes Live settings when Live is selected.' );
importer_check( ! isset( $live['changes']['sandbox_api_key'] ), 'A Live import cannot overwrite Sandbox API values.' );

$without_password = importer_call(
	'prepare_import',
	array( 'sandbox', '', '', '', importer_environment( 'https://mwallet.mmgtest.net/olive/publisher/v1', false ), array() )
);
importer_check( ! isset( $without_password['changes']['sandbox_api_password'] ), 'A blank environment password is omitted so an existing password is preserved.' );

$manual_override = importer_call(
	'prepare_import',
	array( 'sandbox', '', '', '', $sandbox_environment, array( 'password' => 'manual-password' ) )
);
importer_check( $manual_override['changes']['sandbox_api_password'] === 'manual-password', 'A non-empty manual value overrides the imported JSON value.' );

$_POST = array( 'mmgwc_api_key' => '', 'mmgwc_api_password' => 'posted-password' );
$posted = importer_call( 'posted_api_values' );
importer_check( $posted === array( 'password' => 'posted-password' ), 'Blank manual fields are ignored and cannot erase stored credentials.' );
$_POST = array();

$cfg = "[DEFAULT]\nmerchant=Test Merchant\nmerchant_msisdn=6991234\nsecret_key=hosted-secret\nclientId=hosted-client\n";
$public_pem = "-----BEGIN PUBLIC KEY-----\nTEST\n-----END PUBLIC KEY-----";
$private_pem = "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----";
$combined = importer_call( 'prepare_import', array( 'live', $cfg, $public_pem, $private_pem, $live_environment, array() ) );
importer_check( isset( $combined['changes']['live_merchant_id'], $combined['changes']['live_api_key'] ), 'One package can import hosted and Merchant Initiated values together.' );

importer_expect_exception(
	static function () use ( $cfg, $public_pem ): void {
		importer_call( 'prepare_import', array( 'sandbox', $cfg, $public_pem, '', '', array() ) );
	},
	'An incomplete hosted credential set is rejected.'
);

$conflicting = (string) json_encode(
	array(
		'values' => array(
			array( 'key' => 'x-api-key', 'value' => 'first', 'enabled' => true ),
			array( 'key' => 'x-api-key', 'value' => 'second', 'enabled' => true ),
		),
	)
);
importer_expect_exception(
	static function () use ( $conflicting ): void {
		importer_call( 'parse_postman_environment', array( $conflicting ) );
	},
	'Conflicting enabled Postman values are rejected.'
);

importer_expect_exception(
	static function (): void {
		importer_call( 'validate_api_values', array( array( 'api_key' => "unsafe\r\nheader" ), 'sandbox' ) );
	},
	'Header values containing CR or LF are rejected.'
);
importer_expect_exception(
	static function (): void {
		importer_call( 'validate_api_values', array( array( 'api_key' => str_repeat( 'x', 4097 ) ), 'sandbox' ) );
	},
	'Oversized credential values are rejected.'
);
importer_expect_exception(
	static function (): void {
		importer_call( 'validate_api_values', array( array( 'mwallet_base_url' => 'http://mwallet.mmgtest.net/olive/publisher/v1' ), 'sandbox' ) );
	},
	'A non-HTTPS API base is rejected.'
);
importer_expect_exception(
	static function (): void {
		importer_call( 'validate_api_values', array( array( 'mwallet_base_url' => 'https://vendor.example.test/olive/publisher/v1' ), 'live' ) );
	},
	'A credential-bearing API URL outside the approved MMG host list is rejected.'
);

if ( class_exists( 'ZipArchive' ) ) {
	$temp_zip = tempnam( sys_get_temp_dir(), 'mmgwc-import-' );
	$zip = new ZipArchive();
	$zip->open( $temp_zip, ZipArchive::OVERWRITE );
	$zip->addFromString( 'Merchant Package/MMG PROD Environment.postman_environment.json', $live_environment );
	$zip->addFromString( '../ignored.postman_environment.json', 'unsafe' );
	$zip->close();
	$detected = importer_call( 'read_zip_first_match', array( $temp_zip, '/\.postman_environment\.json$/i' ) );
	importer_check( hash_equals( $live_environment, $detected ), 'The importer detects a nested Postman environment inside a zip and ignores traversal paths.' );
	@unlink( $temp_zip );
} else {
	importer_check( true, 'ZipArchive is unavailable, so the zip detection assertion was skipped.' );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} importer tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} importer tests passed.\n" );
