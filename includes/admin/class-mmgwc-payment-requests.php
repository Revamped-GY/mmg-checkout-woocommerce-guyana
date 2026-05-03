<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Payment_Requests {
	private const CAP = 'mmgwc_access_payment_requests';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_mmgwc_create_payment_request', array( __CLASS__, 'handle_create_request' ) );
		add_action( 'admin_post_mmgwc_send_payment_request', array( __CLASS__, 'handle_send_request' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Restrict payment methods on pay-for-order page for MMG payment requests.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_available_gateways' ), 20, 1 );

		// Block expired payment requests.
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_block_expired_pay_link' ), 9 );
	}

	public static function enqueue_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== 'mmgwc-payment-requests' && $page !== 'mmgwc-payment-request-create' ) {
			return;
		}

		// WooCommerce enhanced select (SelectWoo) for product and customer search fields.
		if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
		}
		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		wp_enqueue_script(
			'mmgwc-admin-payment-requests',
			MMGWC_PLUGIN_URL . 'assets/js/admin-payment-requests.js',
			array( 'jquery' ),
			MMGWC_VERSION,
			true
		);

		wp_localize_script(
			'mmgwc-admin-payment-requests',
			'mmgwcPR',
			array(
				'copySuccess' => 'Copied',
			)
		);
	}

	public static function render_list(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$args = array(
			'limit'      => 25,
			'page'       => $paged,
			'paginate'   => true,
			'orderby'    => 'date_created',
			'order'      => 'DESC',
			'meta_query' => array(
				array(
					'key'   => MMGWC_META_PAYMENT_REQUEST,
					'value' => 'yes',
				),
			),
		);

		if ( $status !== '' ) {
			$args['status'] = $status;
		}

		$results = function_exists( 'wc_get_orders' ) ? wc_get_orders( $args ) : (object) array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 1 );

		$orders = is_object( $results ) && isset( $results->orders ) ? (array) $results->orders : array();
		$total_pages = is_object( $results ) && isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1;

		$notice = isset( $_GET['mmgwc_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['mmgwc_notice'] ) ) : '';
		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Payment Requests' );
		}
		echo '<h1>Payment Requests</h1>';
		echo '<p>Create and send MMG payment links for invoices using WooCommerce orders. Payment is completed on MMG and confirmed back on your site.</p>';

		if ( $notice === 'created' ) {
			$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
			if ( $order_id ) {
				echo '<div class="notice notice-success"><p>Payment request created for order #' . esc_html( (string) $order_id ) . '.</p></div>';
			}
		} elseif ( $notice === 'sent' ) {
			echo '<div class="notice notice-success"><p>Payment request email sent.</p></div>';
		} elseif ( $notice === 'send_failed' ) {
			echo '<div class="notice notice-error"><p>Failed to send the email. Check your site email settings.</p></div>';
		}

		$create_url = admin_url( 'admin.php?page=mmgwc-payment-request-create' );
		echo '<p><a class="button button-primary" href="' . esc_url( $create_url ) . '">Create Request</a></p>';

		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		echo '<form method="get" style="margin: 0 0 12px 0">';
		echo '<input type="hidden" name="page" value="mmgwc-payment-requests" />';
		echo '<select name="status">';
		echo '<option value="">All statuses</option>';
		foreach ( $statuses as $key => $label ) {
			$slug = str_replace( 'wc-', '', (string) $key );
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<button class="button">Filter</button>';
		echo '</form>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>Order</th><th>Customer</th><th>Status</th><th>Total</th><th>Created</th><th>Expires</th><th>Actions</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $orders ) ) {
			echo '<tr><td colspan="7">No payment requests found.</td></tr>';
		} else {
			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$order_id = $order->get_id();
				$email = (string) $order->get_billing_email();
				if ( $email === '' ) {
					$email = (string) $order->get_meta( '_mmg_payment_request_customer_email' );
				}
				$status_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status();
				$created = $order->get_date_created();
				$created_text = $created ? $created->date_i18n( 'Y-m-d H:i' ) : '';
				$expires_at = (int) $order->get_meta( MMGWC_META_PR_EXPIRES_AT );
				$expires_text = $expires_at ? gmdate( 'Y-m-d H:i', $expires_at ) . ' UTC' : '-';
				if ( $expires_at && time() > $expires_at && in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
					$expires_text = '<span style="color:#b32d2e">Expired</span>';
				}
				$pay_url = $order->get_checkout_payment_url();
				$edit_url = $order->get_edit_order_url();

				echo '<tr>';
				echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
				echo '<td>' . esc_html( $email !== '' ? $email : '-' ) . '</td>';
				echo '<td>' . esc_html( $status_label ) . '</td>';
				echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
				echo '<td>' . esc_html( $created_text ) . '</td>';
				echo '<td>' . wp_kses_post( $expires_text ) . '</td>';

				echo '<td>';
				echo '<a class="button button-small" href="' . esc_url( $pay_url ) . '" target="_blank" rel="noopener">Open Link</a> ';
				echo '<button type="button" class="button button-small mmgwc-copy" data-copy="' . esc_attr( $pay_url ) . '">Copy Link</button> ';

				if ( $email !== '' && in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
					$send_url = admin_url( 'admin-post.php' );
					echo '<form method="post" action="' . esc_url( $send_url ) . '" style="display:inline">';
					echo '<input type="hidden" name="action" value="mmgwc_send_payment_request" />';
					echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
					wp_nonce_field( 'mmgwc_send_payment_request_' . $order_id );
					echo '<button class="button button-small">Send Email</button>';
					echo '</form>';
				}
				echo '</td>';

				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			$base_url = admin_url( 'admin.php?page=mmgwc-payment-requests' );
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$url = add_query_arg( array( 'paged' => $i, 'status' => $status ), $base_url );
				$current = $i === $paged ? ' style="font-weight:700"' : '';
				echo '<a' . $current . ' href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
			}
			echo '</div></div>';
		}

		echo '</div>';
	}

	public static function render_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$created_order_id = isset( $_GET['created_order_id'] ) ? absint( $_GET['created_order_id'] ) : 0;
		$order = $created_order_id ? wc_get_order( $created_order_id ) : null;

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Create Payment Request' );
		}
		echo '<h1>Create Payment Request</h1>';
		echo '<p>Create an invoice order and send a secure MMG payment link. The customer will pay on MMG and the order will update automatically on your site.</p>';

		$notice = isset( $_GET['mmgwc_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['mmgwc_notice'] ) ) : '';
		if ( $notice === 'missing_email' ) {
			echo '<div class="notice notice-error"><p>Please select a customer or enter a customer email.</p></div>';
		}


		if ( $order instanceof WC_Order ) {
			$pay_url = $order->get_checkout_payment_url();
			echo '<div class="notice notice-success"><p><strong>Created:</strong> Order #' . esc_html( $order->get_order_number() ) . '</p></div>';
			echo '<p><label><strong>Payment link:</strong></label><br>';
			echo '<input type="text" class="regular-text" readonly value="' . esc_attr( $pay_url ) . '" style="width:520px;max-width:100%" /> ';
			echo '<button type="button" class="button mmgwc-copy" data-copy="' . esc_attr( $pay_url ) . '">Copy Link</button></p>';

			$email = (string) $order->get_billing_email();
			if ( $email !== '' && in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="mmgwc_send_payment_request" />';
				echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
				wp_nonce_field( 'mmgwc_send_payment_request_' . $order->get_id() );
				echo '<p><button class="button button-primary">Send Email to ' . esc_html( $email ) . '</button></p>';
				echo '</form>';
			}
		}

		echo '<hr>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_create_payment_request" />';
		wp_nonce_field( 'mmgwc_create_payment_request' );

		echo '<h2>Customer</h2>';
		echo '<p>Select an existing customer or enter an email address.</p>';

		echo '<p><label><strong>Existing customer (optional)</strong></label><br>';
		echo '<select class="wc-customer-search" name="customer_id" data-placeholder="Search customer by email..." data-allow_clear="true" style="width: 360px;"></select>';
		echo '</p>';

		echo '<p><label><strong>Customer email (required if no customer selected)</strong></label><br>';
		echo '<input type="email" name="customer_email" class="regular-text" placeholder="customer@example.com" style="width:360px" />';
		echo '</p>';

		echo '<h2>Products</h2>';
		echo '<p>Add one or more products or variations. Prices are stored on the order at the time you create the request.</p>';

		echo '<table class="widefat striped" id="mmgwc-pr-products" style="max-width:900px">';
		echo '<thead><tr><th style="width:65%">Product</th><th style="width:15%">Qty</th><th style="width:20%"></th></tr></thead><tbody>';

		// Row template (one row).
		echo '<tr class="mmgwc-pr-row">';
		echo '<td><select class="wc-product-search" name="products[0][product_id]" data-placeholder="Search product..." data-action="woocommerce_json_search_products_and_variations" style="width:100%"></select></td>';
		echo '<td><input type="number" min="1" step="1" name="products[0][qty]" value="1" style="width:90px" /></td>';
		echo '<td><button type="button" class="button mmgwc-pr-remove">Remove</button></td>';
		echo '</tr>';

		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="mmgwc-pr-add-product">Add product</button></p>';

		echo '<h2>Custom invoice items</h2>';
		echo '<p>Add custom items that are not products in your store. These will appear on the invoice but will not be added to your catalogue.</p>';

		echo '<table class="widefat striped" id="mmgwc-pr-custom" style="max-width:900px">';
		echo '<thead><tr><th style="width:30%">Item</th><th style="width:45%">Description</th><th style="width:15%">Amount</th><th style="width:10%"></th></tr></thead><tbody>';

		echo '<tr class="mmgwc-pr-custom-row">';
		echo '<td><input type="text" name="custom_items[0][name]" placeholder="Service fee" style="width:100%" /></td>';
		echo '<td><input type="text" name="custom_items[0][desc]" placeholder="Optional description" style="width:100%" /></td>';
		echo '<td><input type="number" step="0.01" min="0" name="custom_items[0][amount]" placeholder="0.00" style="width:120px" /></td>';
		echo '<td><button type="button" class="button mmgwc-pr-remove-custom">Remove</button></td>';
		echo '</tr>';

		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="mmgwc-pr-add-custom">Add custom item</button></p>';

		echo '<h2>Options</h2>';
		echo '<p><label><strong>Expiry date (optional)</strong></label><br>';
		echo '<input type="date" name="expires_date" /></p>';

		echo '<p><label><strong>Customer note (optional)</strong></label><br>';
		echo '<textarea name="customer_note" rows="3" style="width:520px;max-width:100%"></textarea></p>';

		echo '<p><label><strong>Internal admin note (optional)</strong></label><br>';
		echo '<textarea name="admin_note" rows="2" style="width:520px;max-width:100%"></textarea></p>';

		echo '<p><label><strong>Lock prices</strong></label><br>';
		echo '<label><input type="checkbox" name="lock_prices" value="yes" checked /> Lock product prices on this invoice (recommended)</label></p>';

		echo '<p><label><strong>Shipping amount (optional)</strong></label><br>';
		echo '<input type="number" step="0.01" min="0" name="shipping_amount" placeholder="0.00" style="width:120px" />';
		echo ' <span class="description">Adds a fixed shipping line to this invoice order.</span></p>';

		echo '<p><button class="button button-primary">Create payment request</button> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-payment-requests' ) ) . '">Back to list</a></p>';

		echo '</form>';
		echo '</div>';
	}

	public static function handle_create_request(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'mmgwc_create_payment_request' );

		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		$expires_date = isset( $_POST['expires_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_date'] ) ) : '';
		$customer_note = isset( $_POST['customer_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_note'] ) ) : '';
		$admin_note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';
		$lock_prices = ( isset( $_POST['lock_prices'] ) && sanitize_text_field( wp_unslash( $_POST['lock_prices'] ) ) === 'yes' ) ? 'yes' : 'no';
		$shipping_amount = isset( $_POST['shipping_amount'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['shipping_amount'] ) ) : 0.0;

		$products = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? (array) $_POST['products'] : array();
		$custom_items = isset( $_POST['custom_items'] ) && is_array( $_POST['custom_items'] ) ? (array) $_POST['custom_items'] : array();

		// Cap array sizes so a malformed / abusive submission can't create thousands of line items.
		if ( count( $products ) > 100 ) {
			$products = array_slice( $products, 0, 100 );
		}
		if ( count( $custom_items ) > 100 ) {
			$custom_items = array_slice( $custom_items, 0, 100 );
		}

		if ( $customer_id === 0 && $customer_email === '' ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'mmgwc-payment-request-create', 'mmgwc_notice' => 'missing_email' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! function_exists( 'wc_create_order' ) ) {
			wp_die( 'WooCommerce is required.' );
		}

		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		if ( ! $order ) {
			wp_die( 'Failed to create order.' );
		}

		// Payment method: force MMG Checkout.
		$order->set_payment_method( 'mmg_checkout' );
		$order->set_payment_method_title( 'MMG Checkout' );

		// Billing email.
		if ( $customer_id > 0 ) {
			$user = get_user_by( 'id', $customer_id );
			if ( $user && ! empty( $user->user_email ) ) {
				$order->set_billing_email( (string) $user->user_email );
			}
		} else {
			$order->set_billing_email( $customer_email );
			$order->update_meta_data( '_mmg_payment_request_customer_email', $customer_email );
		}

		if ( $customer_note !== '' ) {
			$order->set_customer_note( $customer_note );
		}

		// Add product line items.
		foreach ( $products as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$product_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			$qty = isset( $row['qty'] ) ? max( 1, absint( $row['qty'] ) ) : 1;
			if ( ! $product_id ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			// Only allow published products in payment requests. Drafts/private/trash are skipped.
			if ( $product->get_status() !== 'publish' ) {
				continue;
			}
			$order->add_product( $product, $qty );
		}

		// Add custom fee items.
		foreach ( $custom_items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';
			$desc = isset( $row['desc'] ) ? sanitize_text_field( wp_unslash( $row['desc'] ) ) : '';
			$amount = isset( $row['amount'] ) ? (float) wc_format_decimal( wp_unslash( $row['amount'] ) ) : 0.0;
			if ( $name === '' || $amount <= 0 ) {
				continue;
			}
			$item = new WC_Order_Item_Fee();
			$item->set_name( $name );
			$item->set_amount( $amount );
			$item->set_total( $amount );
			$item->set_tax_status( 'none' );
			if ( $desc !== '' ) {
				$item->add_meta_data( 'Description', $desc, true );
			}
			$order->add_item( $item );
		}

		// Shipping (fixed amount).
		if ( $shipping_amount > 0 ) {
			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_title( 'Shipping' );
			$ship->set_method_id( 'mmgwc_fixed_shipping' );
			$ship->set_total( $shipping_amount );
			$order->add_item( $ship );
		}

		$order->update_meta_data( MMGWC_META_PAYMENT_REQUEST, 'yes' );
		$order->update_meta_data( MMGWC_META_PR_LOCK_PRICES, $lock_prices );

		if ( $expires_date !== '' ) {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			try {
				$dt = new DateTimeImmutable( $expires_date . ' 23:59:59', $tz );
				$expires_at = $dt->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
				$order->update_meta_data( MMGWC_META_PR_EXPIRES_AT, (string) $expires_at );
			} catch ( Exception $e ) {
				// Ignore.
			}
		}

		if ( $admin_note !== '' ) {
			$order->add_order_note( $admin_note );
		}

		$order->set_status( 'pending' );
		$order->set_created_via( 'mmg_payment_request' );

		$order->calculate_totals( true );
		$order->save();

		$redirect = add_query_arg(
			array(
				'page'             => 'mmgwc-payment-request-create',
				'created_order_id' => $order->get_id(),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_send_request(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_die( 'Missing order_id.' );
		}

		check_admin_referer( 'mmgwc_send_payment_request_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found.' );
		}

		$email = (string) $order->get_billing_email();
		if ( $email === '' ) {
			$email = (string) $order->get_meta( '_mmg_payment_request_customer_email' );
		}

		$ok = self::send_payment_request_email( $order, $email );

		$redirect = add_query_arg(
			array(
				'page'         => 'mmgwc-payment-requests',
				'mmgwc_notice' => $ok ? 'sent' : 'send_failed',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	private static function send_payment_request_email( WC_Order $order, string $email ): bool {
		if ( $email === '' || ! is_email( $email ) ) {
			return false;
		}

		$strip_crlf = static function ( string $v ): string {
			return preg_replace( '/[\r\n]+/', ' ', $v ) ?? '';
		};

		$site_name = $strip_crlf( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$order_no  = $strip_crlf( (string) $order->get_order_number() );
		$subject   = sprintf( '[%s] Payment request for order #%s', $site_name, $order_no );
		$pay_url   = $order->get_checkout_payment_url();

		if ( preg_match( '/[\r\n]/', $pay_url ) ) {
			return false;
		}

		$customer_name = $strip_crlf( trim( (string) $order->get_formatted_billing_full_name() ) );
		if ( $customer_name === '' ) {
			$customer_name = 'there';
		}

		$body  = "Hi {$customer_name},\n\n";
		$body .= sprintf( "Here is your secure MMG payment link for order #%s:\n%s\n\n", $order_no, $pay_url );
		$body .= "Click the link to pay on MMG. Once payment is completed, your order will update automatically.\n\n";
		$body .= "If you already paid, you can ignore this message.\n\n";
		$body .= "Thanks\n";
		$body .= $site_name;

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent = (bool) wp_mail( $email, $subject, $body, $headers );

		if ( $sent ) {
			$order->add_order_note( 'MMG payment request email sent to ' . $email );
		}

		return $sent;
	}

	
	private static function maybe_refresh_prices_on_pay_page( WC_Order $order ): void {
		$lock = (string) $order->get_meta( MMGWC_META_PR_LOCK_PRICES );
		if ( $lock !== 'no' ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
			return;
		}
		$refreshed = (string) $order->get_meta( '_mmg_payment_request_prices_refreshed_at' );
		if ( $refreshed !== '' ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$qty = max( 1, (int) $item->get_quantity() );
			$price = (float) $product->get_price();
			if ( $price <= 0 ) {
				continue;
			}
			$line_total = $price * $qty;
			$item->set_subtotal( $line_total );
			$item->set_total( $line_total );
			$item->save();
		}

		$order->calculate_totals( true );
		$order->update_meta_data( '_mmg_payment_request_prices_refreshed_at', (string) time() );
		$order->save();
	}

public static function filter_available_gateways( $gateways ) {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return $gateways;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( ! $order_id ) {
			return $gateways;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $gateways;
		}

		if ( (string) $order->get_meta( MMGWC_META_PAYMENT_REQUEST ) !== 'yes' ) {
			return $gateways;
		}

		// Only allow MMG Checkout for payment requests.
		if ( isset( $gateways['mmg_checkout'] ) ) {
			return array( 'mmg_checkout' => $gateways['mmg_checkout'] );
		}

		return $gateways;
	}

	public static function maybe_block_expired_pay_link(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( (string) $order->get_meta( MMGWC_META_PAYMENT_REQUEST ) !== 'yes' ) {
			return;
		}

		self::maybe_refresh_prices_on_pay_page( $order );

		$expires_at = (int) $order->get_meta( MMGWC_META_PR_EXPIRES_AT );
		if ( ! $expires_at ) {
			return;
		}

		if ( time() <= $expires_at ) {
			return;
		}

		if ( ! in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Invoice expired, contact the store.', 'error' );
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}
