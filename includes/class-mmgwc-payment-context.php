<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the immutable amount and merchant snapshot shared by both MMG rails.
 */
final class MMGWC_Payment_Context {
	/**
	 * Reload an order after invalidating WooCommerce request and datastore caches.
	 * Use this only after acquiring the plugin's per-order payment-state lock.
	 */
	public static function fresh_order( int $order_id ) {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		if ( function_exists( 'wc_get_container' ) ) {
			try {
				$order_cache_class = '\\Automattic\\WooCommerce\\Caches\\OrderCache';
				if ( class_exists( $order_cache_class ) ) {
					$order_cache = wc_get_container()->get( $order_cache_class );
					if ( is_object( $order_cache ) && is_callable( array( $order_cache, 'remove' ) ) ) {
						$order_cache->remove( $order_id );
					}
				}

				$hpos_store_class = '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore';
				if ( class_exists( $hpos_store_class ) ) {
					$hpos_store = wc_get_container()->get( $hpos_store_class );
					if ( is_object( $hpos_store ) && is_callable( array( $hpos_store, 'clear_cached_data' ) ) ) {
						$hpos_store->clear_cached_data( array( $order_id ) );
					}
				}
			} catch ( Throwable $exception ) {
				// Continue with the portable cache invalidation paths below.
			}
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $order_id, 'orders' );
			wp_cache_delete( $order_id, 'order_objects' );
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $order_id );
		}

		return wc_get_order( $order_id );
	}

	/**
	 * Return the currency that the current checkout will actually charge.
	 *
	 * A pay-for-order request can use the currency stored on an older order,
	 * which can differ from the store's current currency.
	 */
	public static function current_checkout_currency(): string {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() && function_exists( 'get_query_var' ) && function_exists( 'wc_get_order' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof WC_Order ) {
					$currency = strtoupper( trim( (string) $order->get_currency() ) );
					if ( $currency !== '' ) {
						return $currency;
					}
				}
			}
		}

		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( trim( (string) get_woocommerce_currency() ) ) : '';
	}

	public static function can_process_currency( string $currency ): bool {
		$currency = strtoupper( trim( $currency ) );
		return $currency === 'GYD' || ( $currency !== '' && (string) MMGWC_Settings::get( 'currency_conversion', 'no' ) === 'yes' );
	}

	/**
	 * Return hosted-checkout fields that are empty in the active credential set.
	 * Availability and redirect creation must use the same requirement list.
	 *
	 * @return string[]
	 */
	public static function missing_hosted_fields( array $config ): array {
		$missing = array();
		foreach ( array( 'checkout_url', 'merchant_id', 'client_id', 'merchant_name', 'secret_key', 'public_key', 'private_key' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_scalar( $config[ $key ] ) || trim( (string) $config[ $key ] ) === '' ) {
				$missing[] = $key;
			}
		}
		if ( ! in_array( 'checkout_url', $missing, true ) ) {
			$parts = wp_parse_url( trim( (string) $config['checkout_url'] ) );
			$mode = isset( $config['mode'] ) ? trim( (string) $config['mode'] ) : '';
			$default_hosts = $mode === 'live' ? array( 'mmgpg.mymmg.gy' ) : array( 'mmgpg.mmgtest.net' );
			$allowed_hosts = apply_filters( 'mmgwc_allowed_checkout_hosts', $default_hosts, $mode, $config );
			$allowed_hosts = is_array( $allowed_hosts ) ? array_map( 'strtolower', array_map( 'strval', $allowed_hosts ) ) : $default_hosts;
			$host = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
			if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' || $host === '' || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( $host, $allowed_hosts, true ) ) {
				$missing[] = 'valid checkout_url';
			}
		}
		return $missing;
	}

	/**
	 * Return an unpaid WooCommerce status without accepting paid or refunded
	 * values through either prefixed constants or unprefixed settings.
	 */
	public static function safe_unpaid_status( $status, string $fallback ): string {
		$status = is_string( $status ) ? trim( $status ) : '';
		$fallback = self::normalise_order_status_slug( $fallback );
		$status = self::normalise_order_status_slug( $status );
		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$paid_statuses = array_map( array( __CLASS__, 'normalise_order_status_slug' ), $paid_statuses );
		$protected_statuses = array( 'refunded', 'trash', 'auto-draft', 'checkout-draft', 'draft', 'inherit', 'future', 'private', 'publish' );
		if ( $status === '' || in_array( $status, $protected_statuses, true ) || in_array( $status, $paid_statuses, true ) || in_array( $status, array( 'processing', 'completed' ), true ) ) {
			return $fallback;
		}
		return $status;
	}

	public static function normalise_order_status_slug( $status ): string {
		$status = is_string( $status ) ? trim( $status ) : '';
		return strpos( $status, 'wc-' ) === 0 ? substr( $status, 3 ) : $status;
	}

	/**
	 * Reconstruct the immutable verification snapshot for a hosted checkout
	 * created before version 2.16.0 began reserving one at redirect time.
	 *
	 * The legacy plugin retained the exact merchant transaction ID, credential
	 * mode, checkout URL and conversion evidence. The merchant ID is recovered
	 * from that stored URL. Converted amounts are recovered from the stored FX
	 * metadata. A GYD order uses its current total because the legacy plugin did
	 * not store a separate GYD amount. Authenticated lookup and the normal final
	 * order-state check still have to match every returned field.
	 */
	public static function legacy_hosted_snapshot( WC_Order $order, string $merchant_transaction_id ): ?array {
		$merchant_transaction_id = trim( $merchant_transaction_id );
		$stored_transaction_id = trim( (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID ) );
		if (
			$merchant_transaction_id === ''
			|| strlen( $merchant_transaction_id ) > 191
			|| $stored_transaction_id === ''
			|| ! hash_equals( $stored_transaction_id, $merchant_transaction_id )
			|| $order->get_payment_method() !== 'mmg_checkout'
		) {
			return null;
		}

		$mode = trim( (string) $order->get_meta( MMGWC_META_MODE ) );
		$last_mode = trim( (string) $order->get_meta( '_mmgwc_last_checkout_url_mode' ) );
		$initiated_at = (int) $order->get_meta( '_mmg_initiated_at' );
		$last_url_at = (int) $order->get_meta( '_mmgwc_last_checkout_url_at' );
		$checkout_url = trim( (string) $order->get_meta( '_mmgwc_last_checkout_url' ) );
		$legacy_transaction_id = (string) $order->get_id() . '-' . (string) $initiated_at;
		if (
			! in_array( $mode, array( 'sandbox', 'live' ), true )
			|| $last_mode !== $mode
			|| $initiated_at <= 0
			|| ! hash_equals( $legacy_transaction_id, $merchant_transaction_id )
			|| $last_url_at < $initiated_at
			|| $checkout_url === ''
		) {
			return null;
		}

		$url_parts = wp_parse_url( $checkout_url );
		if ( ! is_array( $url_parts ) || ! isset( $url_parts['query'] ) || ! is_string( $url_parts['query'] ) ) {
			return null;
		}
		$query = array();
		wp_parse_str( $url_parts['query'], $query );
		$merchant_id = $query['merchantId'] ?? '';
		if ( ! is_scalar( $merchant_id ) ) {
			return null;
		}
		$merchant_id = trim( (string) $merchant_id );
		if ( $merchant_id === '' || strlen( $merchant_id ) > 191 ) {
			return null;
		}

		$order_currency = strtoupper( trim( (string) $order->get_currency() ) );
		$order_total = wc_format_decimal( $order->get_total(), 2 );
		$original_currency = strtoupper( trim( (string) $order->get_meta( MMGWC_META_ORIGINAL_CURRENCY ) ) );
		$original_total = trim( (string) $order->get_meta( MMGWC_META_ORIGINAL_TOTAL ) );
		$converted_amount = trim( (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD ) );

		if ( $original_currency !== '' && $original_currency !== 'GYD' ) {
			if ( ! is_numeric( $original_total ) || (float) $original_total <= 0 || ! is_numeric( $converted_amount ) || (float) $converted_amount <= 0 ) {
				return null;
			}
			$expected_order_total = wc_format_decimal( $original_total, 2 );
			$expected_order_currency = $original_currency;
			$expected_amount = wc_format_decimal( $converted_amount, 2 );
			if ( ! hash_equals( $expected_order_currency, $order_currency ) || ! hash_equals( $expected_order_total, $order_total ) ) {
				return null;
			}
		} else {
			if ( $order_currency !== 'GYD' || ! is_numeric( $order_total ) || (float) $order_total <= 0 ) {
				return null;
			}
			$expected_order_total = $order_total;
			$expected_order_currency = 'GYD';
			$expected_amount = $order_total;
		}

		return array(
			'version' => 1,
			'order_id' => (int) $order->get_id(),
			'merchant_transaction_id' => $merchant_transaction_id,
			'created_at' => $initiated_at,
			'mode' => $mode,
			'expected_amount' => $expected_amount,
			'expected_currency' => 'GYD',
			'expected_merchant_id' => $merchant_id,
			'expected_order_total' => $expected_order_total,
			'expected_order_currency' => $expected_order_currency,
			'legacy_checkout' => true,
		);
	}

	public static function prepare( WC_Order $order, array $config, string $expected_merchant_id, bool $persist = true ): array {
		$total = (float) $order->get_total();
		$order_currency = strtoupper( (string) $order->get_currency() );
		$mmg_total = $total;
		if ( $total <= 0 ) {
			throw new RuntimeException( 'MMG requires an order total greater than zero.' );
		}

		if ( $order_currency !== 'GYD' ) {
			if ( ! self::can_process_currency( $order_currency ) ) {
				throw new RuntimeException( 'This store is using ' . $order_currency . '. MMG requires GYD. Enable Currency conversion in MMG Checkout settings or switch the store currency to GYD.' );
			}

			$rate_info = MMGWC_FX::get_rate_to_gyd( $order_currency, false );
			$conversion = MMGWC_FX::convert_and_round_to_gyd( $total, (float) $rate_info['rate'], 100 );
			$mmg_total = (float) $conversion['rounded'];
			$order->update_meta_data( MMGWC_META_ORIGINAL_CURRENCY, $order_currency );
			$order->update_meta_data( MMGWC_META_ORIGINAL_TOTAL, wc_format_decimal( $total, 2 ) );
			$order->update_meta_data( MMGWC_META_FX_RATE, (string) $rate_info['rate'] );
			$order->update_meta_data( MMGWC_META_FX_DATE, (string) $rate_info['date'] );
			$order->update_meta_data( MMGWC_META_MMG_AMOUNT_GYD_RAW, wc_format_decimal( (float) $conversion['raw'], 2 ) );
			$order->update_meta_data( MMGWC_META_MMG_AMOUNT_GYD, (string) (int) round( $mmg_total ) );
			$order->update_meta_data( MMGWC_META_MMG_ROUNDING_DELTA, wc_format_decimal( (float) $conversion['delta'], 2 ) );
		} else {
			$order->delete_meta_data( MMGWC_META_ORIGINAL_CURRENCY );
			$order->delete_meta_data( MMGWC_META_ORIGINAL_TOTAL );
			$order->delete_meta_data( MMGWC_META_FX_RATE );
			$order->delete_meta_data( MMGWC_META_FX_DATE );
			$order->delete_meta_data( MMGWC_META_MMG_AMOUNT_GYD_RAW );
			$order->delete_meta_data( MMGWC_META_MMG_AMOUNT_GYD );
			$order->delete_meta_data( MMGWC_META_MMG_ROUNDING_DELTA );
		}

		$amount_decimal = wc_format_decimal( $mmg_total, 2 );
		$amount_request = abs( $mmg_total - round( $mmg_total ) ) < 0.00001
			? (string) (int) round( $mmg_total )
			: $amount_decimal;

		$expected_order_total = wc_format_decimal( $order->get_total(), 2 );
		$mode = (string) $config['mode'];
		$order->update_meta_data( MMGWC_META_EXPECTED_AMOUNT, $amount_decimal );
		$order->update_meta_data( MMGWC_META_EXPECTED_CURRENCY, 'GYD' );
		$order->update_meta_data( MMGWC_META_EXPECTED_MERCHANT_ID, $expected_merchant_id );
		$order->update_meta_data( MMGWC_META_EXPECTED_ORDER_TOTAL, $expected_order_total );
		$order->update_meta_data( MMGWC_META_EXPECTED_ORDER_CURRENCY, $order_currency );
		$order->update_meta_data( MMGWC_META_MODE, $mode );
		$order->delete_meta_data( MMGWC_META_VERIFICATION_STATUS );
		if ( $persist ) {
			$order->save();
		}

		return array(
			'amount_decimal' => $amount_decimal,
			'amount_request' => $amount_request,
			'snapshot' => array(
				'mode' => $mode,
				'expected_amount' => $amount_decimal,
				'expected_currency' => 'GYD',
				'expected_merchant_id' => $expected_merchant_id,
				'expected_order_total' => $expected_order_total,
				'expected_order_currency' => $order_currency,
			),
		);
	}
}
