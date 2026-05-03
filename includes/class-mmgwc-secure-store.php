<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMGWC_Secure_Store
 *
 * Lightweight at-rest encryption for sensitive fields (private keys, secrets).
 * Uses AES-256-GCM with a key derived from WordPress AUTH_KEY + SECURE_AUTH_SALT,
 * so a DB dump alone is not enough to read the values; an attacker would also need
 * access to wp-config.php.
 *
 * Values are transparent to callers:
 *   - encrypt( $plaintext ) returns a string prefixed with "mmgenc$v1:" followed by base64(iv|tag|cipher).
 *   - decrypt( $value ) returns the plaintext if the prefix matches, otherwise returns $value unchanged.
 *
 * Any value that does not start with the prefix is treated as legacy plaintext and returned untouched,
 * so sites that never ran the secure store continue to work without a migration step.
 */
final class MMGWC_Secure_Store {
	public const PREFIX = 'mmgenc$v1:';
	private const CIPHER = 'aes-256-gcm';

	private static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	private static function key(): string {
		$auth = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$salt = defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '';
		if ( $auth === '' && $salt === '' ) {
			// Fallback – still site-specific.
			$auth = (string) wp_salt( 'auth' );
			$salt = (string) wp_salt( 'secure_auth' );
		}
		return hash( 'sha256', $auth . '|mmgwc|' . $salt, true );
	}

	public static function is_encrypted( $value ): bool {
		return is_string( $value ) && strpos( $value, self::PREFIX ) === 0;
	}

	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return $plaintext;
		}
		if ( self::is_encrypted( $plaintext ) ) {
			return $plaintext;
		}
		if ( ! self::available() ) {
			return $plaintext;
		}
		try {
			$iv = random_bytes( 12 );
			$tag = '';
			$cipher = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( $cipher === false ) {
				return $plaintext;
			}
			return self::PREFIX . base64_encode( $iv . $tag . $cipher );
		} catch ( Exception $e ) {
			return $plaintext;
		}
	}

	public static function decrypt( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}
		if ( ! self::available() ) {
			return $value;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( ! is_string( $raw ) || strlen( $raw ) < 12 + 16 + 1 ) {
			return $value;
		}
		$iv = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( $plain === false ) {
			return $value;
		}
		return $plain;
	}

	/**
	 * Return the list of settings keys that should be encrypted at rest.
	 */
	public static function protected_keys(): array {
		return array(
			'sandbox_secret_key',
			'sandbox_private_key',
			'live_secret_key',
			'live_private_key',
			'api_key',
			'api_wss_mkey',
			'api_wss_msecret',
			'api_password',
		);
	}
}
