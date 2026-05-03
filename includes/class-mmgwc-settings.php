<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings helper.
 *
 * Users can override settings using constants in wp-config.php.
 * This keeps secrets out of the database.
 *
 * Sensitive values (private keys, secret keys, API creds) are transparently
 * encrypted at rest via MMGWC_Secure_Store. Callers always see plaintext.
 */
final class MMGWC_Settings {

	public static function get_all(): array {
		$settings = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Persist settings. Large/sensitive options are stored with autoload=no,
	 * and protected keys are encrypted before write.
	 */
	public static function update_all( array $settings ): void {
		$settings = self::encrypt_protected( $settings );
		update_option( MMGWC_SETTINGS_OPTION_KEY, $settings, false );
	}

	/**
	 * Merge new values into the existing settings option and save.
	 */
	public static function update_partial( array $changes ): void {
		$existing = self::get_all();
		$merged   = array_merge( $existing, $changes );
		self::update_all( $merged );
	}

	public static function is_debug_enabled(): bool {
		$override = self::get_constant_override( 'debug' );
		if ( $override !== null ) {
			return (bool) $override;
		}
		$all = self::get_all();
		return isset( $all['debug'] ) && $all['debug'] === 'yes';
	}

	public static function get_mode(): string {
		$override = self::get_constant_override( 'mode' );
		if ( is_string( $override ) && $override !== '' ) {
			return $override === 'live' ? 'live' : 'sandbox';
		}
		$all = self::get_all();
		$mode = isset( $all['mode'] ) ? (string) $all['mode'] : 'sandbox';
		return $mode === 'live' ? 'live' : 'sandbox';
	}

	/**
	 * Get a single gateway setting with optional constant override.
	 * Decrypts protected keys transparently.
	 *
	 * @param string $key Gateway setting key, for example live_merchant_id.
	 * @param mixed $default Default value.
	 */
	public static function get( string $key, $default = '' ) {
		$override = self::get_constant_override( $key );
		if ( $override !== null ) {
			return $override;
		}
		$all = self::get_all();
		if ( ! array_key_exists( $key, $all ) ) {
			return $default;
		}
		$value = $all[ $key ];
		if ( class_exists( 'MMGWC_Secure_Store' ) && in_array( $key, MMGWC_Secure_Store::protected_keys(), true ) ) {
			$value = MMGWC_Secure_Store::decrypt( $value );
		}
		return $value;
	}

	/**
	 * Build a config array for the given mode (plaintext secrets).
	 */
	public static function get_config( string $mode ): array {
		$mode = $mode === 'live' ? 'live' : 'sandbox';
		$prefix = $mode === 'live' ? 'live_' : 'sandbox_';

		return array(
			'mode' => $mode,
			'checkout_url' => trim( (string) self::get( $prefix . 'checkout_url', '' ) ),
			'merchant_id' => trim( (string) self::get( $prefix . 'merchant_id', '' ) ),
			'client_id' => trim( (string) self::get( $prefix . 'client_id', '' ) ),
			'merchant_name' => trim( (string) self::get( $prefix . 'merchant_name', '' ) ),
			'secret_key' => trim( (string) self::get( $prefix . 'secret_key', '' ) ),
			'public_key' => (string) self::get( $prefix . 'public_key', '' ),
			'private_key' => (string) self::get( $prefix . 'private_key', '' ),

			// Optional API details.
			'mwallet_base_url' => trim( (string) self::get( 'api_mwallet_base_url', '' ) ),
			'api_key' => trim( (string) self::get( 'api_key', '' ) ),
			'wss_mid' => trim( (string) self::get( 'api_wss_mid', '' ) ),
			'wss_mkey' => trim( (string) self::get( 'api_wss_mkey', '' ) ),
			'wss_msecret' => trim( (string) self::get( 'api_wss_msecret', '' ) ),
			'password' => trim( (string) self::get( 'api_password', '' ) ),

			// Status mapping.
			'status_success_virtual'  => (string) self::get( 'status_success_virtual', '' ),
			'status_success_physical' => (string) self::get( 'status_success_physical', '' ),
			'status_cancelled'        => (string) self::get( 'status_cancelled', 'cancelled' ),
			'status_failed'           => (string) self::get( 'status_failed', 'failed' ),
			'status_timeout'          => (string) self::get( 'status_timeout', 'failed' ),
			'log_retention_days'      => (string) self::get( 'log_retention_days', '14' ),

			// Currency conversion.
			'currency_conversion' => (string) self::get( 'currency_conversion', 'no' ),
			'fx_cache_hours'      => (string) self::get( 'fx_cache_hours', '12' ),
		);
	}

	/**
	 * Encrypt any protected keys inside an array before saving to the DB.
	 */
	public static function encrypt_protected( array $settings ): array {
		if ( ! class_exists( 'MMGWC_Secure_Store' ) ) {
			return $settings;
		}
		foreach ( MMGWC_Secure_Store::protected_keys() as $k ) {
			if ( array_key_exists( $k, $settings ) && is_string( $settings[ $k ] ) && $settings[ $k ] !== '' ) {
				$settings[ $k ] = MMGWC_Secure_Store::encrypt( $settings[ $k ] );
			}
		}
		return $settings;
	}

	/**
	 * Constant mapping (wp-config.php overrides).
	 */
	private static function get_constant_override( string $key ) {
		$map = array(
			// General.
			'mode'  => 'MMGWC_MODE',
			'debug' => 'MMGWC_DEBUG',

			// Sandbox.
			'sandbox_checkout_url'  => 'MMGWC_SANDBOX_CHECKOUT_URL',
			'sandbox_merchant_id'   => 'MMGWC_SANDBOX_MERCHANT_ID',
			'sandbox_client_id'     => 'MMGWC_SANDBOX_CLIENT_ID',
			'sandbox_merchant_name' => 'MMGWC_SANDBOX_MERCHANT_NAME',
			'sandbox_secret_key'    => 'MMGWC_SANDBOX_SECRET_KEY',
			'sandbox_public_key'    => 'MMGWC_SANDBOX_PUBLIC_KEY',
			'sandbox_private_key'   => 'MMGWC_SANDBOX_PRIVATE_KEY',

			// Live.
			'live_checkout_url'  => 'MMGWC_LIVE_CHECKOUT_URL',
			'live_merchant_id'   => 'MMGWC_LIVE_MERCHANT_ID',
			'live_client_id'     => 'MMGWC_LIVE_CLIENT_ID',
			'live_merchant_name' => 'MMGWC_LIVE_MERCHANT_NAME',
			'live_secret_key'    => 'MMGWC_LIVE_SECRET_KEY',
			'live_public_key'    => 'MMGWC_LIVE_PUBLIC_KEY',
			'live_private_key'   => 'MMGWC_LIVE_PRIVATE_KEY',

			// Merchant initiated API.
			'api_mwallet_base_url' => 'MMGWC_MWALLET_BASE_URL',
			'api_key'              => 'MMGWC_API_KEY',
			'api_wss_mid'          => 'MMGWC_WSS_MID',
			'api_wss_mkey'         => 'MMGWC_WSS_MKEY',
			'api_wss_msecret'      => 'MMGWC_WSS_MSECRET',
			'api_password'         => 'MMGWC_API_PASSWORD',

			// Status mapping.
			'status_success_virtual'  => 'MMGWC_STATUS_SUCCESS_VIRTUAL',
			'status_success_physical' => 'MMGWC_STATUS_SUCCESS_PHYSICAL',
			'status_cancelled'        => 'MMGWC_STATUS_CANCELLED',
			'status_failed'           => 'MMGWC_STATUS_FAILED',
			'status_timeout'          => 'MMGWC_STATUS_TIMEOUT',
			'log_retention_days'      => 'MMGWC_LOG_RETENTION_DAYS',

			// Currency conversion.
			'currency_conversion' => 'MMGWC_ENABLE_CURRENCY_CONVERSION',
			'fx_cache_hours'      => 'MMGWC_FX_CACHE_HOURS',
		);

		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}

		$const = $map[ $key ];
		if ( defined( $const ) ) {
			return constant( $const );
		}
		return null;
	}
}
