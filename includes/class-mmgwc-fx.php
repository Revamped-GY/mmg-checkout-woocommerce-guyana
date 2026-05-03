<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Currency conversion helper.
 *
 * MMG settles in GYD. If the store/order currency is not GYD, we can convert
 * using a free public JSON rate feed (no API key) and cache the result.
 *
 * Rates are validated against a plausibility window so a compromised or
 * misconfigured feed cannot push a near-zero or absurdly large conversion.
 */
final class MMGWC_FX {
	private const TRANSIENT_PREFIX = 'mmgwc_fx_';

	/**
	 * Plausibility bounds for 1 unit of a major currency → GYD.
	 * GYD is ~208-215 per USD at time of writing. We allow a generous window
	 * so natural volatility doesn't trigger false rejections, but still blocks
	 * obviously broken rates.
	 */
	private const MIN_RATE_PER_UNIT = 0.01;    // A fraction of a GYD per unit of foreign currency would be nonsensical.
	private const MAX_RATE_PER_UNIT = 100000.0; // 100,000 GYD per unit is far beyond any real currency.

	/**
	 * Validate that a currency code looks like ISO-4217 (3 uppercase letters).
	 */
	public static function is_valid_currency_code( string $code ): bool {
		return (bool) preg_match( '/^[A-Za-z]{3}$/', trim( $code ) );
	}

	/**
	 * Get conversion rate from a currency to GYD.
	 *
	 * @return array{rate:float,date:string,source:string,fetched_at:int,cached:bool}
	 * @throws RuntimeException When no valid rate is available.
	 */
	public static function get_rate_to_gyd( string $from_currency, bool $force_refresh = false ): array {
		$from = strtolower( trim( $from_currency ) );
		if ( $from === '' || ! self::is_valid_currency_code( $from ) ) {
			throw new RuntimeException( 'Invalid currency code' );
		}
		if ( $from === 'gyd' ) {
			return array(
				'rate'       => 1.0,
				'date'       => gmdate( 'Y-m-d' ),
				'source'     => 'local',
				'fetched_at' => time(),
				'cached'     => true,
			);
		}

		$cache_key = self::TRANSIENT_PREFIX . $from . '_gyd';
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['rate'] ) && is_numeric( $cached['rate'] ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$ttl_hours = (int) MMGWC_Settings::get( 'fx_cache_hours', '12' );
		if ( $ttl_hours <= 0 ) {
			$ttl_hours = 12;
		}
		$ttl = $ttl_hours * HOUR_IN_SECONDS;

		$urls = array(
			'https://latest.currency-api.pages.dev/v1/currencies/' . rawurlencode( $from ) . '.json',
			'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . rawurlencode( $from ) . '.json',
		);

		$last_error = '';
		foreach ( $urls as $url ) {
			$res = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 2,
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $res ) ) {
				$last_error = $res->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			if ( $code < 200 || $code >= 300 || $body === '' ) {
				$last_error = 'HTTP ' . $code;
				continue;
			}
			// Guard against absurdly large responses.
			if ( strlen( $body ) > 512 * 1024 ) {
				$last_error = 'Rate feed response too large';
				continue;
			}

			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				$last_error = 'Invalid JSON';
				continue;
			}

			$date = isset( $data['date'] ) && is_string( $data['date'] ) ? $data['date'] : '';
			$rates = $data[ $from ] ?? null;
			if ( ! is_array( $rates ) || ! isset( $rates['gyd'] ) || ! is_numeric( $rates['gyd'] ) ) {
				$last_error = 'Rate not found';
				continue;
			}

			$rate = (float) $rates['gyd'];
			if ( $rate < self::MIN_RATE_PER_UNIT || $rate > self::MAX_RATE_PER_UNIT ) {
				$last_error = 'Rate out of plausible range';
				MMGWC_Logger::warning( 'Rejected FX rate outside plausibility window', array(
					'currency' => strtoupper( $from ),
					'rate'     => $rate,
					'source'   => $url,
				) );
				continue;
			}

			$payload = array(
				'rate'       => $rate,
				'date'       => $date !== '' ? $date : gmdate( 'Y-m-d' ),
				'source'     => $url,
				'fetched_at' => time(),
				'cached'     => false,
			);

			set_transient( $cache_key, $payload, $ttl );
			return $payload;
		}

		// If fetch fails, try to return the last cached rate (even if stale).
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rate'] ) && is_numeric( $cached['rate'] ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		throw new RuntimeException( 'Could not fetch exchange rate for ' . strtoupper( $from ) . ' to GYD. ' . ( $last_error !== '' ? $last_error : '' ) );
	}

	/**
	 * Convert amount to GYD and round to nearest increment.
	 *
	 * @return array{raw:float,rounded:float,delta:float}
	 */
	public static function convert_and_round_to_gyd( float $amount, float $rate, int $round_to = 100 ): array {
		$raw = $amount * $rate;
		if ( $round_to <= 0 ) {
			$round_to = 100;
		}
		$rounded = round( $raw / $round_to ) * $round_to;
		$delta   = $rounded - $raw;
		return array(
			'raw'     => $raw,
			'rounded' => $rounded,
			'delta'   => $delta,
		);
	}
}
