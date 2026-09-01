<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles MMG merchant-initiated approval requests.
 *
 * An accepted initiation response is only a pending request. This class keeps
 * the WooCommerce order unpaid until an authenticated lookup matches the
 * immutable order snapshot and reports a completed payment.
 */
final class MMGWC_Initiated_Payments {
	private const CRON_HOOK = 'mmgwc_check_initiated_payment';
	private const ACTION_GROUP = 'mmg-checkout';
	private const START_LOCK_TTL = 90;
	private const SCHEDULE_LOCK_TTL = 30;
	private const SCHEDULING_DISABLED_OPTION = 'mmgwc_initiated_scheduling_disabled';
	private const PENDING_PREFIX = 'mmgwc_initiated_pending_';
	private const PENDING_MARKER = 'mmgwc_initiated_pending_queue_present';
	private const PENDING_RECOVERY_CURSOR = 'mmgwc_initiated_pending_recovery_cursor';
	private static $owned_start_locks = array();

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_scheduled_payment' ), 10, 1 );
		add_action( 'wp_ajax_mmgwc_initiated_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_mmgwc_initiated_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'woocommerce_thankyou_mmg_initiated', array( __CLASS__, 'render_customer_status' ), 10, 1 );
		if ( (string) get_option( self::PENDING_MARKER, 'no' ) === 'yes' ) {
			add_action( 'init', array( __CLASS__, 'recover_pending_orders' ), 1 );
		}
	}

	public static function activate(): void {
		delete_option( self::SCHEDULING_DISABLED_OPTION );
		update_option( self::PENDING_MARKER, 'yes', false );
		self::recover_pending_orders();
	}

	public static function deactivate(): void {
		update_option( self::SCHEDULING_DISABLED_OPTION, 'yes', false );
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::CRON_HOOK );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Empty args and group make Action Scheduler cancel every pending
			// action with this plugin-specific hook, regardless of order ID.
			as_unschedule_all_actions( self::CRON_HOOK );
		}
	}

	/**
	 * Send one approval request or resume an existing request.
	 *
	 * If the POST result is uncertain, a durable marker blocks blind retries.
	 * MMG does not document an idempotency key or lookup by merchant operation.
	 *
	 * @return array|WP_Error
	 */
	public static function start( WC_Order $order, string $customer_account ) {
		if ( (string) MMGWC_Settings::get( 'initiated_enabled', 'no' ) !== 'yes' ) {
			return new WP_Error( 'mmgwc_initiated_disabled', 'MMG app approval requests are disabled.' );
		}
		if ( ! $order->needs_payment() ) {
			return new WP_Error( 'mmgwc_order_not_payable', 'This order no longer accepts payment.' );
		}

		$customer_account = MMGWC_API::normalise_customer_account( $customer_account );
		if ( $customer_account === '' ) {
			return new WP_Error( 'mmgwc_invalid_customer_account', 'Enter the seven-digit phone number registered to the MMG account.' );
		}

		$mode = MMGWC_Settings::get_mode();
		$config = MMGWC_Settings::get_config( $mode );
		$missing = MMGWC_Payment_Verifier::missing_api_fields( $config );
		if ( trim( (string) ( $config['credit_account_id'] ?? '' ) ) === '' ) {
			$missing[] = 'credit_account_id';
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'mmgwc_initiated_not_configured', 'MMG approval requests are not configured for the selected mode.' );
		}

		$existing = self::existing_request_result( $order );
		if ( $existing !== null ) {
			return $existing;
		}

		if ( ! self::acquire_start_lock( (int) $order->get_id() ) ) {
			return new WP_Error( 'mmgwc_initiated_in_progress', 'An MMG approval request is already being created. Please wait. Do not submit another request.' );
		}

		$request_started = false;
		$payment_state_lock_acquired = false;
		try {
			if ( ! MMGWC_Payment_Verifier::acquire_order_lock( (int) $order->get_id() ) ) {
				return new WP_Error( 'mmgwc_order_state_busy', 'This order is being updated. Wait a moment before sending an MMG approval request.' );
			}
			$payment_state_lock_acquired = true;

			// Re-read under the lock so two checkout submissions cannot both send.
			$locked_order = MMGWC_Payment_Context::fresh_order( (int) $order->get_id() );
			if ( ! $locked_order instanceof WC_Order ) {
				return new WP_Error( 'mmgwc_order_not_found', 'This order is no longer available.' );
			}
			$order = $locked_order;
			if ( ! $order->needs_payment() || $order->is_paid() ) {
				return new WP_Error( 'mmgwc_order_not_payable', 'This order no longer accepts payment.' );
			}
			if ( $order->get_payment_method() !== 'mmg_initiated' ) {
				return new WP_Error( 'mmgwc_payment_method_changed', 'The order payment method changed before the MMG approval request was sent.' );
			}
			$existing = self::existing_request_result( $order );
			if ( $existing !== null ) {
				return $existing;
			}

			$payment_context = MMGWC_Payment_Context::prepare(
				$order,
				$config,
				trim( (string) $config['credit_account_id'] ),
				false
			);
			$now = time();
			$correlation_id = wp_generate_uuid4();
			$order->delete_meta_data( MMGWC_META_INITIATED_REFERENCE );
			$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'initiating' );
			$order->update_meta_data( MMGWC_META_INITIATED_CORRELATION, $correlation_id );
			$order->update_meta_data( MMGWC_META_INITIATED_EXPIRES_AT, self::parse_expiry( '', $now ) );
			$order->update_meta_data( MMGWC_META_INITIATED_LAST_CHECK, 0 );
			$order->update_meta_data( MMGWC_META_INITIATED_ATTEMPTS, 0 );
			$order->update_meta_data( MMGWC_META_INITIATED_CUSTOMER_HINT, self::masked_account( $customer_account ) );
			$order->set_status( 'on-hold', 'MMG approval request started. The order is held until authenticated reconciliation reaches a final result.' );
			$saved_order_id = $order->save();
			if ( (int) $saved_order_id !== (int) $order->get_id() ) {
				return new WP_Error( 'mmgwc_initiated_state_not_saved', 'The MMG approval request was not sent because its order state could not be saved.' );
			}

			$prepared_order = MMGWC_Payment_Context::fresh_order( (int) $order->get_id() );
			$snapshot = is_array( $payment_context['snapshot'] ?? null ) ? $payment_context['snapshot'] : array();
			$postconditions_match = $prepared_order instanceof WC_Order
				&& ! $prepared_order->is_paid()
				&& $prepared_order->get_status() === 'on-hold'
				&& $prepared_order->get_payment_method() === 'mmg_initiated'
				&& (string) $prepared_order->get_meta( MMGWC_META_INITIATED_STATUS ) === 'initiating'
				&& hash_equals( $correlation_id, (string) $prepared_order->get_meta( MMGWC_META_INITIATED_CORRELATION ) )
				&& self::prepared_snapshot_matches_order( $prepared_order, $snapshot )
				&& (string) MMGWC_Settings::get( 'initiated_enabled', 'no' ) === 'yes';
			if ( ! $postconditions_match ) {
				if ( $prepared_order instanceof WC_Order ) {
					$order = $prepared_order;
					$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'review' );
					$order->save();
					$order->add_order_note( 'MMG approval request was not sent because the saved payment state changed before dispatch. The current order status and payment method were left unchanged.' );
				}
				return new WP_Error( 'mmgwc_initiated_state_changed', 'The MMG approval request was not sent because the order changed before dispatch.' );
			}
			$order = $prepared_order;

			$request_started = true;
			$response = MMGWC_API::initiate_payment(
				$config,
				$customer_account,
				(string) $payment_context['amount_decimal'],
				$correlation_id
			);
			if ( is_wp_error( $response ) ) {
				$reason = $response->get_error_code();
				if ( in_array( $reason, array( 'mmgwc_invalid_initiated_request', 'mmgwc_initiated_authentication_failed', 'mmgwc_initiated_account_locked', 'mmgwc_initiated_invalid_credentials', 'mmgwc_initiated_rejected' ), true ) ) {
					$request_started = false;
					self::mark_initiation_not_sent( $order, $reason );
					$message = in_array( $reason, array( 'mmgwc_initiated_account_locked', 'mmgwc_initiated_invalid_credentials' ), true )
						? $response->get_error_message()
						: 'MMG did not accept the approval request, so no request was sent to the app. Try again or choose MMG hosted checkout.';
					return new WP_Error( 'mmgwc_initiated_not_sent', $message );
				}
				self::mark_initiation_uncertain( $order, $correlation_id, $response->get_error_code() );
				return new WP_Error( 'mmgwc_initiation_uncertain', 'MMG did not return a final result. Do not try again while the store reviews the payment record.' );
			}

			$reference = MMGWC_API::initiated_reference( $response );
			$status = strtolower( self::response_value( $response, array( 'status', 'transactionStatus' ) ) );
			if ( is_wp_error( $reference ) || $status !== 'pending' ) {
				$reason = is_wp_error( $reference ) ? $reference->get_error_code() : 'unexpected_status';
				self::mark_initiation_uncertain( $order, $correlation_id, $reason );
				return new WP_Error( 'mmgwc_initiation_uncertain', 'MMG did not return a final approval result. Do not try again while the store reviews the payment record.' );
			}

			$expiry = self::parse_expiry( self::response_value( $response, array( 'expiryTime' ) ), $now );
			$order->update_meta_data( MMGWC_META_INITIATED_REFERENCE, (string) $reference );
			$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'pending' );
			$order->update_meta_data( MMGWC_META_INITIATED_EXPIRES_AT, $expiry );
			$order->save();
			$order->add_order_note( 'MMG approval request sent. The order remains unpaid until authenticated transaction lookup confirms settlement.' );
			if ( ! self::track_pending_order( (int) $order->get_id() ) ) {
				self::move_to_review( $order, 'The MMG approval request was accepted, but durable reconciliation could not be registered. Review the transaction in the merchant records.' );
				return new WP_Error( 'mmgwc_tracking_unavailable', 'MMG received the approval request, but automatic verification could not be registered. Do not try again. The store must review its merchant records.' );
			}
			self::schedule_check( (int) $order->get_id(), self::poll_interval(), false );

			return array( 'reference' => (string) $reference, 'reused' => false );
		} catch ( Throwable $e ) {
			if ( $request_started ) {
				$correlation_id = (string) $order->get_meta( MMGWC_META_INITIATED_CORRELATION );
				self::mark_initiation_uncertain( $order, $correlation_id, 'unexpected_error' );
				return new WP_Error( 'mmgwc_initiation_uncertain', 'MMG did not return a final result. Do not try again while the store reviews the payment record.' );
			}
			throw $e;
		} finally {
			if ( $payment_state_lock_acquired ) {
				MMGWC_Payment_Verifier::release_order_lock( (int) $order->get_id() );
			}
			self::release_start_lock( (int) $order->get_id() );
		}
	}

	/**
	 * Resume a durable request without sending another initiation to MMG.
	 *
	 * This is used when WooCommerce no longer considers an on-hold or closed
	 * order payable. A repeat checkout may restore reconciliation, but it must
	 * never create a second approval request.
	 *
	 * @return array|WP_Error
	 */
	public static function resume_existing_request( WC_Order $order ) {
		$result = self::existing_request_result( $order );
		if ( is_array( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $result ) && $result->get_error_code() === 'mmgwc_previous_request_review' ) {
			return array(
				'reference' => trim( (string) $order->get_meta( MMGWC_META_INITIATED_REFERENCE ) ),
				'reused' => true,
				'review' => true,
			);
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_Error( 'mmgwc_no_existing_request', 'This order no longer accepts payment and no existing MMG approval request was found.' );
	}

	/**
	 * Confirm that the exact request snapshot survived the preparation save.
	 */
	private static function prepared_snapshot_matches_order( WC_Order $order, array $snapshot ): bool {
		$expected = array(
			'mode' => MMGWC_META_MODE,
			'expected_amount' => MMGWC_META_EXPECTED_AMOUNT,
			'expected_currency' => MMGWC_META_EXPECTED_CURRENCY,
			'expected_merchant_id' => MMGWC_META_EXPECTED_MERCHANT_ID,
			'expected_order_total' => MMGWC_META_EXPECTED_ORDER_TOTAL,
			'expected_order_currency' => MMGWC_META_EXPECTED_ORDER_CURRENCY,
		);
		foreach ( $expected as $snapshot_key => $meta_key ) {
			if ( ! isset( $snapshot[ $snapshot_key ] ) || ! is_scalar( $snapshot[ $snapshot_key ] ) ) {
				return false;
			}
			if ( ! hash_equals( (string) $snapshot[ $snapshot_key ], (string) $order->get_meta( $meta_key ) ) ) {
				return false;
			}
		}
		return true;
	}

	public static function check_scheduled_payment( $order_id ): void {
		self::check_order( absint( $order_id ), false, true );
	}

	/**
	 * Restore jobs removed during plugin deactivation. The durable per-order
	 * records remain until reconciliation reaches a terminal state.
	 */
	public static function recover_pending_orders(): void {
		$limit = 100;
		$cursor = max( 0, (int) get_option( self::PENDING_RECOVERY_CURSOR, 0 ) );
		$keys = MMGWC_Atomic_Option::keys_with_prefix( self::PENDING_PREFIX, $limit, $cursor );
		if ( ! is_array( $keys ) ) {
			return;
		}
		if ( empty( $keys ) && $cursor > 0 ) {
			$cursor = 0;
			$keys = MMGWC_Atomic_Option::keys_with_prefix( self::PENDING_PREFIX, $limit, 0 );
			if ( ! is_array( $keys ) ) {
				return;
			}
		}
		foreach ( $keys as $key ) {
			if ( preg_match( '/^' . preg_quote( self::PENDING_PREFIX, '/' ) . '([1-9]\d*)$/', $key, $matches ) === 1 ) {
				self::schedule_check( (int) $matches[1], 5, false );
			}
		}
		$next_cursor = count( $keys ) < $limit ? 0 : $cursor + count( $keys );
		update_option( self::PENDING_RECOVERY_CURSOR, $next_cursor, false );
		// The marker remains set after first use so a concurrent tracker cannot be
		// stranded by a scan-and-clear race.
	}

	/**
	 * Run one authenticated reconciliation check.
	 *
	 * @return array Public status data only.
	 */
	public static function check_order( int $order_id, bool $respect_throttle = true, bool $from_scheduled_action = false ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			if ( self::is_tracked_pending_order( $order_id ) ) {
				self::schedule_check( $order_id, self::poll_interval(), $from_scheduled_action );
				return self::state( 'pending', 'The order could not be loaded. MMG reconciliation will retry automatically.' );
			}
			self::unschedule_order( $order_id );
			return self::state( 'invalid', 'This MMG approval request is not available.' );
		}
		$payment_method_changed = $order->get_payment_method() !== 'mmg_initiated';
		$stored_status = strtolower( trim( (string) $order->get_meta( MMGWC_META_INITIATED_STATUS ) ) );
		if ( in_array( $stored_status, array( 'initiating', 'initiation_uncertain', 'verification_failed', 'review', 'payment_confirmed_review' ), true ) ) {
			self::unschedule_order( $order_id );
			return self::pending_state( $order );
		}
		if ( in_array( $stored_status, self::failed_provider_statuses(), true ) && ! $order->needs_payment() ) {
			self::unschedule_order( $order_id );
			return $order->is_paid()
				? self::state( 'paid', 'This order is already paid. The MMG approval request was not completed.' )
				: self::state( 'failed', 'The MMG payment was not completed. Return to checkout to try again.' );
		}

		$reference = trim( (string) $order->get_meta( MMGWC_META_INITIATED_REFERENCE ) );
		if ( preg_match( '/^\d{1,64}$/', $reference ) !== 1 ) {
			self::unschedule_order( $order_id );
			return self::state( 'review', 'The MMG approval result is unclear. Do not pay again. The store will review it.' );
		}
		$processed = trim( (string) $order->get_meta( MMGWC_META_PROCESSED_TXN_ID ) );
		if ( $order->is_paid() && $processed !== '' && hash_equals( $processed, $reference ) ) {
			self::unschedule_order( $order_id );
			return self::state( 'paid', 'Payment confirmed. Your order is paid.' );
		}

		$now = time();
		$expiry = (int) $order->get_meta( MMGWC_META_INITIATED_EXPIRES_AT );
		$last_check = (int) $order->get_meta( MMGWC_META_INITIATED_LAST_CHECK );
		$completed_attempts = (int) $order->get_meta( MMGWC_META_INITIATED_ATTEMPTS );
		$interval = self::retry_delay( $completed_attempts );
		if ( $respect_throttle && $last_check > 0 && ( $now - $last_check ) < $interval ) {
			return self::pending_state( $order );
		}

		if ( ! MMGWC_Payment_Verifier::acquire_order_lock( $order_id ) ) {
			self::schedule_check( $order_id, self::retry_delay( (int) $order->get_meta( MMGWC_META_INITIATED_ATTEMPTS ) ), $from_scheduled_action );
			return self::pending_state( $order );
		}

		try {
			$locked_order = MMGWC_Payment_Context::fresh_order( $order_id );
			if ( ! $locked_order instanceof WC_Order ) {
				self::schedule_check( $order_id, self::retry_delay( (int) $order->get_meta( MMGWC_META_INITIATED_ATTEMPTS ) ), $from_scheduled_action );
				return self::state( 'pending', 'The order could not be refreshed. MMG reconciliation will retry automatically.' );
			}
			$order = $locked_order;
			$payment_method_changed = $order->get_payment_method() !== 'mmg_initiated';
			$reference = trim( (string) $order->get_meta( MMGWC_META_INITIATED_REFERENCE ) );
			if ( preg_match( '/^\d{1,64}$/', $reference ) !== 1 ) {
				self::unschedule_order( $order_id );
				return self::state( 'review', 'The MMG approval result is unclear. Do not pay again. The store will review it.' );
			}
			$processed = trim( (string) $order->get_meta( MMGWC_META_PROCESSED_TXN_ID ) );
			if ( $order->is_paid() && $processed !== '' && hash_equals( $processed, $reference ) ) {
				self::unschedule_order( $order_id );
				return self::state( 'paid', 'Payment confirmed. Your order is paid.' );
			}

			$mode = (string) $order->get_meta( MMGWC_META_MODE );
			$config = MMGWC_Settings::get_config( $mode );
			$attempts = (int) $order->get_meta( MMGWC_META_INITIATED_ATTEMPTS ) + 1;
			$order->update_meta_data( MMGWC_META_INITIATED_LAST_CHECK, $now );
			$order->update_meta_data( MMGWC_META_INITIATED_ATTEMPTS, $attempts );
			$order->save();

			// A final authenticated lookup is always attempted before any local
			// expiry or non-payable WooCommerce state is made terminal.
			$lookup = MMGWC_API::transaction_lookup( $config, $reference );
			if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
				self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
				return self::state( 'pending', 'MMG verification is still running. Do not approve or send another payment.' );
			}
			$post_lookup_order = MMGWC_Payment_Context::fresh_order( $order_id );
			if ( ! $post_lookup_order instanceof WC_Order ) {
				self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
				return self::state( 'pending', 'The order could not be refreshed after MMG lookup. Reconciliation will retry automatically.' );
			}
			$order = $post_lookup_order;
			$payment_method_changed = $order->get_payment_method() !== 'mmg_initiated';
			$verification_snapshot = $payment_method_changed ? array( 'payment_method' => 'mmg_initiated' ) : array();
			$expiry = (int) $order->get_meta( MMGWC_META_INITIATED_EXPIRES_AT );
			$expired = $expiry > 0 && $now >= $expiry;
			$post_expiry_deadline = $expiry > 0 ? $expiry + self::post_expiry_grace_seconds() : 0;
			if ( ! is_array( $lookup ) ) {
				if ( $expired && $post_expiry_deadline > 0 && $now >= $post_expiry_deadline ) {
					self::move_to_review( $order, 'MMG lookup was unavailable at the end of the approval window. Payment status requires manual review.', $payment_method_changed );
					self::unschedule_order( $order_id );
					return self::state( 'review', 'MMG did not provide a final status. Do not pay again. The store will review it.' );
				}
				$order->update_meta_data( MMGWC_META_INITIATED_STATUS, $expired ? 'post_expiry_lookup_unavailable' : 'lookup_unavailable' );
				$order->save();
				self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
				return $expired
					? self::state( 'pending', 'The approval window ended, but MMG final verification is temporarily unavailable. Do not pay again while the store keeps checking.' )
					: self::state( 'pending', 'MMG has not confirmed the payment yet. Keep the MMG app open and approve the request.' );
			}

			$order->update_meta_data( '_mmg_lookup_last', wp_json_encode( MMGWC_Payment_Verifier::lookup_record( $lookup ) ) );
			$lookup_status = strtolower( self::response_value( $lookup, array( 'transactionStatus', 'status' ) ) );
			$order->update_meta_data( MMGWC_META_INITIATED_STATUS, $lookup_status !== '' ? $lookup_status : 'unknown' );
			$order->save();

			if ( in_array( $lookup_status, array( 'successful', 'completed' ), true ) ) {
				$verification = MMGWC_Payment_Verifier::verify_lookup_for_order( $order, $reference, $lookup, $config, $verification_snapshot );
				if ( ! empty( $verification['valid'] ) || ! empty( $verification['settlement_verified'] ) ) {
					if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
						self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
						return self::state( 'pending', 'MMG payment verification will retry automatically. Do not pay again.' );
					}
					$pre_completion_order = MMGWC_Payment_Context::fresh_order( $order_id );
					if ( ! $pre_completion_order instanceof WC_Order ) {
						self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
						return self::state( 'pending', 'The order could not be refreshed before completion. Reconciliation will retry automatically.' );
					}
					$order = $pre_completion_order;
					$payment_method_changed = $order->get_payment_method() !== 'mmg_initiated';
					$verification_snapshot = $payment_method_changed ? array( 'payment_method' => 'mmg_initiated' ) : array();
					$verification = MMGWC_Payment_Verifier::verify_lookup_for_order( $order, $reference, $lookup, $config, $verification_snapshot );
				}
				if ( $payment_method_changed && ( ! empty( $verification['valid'] ) || ! empty( $verification['settlement_verified'] ) ) ) {
					$review_transaction_id = (string) ( $verification['transaction_id'] ?? $reference );
					$order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $review_transaction_id );
					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:payment_method_changed_review' );
					$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'payment_confirmed_review' );
					$order->save();
					$order->add_order_note( 'MMG settled the approval request after the order payment method changed. Transaction ID: ' . $review_transaction_id . '. The current order status and payment method were preserved for manual review.' );
					self::unschedule_order( $order_id );
					return self::state( 'review', 'MMG confirmed payment after the order payment method changed. Do not pay again. The store will review it.' );
				}
				if ( ! empty( $verification['settlement_verified'] ) ) {
					$review_transaction_id = (string) $verification['transaction_id'];
					$order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $review_transaction_id );
					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
					$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'payment_confirmed_review' );
					$order->save();
					$order->add_order_note( 'MMG reported an additional settled approval transaction after the order was already paid or closed. Transaction ID: ' . $review_transaction_id . '. Review the merchant records for a possible duplicate payment.' );
					self::unschedule_order( $order_id );
					return self::state( 'review', 'This order was already paid and MMG reported another payment. Do not pay again. The store will review it.' );
				}
				if ( empty( $verification['valid'] ) ) {
					$reason = isset( $verification['code'] ) ? sanitize_key( (string) $verification['code'] ) : 'verification_failed';
					$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'failed:' . $reason );
					$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'verification_failed' );
					$order->save();
					$order->add_order_note( 'MMG reported a completed approval payment, but order verification failed: ' . $reason . '. The order was not marked as paid.' );
					MMGWC_Logger::error( 'MMG initiated payment verification failed', array( 'order_id' => $order_id, 'reason' => $reason ) );
					self::unschedule_order( $order_id );
					return self::state( 'review', 'MMG reported a payment, but the store could not verify it. Do not pay again. The store will review it.' );
				}

				$transaction_id = (string) $verification['transaction_id'];
				$order->update_meta_data( MMGWC_META_TXN_ID, $transaction_id );
				$order->update_meta_data( MMGWC_META_PROCESSED_TXN_ID, $transaction_id );
				$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified' );
				$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'paid' );
				$order->save();
				if ( ! $order->is_paid() ) {
					if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
						self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
						return self::state( 'pending', 'Payment was verified and WooCommerce completion will be retried. Do not pay again.' );
					}
					$completion_order = MMGWC_Payment_Context::fresh_order( $order_id );
					if ( ! $completion_order instanceof WC_Order ) {
						self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
						return self::state( 'pending', 'The order could not be refreshed before completion. Reconciliation will retry automatically.' );
					}
					$completion_method_changed = $completion_order->get_payment_method() !== 'mmg_initiated';
					$completion_snapshot = $completion_method_changed ? array( 'payment_method' => 'mmg_initiated' ) : array();
					$completion_verification = MMGWC_Payment_Verifier::verify_lookup_for_order( $completion_order, $reference, $lookup, $config, $completion_snapshot );
					if ( $completion_method_changed && ( ! empty( $completion_verification['valid'] ) || ! empty( $completion_verification['settlement_verified'] ) ) ) {
						$completion_order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
						$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:payment_method_changed_review' );
						$completion_order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'payment_confirmed_review' );
						$completion_order->save();
						$completion_order->add_order_note( 'MMG settlement was verified after the payment method changed. Transaction ID: ' . $transaction_id . '. The current order state was preserved.' );
						self::unschedule_order( $order_id );
						return self::state( 'review', 'MMG confirmed payment after the payment method changed. Do not pay again. The store will review it.' );
					}
					if ( ! empty( $completion_verification['settlement_verified'] ) ) {
						$completion_order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
						$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
						$completion_order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'payment_confirmed_review' );
						$completion_order->save();
						$completion_order->add_order_note( 'MMG settlement was verified after the order closed. Transaction ID: ' . $transaction_id . '. The closed status was preserved.' );
						self::unschedule_order( $order_id );
						return self::state( 'review', 'MMG confirmed payment after the order closed. Do not pay again. The store will review it.' );
					}
					if ( empty( $completion_verification['valid'] ) ) {
						$reason = sanitize_key( (string) ( $completion_verification['code'] ?? 'order_changed' ) );
						$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:' . $reason );
						$completion_order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'review' );
						$completion_order->save();
						$completion_order->add_order_note( 'MMG settlement could not complete after a final order-state check: ' . $reason . '. Manual review is required.' );
						self::unschedule_order( $order_id );
						return self::state( 'review', 'MMG reported payment, but the final order-state check failed. Do not pay again.' );
					}
					$order = $completion_order;
					$order->payment_complete( $transaction_id );
				}

				$fresh_order = MMGWC_Payment_Context::fresh_order( $order_id );
				if ( ! $fresh_order instanceof WC_Order ) {
					self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
					return self::state( 'pending', 'WooCommerce completion could not be confirmed. Reconciliation will retry automatically.' );
				}
				if ( $fresh_order->is_paid() ) {
					if ( empty( $verification['idempotent'] ) ) {
						$fresh_order->add_order_note( 'MMG approval payment authenticated and verified. Transaction ID: ' . $transaction_id );
					}
					self::unschedule_order( $order_id );
					return self::state( 'paid', 'Payment confirmed. Your order is paid.' );
				}

				$fresh_order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'payment_confirmed_review' );
				$fresh_order->save();
				$fresh_order->add_order_note( 'MMG payment was verified, but WooCommerce did not enter a paid status. Manual order review is required.' );
				self::unschedule_order( $order_id );
				return self::state( 'review', 'Payment was confirmed, but the order status needs store review. Do not pay again.' );
			}

			if ( in_array( $lookup_status, self::failed_provider_statuses(), true ) ) {
				if ( $payment_method_changed ) {
					$order->update_meta_data( MMGWC_META_INITIATED_STATUS, $lookup_status );
					$order->save();
					$order->add_order_note( 'The MMG approval request ended with status ' . $lookup_status . ' after the order payment method changed. The current order state was preserved.' );
					self::unschedule_order( $order_id );
					return self::state( 'failed', 'The MMG approval request was not completed. The current order state was preserved.' );
				}
				$order_status = (string) $order->get_status();
				if ( $order->is_paid() || in_array( $order_status, array( 'refunded', 'trash', 'cancelled', 'failed' ), true ) ) {
					self::unschedule_order( $order_id );
					if ( $order->is_paid() ) {
						return self::state( 'paid', 'This order is already paid. The MMG approval request was not completed.' );
					}
					return in_array( $order_status, array( 'cancelled', 'failed' ), true )
						? self::state( 'failed', 'The order is closed and the MMG approval request was not completed.' )
						: self::state( 'review', 'This order is closed. The MMG approval request was not completed.' );
				}
				$setting = $lookup_status === 'cancelled' ? 'status_cancelled' : 'status_failed';
				$fallback = $lookup_status === 'cancelled' ? 'cancelled' : 'failed';
				$order->update_status( self::safe_unpaid_status( $setting, $fallback ), 'MMG approval payment ended with status: ' . $lookup_status . '.' );
				self::unschedule_order( $order_id );
				return self::state( 'failed', 'The MMG payment was not completed. Return to checkout to try again.' );
			}

			if ( $expired && $post_expiry_deadline > 0 && $now < $post_expiry_deadline ) {
				self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
				return self::state( 'pending', 'The approval window ended. Do not pay again while the store performs final MMG checks.' );
			}

			if ( $expired ) {
				self::move_to_review( $order, 'MMG approval remained unconfirmed at the end of the reconciliation window.', $payment_method_changed );
				self::unschedule_order( $order_id );
				return self::state( 'review', 'MMG did not provide a final status. Do not pay again. The store will review it.' );
			}

			self::schedule_check( $order_id, self::retry_delay( $attempts ), $from_scheduled_action );
			return self::pending_state( $order );
		} finally {
			MMGWC_Payment_Verifier::release_order_lock( $order_id );
		}
	}

	public static function ajax_status(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? wc_clean( wp_unslash( (string) $_POST['order_key'] ) ) : '';
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'mmg_initiated' ) {
			wp_send_json_error( array( 'message' => 'Order not found.' ), 404 );
		}
		$expected_key = (string) $order->get_order_key();
		if ( $order_key === '' || $expected_key === '' || ! hash_equals( $expected_key, $order_key ) ) {
			wp_send_json_error( array( 'message' => 'Order access could not be verified.' ), 403 );
		}

		$status = self::check_order( $order_id, true, false );
		$fresh_order = wc_get_order( $order_id );
		if ( $fresh_order instanceof WC_Order ) {
			$order = $fresh_order;
		}
		$status['retry_after_seconds'] = self::retry_delay( (int) $order->get_meta( MMGWC_META_INITIATED_ATTEMPTS ) );
		$status['redirect'] = $order->get_checkout_order_received_url();
		wp_send_json_success( $status );
	}

	public static function render_customer_status( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'mmg_initiated' || $order->is_paid() ) {
			return;
		}

		wp_enqueue_style( 'mmgwc-gateway-frontend', MMGWC_PLUGIN_URL . 'assets/css/gateway.css', array(), MMGWC_VERSION );
		if ( self::customer_must_not_approve( $order ) ) {
			?>
			<section class="mmgwc-initiated-status" data-state="review">
				<h2>MMG payment request closed</h2>
				<p class="mmgwc-initiated-status__message" role="status">Do not approve this request. The order is closed and the store is reviewing its merchant records for any late payment.</p>
			</section>
			<?php
			return;
		}

		wp_enqueue_script( 'mmgwc-initiated-status', MMGWC_PLUGIN_URL . 'assets/js/initiated-status.js', array(), MMGWC_VERSION, true );
		$expiry = (int) $order->get_meta( MMGWC_META_INITIATED_EXPIRES_AT );
		$hint = (string) $order->get_meta( MMGWC_META_INITIATED_CUSTOMER_HINT );
		$initial = self::pending_state( $order );
		?>
		<section
			class="mmgwc-initiated-status"
			data-mmgwc-initiated-status
			data-state="<?php echo esc_attr( (string) $initial['state'] ); ?>"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
			data-order-key="<?php echo esc_attr( (string) $order->get_order_key() ); ?>"
			data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-expiry="<?php echo esc_attr( (string) $expiry ); ?>"
		>
			<h2>Approve the payment in MMG</h2>
			<p>Open the MMG app for <?php echo esc_html( $hint !== '' ? $hint : 'your phone number' ); ?> and approve the request.</p>
			<p class="mmgwc-initiated-status__message" role="status" aria-live="polite"><?php echo esc_html( (string) $initial['message'] ); ?></p>
			<p class="mmgwc-initiated-status__time" data-mmgwc-expiry></p>
		</section>
		<?php
	}

	private static function existing_request_result( WC_Order $order ) {
		$status = strtolower( trim( (string) $order->get_meta( MMGWC_META_INITIATED_STATUS ) ) );
		$reference = trim( (string) $order->get_meta( MMGWC_META_INITIATED_REFERENCE ) );
		if ( in_array( $status, array( 'initiating', 'initiation_uncertain', 'verification_failed', 'review', 'payment_confirmed_review' ), true ) ) {
			return new WP_Error( 'mmgwc_previous_request_review', 'A previous MMG request has no final result. Do not try again while the store reviews the payment record.' );
		}
		if ( preg_match( '/^\d{1,64}$/', $reference ) === 1 && ! in_array( $status, self::failed_provider_statuses(), true ) ) {
			if ( ! $order->is_paid() && $order->needs_payment() && ! in_array( (string) $order->get_status(), array( 'cancelled', 'failed', 'refunded', 'trash' ), true ) ) {
				$order->update_status( 'on-hold', 'Existing MMG approval request restored. The order is held until authenticated reconciliation reaches a final result.' );
			}
			if ( ! self::track_pending_order( (int) $order->get_id() ) ) {
				return new WP_Error( 'mmgwc_tracking_unavailable', 'The existing MMG request could not be registered for automatic verification. Do not try again. The store must review its merchant records.' );
			}
			self::schedule_check( (int) $order->get_id(), 5, false );
			return array( 'reference' => $reference, 'reused' => true );
		}
		return null;
	}

	private static function pending_state( WC_Order $order ): array {
		$status = strtolower( trim( (string) $order->get_meta( MMGWC_META_INITIATED_STATUS ) ) );
		if ( $order->is_paid() ) {
			return self::state( 'review', 'This order is already paid. Do not approve another payment request. The store is reviewing its merchant records for a possible duplicate.' );
		}
		if ( self::customer_must_not_approve( $order ) ) {
			return self::state( 'review', 'Do not approve this request. The order is closed and the store is reviewing its merchant records for any late payment.' );
		}
		if ( in_array( $status, array( 'initiating', 'initiation_uncertain' ), true ) ) {
			return self::state( 'review', 'MMG did not return a final result. Do not pay again while the store reviews the payment record.' );
		}
		if ( in_array( $status, array( 'verification_failed', 'review' ), true ) ) {
			return self::state( 'review', 'MMG did not provide a safely verifiable final result. Do not pay again. The store will review it.' );
		}
		if ( $status === 'payment_confirmed_review' ) {
			return self::state( 'review', 'Payment was confirmed, but the order status needs store review. Do not pay again.' );
		}
		return self::state( 'pending', 'Waiting for MMG confirmation. Open the MMG app and approve the request.' );
	}

	private static function customer_must_not_approve( WC_Order $order ): bool {
		return in_array( (string) $order->get_status(), array( 'cancelled', 'failed', 'refunded', 'trash' ), true );
	}

	private static function mark_initiation_uncertain( WC_Order $order, string $correlation_id, string $reason ): void {
		$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'initiation_uncertain' );
		$note = 'MMG approval request result was uncertain. Do not send another request. Correlation ID: ' . $correlation_id . '. Reason: ' . sanitize_key( $reason ) . '.';
		if ( ! $order->is_paid() && ! in_array( (string) $order->get_status(), array( 'on-hold', 'cancelled', 'failed', 'refunded', 'trash' ), true ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->save();
			$order->add_order_note( $note );
		}
		MMGWC_Logger::warning( 'MMG approval request result uncertain', array( 'order_id' => $order->get_id(), 'correlation_id' => $correlation_id, 'reason' => sanitize_key( $reason ) ) );
	}

	/**
	 * Restore a retryable unpaid order after MMG explicitly rejects the API
	 * request. No approval request exists in this branch.
	 */
	private static function mark_initiation_not_sent( WC_Order $order, string $reason ): void {
		$order->delete_meta_data( MMGWC_META_INITIATED_REFERENCE );
		$order->delete_meta_data( MMGWC_META_INITIATED_CORRELATION );
		$order->delete_meta_data( MMGWC_META_INITIATED_EXPIRES_AT );
		$order->delete_meta_data( MMGWC_META_INITIATED_LAST_CHECK );
		$order->delete_meta_data( MMGWC_META_INITIATED_ATTEMPTS );
		$order->delete_meta_data( MMGWC_META_INITIATED_CUSTOMER_HINT );
		$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'not_sent' );
		if ( ! $order->is_paid() && $order->get_status() === 'on-hold' ) {
			$order->set_status( 'pending', 'MMG rejected the approval request before it was created. The order remains unpaid and can be retried.' );
		}
		$order->save();
		MMGWC_Logger::warning( 'MMG approval request was not sent', array( 'order_id' => $order->get_id(), 'reason' => sanitize_key( $reason ) ) );
	}

	private static function move_to_review( WC_Order $order, string $note, bool $preserve_order_status = false ): void {
		if ( $preserve_order_status || in_array( (string) $order->get_status(), array( 'refunded', 'trash', 'cancelled', 'failed' ), true ) ) {
			$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'review' );
			$order->save();
			$order->add_order_note( $note . ' The closed order status was not changed.' );
			return;
		}
		$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'review' );
		$order->save();
		if ( ! $order->is_paid() && $order->get_status() !== 'on-hold' ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
	}

	private static function failed_provider_statuses(): array {
		return array( 'failed', 'cancelled', 'declined', 'expired', 'rejected', 'reversed' );
	}

	private static function safe_unpaid_status( string $setting, string $fallback ): string {
		$status = trim( (string) MMGWC_Settings::get( $setting, $fallback ) );
		return MMGWC_Payment_Context::safe_unpaid_status( $status, $fallback );
	}

	private static function parse_expiry( string $value, int $now ): int {
		$parsed = $value !== '' ? strtotime( $value ) : false;
		$fallback = $now + max( 60, (int) apply_filters( 'mmgwc_initiated_default_expiry_seconds', 600 ) );
		$maximum = $now + max( 300, (int) apply_filters( 'mmgwc_initiated_max_expiry_seconds', 3600 ) );
		if ( ! is_int( $parsed ) || $parsed <= $now ) {
			return min( $fallback, $maximum );
		}
		return min( $parsed, $maximum );
	}

	private static function masked_account( string $account ): string {
		return '***' . substr( $account, -4 );
	}

	private static function response_value( array $response, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $response ) && is_scalar( $response[ $key ] ) ) {
				return trim( (string) $response[ $key ] );
			}
		}
		return '';
	}

	private static function poll_interval(): int {
		return max( 15, min( 120, (int) apply_filters( 'mmgwc_initiated_poll_interval_seconds', 20 ) ) );
	}

	private static function retry_delay( int $completed_attempts ): int {
		$base = self::poll_interval();
		$factor = 1 << min( 3, max( 0, intdiv( max( 0, $completed_attempts ), 5 ) ) );
		return min( 120, $base * $factor );
	}

	private static function post_expiry_grace_seconds(): int {
		return max( 60, min( 3600, (int) apply_filters( 'mmgwc_initiated_post_expiry_grace_seconds', 900 ) ) );
	}

	private static function schedule_check( int $order_id, int $delay, bool $from_scheduled_action ): void {
		if ( $order_id <= 0 || ! self::track_pending_order( $order_id ) ) {
			return;
		}
		if ( self::scheduling_disabled() ) {
			return;
		}

		$timestamp = time() + max( 5, $delay );
		$args = array( $order_id );
		$lock_key = 'mmgwc_initiated_schedule_lock_' . $order_id;
		$lock = MMGWC_Atomic_Option::acquire_lock( $lock_key, self::SCHEDULE_LOCK_TTL );
		if ( $lock === '' ) {
			// A crashed scheduler can leave this short lock behind. Keep a WP-Cron
			// safety successor so the payment is not left without reconciliation.
			if ( ! self::scheduling_disabled() && ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
				wp_schedule_single_event( $timestamp, self::CRON_HOOK, $args );
			}
			if ( self::scheduling_disabled() ) {
				self::unschedule_order( $order_id, true );
			}
			return;
		}

		try {
			$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK, $args );
			if ( ! $scheduled && function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_schedule_single_action' ) ) {
				$pending = as_get_scheduled_actions(
					array(
						'hook' => self::CRON_HOOK,
						'args' => $args,
						'group' => self::ACTION_GROUP,
						'status' => class_exists( 'ActionScheduler_Store' ) ? ActionScheduler_Store::STATUS_PENDING : 'pending',
						'per_page' => 1,
					),
					'ids'
				);
				if ( empty( $pending ) ) {
					$scheduled = (int) as_schedule_single_action( $timestamp, self::CRON_HOOK, $args, self::ACTION_GROUP ) > 0;
				} else {
					$scheduled = true;
				}
			} elseif ( ! $scheduled && function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
				$next = as_next_scheduled_action( self::CRON_HOOK, $args, self::ACTION_GROUP );
				if ( $next === false || ( $from_scheduled_action && $next === true ) ) {
					$scheduled = (int) as_schedule_single_action( $timestamp, self::CRON_HOOK, $args, self::ACTION_GROUP ) > 0;
				} else {
					$scheduled = true;
				}
			}

			if ( ! $scheduled ) {
				if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
					$scheduled = (bool) wp_schedule_single_event( $timestamp, self::CRON_HOOK, $args );
				} else {
					$scheduled = true;
				}
			}

			// Deactivation can run after the first marker check but before a job is
			// created. Remove that job if the marker changed during this operation.
			if ( self::scheduling_disabled() ) {
				self::unschedule_order( $order_id, true );
			}

		} finally {
			MMGWC_Atomic_Option::release_lock( $lock_key, $lock );
		}
	}

	private static function scheduling_disabled(): bool {
		return (string) get_option( self::SCHEDULING_DISABLED_OPTION, 'no' ) === 'yes';
	}

	private static function acquire_start_lock( int $order_id ): bool {
		$key = 'mmgwc_initiated_start_lock_' . $order_id;
		$owned_value = MMGWC_Atomic_Option::acquire_lock( $key, self::START_LOCK_TTL );
		if ( $owned_value === '' ) {
			return false;
		}
		self::$owned_start_locks[ $order_id ] = $owned_value;
		return true;
	}

	private static function release_start_lock( int $order_id ): void {
		if ( ! isset( self::$owned_start_locks[ $order_id ] ) ) {
			return;
		}
		MMGWC_Atomic_Option::release_lock( 'mmgwc_initiated_start_lock_' . $order_id, (string) self::$owned_start_locks[ $order_id ] );
		unset( self::$owned_start_locks[ $order_id ] );
	}

	private static function track_pending_order( int $order_id ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}
		$key = self::PENDING_PREFIX . $order_id;
		$value = (string) $order_id;
		// A marker written first may be cleaned later if reservation fails. The
		// opposite order could strand a payment after a process crash.
		update_option( self::PENDING_MARKER, 'yes', false );
		if ( (string) get_option( self::PENDING_MARKER, 'no' ) !== 'yes' ) {
			return false;
		}
		$tracked = MMGWC_Atomic_Option::reserve( $key, $value );
		return $tracked;
	}

	private static function is_tracked_pending_order( int $order_id ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}
		$value = MMGWC_Atomic_Option::reserved_value( self::PENDING_PREFIX . $order_id );
		return is_string( $value ) && hash_equals( (string) $order_id, $value );
	}

	private static function unschedule_order( int $order_id, bool $preserve_pending_record = false ): void {
		$args = array( $order_id );
		wp_clear_scheduled_hook( self::CRON_HOOK, $args );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, $args, self::ACTION_GROUP );
		}
		if ( ! $preserve_pending_record && $order_id > 0 ) {
			MMGWC_Atomic_Option::delete_if_owned( self::PENDING_PREFIX . $order_id, (string) $order_id );
			// The persistent recovery marker is removed only during uninstall.
		}
	}

	private static function state( string $state, string $message ): array {
		return array(
			'state' => $state,
			'message' => $message,
		);
	}
}
