<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Logger {

	private const REDACTED_KEYS = array(
		'token', 'secretkey', 'secret_key', 'secret',
		'private_key', 'public_key',
		'password', 'api_password', 'apikey', 'api_key',
		'access_token', 'refresh_token',
		'x-wss-token', 'x-wss-mkey', 'x-wss-msecret', 'x-api-key',
		'authorization', 'auth',
	);

	/**
	 * @return WC_Logger
	 */
	private static function logger() {
		return wc_get_logger();
	}

	public static function debug( $message, $context = array() ) {
		if ( ! MMGWC_Settings::is_debug_enabled() ) {
			return;
		}
		self::logger()->info( $message, self::with_source( self::sanitize_context( $context ) ) );
	}

	public static function info( $message, $context = array() ) {
		self::logger()->info( $message, self::with_source( self::sanitize_context( $context ) ) );
	}

	public static function warning( $message, $context = array() ) {
		self::logger()->warning( $message, self::with_source( self::sanitize_context( $context ) ) );
	}

	public static function error( $message, $context = array() ) {
		self::logger()->error( $message, self::with_source( self::sanitize_context( $context ) ) );
	}

	private static function with_source( array $context ): array {
		return array_merge( array( 'source' => MMGWC_LOG_SOURCE ), $context );
	}

	/**
	 * Recursively scrub context values: drop known secret-like keys entirely,
	 * and scrub string values for embedded tokens / bearer headers.
	 */
	private static function sanitize_context( array $context ): array {
		$out = array();
		foreach ( $context as $k => $v ) {
			$key_lower = is_string( $k ) ? strtolower( $k ) : (string) $k;
			if ( in_array( $key_lower, self::REDACTED_KEYS, true ) ) {
				$out[ $k ] = '[redacted]';
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $k ] = self::sanitize_context( $v );
			} elseif ( is_string( $v ) ) {
				$out[ $k ] = self::redact_string( $key_lower, $v );
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	private static function redact_string( string $key, string $value ): string {
		// Redact URL query tokens.
		$value = preg_replace( '/([?&](?:token|access_token|refresh_token|api_key|apikey|password)=)[^&\s]+/i', '$1[redacted]', $value ) ?? $value;
		// Redact path-style MMG callback token.
		$value = preg_replace( '/mmg-checkout\/[A-Za-z0-9\-_\.]+/', 'mmg-checkout/[redacted]', $value ) ?? $value;
		// Redact bearer / authorization headers embedded in strings.
		$value = preg_replace( '/(Authorization:\s*Bearer\s+)\S+/i', '$1[redacted]', $value ) ?? $value;
		// Redact PEM blocks.
		$value = preg_replace( '/-----BEGIN [^-]+-----[\s\S]+?-----END [^-]+-----/', '[redacted-pem]', $value ) ?? $value;
		return $value;
	}
}
