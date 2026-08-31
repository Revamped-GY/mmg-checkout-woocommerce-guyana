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
	public const DEFAULT_SANDBOX_API_BASE = 'https://mwallet.mmgtest.net/olive/publisher/v1';
	private const LEGACY_SANDBOX_API_BASE = 'https://mwallet.mmgtest.net/mwallet/v1';

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
			return self::normalise_setting_value( $key, $override );
		}
		$all = self::get_all();
		if ( ! array_key_exists( $key, $all ) ) {
			$legacy_key = self::legacy_sandbox_api_key( $key );
			if ( $legacy_key !== '' ) {
				return self::normalise_setting_value( $key, self::get( $legacy_key, $default ) );
			}
			return self::normalise_setting_value( $key, $default );
		}
		$value = $all[ $key ];
		if ( class_exists( 'MMGWC_Secure_Store' ) && in_array( $key, MMGWC_Secure_Store::protected_keys(), true ) ) {
			$value = MMGWC_Secure_Store::decrypt( $value );
		}
		return self::normalise_setting_value( $key, $value );
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

			// Merchant Initiated API details are environment-specific. Legacy
			// global values are accepted only as Sandbox fallbacks because the
			// previous built-in endpoint was an MMG UAT endpoint.
			'mwallet_base_url' => trim( (string) self::get( $prefix . 'api_mwallet_base_url', $mode === 'sandbox' ? self::DEFAULT_SANDBOX_API_BASE : '' ) ),
			'api_key' => trim( (string) self::get( $prefix . 'api_key', '' ) ),
			'wss_mid' => trim( (string) self::get( $prefix . 'api_wss_mid', '' ) ),
			'wss_mkey' => trim( (string) self::get( $prefix . 'api_wss_mkey', '' ) ),
			'wss_msecret' => trim( (string) self::get( $prefix . 'api_wss_msecret', '' ) ),
			'password' => trim( (string) self::get( $prefix . 'api_password', '' ) ),
			'credit_account_id' => trim( (string) self::get( $prefix . 'api_credit_account_id', '' ) ),

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
		$existing = self::get_all();
		foreach ( MMGWC_Secure_Store::protected_keys() as $k ) {
			if ( array_key_exists( $k, $settings ) && is_string( $settings[ $k ] ) && $settings[ $k ] !== '' ) {
				$settings[ $k ] = MMGWC_Secure_Store::encrypt_preserving_existing(
					$settings[ $k ],
					isset( $existing[ $k ] ) && is_string( $existing[ $k ] ) ? $existing[ $k ] : ''
				);
			}
		}
		return $settings;
	}

	/**
	 * Convert the former global API fields into read-only Sandbox fallbacks.
	 * New values are saved under mode-specific keys on the next settings save.
	 */
	private static function legacy_sandbox_api_key( string $key ): string {
		$map = array(
			'sandbox_api_mwallet_base_url' => 'api_mwallet_base_url',
			'sandbox_api_key' => 'api_key',
			'sandbox_api_wss_mid' => 'api_wss_mid',
			'sandbox_api_wss_mkey' => 'api_wss_mkey',
			'sandbox_api_wss_msecret' => 'api_wss_msecret',
			'sandbox_api_password' => 'api_password',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Migrate the stale built-in UAT API base without rewriting custom URLs.
	 */
	private static function normalise_setting_value( string $key, $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( in_array( $key, array( 'api_mwallet_base_url', 'sandbox_api_mwallet_base_url' ), true ) ) {
			$trimmed = rtrim( trim( $value ), '/' );
			if ( $trimmed === '' || hash_equals( self::LEGACY_SANDBOX_API_BASE, $trimmed ) ) {
				return self::DEFAULT_SANDBOX_API_BASE;
			}
		}
		return $value;
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

			// Legacy global Merchant Initiated API overrides.
			'api_mwallet_base_url' => 'MMGWC_MWALLET_BASE_URL',
			'api_key'              => 'MMGWC_API_KEY',
			'api_wss_mid'          => 'MMGWC_WSS_MID',
			'api_wss_mkey'         => 'MMGWC_WSS_MKEY',
			'api_wss_msecret'      => 'MMGWC_WSS_MSECRET',
			'api_password'         => 'MMGWC_API_PASSWORD',

			// Environment-specific Merchant Initiated API overrides.
			'sandbox_api_mwallet_base_url' => 'MMGWC_SANDBOX_MWALLET_BASE_URL',
			'sandbox_api_key'              => 'MMGWC_SANDBOX_API_KEY',
			'sandbox_api_wss_mid'          => 'MMGWC_SANDBOX_WSS_MID',
			'sandbox_api_wss_mkey'         => 'MMGWC_SANDBOX_WSS_MKEY',
			'sandbox_api_wss_msecret'      => 'MMGWC_SANDBOX_WSS_MSECRET',
			'sandbox_api_password'         => 'MMGWC_SANDBOX_API_PASSWORD',
			'sandbox_api_credit_account_id' => 'MMGWC_SANDBOX_API_CREDIT_ACCOUNT_ID',
			'live_api_mwallet_base_url' => 'MMGWC_LIVE_MWALLET_BASE_URL',
			'live_api_key'              => 'MMGWC_LIVE_API_KEY',
			'live_api_wss_mid'          => 'MMGWC_LIVE_WSS_MID',
			'live_api_wss_mkey'         => 'MMGWC_LIVE_WSS_MKEY',
			'live_api_wss_msecret'      => 'MMGWC_LIVE_WSS_MSECRET',
			'live_api_password'         => 'MMGWC_LIVE_API_PASSWORD',
			'live_api_credit_account_id' => 'MMGWC_LIVE_API_CREDIT_ACCOUNT_ID',

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
