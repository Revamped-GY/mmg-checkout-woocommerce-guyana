<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the invariants required before an MMG transaction can pay an order.
 *
 * The encrypted browser callback is correlation data, not sufficient proof of
 * settlement. A successful order update also requires an authenticated MMG
 * transaction lookup that matches the immutable checkout snapshot.
 */
final class MMGWC_Payment_Verifier {
	private const PAID_STATUSES = array( 'successful', 'completed' );
	private const PAYMENT_METHODS = array( 'mmg_checkout', 'mmg_initiated' );
	private const LOCK_TTL_SECONDS = 600;
	private static $owned_order_locks = array();

	public static function acquire_order_lock( int $order_id ): bool {
		$key = 'mmgwc_payment_verify_lock_' . $order_id;
		$owned_value = MMGWC_Atomic_Option::acquire_lock( $key, self::LOCK_TTL_SECONDS );
		if ( $owned_value === '' ) {
			return false;
		}
		self::$owned_order_locks[ $order_id ] = $owned_value;
		return true;
	}

	public static function release_order_lock( int $order_id ): void {
		if ( ! isset( self::$owned_order_locks[ $order_id ] ) ) {
			return;
		}
		MMGWC_Atomic_Option::release_lock( 'mmgwc_payment_verify_lock_' . $order_id, (string) self::$owned_order_locks[ $order_id ] );
		unset( self::$owned_order_locks[ $order_id ] );
	}

	/**
	 * Extend the lease after external I/O, but only if this request still owns it.
	 */
	public static function renew_order_lock( int $order_id ): bool {
		if ( ! isset( self::$owned_order_locks[ $order_id ] ) ) {
			return false;
		}
		$key = 'mmgwc_payment_verify_lock_' . $order_id;
		$renewed = MMGWC_Atomic_Option::renew_lock( $key, (string) self::$owned_order_locks[ $order_id ] );
		if ( $renewed === '' ) {
			unset( self::$owned_order_locks[ $order_id ] );
			return false;
		}
		self::$owned_order_locks[ $order_id ] = $renewed;
		return true;
	}

	public static function missing_api_fields( array $config ): array {
		$required = array(
			'mwallet_base_url',
			'api_key',
			'wss_mid',
			'wss_mkey',
			'wss_msecret',
			'password',
		);
		$missing = array();
		foreach ( $required as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_scalar( $config[ $key ] ) || trim( (string) $config[ $key ] ) === '' ) {
				$missing[] = $key;
			}
		}

		if ( ! in_array( 'mwallet_base_url', $missing, true ) ) {
			if ( ! class_exists( 'MMGWC_API' ) || ! MMGWC_API::is_valid_base_url( trim( (string) $config['mwallet_base_url'] ), $config ) ) {
				$missing[] = 'approved HTTPS mwallet_base_url';
			}
		}

		return $missing;
	}

	public static function verify_callback( WC_Order $order, array $response, array $lookup, array $config, array $snapshot = array() ): array {
		$verification_payment_method = isset( $snapshot['payment_method'] ) && is_string( $snapshot['payment_method'] )
			? trim( $snapshot['payment_method'] )
			: $order->get_payment_method();
		if ( $verification_payment_method !== 'mmg_checkout' ) {
			return self::failure( 'wrong_payment_method', 'The order does not use MMG Checkout.' );
		}

		$merchant_transaction_id = self::callback_value(
			$response,
			array( 'merchantTransactionId', 'MerchantTransactionId', 'merchantTransactionID', 'MerchantTransactionID' )
		);
		$stored_merchant_transaction_id = self::snapshot_value( $order, $snapshot, 'merchant_transaction_id', MMGWC_META_MERCHANT_TXN_ID );

		if ( $merchant_transaction_id === '' || $stored_merchant_transaction_id === '' || ! hash_equals( $stored_merchant_transaction_id, $merchant_transaction_id ) ) {
			return self::failure( 'merchant_transaction_mismatch', 'The callback does not match the exact checkout transaction created for this order.' );
		}

		$result_code = self::callback_value( $response, array( 'resultCode', 'ResultCode', 'transactionStatus', 'transactionStatusCode' ) );
		if ( $result_code !== '0' ) {
			return self::failure( 'callback_not_successful', 'The callback does not report a successful payment.' );
		}

		$order_mode = self::snapshot_value( $order, $snapshot, 'mode', MMGWC_META_MODE );
		$decrypted_mode = self::callback_value( $response, array( '_mmgwc_decrypted_mode' ) );
		if ( ! in_array( $order_mode, array( 'sandbox', 'live' ), true ) || $decrypted_mode !== $order_mode ) {
			return self::failure( 'mode_mismatch', 'The callback credential mode does not match the order checkout mode.' );
		}

		$transaction_id = self::callback_value(
			$response,
			array( 'transactionId', 'TransactionId', 'transactionReference', 'transactionReceipt', 'executionId' )
		);

		return self::verify_lookup_for_order( $order, $transaction_id, $lookup, $config, $snapshot );
	}

	public static function verify_lookup_for_order( WC_Order $order, string $transaction_id, array $lookup, array $config, array $snapshot = array() ): array {
		$live_payment_method = $order->get_payment_method();
		$verification_payment_method = isset( $snapshot['payment_method'] ) && is_string( $snapshot['payment_method'] )
			? trim( $snapshot['payment_method'] )
			: $live_payment_method;
		$payment_method_changed = $live_payment_method !== $verification_payment_method;
		if ( ! in_array( $verification_payment_method, self::PAYMENT_METHODS, true ) ) {
			return self::failure( 'wrong_payment_method', 'The order does not use an MMG payment method.' );
		}

		$transaction_id = trim( $transaction_id );
		if ( preg_match( '/^\d{1,64}$/', $transaction_id ) !== 1 ) {
			return self::failure( 'invalid_transaction_id', 'The MMG transaction ID is missing or invalid.' );
		}

		$missing = self::missing_api_fields( $config );
		if ( ! empty( $missing ) ) {
			return self::failure( 'lookup_not_configured', 'Authenticated MMG transaction verification is not configured.' );
		}

		$order_mode = self::snapshot_value( $order, $snapshot, 'mode', MMGWC_META_MODE );
		$config_mode = isset( $config['mode'] ) ? trim( (string) $config['mode'] ) : '';
		if ( ! in_array( $order_mode, array( 'sandbox', 'live' ), true ) || $config_mode !== $order_mode ) {
			return self::failure( 'mode_mismatch', 'The MMG API mode does not match the order checkout mode.' );
		}

		$lookup_ids = self::lookup_transaction_ids( $lookup );
		if ( count( $lookup_ids ) > 1 ) {
			return self::failure( 'lookup_transaction_ambiguous', 'The authenticated lookup returned conflicting transaction identifiers.' );
		}
		if ( empty( $lookup_ids ) || ! hash_equals( $transaction_id, $lookup_ids[0] ) ) {
			return self::failure( 'lookup_transaction_mismatch', 'The authenticated lookup returned a different transaction ID.' );
		}

		$status = strtolower( self::lookup_value( $lookup, array( 'transactionStatus', 'transaction_status', 'status' ) ) );
		if ( ! in_array( $status, self::PAID_STATUSES, true ) ) {
			return self::failure( 'lookup_not_paid', 'The authenticated lookup does not report a completed payment.' );
		}

		$expected_amount = self::normalise_amount( self::snapshot_value( $order, $snapshot, 'expected_amount', MMGWC_META_EXPECTED_AMOUNT ) );
		$lookup_amount = self::normalise_amount( self::lookup_value( $lookup, array( 'amount' ) ) );
		if ( $expected_amount === null || $lookup_amount === null || ! hash_equals( $expected_amount, $lookup_amount ) ) {
			return self::failure( 'amount_mismatch', 'The authenticated MMG amount does not match the checkout amount.' );
		}

		$expected_currency = strtoupper( self::snapshot_value( $order, $snapshot, 'expected_currency', MMGWC_META_EXPECTED_CURRENCY ) );
		$lookup_currency = strtoupper( self::lookup_value( $lookup, array( 'currency' ) ) );
		if ( $expected_currency !== 'GYD' || $lookup_currency !== $expected_currency ) {
			return self::failure( 'currency_mismatch', 'The authenticated MMG currency does not match the checkout currency.' );
		}

		$expected_merchant = self::snapshot_value( $order, $snapshot, 'expected_merchant_id', MMGWC_META_EXPECTED_MERCHANT_ID );
		$configured_destination = $verification_payment_method === 'mmg_initiated'
			? trim( (string) ( $config['credit_account_id'] ?? '' ) )
			: trim( (string) ( $config['merchant_id'] ?? '' ) );
		if ( $expected_merchant === '' || $configured_destination === '' || ! hash_equals( $expected_merchant, $configured_destination ) ) {
			return self::failure( 'merchant_configuration_mismatch', 'The order merchant snapshot does not match the current MMG merchant configuration.' );
		}
		$transaction_scope = self::transaction_scope( $order_mode, $config );

		$lookup_merchants = self::lookup_merchant_ids( $lookup );
		if ( ! in_array( $expected_merchant, $lookup_merchants, true ) ) {
			return self::failure( 'merchant_mismatch', 'The authenticated MMG transaction was credited to a different merchant.' );
		}

		$expected_order_total = self::normalise_amount( self::snapshot_value( $order, $snapshot, 'expected_order_total', MMGWC_META_EXPECTED_ORDER_TOTAL ) );
		$current_order_total = self::normalise_amount( (string) $order->get_total() );
		$expected_order_currency = strtoupper( self::snapshot_value( $order, $snapshot, 'expected_order_currency', MMGWC_META_EXPECTED_ORDER_CURRENCY ) );
		$current_order_currency = strtoupper( trim( (string) $order->get_currency() ) );
		if ( $expected_order_total === null || $current_order_total === null || ! hash_equals( $expected_order_total, $current_order_total ) || $expected_order_currency === '' || $current_order_currency !== $expected_order_currency ) {
			return self::failure( 'order_changed', 'The WooCommerce order total or currency changed after MMG checkout started.' );
		}

		if ( self::transaction_used_by_another_order( $transaction_id, (int) $order->get_id(), $order_mode ) ) {
			return self::failure( 'transaction_reused', 'The MMG transaction ID is already attached to another order.' );
		}

		$processed_transaction_id = trim( (string) $order->get_meta( MMGWC_META_PROCESSED_TXN_ID ) );
		if ( ! $order->needs_payment() ) {
			$order_status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
			$closed_status = in_array( $order_status, array( 'refunded', 'trash', 'cancelled', 'failed' ), true );
			$allow_new_initiated_late_settlement = $verification_payment_method === 'mmg_initiated'
				&& ! $payment_method_changed
				&& $processed_transaction_id === ''
				&& in_array( $order_status, array( 'cancelled', 'failed' ), true );
			if ( $closed_status && ! $allow_new_initiated_late_settlement ) {
				if ( ! self::claim_transaction( $transaction_scope, $transaction_id, (int) $order->get_id() ) ) {
					return self::failure( 'transaction_reused', 'The MMG transaction ID is already claimed by another order.' );
				}
				return self::settlement_review( $transaction_id );
			}
			if ( $payment_method_changed ) {
				if ( ! self::claim_transaction( $transaction_scope, $transaction_id, (int) $order->get_id() ) ) {
					return self::failure( 'transaction_reused', 'The MMG transaction ID is already claimed by another order.' );
				}
				return self::settlement_review( $transaction_id );
			}
			if ( $processed_transaction_id !== '' && hash_equals( $processed_transaction_id, $transaction_id ) && $order->is_paid() ) {
				if ( ! self::claim_transaction( $transaction_scope, $transaction_id, (int) $order->get_id() ) ) {
					return self::failure( 'transaction_reused', 'The MMG transaction ID is already claimed by another order.' );
				}
				return self::success( $transaction_id, true );
			}
			$allow_initiated_completion_retry = $verification_payment_method === 'mmg_initiated'
				&& $order_status === 'on-hold'
				&& $processed_transaction_id !== ''
				&& hash_equals( $processed_transaction_id, $transaction_id );
			if ( $allow_initiated_completion_retry ) {
				if ( ! self::claim_transaction( $transaction_scope, $transaction_id, (int) $order->get_id() ) ) {
					return self::failure( 'transaction_reused', 'The MMG transaction ID is already claimed by another order.' );
				}
				return self::success( $transaction_id, true );
			}
			if ( $order->is_paid() ) {
				if ( ! self::claim_transaction( $transaction_scope, $transaction_id, (int) $order->get_id() ) ) {
					return self::failure( 'transaction_reused', 'The MMG transaction ID is already claimed by another order.' );
				}
				return self::settlement_review( $transaction_id );
			}
			if ( $verification_payment_method !== 'mmg_initiated' || $processed_transaction_id !== '' ) {
				return self::failure( 'unexpected_order_state', 'The order no longer expects this payment.' );
			}
		}

		if ( $processed_transaction_id !== '' && ! hash_equals( $processed_transaction_id, $transaction_id ) ) {
			return self::failure( 'different_transaction_processed', 'The order already records a different MMG payment.' );
		}
		if ( ! self::claim_transaction( $transaction_scope, $transaction_id, (int) $order->get_id() ) ) {
			return self::failure( 'transaction_reused', 'The MMG transaction ID is already claimed by another order.' );
		}

		return self::success( $transaction_id, false );
	}

	public static function callback_record( array $response ): array {
		$record = array();
		$map = array(
			'merchantTransactionId' => array( 'merchantTransactionId', 'MerchantTransactionId', 'merchantTransactionID', 'MerchantTransactionID' ),
			'transactionId' => array( 'transactionId', 'TransactionId', 'transactionReference', 'transactionReceipt', 'executionId' ),
			'resultCode' => array( 'resultCode', 'ResultCode', 'transactionStatus', 'transactionStatusCode' ),
			'resultMessage' => array( 'resultMessage', 'ResultMessage', 'message' ),
		);
		foreach ( $map as $target => $keys ) {
			$value = self::callback_value( $response, $keys );
			if ( $value !== '' ) {
				$record[ $target ] = substr( $value, 0, 191 );
			}
		}
		return $record;
	}

	public static function lookup_record( array $lookup ): array {
		$record = array();
		foreach ( array( 'amount', 'currency', 'transactionStatus', 'status', 'requestDate', 'creationDate', 'transactionReference', 'transactionReceipt', 'executionId' ) as $key ) {
			if ( isset( $lookup[ $key ] ) && is_scalar( $lookup[ $key ] ) ) {
				$record[ $key ] = substr( trim( (string) $lookup[ $key ] ), 0, 191 );
			}
		}
		return $record;
	}

	private static function normalise_amount( string $amount ) {
		$amount = str_replace( ',', '', trim( $amount ) );
		if ( preg_match( '/^(\d+)(?:\.(\d+))?$/', $amount, $matches ) !== 1 ) {
			return null;
		}

		$whole = ltrim( $matches[1], '0' );
		$whole = $whole === '' ? '0' : $whole;
		$fraction = isset( $matches[2] ) ? $matches[2] : '';
		if ( strlen( $fraction ) > 2 && trim( substr( $fraction, 2 ), '0' ) !== '' ) {
			return null;
		}
		$fraction = substr( str_pad( $fraction, 2, '0' ), 0, 2 );
		return $whole . '.' . $fraction;
	}

	private static function callback_value( array $response, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $response ) && is_scalar( $response[ $key ] ) ) {
				return trim( (string) $response[ $key ] );
			}
		}
		return '';
	}

	private static function snapshot_value( WC_Order $order, array $snapshot, string $snapshot_key, string $meta_key ): string {
		if ( array_key_exists( $snapshot_key, $snapshot ) && is_scalar( $snapshot[ $snapshot_key ] ) ) {
			return trim( (string) $snapshot[ $snapshot_key ] );
		}
		return trim( (string) $order->get_meta( $meta_key ) );
	}

	private static function lookup_value( array $lookup, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $lookup ) && is_scalar( $lookup[ $key ] ) ) {
				return trim( (string) $lookup[ $key ] );
			}
		}
		return '';
	}

	private static function lookup_transaction_ids( array $lookup ): array {
		$ids = array();
		foreach ( array( 'transactionId', 'transaction_id', 'transactionReference', 'transactionReceipt', 'executionId' ) as $key ) {
			if ( isset( $lookup[ $key ] ) && is_scalar( $lookup[ $key ] ) ) {
				$value = trim( (string) $lookup[ $key ] );
				if ( preg_match( '/^\d{1,64}$/', $value ) === 1 ) {
					$ids[] = $value;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function lookup_merchant_ids( array $lookup ): array {
		$ids = array();
		foreach ( array( 'creditParty', 'credit_party' ) as $party_key ) {
			if ( empty( $lookup[ $party_key ] ) || ! is_array( $lookup[ $party_key ] ) ) {
				continue;
			}
			foreach ( $lookup[ $party_key ] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$key = isset( $item['key'] ) ? strtolower( trim( (string) $item['key'] ) ) : '';
				$value = isset( $item['value'] ) && is_scalar( $item['value'] ) ? trim( (string) $item['value'] ) : '';
				if ( in_array( $key, array( 'accountid', 'merchant', 'msisdn' ), true ) && $value !== '' ) {
					$ids[] = $value;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function transaction_used_by_another_order( string $transaction_id, int $current_order_id, string $mode ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return true;
		}

		foreach ( array( MMGWC_META_PROCESSED_TXN_ID, MMGWC_META_TXN_ID, MMGWC_META_REVIEW_TXN_ID ) as $meta_key ) {
			$order_ids = wc_get_orders(
				array(
					'limit' => 10,
					'return' => 'ids',
					'meta_key' => $meta_key,
					'meta_value' => $transaction_id,
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'key' => MMGWC_META_MODE,
							'value' => $mode,
							'compare' => '=',
						),
					),
				)
			);
			if ( ! is_array( $order_ids ) ) {
				return true;
			}
			foreach ( $order_ids as $order_id ) {
				if ( (int) $order_id !== $current_order_id ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Atomically reserve one MMG transaction for one WooCommerce order.
	 *
	 * The unique option_name index is reserved with a direct insert-only database
	 * operation. The claim is retained because a settled transaction must never
	 * be reused by another order.
	 */
	private static function claim_transaction( string $scope, string $transaction_id, int $order_id ): bool {
		$key = 'mmgwc_transaction_claim_' . hash( 'sha256', $scope . '|' . $transaction_id );
		return MMGWC_Atomic_Option::reserve( $key, (string) $order_id );
	}

	private static function transaction_scope( string $mode, array $config ): string {
		$base_url = strtolower( rtrim( trim( (string) ( $config['mwallet_base_url'] ?? '' ) ), '/' ) );
		$api_tenant = trim( (string) ( $config['wss_mid'] ?? '' ) );
		return hash( 'sha256', $mode . '|' . $base_url . '|' . $api_tenant );
	}

	private static function success( string $transaction_id, bool $idempotent ): array {
		return array(
			'valid' => true,
			'code' => 'verified',
			'message' => 'The MMG payment passed authenticated order verification.',
			'transaction_id' => $transaction_id,
			'idempotent' => $idempotent,
		);
	}

	private static function settlement_review( string $transaction_id ): array {
		return array(
			'valid' => false,
			'code' => 'order_already_paid',
				'message' => 'MMG reports another settled transaction for an order that is already paid or closed.',
			'transaction_id' => $transaction_id,
			'idempotent' => false,
			'settlement_verified' => true,
		);
	}

	private static function failure( string $code, string $message ): array {
		return array(
			'valid' => false,
			'code' => $code,
			'message' => $message,
			'transaction_id' => '',
			'idempotent' => false,
		);
	}
}
