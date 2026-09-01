<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Admin {
	public static function init(): void {
		// Admin notices should always run for admins.
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'configuration_notice' ) );

		// Order tools (verify payment, resend link) can be disabled by Feature Manager.
		if ( class_exists( 'MMGWC_Features' ) && ! MMGWC_Features::is_enabled( 'order_tools' ) ) {
			return;
		}
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_order_verify_ui' ) );
		add_action( 'admin_post_mmgwc_verify_payment', array( __CLASS__, 'handle_verify_payment' ) );
		add_action( 'wp_ajax_mmgwc_verify_payment_ajax', array( __CLASS__, 'handle_verify_payment_ajax' ) );
		add_action( 'admin_post_mmgwc_resend_payment', array( __CLASS__, 'handle_resend_payment' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_order_admin_assets' ) );
	}

	public static function render_order_verify_ui( $order ): void {
		if ( class_exists( 'MMGWC_Features' ) ) {
			if ( ! MMGWC_Features::is_enabled( 'order_tools' ) ) {
				return;
			}
			if ( ! current_user_can( MMGWC_Features::cap_for( 'order_tools' ) ) ) {
				return;
			}
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$payment_method = (string) $order->get_payment_method();
		if ( ! in_array( $payment_method, array( 'mmg_checkout', 'mmg_initiated' ), true ) ) {
			return;
		}

		$txn_id = (string) $order->get_meta( MMGWC_META_TXN_ID );
		$merchant_txn_id = $payment_method === 'mmg_initiated'
			? (string) $order->get_meta( MMGWC_META_INITIATED_REFERENCE )
			: (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID );
		$result_code = (string) $order->get_meta( MMGWC_META_RESULT_CODE );
		$result_message = (string) $order->get_meta( MMGWC_META_RESULT_MESSAGE );
		$last_verified = (string) $order->get_meta( MMGWC_META_LAST_VERIFIED_AT );
		$last_verified_text = $last_verified !== '' ? gmdate( 'Y-m-d H:i:s', (int) $last_verified ) . ' UTC' : 'Never';

		echo '<div class="order_data_column" style="padding:12px 0">';
		echo '<h3>MMG Payment</h3>';
		echo '<p style="margin:0 0 8px 0">Payment reference: <code>' . esc_html( $merchant_txn_id !== '' ? $merchant_txn_id : 'N/A' ) . '</code><br>';
		echo 'Transaction ID: <code>' . esc_html( $txn_id !== '' ? $txn_id : 'N/A' ) . '</code><br>';
		echo 'Result: <code>' . esc_html( $result_code !== '' ? $result_code : 'N/A' ) . '</code> ' . esc_html( $result_message ) . '<br>';
		echo 'Last verified: ' . esc_html( $last_verified_text ) . '</p>';

		$action_url = admin_url( 'admin-post.php' );
		$ajax_nonce = wp_create_nonce( 'mmgwc_verify_payment_' . $order->get_id() );
		echo '<form id="mmgwc-verify-form" class="mmgwc-verify-form" method="post" action="' . esc_url( $action_url ) . '" style="margin:0" data-order-id="' . esc_attr( (string) $order->get_id() ) . '" data-ajax-action="mmgwc_verify_payment_ajax" data-ajax-nonce="' . esc_attr( $ajax_nonce ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_verify_payment">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		wp_nonce_field( 'mmgwc_verify_payment_' . $order->get_id() );

		echo '<p style="margin:0 0 8px 0">';
		echo '<label for="mmgwc_txn_id" style="display:block; margin-bottom:4px">Transaction ID to verify</label>';
		echo '<input type="text" id="mmgwc_txn_id" name="txn_id" value="' . esc_attr( $txn_id !== '' ? $txn_id : ( $payment_method === 'mmg_initiated' ? $merchant_txn_id : '' ) ) . '" style="width:100%" placeholder="Paste the MMG transactionId">';
		echo '</p>';

		echo '<p style="margin:0">';
		echo '<button type="submit" id="mmgwc-verify-button" class="button">Verify payment</button>';
		echo ' <span class="description">Requires MMG Transaction Lookup API credentials and verifies transaction ID, amount, currency, merchant and status.</span>';
		echo '</p>';
		echo '<div id="mmgwc-verify-result" class="mmgwc-verify-result" style="margin-top:8px;"></div>';
		echo '</form>';

		// Resend payment link (pending orders).
		if ( $payment_method === 'mmg_checkout' && $order->needs_payment() ) {
			$resend_url = admin_url( 'admin-post.php' );
			echo '<hr style="margin:12px 0;">';
			echo '<h4 style="margin:0 0 8px 0">Resend payment link</h4>';
			echo '<p style="margin:0 0 8px 0">Use this if the customer did not complete payment or closed the tab before returning. This generates a fresh MMG payment link and emails it to the customer.</p>';

			// Show a safe "Pay for order" link (always available).
			$pay_for_order_url = $order->get_checkout_payment_url( true );
			echo '<p style="margin:0 0 8px 0"><strong>Pay for order link:</strong> <a href="' . esc_url( $pay_for_order_url ) . '" target="_blank" rel="noopener">' . esc_html( $pay_for_order_url ) . '</a></p>';

			// Show last generated direct MMG link for this admin user (temporary).
			$tk = 'mmgwc_admin_payment_link_' . (string) $order->get_id() . '_' . (string) get_current_user_id();
			$tmp = get_transient( $tk );
			if ( is_array( $tmp ) && ! empty( $tmp['mmg_url'] ) ) {
				echo '<p style="margin:0 0 8px 0"><strong>Latest generated MMG link (temporary):</strong><br><a href="' . esc_url( (string) $tmp['mmg_url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $tmp['mmg_url'] ) . '</a></p>';
			}

			echo '<form method="post" action="' . esc_url( $resend_url ) . '">';
			echo '<input type="hidden" name="action" value="mmgwc_resend_payment">';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
			wp_nonce_field( 'mmgwc_resend_payment_' . $order->get_id() );
			echo '<p style="margin:0 0 8px 0">';
			echo '<label style="display:inline-flex; align-items:center; gap:6px;"><input type="checkbox" name="send_email" value="1" checked> Send to customer email (' . esc_html( (string) $order->get_billing_email() ) . ')</label>';
			echo '</p>';
			echo '<p style="margin:0">';
			echo '<button type="submit" class="button button-primary">Resend payment link</button>';
			echo ' <span class="description">Generates a new MMG payment URL and optionally emails it to the customer.</span>';
			echo '</p>';
		echo '</form>';
		}

		echo '</div>';
	}

	public static function enqueue_order_admin_assets( string $hook = '' ): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}
		$id = (string) $screen->id;
		// Classic order edit screen: shop_order. HPOS screen: woocommerce_page_wc-orders.
		if ( strpos( $id, 'shop_order' ) === false && strpos( $id, 'wc-orders' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'mmgwc-admin-order',
			MMGWC_PLUGIN_URL . 'assets/js/admin-order.js',
			array( 'jquery' ),
			MMGWC_VERSION,
			true
		);

		wp_localize_script(
			'mmgwc-admin-order',
			'MMGWCAdminOrder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18nVerifying' => 'Verifying payment...',
				'i18nVerified' => 'Verification complete.',
				'i18nError' => 'Verification failed.',
			)
		);
	}

	public static function handle_verify_payment_ajax(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => 'Missing order_id.' ), 400 );
		}

		// Nonce first, then capability checks.
		check_ajax_referer( 'mmgwc_verify_payment_' . $order_id, 'nonce' );

		if ( class_exists( 'MMGWC_Features' ) ) {
			if ( ! MMGWC_Features::is_enabled( 'order_tools' ) || ! current_user_can( MMGWC_Features::cap_for( 'order_tools' ) ) ) {
				wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
			}
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found.' ), 404 );
		}
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		if ( ! in_array( $order->get_payment_method(), array( 'mmg_checkout', 'mmg_initiated' ), true ) ) {
			wp_send_json_error( array( 'message' => 'This order is not using an MMG payment method.' ), 400 );
		}

		$txn_id = isset( $_POST['txn_id'] ) ? sanitize_text_field( wp_unslash( $_POST['txn_id'] ) ) : '';
		$txn_id = trim( $txn_id );
		if ( preg_match( '/^\d{1,64}$/', $txn_id ) !== 1 ) {
			wp_send_json_error( array( 'message' => 'Enter a valid numeric MMG Transaction ID.' ), 400 );
		}

		$result = self::verify_and_complete_order( $order_id, $txn_id );
		if ( empty( $result['success'] ) ) {
			$data = array( 'message' => (string) $result['message'] );
			if ( ! empty( $result['summary'] ) ) {
				$data['summary'] = (string) $result['summary'];
			}
			wp_send_json_error( $data, (int) $result['http_status'] );
		}
		$completion = (array) $result['completion'];

		wp_send_json_success( array(
			'paid' => true,
			'orderStatus' => (string) $completion['order_status'],
			'updated' => (bool) $completion['updated'],
			'summary' => (string) $result['summary'],
			'message' => ! empty( $completion['updated'] ) ? 'Payment verified and order updated.' : 'Payment verified. Order was already marked as paid.',
			'reload' => true,
		) );
	}

	private static function missing_lookup_fields( array $config ): array {
		return MMGWC_Payment_Verifier::missing_lookup_fields( $config );
	}

	private static function verify_and_complete_order( int $order_id, string $transaction_id ): array {
		if ( ! MMGWC_Payment_Verifier::acquire_order_lock( $order_id ) ) {
			return array(
				'success' => false,
				'http_status' => 409,
				'message' => 'Another MMG verification is already running for this order. Wait a moment and try again.',
				'summary' => '',
			);
		}

		try {
			$order = MMGWC_Payment_Context::fresh_order( $order_id );
			if ( ! $order instanceof WC_Order || ! in_array( $order->get_payment_method(), array( 'mmg_checkout', 'mmg_initiated' ), true ) ) {
				return array(
					'success' => false,
					'http_status' => 400,
					'message' => 'This order is not using an MMG payment method.',
					'summary' => '',
				);
			}

			$mode = (string) $order->get_meta( MMGWC_META_MODE );
			$mode = in_array( $mode, array( 'sandbox', 'live' ), true ) ? $mode : MMGWC_Settings::get_mode();
			$config = MMGWC_Settings::get_config( $mode );
			$missing = self::missing_lookup_fields( $config );
			if ( ! empty( $missing ) ) {
				return array(
					'success' => false,
					'http_status' => 400,
					'message' => 'Missing Transaction Lookup settings: ' . implode( ', ', $missing ) . '. Go to WP Admin → MMG Checkout → Settings and complete the optional Merchant Initiated API section.',
					'summary' => '',
				);
			}

			$lookup = MMGWC_API::transaction_lookup( $config, $transaction_id );
			if ( ! MMGWC_Payment_Verifier::renew_order_lock( $order_id ) ) {
				return array(
					'success' => false,
					'http_status' => 409,
					'message' => 'The order changed while MMG verification was running. Wait a moment and verify it again.',
					'summary' => '',
				);
			}
			$post_lookup_order = MMGWC_Payment_Context::fresh_order( $order_id );
			if ( ! $post_lookup_order instanceof WC_Order || ! in_array( $post_lookup_order->get_payment_method(), array( 'mmg_checkout', 'mmg_initiated' ), true ) ) {
				return array(
					'success' => false,
					'http_status' => 409,
					'message' => 'The order changed while MMG verification was running. Review the order before trying again.',
					'summary' => '',
				);
			}
			$order = $post_lookup_order;
			$order->update_meta_data( MMGWC_META_LAST_VERIFIED_AT, (string) time() );
			if ( is_array( $lookup ) ) {
				$order->update_meta_data( '_mmg_lookup_last', wp_json_encode( MMGWC_Payment_Verifier::lookup_record( $lookup ) ) );
			}
			$order->save();

			if ( ! is_array( $lookup ) ) {
				$order->add_order_note( 'MMG verification failed: Transaction lookup returned no data.' );
				return array(
					'success' => false,
					'http_status' => 502,
					'message' => 'MMG verification failed. Check WooCommerce logs for more details.',
					'summary' => '',
				);
			}

			$verification = MMGWC_Payment_Verifier::verify_lookup_for_order( $order, $transaction_id, $lookup, $config );
			$summary = self::lookup_summary( $lookup );
			$order->add_order_note( 'MMG authenticated lookup result: ' . $summary );
			if ( ! empty( $verification['settlement_verified'] ) ) {
				$order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
				$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
				if ( $order->get_payment_method() === 'mmg_initiated' ) {
					$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'payment_confirmed_review' );
				}
				$order->save();
				$order->add_order_note( 'MMG reported an additional settled transaction after the order was already paid or closed. Transaction ID: ' . $transaction_id . '. Review the merchant records for a possible duplicate payment.' );
				return array(
					'success' => false,
					'http_status' => 409,
					'message' => 'MMG verified an additional payment for an order that was already paid or closed. Review the merchant records for a possible duplicate.',
					'summary' => $summary,
				);
			}
			if ( empty( $verification['valid'] ) ) {
				$reason = isset( $verification['code'] ) ? sanitize_key( (string) $verification['code'] ) : 'verification_failed';
				$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'failed:' . $reason );
				$order->save();
				$order->add_order_note( 'MMG verification rejected the transaction: ' . $reason . '. The order was not marked as paid.' );
				return array(
					'success' => false,
					'http_status' => 409,
					'message' => 'MMG returned a transaction, but it did not match this order: ' . $reason . '.',
					'summary' => $summary,
				);
			}

			$completion = self::complete_verified_order( $order, $transaction_id, $lookup, $config );
			if ( empty( $completion['paid'] ) ) {
				return array(
					'success' => false,
					'http_status' => 409,
					'message' => 'MMG verified the payment, but WooCommerce did not enter a paid status. Review the order before asking the customer to pay again.',
					'summary' => $summary,
				);
			}

			return array(
				'success' => true,
				'http_status' => 200,
				'message' => '',
				'summary' => $summary,
				'completion' => $completion,
			);
		} catch ( Throwable $exception ) {
			MMGWC_Logger::error( 'Manual MMG verification failed unexpectedly', array( 'order_id' => $order_id, 'exception' => get_class( $exception ) ) );
			return array(
				'success' => false,
				'http_status' => 500,
				'message' => 'MMG verification could not be completed. Check WooCommerce logs and try again.',
				'summary' => '',
			);
		} finally {
			MMGWC_Payment_Verifier::release_order_lock( $order_id );
		}
	}

	private static function complete_verified_order( WC_Order $order, string $transaction_id, array $lookup, array $config ): array {
		if ( ! MMGWC_Payment_Verifier::renew_order_lock( (int) $order->get_id() ) ) {
			return array(
				'paid' => false,
				'updated' => false,
				'order_status' => (string) $order->get_status(),
			);
		}
		$latest_order = MMGWC_Payment_Context::fresh_order( (int) $order->get_id() );
		if ( ! $latest_order instanceof WC_Order ) {
			return array(
				'paid' => false,
				'updated' => false,
				'order_status' => (string) $order->get_status(),
			);
		}
		$order = $latest_order;
		$latest_verification = MMGWC_Payment_Verifier::verify_lookup_for_order( $order, $transaction_id, $lookup, $config );
		if ( ! empty( $latest_verification['settlement_verified'] ) ) {
			$order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
			$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
			$order->save();
			$order->add_order_note( 'MMG settlement was verified after the order closed. Transaction ID: ' . $transaction_id . '. The closed status was preserved.' );
			return array(
				'paid' => false,
				'updated' => false,
				'order_status' => (string) $order->get_status(),
			);
		}
		if ( empty( $latest_verification['valid'] ) ) {
			return array(
				'paid' => false,
				'updated' => false,
				'order_status' => (string) $order->get_status(),
			);
		}
		$already_paid = $order->is_paid();
		if ( in_array( (string) $order->get_status(), array( 'refunded', 'trash' ), true ) ) {
			$order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
			$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
			$order->save();
			$order->add_order_note( 'MMG verified a settled transaction after the order was closed. Transaction ID: ' . $transaction_id . '. The closed status was not changed.' );
			return array(
				'paid' => false,
				'updated' => false,
				'order_status' => (string) $order->get_status(),
			);
		}
		$order->update_meta_data( MMGWC_META_TXN_ID, $transaction_id );
		$order->update_meta_data( MMGWC_META_PROCESSED_TXN_ID, $transaction_id );
		$order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified' );
		if ( $order->get_payment_method() === 'mmg_initiated' ) {
			$order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'paid' );
		}
		$order->save();

		if ( ! $already_paid ) {
			if ( ! MMGWC_Payment_Verifier::renew_order_lock( (int) $order->get_id() ) ) {
				return array(
					'paid' => false,
					'updated' => false,
					'order_status' => (string) $order->get_status(),
				);
			}
			$completion_order = MMGWC_Payment_Context::fresh_order( (int) $order->get_id() );
			if ( ! $completion_order instanceof WC_Order || ! in_array( $completion_order->get_payment_method(), array( 'mmg_checkout', 'mmg_initiated' ), true ) ) {
				return array(
					'paid' => false,
					'updated' => false,
					'order_status' => (string) $order->get_status(),
				);
			}
			if ( in_array( (string) $completion_order->get_status(), array( 'refunded', 'trash' ), true ) ) {
				$completion_order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
				$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
				$completion_order->save();
				$completion_order->add_order_note( 'MMG settlement was verified, but the order closed before completion. Transaction ID: ' . $transaction_id . '. The closed status was preserved.' );
				return array(
					'paid' => false,
					'updated' => false,
					'order_status' => (string) $completion_order->get_status(),
				);
			}
			$completion_verification = MMGWC_Payment_Verifier::verify_lookup_for_order( $completion_order, $transaction_id, $lookup, $config );
			if ( ! empty( $completion_verification['settlement_verified'] ) ) {
				$completion_order->update_meta_data( MMGWC_META_REVIEW_TXN_ID, $transaction_id );
				$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'verified:order_already_paid_review' );
				$completion_order->save();
				$completion_order->add_order_note( 'MMG settlement was verified, but the order state changed before completion. The current status was preserved.' );
				return array(
					'paid' => false,
					'updated' => false,
					'order_status' => (string) $completion_order->get_status(),
				);
			}
			if ( empty( $completion_verification['valid'] ) ) {
				$reason = sanitize_key( (string) ( $completion_verification['code'] ?? 'order_changed' ) );
				$completion_order->update_meta_data( MMGWC_META_VERIFICATION_STATUS, 'review:' . $reason );
				$completion_order->save();
				$completion_order->add_order_note( 'MMG settlement could not complete after a final order-state check: ' . $reason . '. Manual review is required.' );
				return array(
					'paid' => false,
					'updated' => false,
					'order_status' => (string) $completion_order->get_status(),
				);
			}
			$order = $completion_order;
			$order->payment_complete( $transaction_id );
		}

		$fresh_order = MMGWC_Payment_Context::fresh_order( (int) $order->get_id() );
		if ( ! $fresh_order instanceof WC_Order ) {
			return array(
				'paid' => false,
				'updated' => false,
				'order_status' => (string) $order->get_status(),
			);
		}
		if ( ! $fresh_order->is_paid() ) {
			if ( $fresh_order->get_payment_method() === 'mmg_initiated' ) {
				$fresh_order->update_meta_data( MMGWC_META_INITIATED_STATUS, 'payment_confirmed_review' );
				$fresh_order->save();
			}
			$fresh_order->add_order_note( 'MMG payment was verified, but WooCommerce did not enter a paid status. Manual order review is required.' );
		}

		return array(
			'paid' => $fresh_order->is_paid(),
			'updated' => ! $already_paid && $fresh_order->is_paid(),
			'order_status' => (string) $fresh_order->get_status(),
		);
	}

	public static function handle_verify_payment(): void {
		if ( class_exists( 'MMGWC_Features' ) ) {
			if ( ! MMGWC_Features::is_enabled( 'order_tools' ) || ! current_user_can( MMGWC_Features::cap_for( 'order_tools' ) ) ) {
				wp_die( 'Not allowed.' );
			}
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Not allowed.' );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_die( 'Missing order_id.' );
		}

		check_admin_referer( 'mmgwc_verify_payment_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found.' );
		}
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( ! in_array( $order->get_payment_method(), array( 'mmg_checkout', 'mmg_initiated' ), true ) ) {
			wp_die( 'This order is not using an MMG payment method.' );
		}

		$txn_id = isset( $_POST['txn_id'] ) ? sanitize_text_field( wp_unslash( $_POST['txn_id'] ) ) : '';
		$txn_id = trim( $txn_id );
		if ( preg_match( '/^\d{1,64}$/', $txn_id ) !== 1 ) {
			self::redirect_with_notice( $order_id, 'error', 'Enter a valid numeric MMG Transaction ID.' );
			return;
		}

		$result = self::verify_and_complete_order( $order_id, $txn_id );
		if ( empty( $result['success'] ) ) {
			self::redirect_with_notice( $order_id, 'error', (string) $result['message'] );
			return;
		}
		$completion = (array) $result['completion'];
		if ( ! empty( $completion['updated'] ) ) {
			self::redirect_with_notice( $order_id, 'success', 'Payment verified and order updated.' );
			return;
		}

		self::redirect_with_notice( $order_id, 'success', 'Payment verified. Order was already marked as paid.' );
	}

	public static function handle_resend_payment(): void {
		if ( class_exists( 'MMGWC_Features' ) ) {
			if ( ! MMGWC_Features::is_enabled( 'order_tools' ) || ! current_user_can( MMGWC_Features::cap_for( 'order_tools' ) ) ) {
				wp_die( 'Not allowed.' );
			}
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Not allowed.' );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_die( 'Missing order_id.' );
		}

		check_admin_referer( 'mmgwc_resend_payment_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found.' );
		}
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( $order->get_payment_method() !== 'mmg_checkout' ) {
			wp_die( 'This order is not using MMG Checkout.' );
		}
		if ( ! $order->needs_payment() ) {
			self::redirect_with_notice( $order_id, 'notice', 'This order does not need payment.' );
			return;
		}

		$send_email = isset( $_POST['send_email'] ) && (string) $_POST['send_email'] === '1';

		$gateway = null;
		if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( is_array( $gateways ) && isset( $gateways['mmg_checkout'] ) ) {
				$gateway = $gateways['mmg_checkout'];
			}
		}
		if ( ! $gateway || ! method_exists( $gateway, 'with_locked_mmg_redirect_url' ) ) {
			self::redirect_with_notice( $order_id, 'error', 'MMG gateway not available.' );
			return;
		}

		$link_generation_reached = false;
		$email_delivery_attempted = false;
		try {
			$delivery = $gateway->with_locked_mmg_redirect_url(
				$order,
				true,
				static function( string $mmg_url, WC_Order $locked_order ) use ( $order_id, $send_email, &$link_generation_reached, &$email_delivery_attempted ): array {
					$delivery_order = MMGWC_Payment_Context::fresh_order( $order_id );
					if ( ! $delivery_order instanceof WC_Order || $delivery_order->get_payment_method() !== 'mmg_checkout' || ! $delivery_order->needs_payment() || $delivery_order->is_paid() ) {
						if ( $delivery_order instanceof WC_Order ) {
							$delivery_order->add_order_note( 'An admin-generated MMG payment link was not delivered because the order no longer needed payment.' );
						}
						return array( 'delivered' => false, 'sent' => false );
					}

					$link_generation_reached = true;
					$transient_key = 'mmgwc_admin_payment_link_' . (string) $order_id . '_' . (string) get_current_user_id();
					set_transient( $transient_key, array( 'mmg_url' => $mmg_url, 'generated_at' => time() ), 10 * MINUTE_IN_SECONDS );
					if ( $send_email ) {
						$email_delivery_attempted = true;
					}
					$sent = ! $send_email || self::send_payment_link_email( $delivery_order, $mmg_url );
					$delivery_order->add_order_note( 'MMG payment link regenerated by admin. ' . ( $send_email ? ( $sent ? 'Email sent to customer.' : 'Email could not be sent.' ) : 'Email not sent.' ) );
					return array( 'delivered' => true, 'sent' => $sent );
				}
			);
		} catch ( Throwable $e ) {
			MMGWC_Logger::error( 'MMG resend payment link failed: ' . $e->getMessage(), array( 'order_id' => $order_id ) );
			if ( $link_generation_reached || $email_delivery_attempted || stripos( $e->getMessage(), 'uncertain' ) !== false ) {
				self::redirect_with_notice( $order_id, 'warning', 'The payment link or email outcome is uncertain. Do not regenerate it yet. Check the order notes and confirm whether the customer received the link.' );
				return;
			}
			self::redirect_with_notice( $order_id, 'error', 'Could not generate a new MMG payment link. Check WooCommerce logs for details.' );
			return;
		}

		if ( empty( $delivery['delivered'] ) ) {
			self::redirect_with_notice( $order_id, 'notice', 'The payment link was not sent because this order no longer needs payment.' );
			return;
		}

		if ( $send_email && empty( $delivery['sent'] ) ) {
			self::redirect_with_notice( $order_id, 'warning', 'Payment link regenerated, but the email could not be sent. You can copy the link from the order screen.' );
			return;
		}

		self::redirect_with_notice( $order_id, 'success', $send_email ? 'Payment link regenerated and sent.' : 'Payment link regenerated. You can copy it from the order screen.' );
	}

	private static function send_payment_link_email( WC_Order $order, string $mmg_url ): bool {
		$email = (string) $order->get_billing_email();
		if ( $email === '' || ! is_email( $email ) ) {
			return false;
		}

		// Strip any CR/LF from header-adjacent values to defeat injection attempts.
		$strip_crlf = static function ( string $v ): string {
			return preg_replace( '/[\r\n]+/', ' ', $v ) ?? '';
		};

		$site_name = $strip_crlf( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$order_no  = $strip_crlf( (string) $order->get_order_number() );
		$subject   = sprintf( '[%s] Payment link for order #%s', $site_name, $order_no );

		$customer_name = trim( (string) $order->get_formatted_billing_full_name() );
		$customer_name = $strip_crlf( $customer_name );
		if ( $customer_name === '' ) {
			$customer_name = 'there';
		}

		// Refuse to embed URLs that contain CR/LF.
		if ( preg_match( '/[\r\n]/', $mmg_url ) ) {
			return false;
		}

		$body  = "Hi {$customer_name},\n\n";
		$body .= sprintf( "Here is your MMG payment link for order #%s:\n%s\n\n", $order_no, $mmg_url );
		$body .= "If you already completed payment, you can ignore this email.\n\n";
		$body .= "Thanks\n";
		$body .= $site_name;

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		return (bool) wp_mail( $email, $subject, $body, $headers );
	}

	private static function lookup_summary( array $lookup ): string {
		$fields = array();
		foreach ( array( 'transactionId', 'transaction_id', 'amount', 'currency', 'status', 'transactionStatus', 'resultCode', 'message' ) as $key ) {
			if ( isset( $lookup[ $key ] ) && $lookup[ $key ] !== '' ) {
				$fields[] = $key . '=' . ( is_scalar( $lookup[ $key ] ) ? (string) $lookup[ $key ] : '[complex]' );
			}
		}
		return $fields ? implode( ', ', $fields ) : 'No summary fields found.';
	}

	private static function redirect_with_notice( int $order_id, string $type, string $message ): void {
		// Store the notice in a user+order scoped transient so it can't be spoofed via URL.
		$tk = self::notice_transient_key( $order_id, get_current_user_id() );
		set_transient( $tk, array(
			'type'    => $type,
			'message' => $message,
		), 2 * MINUTE_IN_SECONDS );

		$url = self::get_order_edit_url( $order_id );
		$url = add_query_arg( array( 'mmgwc_notice_ref' => '1' ), $url );
		wp_safe_redirect( $url );
		exit;
	}

	private static function notice_transient_key( int $order_id, int $user_id ): string {
		return 'mmgwc_notice_' . (int) $order_id . '_' . (int) $user_id;
	}

	private static function get_order_edit_url( int $order_id ): string {
		// Classic...
		$edit_link = get_edit_post_link( $order_id, 'raw' );
		if ( $edit_link ) {
			return $edit_link;
		}
		return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
	}

	public static function configuration_notice(): void {
		$hosted_enabled = MMGWC_Settings::get( 'enabled', 'no' ) === 'yes';
		$initiated_enabled = MMGWC_Settings::get( 'initiated_enabled', 'no' ) === 'yes';
		if ( ! current_user_can( 'manage_woocommerce' ) || ( ! $hosted_enabled && ! $initiated_enabled ) ) {
			return;
		}
		$mode = MMGWC_Settings::get_mode();
		$config = MMGWC_Settings::get_config( $mode );
		if ( $hosted_enabled ) {
			$missing_hosted = MMGWC_Payment_Context::missing_hosted_fields( $config );
			if ( ! empty( $missing_hosted ) ) {
				echo '<div class="notice notice-error"><p><strong>MMG hosted checkout is unavailable to customers.</strong> Complete these Merchant Checkout settings for ' . esc_html( ucfirst( $mode ) ) . ': ' . esc_html( implode( ', ', array_unique( $missing_hosted ) ) ) . '.</p></div>';
			}
		}
		if ( ! $initiated_enabled ) {
			return;
		}
		$missing = MMGWC_Payment_Verifier::missing_api_fields( $config );
		if ( trim( (string) ( $config['credit_account_id'] ?? '' ) ) === '' ) {
			$missing[] = 'credit_account_id';
		}
		if ( empty( $missing ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>MMG app approval requests are unavailable to customers.</strong> Complete these Merchant Initiated API settings for ' . esc_html( ucfirst( $mode ) ) . ': ' . esc_html( implode( ', ', array_unique( $missing ) ) ) . '.</p></div>';
	}

	public static function admin_notices(): void {
		// Only surface notices we persisted ourselves via a short-lived transient.
		// Ignore any free-text that comes back in GET so attacker-crafted links can't plant fake notices.
		if ( empty( $_GET['mmgwc_notice_ref'] ) ) {
			return;
		}

		// Figure out the order_id from either classic or HPOS URLs.
		$order_id = 0;
		if ( isset( $_GET['post'] ) ) {
			$order_id = absint( wp_unslash( (string) $_GET['post'] ) );
		} elseif ( isset( $_GET['id'] ) ) {
			$order_id = absint( wp_unslash( (string) $_GET['id'] ) );
		}
		if ( ! $order_id ) {
			return;
		}

		$tk = self::notice_transient_key( $order_id, get_current_user_id() );
		$data = get_transient( $tk );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return;
		}
		delete_transient( $tk );

		$type = isset( $data['type'] ) ? (string) $data['type'] : 'notice';
		$msg  = (string) $data['message'];

		$cls = 'notice';
		if ( $type === 'success' ) {
			$cls = 'notice notice-success';
		} elseif ( $type === 'error' ) {
			$cls = 'notice notice-error';
		} else {
			$cls = 'notice notice-warning';
		}

		echo '<div class="' . esc_attr( $cls ) . '"><p>' . esc_html( $msg ) . '</p></div>';
	}
}
