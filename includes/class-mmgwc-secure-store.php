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
 *   - decrypt( $value ) returns plaintext for a readable envelope, legacy plaintext unchanged or an empty
 *     string for an unreadable envelope.
 *
 * Any value that does not start with the prefix is treated as legacy plaintext and returned untouched,
 * so sites that never ran the secure store continue to work without a migration step.
 */
final class MMGWC_Secure_Store {
	public const PREFIX = 'mmgenc$v1:';
	private const CIPHER = 'aes-256-gcm';
	private static $operation_errors = array();

	private static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	private static function key(): string {
		$auth = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$salt = defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '';
		if ( $auth === '' && $salt === '' ) {
			// This fallback remains site-specific.
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
			if ( self::can_decrypt( $plaintext ) ) {
				return $plaintext;
			}
			self::report_unreadable_envelope();
			return '';
		}
		if ( ! self::available() ) {
			self::report_encryption_failure();
			return '';
		}
		try {
			$iv = random_bytes( 12 );
			$tag = '';
			$cipher = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( $cipher === false ) {
				self::report_encryption_failure();
				return '';
			}
			return self::PREFIX . base64_encode( $iv . $tag . $cipher );
		} catch ( Throwable $e ) {
			self::report_encryption_failure();
			return '';
		}
	}

	/**
	 * Encrypt a replacement value without erasing a value that is already stored.
	 * A blank admin field means "keep the stored value". Encrypted envelopes are
	 * accepted only when they are the existing value or are readable on this site.
	 */
	public static function encrypt_preserving_existing( string $plaintext, string $existing = '' ): string {
		if ( $plaintext === '' ) {
			return $existing;
		}
		if ( self::is_encrypted( $plaintext ) ) {
			if ( $existing !== '' && hash_equals( $existing, $plaintext ) ) {
				return $existing;
			}
			if ( self::can_decrypt( $plaintext ) ) {
				return $plaintext;
			}
			self::report_unreadable_envelope();
			return $existing;
		}
		$encrypted = self::encrypt( $plaintext );
		if ( $encrypted === '' ) {
			return $existing;
		}
		return $encrypted;
	}

	private static function report_encryption_failure(): void {
		$message = 'MMG protected credentials were not saved because secure encryption is unavailable.';
		self::record_operation_error( $message );
		if ( class_exists( 'WC_Admin_Settings' ) && method_exists( 'WC_Admin_Settings', 'add_error' ) ) {
			WC_Admin_Settings::add_error( $message );
		} elseif ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'mmgwc_secure_store', 'mmgwc_encryption_unavailable', $message, 'error' );
		}
	}

	private static function report_unreadable_envelope(): void {
		$message = 'MMG protected credentials were not changed because encrypted storage text is not a valid credential. Enter the original credential value instead.';
		self::record_operation_error( $message );
		if ( class_exists( 'WC_Admin_Settings' ) && method_exists( 'WC_Admin_Settings', 'add_error' ) ) {
			WC_Admin_Settings::add_error( $message );
		} elseif ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'mmgwc_secure_store', 'mmgwc_unreadable_envelope', $message, 'error' );
		}
	}

	private static function record_operation_error( string $message ): void {
		if ( ! in_array( $message, self::$operation_errors, true ) ) {
			self::$operation_errors[] = $message;
		}
	}

	/**
	 * Reset protected-value errors before one administrator save operation.
	 */
	public static function reset_operation_errors(): void {
		self::$operation_errors = array();
	}

	/**
	 * Return safe administrator messages recorded during the current save operation.
	 */
	public static function operation_errors(): array {
		return self::$operation_errors;
	}

	/**
	 * Determine whether an encrypted envelope can be opened with this site's key.
	 */
	public static function can_decrypt( $value ): bool {
		return is_string( $value )
			&& self::is_encrypted( $value )
			&& self::decrypt_envelope( $value ) !== false;
	}

	public static function decrypt( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}
		$plain = self::decrypt_envelope( $value );
		return $plain === false ? '' : $plain;
	}

	/**
	 * Open one encrypted envelope. False means that the envelope is malformed,
	 * encryption is unavailable or the site's key no longer matches.
	 *
	 * @return string|false
	 */
	private static function decrypt_envelope( string $value ) {
		if ( ! self::available() ) {
			return false;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( ! is_string( $raw ) || strlen( $raw ) < 12 + 16 + 1 ) {
			return false;
		}
		$iv = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return is_string( $plain ) ? $plain : false;
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
			'sandbox_api_key',
			'sandbox_api_wss_mkey',
			'sandbox_api_wss_msecret',
			'sandbox_api_password',
			'live_api_key',
			'live_api_wss_mkey',
			'live_api_wss_msecret',
			'live_api_password',
			'api_key',
			'api_wss_mkey',
			'api_wss_msecret',
			'api_password',
		);
	}
}
