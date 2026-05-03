<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Analytics {
	private const CAP = 'mmgwc_access_analytics';

	// Safety cap so the analytics page stays bounded even on very large stores.
	private const MAX_ORDERS_SCANNED = 5000;
	private const CHUNK_SIZE         = 500;

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<div class="wrap mmgwc-wrap">';
			if ( class_exists( 'MMGWC_Admin_UI' ) ) {
				MMGWC_Admin_UI::brandbar( 'Analytics' );
			}
			echo '<h1>MMG Analytics</h1><p>WooCommerce is not active.</p></div>';
			return;
		}

		$now = time();

		$week_start  = gmdate( 'Y-m-d H:i:s', $now - ( 7 * DAY_IN_SECONDS ) );
		$month_start = gmdate( 'Y-m-d H:i:s', $now - ( 30 * DAY_IN_SECONDS ) );

		$week  = self::compute_stats( $week_start );
		$month = self::compute_stats( $month_start );

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Analytics' );
		}
		echo '<h1>MMG Analytics</h1>';
		echo '<p>Quick totals for MMG Checkout orders. This reads WooCommerce orders that used the <strong>MMG Checkout</strong> gateway.</p>';

		if ( ! empty( $month['truncated'] ) ) {
			echo '<div class="notice notice-warning"><p>More than ' . esc_html( number_format_i18n( self::MAX_ORDERS_SCANNED ) ) . ' MMG orders exist in the last 30 days. This dashboard shows the most recent ' . esc_html( number_format_i18n( self::MAX_ORDERS_SCANNED ) ) . ' only. Use Exports for a full report.</p></div>';
		}

		echo '<h2>This week</h2>';
		self::render_stats_block( $week );

		echo '<h2>This month</h2>';
		self::render_stats_block( $month );

		echo '<h2>Top products (last 30 days)</h2>';
		self::render_top_products( $month['top_products'] ?? array() );

		echo '</div>';
	}

	private static function compute_stats( string $created_after_mysql ): array {
		$totals = array(
			'count'        => 0,
			'success'      => 0,
			'failed'       => 0,
			'cancelled'    => 0,
			'pending'      => 0,
			'other'        => 0,
			'total_store'  => 0.0,
			'total_gyd'    => 0.0,
			'top_products' => array(),
			'truncated'    => false,
		);

		$success_statuses = array( 'processing', 'completed' );
		$failed_statuses  = array( 'failed' );
		$cancel_statuses  = array( 'cancelled' );
		$pending_statuses = array( 'pending', 'on-hold' );

		$offset  = 0;
		$scanned = 0;

		while ( $scanned < self::MAX_ORDERS_SCANNED ) {
			$args = array(
				'limit'          => self::CHUNK_SIZE,
				'offset'         => $offset,
				'return'         => 'ids',
				'payment_method' => 'mmg_checkout',
				'date_created'   => '>' . $created_after_mysql,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);
			$ids = wc_get_orders( $args );
			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $oid ) {
				$scanned++;
				$order = wc_get_order( $oid );
				if ( ! $order ) {
					continue;
				}

				$totals['count']++;
				$totals['total_store'] += (float) $order->get_total();

				$gyd = $order->get_meta( MMGWC_META_MMG_AMOUNT_GYD );
				if ( $gyd !== '' ) {
					$totals['total_gyd'] += (float) $gyd;
				}

				$st = $order->get_status();
				if ( in_array( $st, $success_statuses, true ) ) {
					$totals['success']++;
					foreach ( $order->get_items( 'line_item' ) as $item ) {
						if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
							continue;
						}
						$pid = absint( $item->get_product_id() );
						if ( $pid <= 0 ) {
							continue;
						}
						$name = $item->get_name();
						if ( ! isset( $totals['top_products'][ $pid ] ) ) {
							$totals['top_products'][ $pid ] = array( 'name' => $name, 'qty' => 0 );
						}
						$totals['top_products'][ $pid ]['qty'] += (int) $item->get_quantity();
					}
				} elseif ( in_array( $st, $failed_statuses, true ) ) {
					$totals['failed']++;
				} elseif ( in_array( $st, $cancel_statuses, true ) ) {
					$totals['cancelled']++;
				} elseif ( in_array( $st, $pending_statuses, true ) ) {
					$totals['pending']++;
				} else {
					$totals['other']++;
				}
			}

			$offset += self::CHUNK_SIZE;
			if ( count( $ids ) < self::CHUNK_SIZE ) {
				break;
			}
		}

		if ( $scanned >= self::MAX_ORDERS_SCANNED ) {
			$totals['truncated'] = true;
		}

		// Sort top products by qty desc and keep top 10.
		if ( ! empty( $totals['top_products'] ) ) {
			usort( $totals['top_products'], function( $a, $b ) {
				return ( $b['qty'] ?? 0 ) <=> ( $a['qty'] ?? 0 );
			} );
			$totals['top_products'] = array_slice( $totals['top_products'], 0, 10 );
		}

		$denom = max( 1, (int) $totals['count'] );
		$totals['success_rate'] = round( ( (int) $totals['success'] / $denom ) * 100, 2 );

		return $totals;
	}

	private static function render_stats_block( array $s ): void {
		$count = absint( $s['count'] ?? 0 );
		$success = absint( $s['success'] ?? 0 );
		$failed = absint( $s['failed'] ?? 0 );
		$cancelled = absint( $s['cancelled'] ?? 0 );
		$pending = absint( $s['pending'] ?? 0 );
		$other = absint( $s['other'] ?? 0 );
		$success_rate = isset( $s['success_rate'] ) ? (float) $s['success_rate'] : 0.0;

		$store_total = isset( $s['total_store'] ) ? (float) $s['total_store'] : 0.0;
		$gyd_total = isset( $s['total_gyd'] ) ? (float) $s['total_gyd'] : 0.0;

		echo '<table class="widefat striped" style="max-width:880px;">';
		echo '<tbody>';
		echo '<tr><th style="width:260px;">Orders</th><td>' . esc_html( (string) $count ) . '</td></tr>';
		echo '<tr><th>Successful</th><td>' . esc_html( (string) $success ) . ' (' . esc_html( (string) $success_rate ) . '%)</td></tr>';
		echo '<tr><th>Pending</th><td>' . esc_html( (string) $pending ) . '</td></tr>';
		echo '<tr><th>Failed</th><td>' . esc_html( (string) $failed ) . '</td></tr>';
		echo '<tr><th>Cancelled</th><td>' . esc_html( (string) $cancelled ) . '</td></tr>';
		if ( $other > 0 ) {
			echo '<tr><th>Other statuses</th><td>' . esc_html( (string) $other ) . '</td></tr>';
		}
		echo '<tr><th>Total (store currency)</th><td>' . wp_kses_post( wc_price( $store_total ) ) . '</td></tr>';
		echo '<tr><th>Total charged (GYD)</th><td>' . esc_html( number_format_i18n( $gyd_total, 0 ) ) . '</td></tr>';
		echo '</tbody>';
		echo '</table>';
	}

	private static function render_top_products( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>No data yet.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:880px;">';
		echo '<thead><tr><th>Product</th><th>Qty</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$name = isset( $r['name'] ) ? (string) $r['name'] : '';
			$qty = absint( $r['qty'] ?? 0 );
			echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( (string) $qty ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
