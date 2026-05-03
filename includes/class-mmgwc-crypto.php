<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements RSA-OAEP with SHA-256, while delegating the raw RSA operation to OpenSSL.
 *
 * Note: PHP's openssl_public_encrypt() OAEP mode does not expose OAEP hash selection.
 * MMG's reference implementation uses OAEP with SHA-256, so we perform OAEP encode/decode
 * in PHP and use OPENSSL_NO_PADDING for the raw RSA operation.
 */
final class MMGWC_Crypto {
	private const HASH_ALGO = 'sha256';

	public static function base64url_encode( string $binary ): string {
		$encoded = base64_encode( $binary );
		return strtr( $encoded, '+/', '-_' );
	}

	public static function base64url_decode( string $data ): string {
		$data = strtr( $data, '-_', '+/' );
		$pad_len = 4 - ( strlen( $data ) % 4 );
		if ( $pad_len > 0 && $pad_len < 4 ) {
			$data .= str_repeat( '=', $pad_len );
		}
		$decoded = base64_decode( $data, true );
		if ( $decoded === false ) {
			throw new RuntimeException( 'Invalid base64-url data' );
		}
		return $decoded;
	}

	/**
	 * openssl_free_key() is a no-op on PHP 8.0+ and emits a deprecation notice.
	 * Use this helper so we stay quiet across versions.
	 */
	private static function free_key( $key ): void {
		if ( PHP_MAJOR_VERSION < 8 && function_exists( 'openssl_free_key' ) ) {
			// phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.openssl_free_keyDeprecated
			@openssl_free_key( $key );
		}
	}

	/**
	 * Encrypts plaintext using RSA-OAEP (SHA-256) then returns ciphertext bytes.
	 */
	public static function encrypt_oaep_sha256( string $plaintext, string $public_pem ): string {
		$pub = openssl_pkey_get_public( $public_pem );
		if ( ! $pub ) {
			throw new RuntimeException( 'Invalid public key' );
		}

		$details = openssl_pkey_get_details( $pub );
		if ( empty( $details['bits'] ) ) {
			self::free_key( $pub );
			throw new RuntimeException( 'Unable to read public key details' );
		}

		$k = (int) ceil( $details['bits'] / 8 );
		$em = self::oaep_encode( $plaintext, $k, '' );

		$encrypted = '';
		$ok = openssl_public_encrypt( $em, $encrypted, $pub, OPENSSL_NO_PADDING );
		self::free_key( $pub );

		if ( ! $ok ) {
			throw new RuntimeException( 'OpenSSL public encrypt failed' );
		}

		return $encrypted;
	}

	/**
	 * Decrypts ciphertext bytes using RSA-OAEP (SHA-256) and returns plaintext.
	 *
	 * All decryption failures raise a single generic "Decryption error" to avoid
	 * leaking oracle information (label hash, padding format, length) to callers.
	 */
	public static function decrypt_oaep_sha256( string $ciphertext, string $private_pem ): string {
		$priv = openssl_pkey_get_private( $private_pem );
		if ( ! $priv ) {
			throw new RuntimeException( 'Invalid private key' );
		}

		$details = openssl_pkey_get_details( $priv );
		if ( empty( $details['bits'] ) ) {
			self::free_key( $priv );
			throw new RuntimeException( 'Decryption error' );
		}

		$k = (int) ceil( $details['bits'] / 8 );
		if ( strlen( $ciphertext ) < $k ) {
			$ciphertext = str_pad( $ciphertext, $k, "\0", STR_PAD_LEFT );
		}

		$em = '';
		$ok = openssl_private_decrypt( $ciphertext, $em, $priv, OPENSSL_NO_PADDING );
		self::free_key( $priv );

		if ( ! $ok ) {
			throw new RuntimeException( 'Decryption error' );
		}

		try {
			return self::oaep_decode( $em, '' );
		} catch ( Exception $e ) {
			throw new RuntimeException( 'Decryption error' );
		}
	}

	private static function oaep_encode( string $m, int $k, string $label = '' ): string {
		$h_len = strlen( hash( self::HASH_ALGO, '', true ) );
		$m_len = strlen( $m );
		if ( $m_len > $k - 2 * $h_len - 2 ) {
			throw new RuntimeException( 'Message too long for RSA key size' );
		}

		$l_hash = hash( self::HASH_ALGO, $label, true );
		$ps = str_repeat( "\0", $k - $m_len - 2 * $h_len - 2 );
		$db = $l_hash . $ps . "\x01" . $m;
		$seed = random_bytes( $h_len );

		$db_mask = self::mgf1( $seed, $k - $h_len - 1 );
		$masked_db = self::str_xor( $db, $db_mask );

		$seed_mask = self::mgf1( $masked_db, $h_len );
		$masked_seed = self::str_xor( $seed, $seed_mask );

		return "\x00" . $masked_seed . $masked_db;
	}

	private static function oaep_decode( string $em, string $label = '' ): string {
		$h_len = strlen( hash( self::HASH_ALGO, '', true ) );
		$k = strlen( $em );

		if ( $k < 2 * $h_len + 2 ) {
			throw new RuntimeException( 'Decryption error' );
		}

		$y = ord( $em[0] );
		$masked_seed = substr( $em, 1, $h_len );
		$masked_db = substr( $em, 1 + $h_len );

		$seed_mask = self::mgf1( $masked_db, $h_len );
		$seed = self::str_xor( $masked_seed, $seed_mask );

		$db_mask = self::mgf1( $seed, $k - $h_len - 1 );
		$db = self::str_xor( $masked_db, $db_mask );

		$l_hash = hash( self::HASH_ALGO, $label, true );
		$l_hash_prime = substr( $db, 0, $h_len );

		// Unified failure: any mismatch or malformed padding collapses to the same error.
		$ok = ( $y === 0 ) && hash_equals( $l_hash, $l_hash_prime );

		$rest = substr( $db, $h_len );
		$pos = strpos( $rest, "\x01" );
		if ( ! $ok || $pos === false ) {
			throw new RuntimeException( 'Decryption error' );
		}

		$ps = substr( $rest, 0, $pos );
		if ( $ps !== '' && trim( $ps, "\0" ) !== '' ) {
			throw new RuntimeException( 'Decryption error' );
		}

		return substr( $rest, $pos + 1 );
	}

	private static function mgf1( string $seed, int $mask_len ): string {
		$h_len = strlen( hash( self::HASH_ALGO, '', true ) );
		$t = '';
		$counter = 0;
		while ( strlen( $t ) < $mask_len ) {
			$c = pack( 'N', $counter );
			$t .= hash( self::HASH_ALGO, $seed . $c, true );
			$counter++;
		}
		return substr( $t, 0, $mask_len );
	}

	private static function str_xor( string $a, string $b ): string {
		$len = strlen( $a );
		$out = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $a[ $i ] ^ $b[ $i ];
		}
		return $out;
	}
}
