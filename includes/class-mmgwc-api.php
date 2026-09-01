<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_API {
	private const MAX_RESPONSE_BYTES = 1048576;
	private const DEFAULT_TOKEN_TTL = 90;

	/**
	 * Obtain and briefly cache MMG's short-lived resource token.
	 *
	 * @return string|null
	 */
	public static function get_resource_token( array $config, bool $force_refresh = false ) {
		$base = trim( (string) ( $config['mwallet_base_url'] ?? '' ) );
		$api_key = trim( (string) ( $config['api_key'] ?? '' ) );
		$username = trim( (string) ( $config['wss_mid'] ?? '' ) );
		$password = trim( (string) ( $config['password'] ?? '' ) );

		if ( $base === '' || $api_key === '' || $username === '' || $password === '' || ! self::is_valid_base_url( $base, $config ) ) {
			return null;
		}

		$cache_key = self::token_cache_key( $config );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}
		delete_transient( $cache_key );

		$url = rtrim( $base, '/' ) . '/e-commerce-login/mer';
		$resp = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 20,
				'reject_unsafe_urls' => true,
				'headers' => array(
					'Accept' => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body' => array(
					'grant_type' => 'password',
					'api_key' => $api_key,
					'username' => $username,
					'password' => $password,
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			MMGWC_Logger::warning( 'MMG API login failed: ' . $resp->get_error_message() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( $code < 200 || $code >= 300 ) {
			MMGWC_Logger::warning( 'MMG API login non-2xx: ' . $code );
			return null;
		}
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			MMGWC_Logger::warning( 'MMG API login response too large, ignoring.' );
			return null;
		}

		$data = json_decode( $body, true );
		$token = is_array( $data ) ? ( $data['access_token'] ?? null ) : null;
		if ( ! is_string( $token ) || $token === '' || strlen( $token ) > 4096 ) {
			return null;
		}

		$expires_in = isset( $data['expires_in'] ) && is_numeric( $data['expires_in'] ) ? (int) $data['expires_in'] : 120;
		$ttl = min( self::DEFAULT_TOKEN_TTL, $expires_in - 30 );
		if ( $ttl >= 5 ) {
			set_transient( $cache_key, $token, $ttl );
		}
		return $token;
	}

	/**
	 * Transaction Lookup from MMG's Merchant Initiated API.
	 *
	 * The published Lookup operation lists the merchant headers but omits
	 * x-wss-token. When an API password is configured, the plugin still includes
	 * the short-lived token described by the API authentication section.
	 */
	public static function transaction_lookup( array $config, string $transaction_id ) {
		$transaction_id = trim( $transaction_id );
		if ( preg_match( '/^\d{1,64}$/', $transaction_id ) !== 1 || ! self::has_lookup_credentials( $config ) ) {
			return null;
		}

		$url = rtrim( (string) $config['mwallet_base_url'], '/' ) . '/e-merchant-initiated-transactions/lookup';
		$url = add_query_arg( array( 'transactionId' => $transaction_id ), $url );
		return self::authenticated_request( 'GET', $url, $config, null, '', false );
	}

	/**
	 * Send an MMG approval request. A successful response is pending, not paid.
	 */
	public static function initiate_payment( array $config, string $customer_account, string $amount, string $correlation_id = '' ) {
		$customer_account = self::normalise_customer_account( $customer_account );
		$amount = self::normalise_amount( $amount );
		$credit_account = trim( (string) ( $config['credit_account_id'] ?? '' ) );
		if ( $customer_account === '' || $amount === '' || $credit_account === '' || ! self::has_request_credentials( $config ) ) {
			return new WP_Error( 'mmgwc_invalid_initiated_request', 'The MMG approval request is not configured correctly.' );
		}

		$url = rtrim( (string) $config['mwallet_base_url'], '/' ) . '/e-merchant-initiated-transactions/payment';
		$url = add_query_arg( array( 'merchant_msisdn' => (string) $config['wss_mid'] ), $url );
		$body = array(
			'amount' => $amount,
			'currency' => 'GYD',
			'subType' => 'merinipmt',
			'type' => 'transfer',
			'debitParty' => array(
				array(
					'key' => 'accountid',
					'value' => $customer_account,
				),
			),
			'creditParty' => array(
				array(
					'key' => 'accountid',
					'value' => $credit_account,
				),
			),
		);

		$data = self::authenticated_request( 'POST', $url, $config, $body, $correlation_id, true, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mmgwc_initiated_response_uncertain', 'MMG returned an unclear response after the approval request was sent.' );
		}
		return $data;
	}

	/**
	 * Extract the only reference that can be reconciled safely.
	 */
	public static function initiated_reference( array $response ) {
		$object_reference = self::scalar_string( $response['objectReference'] ?? '' );
		$execution_id = self::scalar_string( $response['executionId'] ?? '' );
		if ( $object_reference !== '' && $execution_id !== '' && ! hash_equals( $object_reference, $execution_id ) ) {
			return new WP_Error( 'mmgwc_ambiguous_initiated_reference', 'MMG returned different payment references. The order was not marked as paid.' );
		}
		$reference = $execution_id !== '' ? $execution_id : $object_reference;
		if ( preg_match( '/^\d{1,64}$/', $reference ) !== 1 ) {
			return new WP_Error( 'mmgwc_invalid_initiated_reference', 'MMG did not return a valid payment reference.' );
		}
		return $reference;
	}

	/**
	 * Accept a local seven-digit MMG number or the same number prefixed by 592.
	 */
	public static function normalise_customer_account( string $value ): string {
		$value = trim( $value );
		if ( $value === '' || preg_match( '/^[+0-9\s().-]+$/', $value ) !== 1 ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', $value );
		if ( ! is_string( $digits ) ) {
			return '';
		}
		if ( strlen( $digits ) === 10 && strpos( $digits, '592' ) === 0 ) {
			$digits = substr( $digits, 3 );
		}
		return preg_match( '/^\d{7}$/', $digits ) === 1 ? $digits : '';
	}

	private static function authenticated_request( string $method, string $url, array $config, ?array $body = null, string $correlation_id = '', bool $token_required = true, bool $return_errors = false ) {
		if ( preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $correlation_id ) !== 1 ) {
			$correlation_id = wp_generate_uuid4();
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = isset( $config['wss_token'] ) && is_string( $config['wss_token'] ) && $config['wss_token'] !== '' && $attempt === 0
				? $config['wss_token']
				: self::get_resource_token( $config, $attempt > 0 );
			if ( ( ! is_string( $token ) || $token === '' ) && $token_required ) {
				return $return_errors
					? new WP_Error( 'mmgwc_initiated_authentication_failed', 'MMG did not accept the API sign-in. Check the optional Initiated API details and try again.' )
					: null;
			}

			$args = array(
				'timeout' => 20,
				'reject_unsafe_urls' => true,
				'headers' => array(
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
					'x-wss-mid' => (string) $config['wss_mid'],
					'x-wss-mkey' => (string) $config['wss_mkey'],
					'x-wss-msecret' => (string) $config['wss_msecret'],
					'x-api-key' => (string) $config['api_key'],
					'x-wss-correlationid' => $correlation_id,
				),
			);
			if ( is_string( $token ) && $token !== '' ) {
				$args['headers']['x-wss-token'] = $token;
			}
			if ( $body !== null ) {
				$args['body'] = wp_json_encode( $body );
			}

			$resp = strtoupper( $method ) === 'POST'
				? wp_safe_remote_post( $url, $args )
				: wp_safe_remote_get( $url, $args );
			if ( is_wp_error( $resp ) ) {
				MMGWC_Logger::warning( 'MMG API request failed: ' . $resp->get_error_message() );
				return $return_errors
					? new WP_Error( 'mmgwc_initiated_transport_uncertain', 'The connection ended before MMG returned a result.' )
					: null;
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( in_array( $code, array( 401, 403 ), true ) && $attempt === 0 ) {
				if ( ! isset( $config['password'] ) || trim( (string) $config['password'] ) === '' ) {
					return $return_errors
						? new WP_Error( 'mmgwc_initiated_authentication_failed', 'MMG did not accept the API sign-in. Check the optional Initiated API details and try again.' )
						: null;
				}
				delete_transient( self::token_cache_key( $config ) );
				continue;
			}
			$raw = (string) wp_remote_retrieve_body( $resp );
			if ( $code < 200 || $code >= 300 ) {
				MMGWC_Logger::warning( 'MMG API request non-2xx: ' . $code );
				if ( $return_errors ) {
					return in_array( $code, array( 400, 401, 403, 404, 405, 406, 415, 422 ), true )
						? new WP_Error( 'mmgwc_initiated_rejected', 'MMG rejected the approval request. Check the optional Initiated API details and try again.' )
						: new WP_Error( 'mmgwc_initiated_server_uncertain', 'MMG did not return a final result for the approval request.' );
				}
				return null;
			}
			if ( strlen( $raw ) > self::MAX_RESPONSE_BYTES ) {
				MMGWC_Logger::warning( 'MMG API response too large, ignoring.' );
				return $return_errors
					? new WP_Error( 'mmgwc_initiated_response_uncertain', 'MMG returned an unreadable result after the approval request was sent.' )
					: null;
			}

			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				return $data;
			}
			return $return_errors
				? new WP_Error( 'mmgwc_initiated_response_uncertain', 'MMG returned an unreadable result after the approval request was sent.' )
				: null;
		}
		return null;
	}

	private static function has_request_credentials( array $config ): bool {
		foreach ( array( 'mwallet_base_url', 'api_key', 'wss_mid', 'wss_mkey', 'wss_msecret', 'password' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_scalar( $config[ $key ] ) || trim( (string) $config[ $key ] ) === '' ) {
				return false;
			}
		}
		return self::is_valid_base_url( (string) $config['mwallet_base_url'], $config );
	}

	private static function has_lookup_credentials( array $config ): bool {
		foreach ( array( 'mwallet_base_url', 'api_key', 'wss_mid', 'wss_mkey', 'wss_msecret' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_scalar( $config[ $key ] ) || trim( (string) $config[ $key ] ) === '' ) {
				return false;
			}
		}
		return self::is_valid_base_url( (string) $config['mwallet_base_url'], $config );
	}

	private static function normalise_amount( string $amount ): string {
		$amount = str_replace( ',', '', trim( $amount ) );
		if ( preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches ) !== 1 ) {
			return '';
		}
		$whole = ltrim( $matches[1], '0' );
		$whole = $whole === '' ? '0' : $whole;
		$fraction = isset( $matches[2] ) ? str_pad( $matches[2], 2, '0' ) : '00';
		if ( $whole === '0' && $fraction === '00' ) {
			return '';
		}
		return $whole . '.' . $fraction;
	}

	private static function token_cache_key( array $config ): string {
		$fingerprint = hash(
			'sha256',
			implode(
				'|',
				array(
					(string) ( $config['mode'] ?? '' ),
					(string) ( $config['mwallet_base_url'] ?? '' ),
					(string) ( $config['api_key'] ?? '' ),
					(string) ( $config['wss_mid'] ?? '' ),
					(string) ( $config['password'] ?? '' ),
				)
			)
		);
		return 'mmgwc_api_token_' . substr( $fingerprint, 0, 24 );
	}

	private static function scalar_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Validate a credential-bearing MMG API base URL.
	 *
	 * Stores can add another production domain through
	 * `mmgwc_allowed_api_hosts`. Each entry permits that host and its subdomains.
	 */
	public static function is_valid_base_url( string $base, array $config = array() ): bool {
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts )
			|| strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https'
			|| empty( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 )
		) {
			MMGWC_Logger::warning( 'MMG API base URL must be a valid HTTPS URL without credentials, query values or a non-standard port.' );
			return false;
		}

		$mode = isset( $config['mode'] ) && is_scalar( $config['mode'] ) ? strtolower( trim( (string) $config['mode'] ) ) : '';
		$default_hosts = $mode === 'sandbox'
			? array( 'mmgtest.net' )
			: ( $mode === 'live' ? array( 'mmg.gy', 'mymmg.gy' ) : array( 'mmgtest.net', 'mmg.gy', 'mymmg.gy' ) );
		$allowed_hosts = apply_filters( 'mmgwc_allowed_api_hosts', $default_hosts, $mode, $base );
		if ( ! is_array( $allowed_hosts ) ) {
			$allowed_hosts = $default_hosts;
		}

		$host = strtolower( rtrim( trim( (string) $parts['host'] ), '.' ) );
		foreach ( $allowed_hosts as $allowed_host ) {
			if ( ! is_scalar( $allowed_host ) ) {
				continue;
			}
			$allowed_host = strtolower( trim( (string) $allowed_host, " \t\n\r\0\x0B." ) );
			if ( $allowed_host === '' ) {
				continue;
			}
			if ( $host === $allowed_host || substr( $host, -( strlen( $allowed_host ) + 1 ) ) === '.' . $allowed_host ) {
				return true;
			}
		}

		MMGWC_Logger::warning( 'MMG API base URL host is not on the approved MMG host list.' );
		return false;
	}
}
