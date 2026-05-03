<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Admin {
	public static function init(): void {
		// Admin notices should always run for admins.
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

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
		if ( $order->get_payment_method() !== 'mmg_checkout' ) {
			return;
		}

		$txn_id = (string) $order->get_meta( MMGWC_META_TXN_ID );
		$merchant_txn_id = (string) $order->get_meta( MMGWC_META_MERCHANT_TXN_ID );
		$result_code = (string) $order->get_meta( MMGWC_META_RESULT_CODE );
		$result_message = (string) $order->get_meta( MMGWC_META_RESULT_MESSAGE );
		$last_verified = (string) $order->get_meta( MMGWC_META_LAST_VERIFIED_AT );
		$last_verified_text = $last_verified !== '' ? gmdate( 'Y-m-d H:i:s', (int) $last_verified ) . ' UTC' : 'Never';

		echo '<div class="order_data_column" style="padding:12px 0">';
		echo '<h3>MMG Checkout</h3>';
		echo '<p style="margin:0 0 8px 0">Merchant Transaction ID: <code>' . esc_html( $merchant_txn_id !== '' ? $merchant_txn_id : 'N/A' ) . '</code><br>';
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
		echo '<input type="text" id="mmgwc_txn_id" name="txn_id" value="' . esc_attr( $txn_id ) . '" style="width:100%" placeholder="Paste the MMG transactionId">';
		echo '</p>';

		echo '<p style="margin:0">';
		echo '<button type="submit" id="mmgwc-verify-button" class="button">Verify payment</button>';
		echo ' <span class="description">Uses MMG Transaction Lookup API if configured in MMG settings.</span>';
		echo '</p>';
		echo '<div id="mmgwc-verify-result" class="mmgwc-verify-result" style="margin-top:8px;"></div>';
		echo '</form>';

		// Resend payment link (pending orders).
		if ( $order->needs_payment() ) {
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
		if ( $order->get_payment_method() !== 'mmg_checkout' ) {
			wp_send_json_error( array( 'message' => 'This order is not using MMG Checkout.' ), 400 );
		}

		$txn_id = isset( $_POST['txn_id'] ) ? sanitize_text_field( wp_unslash( $_POST['txn_id'] ) ) : '';
		$txn_id = trim( $txn_id );
		if ( $txn_id === '' ) {
			wp_send_json_error( array( 'message' => 'Missing Transaction ID.' ), 400 );
		}

		$mode = (string) $order->get_meta( '_mmg_mode' );
		$mode = $mode === 'live' ? 'live' : MMGWC_Settings::get_mode();
		$config = MMGWC_Settings::get_config( $mode );

		$missing = self::missing_initiated_api_fields( $config );
		if ( ! empty( $missing ) ) {
			wp_send_json_error( array(
				'message' => 'Missing Merchant Initiated API settings: ' . implode( ', ', $missing ) . '. Go to WP Admin → MMG Checkout → Settings and fill the Merchant Initiated API section.',
			), 400 );
		}

		$lookup = MMGWC_API::transaction_lookup( $config, $txn_id );
		$order->update_meta_data( MMGWC_META_LAST_VERIFIED_AT, (string) time() );
		if ( is_array( $lookup ) ) {
			$order->update_meta_data( '_mmg_lookup_last', wp_json_encode( $lookup ) );
		}
		$order->save();

		if ( ! is_array( $lookup ) ) {
			$order->add_order_note( 'MMG verification failed: Transaction lookup returned no data.' );
			wp_send_json_error( array( 'message' => 'MMG verification failed. Check WooCommerce logs for more details.' ), 502 );
		}

		$paid = self::is_lookup_paid( $lookup );
		$summary = self::lookup_summary( $lookup );
		$order->add_order_note( 'MMG verification result: ' . $summary );

		$updated = false;
		if ( $paid && $order->needs_payment() ) {
			$order->payment_complete( $txn_id );
			$order->update_meta_data( MMGWC_META_PROCESSED_TXN_ID, $txn_id );
			$order->save();
			$updated = true;
		}

		wp_send_json_success( array(
			'paid' => $paid,
			'orderStatus' => $order->get_status(),
			'updated' => $updated,
			'summary' => $summary,
			'message' => $paid ? ( $updated ? 'Payment verified and order updated.' : 'Payment verified. Order was already marked as paid.' ) : 'Payment not marked as successful in lookup. See the order note for details.',
			'reload' => true,
		) );
	}

	private static function missing_initiated_api_fields( array $config ): array {
		$missing = array();
		$map = array(
			'mwallet_base_url' => 'mwallet_base_url',
			'api_key' => 'api_key',
			'wss_mid' => 'wss_mid',
			'wss_mkey' => 'wss_mkey',
			'wss_msecret' => 'wss_msecret',
			'password' => 'password',
		);
		foreach ( $map as $k => $label ) {
			if ( empty( $config[ $k ] ) ) {
				$missing[] = $label;
			}
		}
		return $missing;
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
		if ( $order->get_payment_method() !== 'mmg_checkout' ) {
			wp_die( 'This order is not using MMG Checkout.' );
		}

		$txn_id = isset( $_POST['txn_id'] ) ? sanitize_text_field( wp_unslash( $_POST['txn_id'] ) ) : '';
		if ( $txn_id === '' ) {
			self::redirect_with_notice( $order_id, 'error', 'Missing Transaction ID.' );
			return;
		}

		$mode = (string) $order->get_meta( '_mmg_mode' );
		$mode = $mode === 'live' ? 'live' : MMGWC_Settings::get_mode();

		$config = MMGWC_Settings::get_config( $mode );
		$missing = self::missing_initiated_api_fields( $config );
		if ( ! empty( $missing ) ) {
			self::redirect_with_notice(
				$order_id,
				'error',
				'Missing Merchant Initiated API settings: ' . implode( ', ', $missing ) . '. Go to WP Admin → MMG Checkout → Settings and fill the Merchant Initiated API section.'
			);
			return;
		}

		$lookup = MMGWC_API::transaction_lookup( $config, $txn_id );
		$order->update_meta_data( MMGWC_META_LAST_VERIFIED_AT, (string) time() );
		if ( is_array( $lookup ) ) {
			$order->update_meta_data( '_mmg_lookup_last', wp_json_encode( $lookup ) );
		}
		$order->save();

		if ( ! is_array( $lookup ) ) {
			$order->add_order_note( 'MMG verification failed: Transaction lookup returned no data.' );
			self::redirect_with_notice( $order_id, 'error', 'MMG verification failed. Check WooCommerce logs for more details.' );
			return;
		}

		$paid = self::is_lookup_paid( $lookup );
		$summary = self::lookup_summary( $lookup );
		$order->add_order_note( 'MMG verification result: ' . $summary );

		if ( $paid && $order->needs_payment() ) {
			$order->payment_complete( $txn_id );
			$order->update_meta_data( MMGWC_META_PROCESSED_TXN_ID, $txn_id );
			$order->save();
			self::redirect_with_notice( $order_id, 'success', 'Payment verified and order updated.' );
			return;
		}

		if ( $paid ) {
			self::redirect_with_notice( $order_id, 'success', 'Payment verified. Order was already marked as paid.' );
			return;
		}

		self::redirect_with_notice( $order_id, 'notice', 'Payment not marked as successful in lookup. See the order note for details.' );
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
		if ( ! $gateway || ! method_exists( $gateway, 'build_mmg_redirect_url' ) ) {
			self::redirect_with_notice( $order_id, 'error', 'MMG gateway not available.' );
			return;
		}

		try {
			$mmg_url = $gateway->build_mmg_redirect_url( $order, true );
		} catch ( Exception $e ) {
			MMGWC_Logger::error( 'MMG resend payment link failed: ' . $e->getMessage(), array( 'order_id' => $order_id ) );
			self::redirect_with_notice( $order_id, 'error', 'Could not generate a new MMG payment link. Check WooCommerce logs for details.' );
			return;
		}

		// Store for temporary admin display.
		$tk = 'mmgwc_admin_payment_link_' . (string) $order_id . '_' . (string) get_current_user_id();
		set_transient( $tk, array( 'mmg_url' => $mmg_url, 'generated_at' => time() ), 10 * MINUTE_IN_SECONDS );

		$sent = true;
		if ( $send_email ) {
			$sent = self::send_payment_link_email( $order, $mmg_url );
		}

		$order->add_order_note( 'MMG payment link regenerated by admin. ' . ( $send_email ? ( $sent ? 'Email sent to customer.' : 'Email could not be sent.' ) : 'Email not sent.' ) );

		if ( $send_email && ! $sent ) {
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

	private static function is_lookup_paid( array $lookup ): bool {
		// Authoritative status-like fields only.
		$status_fields = array(
			$lookup['status'] ?? null,
			$lookup['transactionStatus'] ?? null,
			$lookup['transaction_status'] ?? null,
		);
		foreach ( $status_fields as $v ) {
			if ( is_string( $v ) ) {
				$vv = strtolower( trim( $v ) );
				if ( in_array( $vv, array( 'success', 'successful', 'completed', 'paid', 'approved' ), true ) ) {
					return true;
				}
			}
		}

		// resultCode == 0 is MMG's success code in the checkout response shape.
		foreach ( array( 'resultCode', 'result_code' ) as $rk ) {
			if ( array_key_exists( $rk, $lookup ) ) {
				$v = $lookup[ $rk ];
				if ( is_string( $v ) || is_numeric( $v ) ) {
					if ( trim( (string) $v ) === '0' ) {
						return true;
					}
				}
			}
		}

		// Boolean "successful": only trust an explicit true.
		if ( array_key_exists( 'successful', $lookup ) && $lookup['successful'] === true ) {
			return true;
		}

		return false;
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
