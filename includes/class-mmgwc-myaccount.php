<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_MyAccount {

	public const ENDPOINT_INVOICES      = 'mmg-invoices';
	public const ENDPOINT_SUBSCRIPTIONS = 'mmg-subscriptions';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_items' ), 20 );

		// Endpoint renderers should only be registered when the related module is enabled.
		$payment_requests_enabled = ! class_exists( 'MMGWC_Features' ) || MMGWC_Features::is_enabled( 'payment_requests' );
		$subscriptions_enabled    = ! class_exists( 'MMGWC_Features' ) || MMGWC_Features::is_enabled( 'subscriptions' );

		if ( $payment_requests_enabled ) {
			add_action( 'woocommerce_account_' . self::ENDPOINT_INVOICES . '_endpoint', array( __CLASS__, 'render_invoices' ) );
		}

		if ( $subscriptions_enabled ) {
			add_action( 'woocommerce_account_' . self::ENDPOINT_SUBSCRIPTIONS . '_endpoint', array( __CLASS__, 'render_subscriptions' ) );
			add_action( 'template_redirect', array( __CLASS__, 'handle_subscription_actions' ) );
		}
	}

		public static function add_endpoints(): void {
		add_rewrite_endpoint( self::ENDPOINT_INVOICES , EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::ENDPOINT_SUBSCRIPTIONS , EP_ROOT | EP_PAGES );
	}

	public static function add_menu_items( array $items ): array {
		$payment_requests_enabled = ! class_exists( 'MMGWC_Features' ) || MMGWC_Features::is_enabled( 'payment_requests' );
		$subscriptions_enabled    = ! class_exists( 'MMGWC_Features' ) || MMGWC_Features::is_enabled( 'subscriptions' );

		if ( ! $payment_requests_enabled && ! $subscriptions_enabled ) {
			return $items;
		}

		// Insert after "Orders" if present.
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;

			if ( $key === 'orders' ) {
				if ( $payment_requests_enabled ) {
					$new[ self::ENDPOINT_INVOICES ] = __( 'My Invoices', 'mmg-checkout-woocommerce' );
				}
				if ( $subscriptions_enabled ) {
					$new[ self::ENDPOINT_SUBSCRIPTIONS ] = __( 'My Subscriptions', 'mmg-checkout-woocommerce' );
				}
			}
		}

		// If "Orders" is not present, append to the end.
		if ( $payment_requests_enabled && ! isset( $new[ self::ENDPOINT_INVOICES ] ) ) {
			$new[ self::ENDPOINT_INVOICES ] = __( 'My Invoices', 'mmg-checkout-woocommerce' );
		}
		if ( $subscriptions_enabled && ! isset( $new[ self::ENDPOINT_SUBSCRIPTIONS ] ) ) {
			$new[ self::ENDPOINT_SUBSCRIPTIONS ] = __( 'My Subscriptions', 'mmg-checkout-woocommerce' );
		}

		return $new;
	}

	
	private static function get_invoice_orders_for_user( int $user_id, string $email, int $limit = 20 ): array {
		$ids = array();

		if ( $user_id > 0 ) {
			$orders = wc_get_orders( array(
				'customer_id' => $user_id,
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'ids',
				'meta_key'    => MMGWC_META_PAYMENT_REQUEST,
				'meta_value'  => 'yes',
			) );
			if ( is_array( $orders ) ) {
				$ids = array_merge( $ids, $orders );
			}
		}

		if ( $email !== '' ) {
			$orders2 = wc_get_orders( array(
				'billing_email' => $email,
				'limit'         => $limit,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'return'        => 'ids',
				'meta_key'      => MMGWC_META_PAYMENT_REQUEST,
				'meta_value'    => 'yes',
			) );
			if ( is_array( $orders2 ) ) {
				$ids = array_merge( $ids, $orders2 );
			}
		}

		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		rsort( $ids );

		$out = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	
public static function handle_subscription_actions(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	$endpoint = self::ENDPOINT_SUBSCRIPTIONS;
	global $wp;
	// Only accept state-changing actions via POST so nonces don't leak through referer/history.
	$is_post_action = ( ! empty( $_SERVER['REQUEST_METHOD'] ) && strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) === 'POST' && isset( $_POST['mmgwc_sub_action'] ) );
	if ( empty( $wp->query_vars[ $endpoint ] ) && ! $is_post_action ) {
		return;
	}
	if ( ! $is_post_action ) {
		return;
	}

	$action = sanitize_text_field( wp_unslash( (string) $_POST['mmgwc_sub_action'] ) );
	$sub_id = isset( $_POST['sub_id'] ) ? absint( $_POST['sub_id'] ) : 0;
	$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';

	if ( $action === '' || $sub_id <= 0 || $nonce === '' ) {
		return;
	}

	if ( ! wp_verify_nonce( $nonce, 'mmgwc_sub_action_' . $sub_id ) ) {
		wc_add_notice( 'Security check failed, please try again.', 'error' );
		return;
	}

	$user = wp_get_current_user();
	$email = $user ? $user->user_email : '';

	if ( $action === 'pause' ) {
		$ok = MMGWC_Subscriptions::customer_set_status( $sub_id, get_current_user_id(), (string) $email, 'paused' );
		wc_add_notice( $ok ? 'Subscription paused.' : 'Unable to pause this subscription.', $ok ? 'success' : 'error' );
	} elseif ( $action === 'resume' ) {
		$ok = MMGWC_Subscriptions::customer_set_status( $sub_id, get_current_user_id(), (string) $email, 'active' );
		wc_add_notice( $ok ? 'Subscription resumed.' : 'Unable to resume this subscription.', $ok ? 'success' : 'error' );
	} else {
		return;
	}

	wp_safe_redirect( wc_get_account_endpoint_url( $endpoint ) );
	exit;
}

public static function render_invoices(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'Please log in to view your invoices.', 'mmg-checkout-woocommerce' ) . '</p>';
			return;
		}

		$user = wp_get_current_user();
		$user_id = absint( $user->ID );
		$email = sanitize_email( (string) $user->user_email );

		$orders = self::get_invoice_orders_for_user( $user_id, $email, 50 );

		echo '<h3>' . esc_html__( 'My Invoices', 'mmg-checkout-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'These are invoices created by the store that you can pay via MMG.', 'mmg-checkout-woocommerce' ) . '</p>';

		if ( empty( $orders ) ) {
			echo '<p>' . esc_html__( 'No invoices found.', 'mmg-checkout-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="shop_table shop_table_responsive my_account_orders">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Invoice', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Total', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			/** @var WC_Order $order */
			$view_url = $order->get_view_order_url();
			$pay_url  = $order->get_checkout_payment_url();
			$is_paid  = $order->is_paid();

			$expires_at = absint( $order->get_meta( MMGWC_META_PR_EXPIRES_AT ) );
			$is_expired = ( ! $is_paid && $expires_at > 0 && time() > $expires_at );

			echo '<tr>';
			echo '<td data-title="Invoice"><a href="' . esc_url( $view_url ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a></td>';
			echo '<td data-title="Date">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>';
			echo '<td data-title="Total">' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
			echo '<td data-title="Status">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
			echo '<td data-title="Actions">';
			echo '<a class="button" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'mmg-checkout-woocommerce' ) . '</a> ';
			if ( $is_expired ) {
				echo '<span style="margin-left:6px;">' . esc_html__( 'Expired', 'mmg-checkout-woocommerce' ) . '</span>';
			} elseif ( ! $is_paid && $order->get_status() === 'pending' ) {
				echo '<a class="button" style="margin-left:6px;" href="' . esc_url( $pay_url ) . '">' . esc_html__( 'Pay', 'mmg-checkout-woocommerce' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public static function render_subscriptions(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'Please log in to view your subscriptions.', 'mmg-checkout-woocommerce' ) . '</p>';
			return;
		}

		$user = wp_get_current_user();
		$user_id = absint( $user->ID );
		$email = sanitize_email( (string) $user->user_email );

		$subs = MMGWC_Subscriptions::get_customer_subscriptions( $user_id, $email );

		echo '<h3>' . esc_html__( 'My Subscriptions', 'mmg-checkout-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'These are reminder based renewals. They are not automatic charges.', 'mmg-checkout-woocommerce' ) . '</p>';

		if ( empty( $subs ) ) {
			echo '<p>' . esc_html__( 'No subscriptions found.', 'mmg-checkout-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="shop_table shop_table_responsive my_account_orders">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Last paid', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Next due', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mmg-checkout-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $subs as $sub ) {
			$id = absint( $sub['id'] ?? 0 );
			$product_id = absint( $sub['product_id'] ?? 0 );
			$variation_id = absint( $sub['variation_id'] ?? 0 );

			$prod = null;
			if ( $variation_id > 0 ) {
				$prod = wc_get_product( $variation_id );
			}
			if ( ! $prod && $product_id > 0 ) {
				$prod = wc_get_product( $product_id );
			}
			$name = $prod ? $prod->get_name() : __( 'Subscription', 'mmg-checkout-woocommerce' );

			$status = isset( $sub['status'] ) ? (string) $sub['status'] : 'active';
			$last_paid = isset( $sub['last_paid'] ) && $sub['last_paid'] ? wc_format_datetime( new WC_DateTime( $sub['last_paid'] ) ) : '';
			$next_due = isset( $sub['next_due'] ) && $sub['next_due'] ? wc_format_datetime( new WC_DateTime( $sub['next_due'] ) ) : '';

			$pay_url = MMGWC_Subscriptions::get_renewal_payment_url( $id );

			echo '<tr>';
			echo '<td data-title="Product">' . esc_html( $name ) . '</td>';
			echo '<td data-title="Status">' . esc_html( ucfirst( $status ) ) . '</td>';
			echo '<td data-title="Last paid">' . esc_html( $last_paid ) . '</td>';
			echo '<td data-title="Next due">' . esc_html( $next_due ) . '</td>';
			
echo '<td data-title="Actions">';
if ( $pay_url ) {
	echo '<a class="button" href="' . esc_url( $pay_url ) . '">' . esc_html__( 'Renew', 'mmg-checkout-woocommerce' ) . '</a> ';
} else {
	echo '<span>' . esc_html__( 'Unavailable', 'mmg-checkout-woocommerce' ) . '</span> ';
}

$nonce = wp_create_nonce( 'mmgwc_sub_action_' . $id );
$endpoint_url = wc_get_account_endpoint_url( self::ENDPOINT_SUBSCRIPTIONS );
$render_action_form = static function ( string $action, string $label ) use ( $id, $nonce, $endpoint_url ) {
	echo '<form method="post" action="' . esc_url( $endpoint_url ) . '" style="display:inline">';
	echo '<input type="hidden" name="mmgwc_sub_action" value="' . esc_attr( $action ) . '" />';
	echo '<input type="hidden" name="sub_id" value="' . esc_attr( (string) $id ) . '" />';
	echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
	echo '<button class="button" type="submit">' . esc_html( $label ) . '</button>';
	echo '</form>';
};
if ( in_array( $status, array( 'active', 'due', 'overdue' ), true ) ) {
	$render_action_form( 'pause', __( 'Pause', 'mmg-checkout-woocommerce' ) );
} elseif ( in_array( $status, array( 'paused', 'stopped' ), true ) ) {
	$render_action_form( 'resume', __( 'Resume', 'mmg-checkout-woocommerce' ) );
}

echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
