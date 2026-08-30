<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_API {
	/**
	 * @param array $config
	 * @return string|null
	 */
	public static function get_resource_token( array $config ) {
		$base = trim( $config['mwallet_base_url'] ?? '' );
		$api_key = trim( $config['api_key'] ?? '' );
		$username = trim( $config['wss_mid'] ?? '' );
		$password = trim( $config['password'] ?? '' );

		if ( $base === '' || $api_key === '' || $username === '' || $password === '' || ! self::is_valid_base_url( $base ) ) {
			return null;
		}

		$url = rtrim( $base, '/' ) . '/e-commerce-login/mer';

		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
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

		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		if ( $code < 200 || $code >= 300 ) {
			MMGWC_Logger::warning( 'MMG API login non-2xx: ' . $code );
			return null;
		}
		if ( strlen( $body ) > 1048576 ) {
			MMGWC_Logger::warning( 'MMG API login response too large, ignoring.' );
			return null;
		}

		$data = json_decode( $body, true );
		$token = $data['access_token'] ?? null;
		return is_string( $token ) ? $token : null;
	}

	/**
	 * Authenticated transaction lookup (Merchant Initiated API).
	 *
	 * A successful lookup is required before an order can be marked as paid.
	 */
	public static function transaction_lookup( array $config, string $transaction_id ) {
		$base = trim( $config['mwallet_base_url'] ?? '' );
		$api_key = trim( $config['api_key'] ?? '' );
		$wss_mid = trim( $config['wss_mid'] ?? '' );
		$wss_mkey = trim( $config['wss_mkey'] ?? '' );
		$wss_msecret = trim( $config['wss_msecret'] ?? '' );

		if ( $base === '' || $api_key === '' || $wss_mid === '' || $wss_mkey === '' || $wss_msecret === '' || preg_match( '/^\d{1,64}$/', $transaction_id ) !== 1 || ! self::is_valid_base_url( $base ) ) {
			return null;
		}

		$token = $config['wss_token'] ?? '';
		if ( ! is_string( $token ) || $token === '' ) {
			$token = self::get_resource_token( $config );
			if ( ! $token ) {
				return null;
			}
		}

		$url = rtrim( $base, '/' ) . '/e-merchant-initiated-transactions/lookup';
		$url = add_query_arg( array( 'transactionId' => $transaction_id ), $url );

		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'x-wss-mid' => $wss_mid,
					'x-wss-mkey' => $wss_mkey,
					'x-wss-msecret' => $wss_msecret,
					'x-api-key' => $api_key,
					'x-wss-token' => $token,
					'x-wss-correlationid' => wp_generate_uuid4(),
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			MMGWC_Logger::warning( 'MMG transaction lookup failed: ' . $resp->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		if ( $code < 200 || $code >= 300 ) {
			MMGWC_Logger::warning( 'MMG transaction lookup non-2xx: ' . $code );
			return null;
		}
		if ( strlen( $body ) > 1048576 ) {
			MMGWC_Logger::warning( 'MMG transaction lookup response too large, ignoring.' );
			return null;
		}

		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	private static function is_valid_base_url( string $base ): bool {
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' || empty( $parts['host'] ) ) {
			MMGWC_Logger::warning( 'MMG API base URL must be a valid HTTPS URL.' );
			return false;
		}
		return true;
	}
}
