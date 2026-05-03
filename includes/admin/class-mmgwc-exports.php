<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Exports {
	private const CAP = 'mmgwc_access_exports';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_mmgwc_export_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_post_mmgwc_export_monthly_summary_csv', array( __CLASS__, 'handle_export_monthly_summary_csv' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$today        = gmdate( 'Y-m-d' );
		$default_from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );

		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : $default_from;
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : $today;
		$status    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;
		$customer_email = isset( $_GET['customer_email'] ) ? sanitize_email( wp_unslash( $_GET['customer_email'] ) ) : '';

		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$status_options = array( '' => 'All statuses' );
		foreach ( $statuses as $key => $label ) {
			$slug = str_replace( 'wc-', '', (string) $key );
			$status_options[ $slug ] = $label;
		}

		$action = admin_url( 'admin-post.php' );

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Exports' );
		}
		echo '<h1>MMG Checkout Exports</h1>';
		echo '<p>Download CSV exports for MMG Checkout orders for accounting and reconciliation.</p>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-bottom:16px;">';
		echo '<input type="hidden" name="page" value="mmgwc-exports">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="mmgwc_date_from">From</label></th><td><input type="date" id="mmgwc_date_from" name="date_from" value="' . esc_attr( $date_from ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="mmgwc_date_to">To</label></th><td><input type="date" id="mmgwc_date_to" name="date_to" value="' . esc_attr( $date_to ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="mmgwc_status">Status</label></th><td><select id="mmgwc_status" name="status">';
		foreach ( $status_options as $k => $v ) {
			echo '<option value="' . esc_attr( (string) $k ) . '" ' . selected( $status, (string) $k, false ) . '>' . esc_html( (string) $v ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="mmgwc_product_id">Product ID</label></th><td><input type="number" min="0" step="1" id="mmgwc_product_id" name="product_id" value="' . esc_attr( (string) $product_id ) . '" placeholder="0">';
		echo '<p class="description">Optional. Export only orders that include this product (or variation) ID.</p></td></tr>';

		echo '<tr><th scope="row"><label for="mmgwc_customer_email">Customer email</label></th><td><input type="email" id="mmgwc_customer_email" name="customer_email" value="' . esc_attr( (string) $customer_email ) . '" placeholder="customer@example.com">';
		echo '<p class="description">Optional. Export only orders for this billing email.</p></td></tr>';

		echo '<tr><th scope="row"><label for="mmgwc_customer_id">Customer user ID</label></th><td><input type="number" min="0" step="1" id="mmgwc_customer_id" name="customer_id" value="' . esc_attr( (string) $customer_id ) . '" placeholder="0">';
		echo '<p class="description">Optional. Export only orders for this WooCommerce customer user ID.</p></td></tr>';

		echo '</table>';
		submit_button( 'Apply filters', 'secondary', 'submit', false );
		echo '</form>';

		echo '<hr>';

		echo '<h2>Detailed CSV</h2>';
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_export_csv">';
		wp_nonce_field( 'mmgwc_export_csv', 'mmgwc_nonce' );
		echo '<input type="hidden" name="date_from" value="' . esc_attr( $date_from ) . '">';
		echo '<input type="hidden" name="date_to" value="' . esc_attr( $date_to ) . '">';
		echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '">';
		echo '<input type="hidden" name="customer_email" value="' . esc_attr( (string) $customer_email ) . '">';
		echo '<input type="hidden" name="customer_id" value="' . esc_attr( (string) $customer_id ) . '">';
		echo '<p><button type="submit" class="button button-primary">Download detailed CSV</button></p>';
		echo '<p class="description">Columns include store totals, MMG totals, FX rate, FX date and rounding delta.</p>';
		echo '</form>';

		echo '<h2 style="margin-top:24px;">Monthly summary CSV</h2>';
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_export_monthly_summary_csv">';
		wp_nonce_field( 'mmgwc_export_monthly_summary_csv', 'mmgwc_nonce' );
		echo '<input type="hidden" name="date_from" value="' . esc_attr( $date_from ) . '">';
		echo '<input type="hidden" name="date_to" value="' . esc_attr( $date_to ) . '">';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '">';
		echo '<input type="hidden" name="customer_email" value="' . esc_attr( (string) $customer_email ) . '">';
		echo '<input type="hidden" name="customer_id" value="' . esc_attr( (string) $customer_id ) . '">';
		echo '<p><button type="submit" class="button">Download monthly summary CSV</button></p>';
		echo '<p class="description">Monthly totals, counts and success rate (based on paid order statuses).</p>';
		echo '</form>';

		echo '</div>';
	}

	private static function parse_date( string $d, string $fallback ): string {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return $d;
		}
		return $fallback;
	}

	/**
	 * Defend against CSV formula injection when CSV files are opened in Excel/LibreOffice.
	 * If a cell starts with =, +, -, @ or tab, prefix it with a single quote.
	 */
	private static function csv_safe( $value ): string {
		$v = is_scalar( $value ) || $value === null ? (string) $value : wp_json_encode( $value );
		if ( $v === '' ) {
			return '';
		}
		$first = $v[0];
		if ( $first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r" || $first === "\n" ) {
			return "'" . $v;
		}
		return $v;
	}

	private static function csv_row( $handle, array $row ): void {
		$safe = array_map( array( __CLASS__, 'csv_safe' ), $row );
		fputcsv( $handle, $safe );
	}

	private static function order_matches_customer( WC_Order $order, int $customer_id, string $customer_email ): bool {
		if ( $customer_id > 0 ) {
			return absint( $order->get_customer_id() ) === $customer_id;
		}
		if ( $customer_email !== '' ) {
			$bill = strtolower( (string) $order->get_billing_email() );
			if ( $bill === '' ) {
				$bill = strtolower( (string) $order->get_meta( '_billing_email' ) );
			}
			if ( $bill === '' ) {
				$bill = strtolower( (string) $order->get_meta( '_mmg_payment_request_customer_email' ) );
			}
			return $bill !== '' && $bill === strtolower( $customer_email );
		}
		return true;
	}

	private static function order_contains_product( WC_Order $order, int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$pid = absint( $item->get_product_id() );
			$vid = absint( $item->get_variation_id() );
			if ( $pid === $product_id || $vid === $product_id ) {
				return true;
			}
		}
		return false;
	}

	public static function handle_export_csv(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'mmgwc_export_csv', 'mmgwc_nonce' );

		@set_time_limit( 0 );
		@ignore_user_abort( true );
		@ini_set( 'display_errors', '0' );
		@ini_set( 'html_errors', '0' );

		$today        = gmdate( 'Y-m-d' );
		$default_from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );

		$date_from = isset( $_POST['date_from'] ) ? self::parse_date( sanitize_text_field( wp_unslash( $_POST['date_from'] ) ), $default_from ) : $default_from;
		$date_to   = isset( $_POST['date_to'] ) ? self::parse_date( sanitize_text_field( wp_unslash( $_POST['date_to'] ) ), $today ) : $today;
		$status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';

		$filename = 'mmg-payments-' . $date_from . '-to-' . $date_to . '.csv';

		// Make sure we do not send any buffered output before headers.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			exit;
		}

		// UTF-8 BOM so Excel reads non-ASCII characters correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		self::csv_row( $out, array(
			'Order ID',
			'Date (UTC)',
			'Customer',
			'Email',
			'Store Amount',
			'Store Currency',
			'MMG Amount (GYD)',
			'FX Rate',
			'FX Date',
			'Rounding Delta (GYD)',
			'MMG Transaction ID',
			'Result Code',
			'Status',
		) );

		$limit  = 500;
		$offset = 0;

		$base_args = array(
			'limit'          => $limit,
			'offset'         => 0,
			'return'         => 'objects',
			'payment_method' => 'mmg_checkout',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $status !== '' ) {
			$base_args['status'] = array( $status );
		}

		$from_ts = strtotime( $date_from . ' 00:00:00 UTC' );
		$to_ts   = strtotime( $date_to . ' 23:59:59 UTC' );
		if ( ! is_int( $from_ts ) || $from_ts <= 0 ) {
			$from_ts = strtotime( $default_from . ' 00:00:00 UTC' );
		}
		if ( ! is_int( $to_ts ) || $to_ts <= 0 ) {
			$to_ts = strtotime( $today . ' 23:59:59 UTC' );
		}

		try {
			for ( ;; ) {
				$args = $base_args;
				$args['offset'] = $offset;

				$orders = wc_get_orders( $args );
				if ( ! is_array( $orders ) || empty( $orders ) ) {
					break;
				}

				$stop_all = false;
				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order ) {
						continue;
					}
					// Query already filters to mmg_checkout, kept as defence in depth.
					if ( $order->get_payment_method() !== 'mmg_checkout' ) {
						continue;
					}

					$created = $order->get_date_created();
					$created_ts = ( $created instanceof WC_DateTime ) ? (int) $created->getTimestamp() : 0;

					// Since we order by created date DESC, once we pass below the from_ts, we can stop paging.
					if ( $created_ts > 0 && $created_ts < $from_ts ) {
						$stop_all = true;
						continue;
					}
					if ( $created_ts > 0 && $created_ts > $to_ts ) {
						continue;
					}

					if ( ! self::order_matches_customer( $order, $customer_id, $customer_email ) ) {
						continue;
					}
					if ( ! self::order_contains_product( $order, $product_id ) ) {
						continue;
					}

					$id       = (string) $order->get_id();
					$date_str = $created_ts ? gmdate( 'Y-m-d H:i:s', $created_ts ) : '';
					$name     = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
					$email    = (string) $order->get_billing_email();
					$amount   = (string) $order->get_total();
					$currency = (string) $order->get_currency();

					$txn_id      = (string) $order->get_meta( MMGWC_META_TXN_ID );
					$result_code = (string) $order->get_meta( MMGWC_META_RESULT_CODE );
					$status_slug = (string) $order->get_status();

					$mmg_amount_gyd = (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD );
					$fx_rate        = (string) $order->get_meta( MMGWC_META_FX_RATE );
					$fx_date        = (string) $order->get_meta( MMGWC_META_FX_DATE );
					$rounding_delta = (string) $order->get_meta( MMGWC_META_MMG_ROUNDING_DELTA );

					if ( $mmg_amount_gyd === '' && strtoupper( $currency ) === 'GYD' ) {
						$mmg_amount_gyd = $amount;
					}

					self::csv_row( $out, array(
						$id,
						$date_str,
						$name,
						$email,
						$amount,
						$currency,
						$mmg_amount_gyd,
						$fx_rate,
						$fx_date,
						$rounding_delta,
						$txn_id,
						$result_code,
						$status_slug,
					) );
				}

				if ( $stop_all ) {
					break;
				}

				if ( count( $orders ) < $limit ) {
					break;
				}
				$offset += $limit;
			}
		} catch ( Throwable $e ) {
			MMGWC_Logger::error( 'Export failed', array(
				'date_from'  => $date_from,
				'date_to'    => $date_to,
				'status'     => $status,
				'product_id' => $product_id,
				'customer_id' => $customer_id,
			) );
		}

		fclose( $out );
		exit;
	}

	public static function handle_export_monthly_summary_csv(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'mmgwc_export_monthly_summary_csv', 'mmgwc_nonce' );

		@set_time_limit( 0 );
		@ignore_user_abort( true );
		@ini_set( 'display_errors', '0' );
		@ini_set( 'html_errors', '0' );

		$today        = gmdate( 'Y-m-d' );
		$default_from = gmdate( 'Y-m-d', time() - 365 * DAY_IN_SECONDS );

		$date_from = isset( $_POST['date_from'] ) ? self::parse_date( sanitize_text_field( wp_unslash( $_POST['date_from'] ) ), $default_from ) : $default_from;
		$date_to   = isset( $_POST['date_to'] ) ? self::parse_date( sanitize_text_field( wp_unslash( $_POST['date_to'] ) ), $today ) : $today;

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';

		$filename = 'mmg-payments-monthly-summary-' . $date_from . '-to-' . $date_to . '.csv';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			exit;
		}

		// UTF-8 BOM so Excel reads non-ASCII characters correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		self::csv_row( $out, array(
			'Month',
			'Orders',
			'Paid',
			'Failed',
			'Cancelled',
			'Refunded',
			'Pending',
			'Total MMG (GYD)',
			'Success Rate',
		) );

		$limit  = 500;
		$offset = 0;

		$from_ts = strtotime( $date_from . ' 00:00:00 UTC' );
		$to_ts   = strtotime( $date_to . ' 23:59:59 UTC' );
		if ( ! is_int( $from_ts ) || $from_ts <= 0 ) {
			$from_ts = strtotime( $default_from . ' 00:00:00 UTC' );
		}
		if ( ! is_int( $to_ts ) || $to_ts <= 0 ) {
			$to_ts = strtotime( $today . ' 23:59:59 UTC' );
		}

		$agg = array();

		$base_args = array(
			'limit'          => $limit,
			'offset'         => 0,
			'return'         => 'objects',
			'payment_method' => 'mmg_checkout',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		try {
			for ( ;; ) {
				$args = $base_args;
				$args['offset'] = $offset;

				$orders = wc_get_orders( $args );
				if ( ! is_array( $orders ) || empty( $orders ) ) {
					break;
				}

				$stop_all = false;
				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order ) {
						continue;
					}
					// Query already filters to mmg_checkout, kept as defence in depth.
					if ( $order->get_payment_method() !== 'mmg_checkout' ) {
						continue;
					}

					$created = $order->get_date_created();
					$created_ts = ( $created instanceof WC_DateTime ) ? (int) $created->getTimestamp() : 0;

					if ( $created_ts > 0 && $created_ts < $from_ts ) {
						$stop_all = true;
						continue;
					}
					if ( $created_ts > 0 && $created_ts > $to_ts ) {
						continue;
					}

					if ( ! self::order_matches_customer( $order, $customer_id, $customer_email ) ) {
						continue;
					}
					if ( ! self::order_contains_product( $order, $product_id ) ) {
						continue;
					}

					$month = $created_ts ? gmdate( 'Y-m', $created_ts ) : 'unknown';
					if ( ! isset( $agg[ $month ] ) ) {
						$agg[ $month ] = array(
							'orders' => 0,
							'paid' => 0,
							'failed' => 0,
							'cancelled' => 0,
							'refunded' => 0,
							'pending' => 0,
							'total_gyd' => 0.0,
						);
					}

					$agg[ $month ]['orders']++;

					$status_slug = (string) $order->get_status();
					if ( in_array( $status_slug, array( 'processing', 'completed' ), true ) ) {
						$agg[ $month ]['paid']++;
					} elseif ( $status_slug === 'failed' ) {
						$agg[ $month ]['failed']++;
					} elseif ( $status_slug === 'cancelled' ) {
						$agg[ $month ]['cancelled']++;
					} elseif ( $status_slug === 'refunded' ) {
						$agg[ $month ]['refunded']++;
					} else {
						$agg[ $month ]['pending']++;
					}

					$currency = (string) $order->get_currency();
					$mmg_amount_gyd = (string) $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD );
					if ( $mmg_amount_gyd === '' && strtoupper( $currency ) === 'GYD' ) {
						$mmg_amount_gyd = (string) $order->get_total();
					}
					$agg[ $month ]['total_gyd'] += (float) $mmg_amount_gyd;
				}

				if ( $stop_all ) {
					break;
				}

				if ( count( $orders ) < $limit ) {
					break;
				}
				$offset += $limit;
			}
		} catch ( Throwable $e ) {
			MMGWC_Logger::error( 'Monthly summary export failed', array(
				'date_from'  => $date_from,
				'date_to'    => $date_to,
				'product_id' => $product_id,
				'customer_id' => $customer_id,
			) );
		}

		ksort( $agg );
		foreach ( $agg as $month => $row ) {
			$orders = (int) $row['orders'];
			$paid = (int) $row['paid'];
			$rate = $orders > 0 ? round( ( $paid / $orders ) * 100, 2 ) : 0;
			self::csv_row( $out, array(
				$month,
				$orders,
				$paid,
				(int) $row['failed'],
				(int) $row['cancelled'],
				(int) $row['refunded'],
				(int) $row['pending'],
				number_format( (float) $row['total_gyd'], 2, '.', '' ),
				$rate . '%',
			) );
		}

		fclose( $out );
		exit;
	}
}
