<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Subscriptions_Admin {
	private const CAP = 'mmgwc_access_subscriptions';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_mmgwc_subscriptions_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_mmgwc_subscriptions_send_now', array( __CLASS__, 'handle_send_now' ) );
		add_action( 'admin_post_mmgwc_subscriptions_set_status', array( __CLASS__, 'handle_set_status' ) );
		add_action( 'admin_post_mmgwc_subscriptions_lock_price', array( __CLASS__, 'handle_lock_price' ) );
		add_action( 'admin_post_mmgwc_subscriptions_update_due', array( __CLASS__, 'handle_update_due' ) );
		add_action( 'admin_post_mmgwc_subscriptions_export_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_post_mmgwc_subscriptions_bulk_product', array( __CLASS__, 'handle_bulk_product' ) );

		// Product settings panel.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_panel' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_mmgwc' && strpos( $hook, 'mmgwc' ) === false ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== 'mmgwc-subscriptions' ) {
			return;
		}
		if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
		}
		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'subscribers';

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Subscriptions' );
		}
		echo '<h1>Subscriptions</h1>';
		echo '<p>Manage subscription reminders. This is a renewal reminder system, not automatic recurring billing.</p>';

		echo '<h2 class="nav-tab-wrapper">';
		self::tab_link( 'subscribers', 'Subscribers', $tab );
		self::tab_link( 'settings', 'Settings', $tab );
		echo '</h2>';

		if ( $tab === 'settings' ) {
			self::render_settings();
		} else {
			self::render_subscribers();
		}

		echo '</div>';
	}

	private static function tab_link( string $key, string $label, string $active ): void {
		$url = admin_url( 'admin.php?page=mmgwc-subscriptions&tab=' . $key );
		$cls = $active === $key ? 'nav-tab nav-tab-active' : 'nav-tab';
		echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private static function render_subscribers(): void {
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$results = MMGWC_Subscriptions::get_subscriptions( array(
			'status' => $status,
			'q'      => $q,
			'page'   => $paged,
			'limit'  => 25,
		) );

		$rows = $results['rows'] ?? array();
		$total_pages = $results['pages'] ?? 1;

		// Only surface a fixed set of notice keys so an attacker can't inject free text via URL.
		$notice_key = isset( $_GET['mmgwc_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['mmgwc_notice'] ) ) : '';
		$known_notices = array(
			'settings_saved'  => 'Settings saved.',
			'reminder_sent'   => 'Reminder sent.',
			'due_updated'     => 'Next due date updated.',
			'select_product'  => 'Select a product first.',
			'product_saved'   => 'Saved subscription settings to product.',
		);
		if ( isset( $known_notices[ $notice_key ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $known_notices[ $notice_key ] ) . '</p></div>';
		}

		echo '<form method="get" style="margin: 12px 0;">';
		echo '<input type="hidden" name="page" value="mmgwc-subscriptions" />';
		echo '<input type="hidden" name="tab" value="subscribers" />';
		echo '<input type="text" name="q" value="' . esc_attr( $q ) . '" placeholder="Search email" />';
		echo '<select name="status">';
		echo '<option value="">All statuses</option>';
		foreach ( array( 'active', 'due', 'overdue', 'paused', 'stopped', 'cancelled' ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
		}
		echo '</select> ';
		submit_button( 'Filter', 'secondary', '', false );
		echo '</form>';

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=mmgwc_subscriptions_export_csv' ), 'mmgwc_subs_export' );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">Download CSV</a></p>';

		echo '<h2>Quick Setup (mark a product as a subscription)</h2>';
		self::render_bulk_product_form();

		echo '<h2>Subscribers</h2>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>Customer</th><th>Product</th><th>Status</th><th>Missed</th><th>Last paid</th><th>Next due</th><th>Actions</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">No subscribers yet.</td></tr>';
		} else {
			foreach ( $rows as $r ) {
				$id = absint( $r['id'] ?? 0 );
				$email = esc_html( (string) ( $r['email'] ?? '' ) );
				$user_id = absint( $r['user_id'] ?? 0 );
				$customer = $user_id > 0 ? 'User #' . $user_id . '<br><small>' . $email . '</small>' : $email;

				$product_id = absint( $r['product_id'] ?? 0 );
				$variation_id = absint( $r['variation_id'] ?? 0 );
				$pname = '';
				if ( $variation_id > 0 ) {
					$p = wc_get_product( $variation_id );
					$pname = $p ? $p->get_name() : '';
				}
				if ( $pname === '' && $product_id > 0 ) {
					$p = wc_get_product( $product_id );
					$pname = $p ? $p->get_name() : '';
				}
				if ( $pname === '' ) {
					$pname = 'Product #' . $product_id;
				}

				$status_txt = esc_html( (string) ( $r['status'] ?? '' ) );
				$last_paid  = esc_html( (string) ( $r['last_paid'] ?? '' ) );
				$next_due   = esc_html( (string) ( $r['next_due'] ?? '' ) );

				echo '<tr>';
				echo '<td>' . $customer . '</td>';
				echo '<td>' . esc_html( $pname ) . '</td>';
				echo '<td>' . $status_txt . '</td>';
					$missed = absint( $r['missed_cycles'] ?? 0 );
					echo '<td>' . esc_html( (string) $missed ) . '</td>';
				echo '<td>' . $last_paid . '</td>';
				echo '<td>' . $next_due . '</td>';
				echo '<td>' . self::row_actions( $id, (string) ( $r['status'] ?? '' ), (string) ( $r['next_due'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$url = admin_url( 'admin.php?page=mmgwc-subscriptions&tab=subscribers&paged=' . $i . '&status=' . urlencode( $status ) . '&q=' . urlencode( $q ) );
				$cls = $i === (int) $paged ? 'button button-primary' : 'button';
				echo '<a class="' . esc_attr( $cls ) . '" style="margin-right:6px" href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a>';
			}
			echo '</div></div>';
		}
	}

	private static function render_bulk_product_form(): void {
		$nonce = wp_create_nonce( 'mmgwc_subs_bulk_product' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width: 900px; margin: 10px 0; padding: 12px; background: #fff; border: 1px solid #ccd0d4;">';
		echo '<input type="hidden" name="action" value="mmgwc_subscriptions_bulk_product" />';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';

		echo '<p><label><strong>Product</strong></label><br />';
		echo '<select name="product_id" class="wc-product-search" style="width: 360px;" data-placeholder="Search for a product" data-action="woocommerce_json_search_products_and_variations"></select></p>';

		echo '<p><label><strong>Interval</strong></label><br />';
		echo '<select name="interval_type"><option value="week">Weekly</option><option value="month" selected>Monthly</option><option value="year">Yearly</option></select> ';
		echo '<input type="number" name="interval_count" value="1" min="1" style="width:100px" /></p>';

		echo '<p><label><strong>Reminders (days)</strong></label><br />';
		echo '<input type="text" name="reminders" value="7,1,0,-3" style="width: 240px;" /> <small>Example: 7,1,0,-3</small></p>';

		echo '<p><label><strong>Grace period (days)</strong></label><br />';
		echo '<input type="number" name="grace_days" value="3" min="0" style="width:100px" /></p>';

		echo '<p><label><input type="checkbox" name="lock_price" value="1" /> Lock renewal price</label></p>';

		submit_button( 'Save to Product', 'primary', 'submit', false );
		echo '</form>';
	}

	private static function row_actions( int $id, string $status, string $next_due ): string {
		$out = array();
		$sub = MMGWC_Subscriptions::get_subscription( $id );
		if ( ! $sub ) {
			$sub = array();
		}

		$send_url = wp_nonce_url( admin_url( 'admin-post.php?action=mmgwc_subscriptions_send_now&id=' . $id ), 'mmgwc_subs_send_' . $id );
		$out[] = '<a class="button" href="' . esc_url( $send_url ) . '">Send reminder</a>';

		$render_post_button = static function ( string $label, string $action, array $fields, string $nonce_action, string $extra_class = '' ) {
			$nonce = wp_create_nonce( $nonce_action );
			$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
			$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
			foreach ( $fields as $k => $v ) {
				$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
			}
			$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
			$html .= '<button type="submit" class="button"' . ( $extra_class ? ' style="' . esc_attr( $extra_class ) . '"' : '' ) . '>' . esc_html( $label ) . '</button>';
			$html .= '</form>';
			return $html;
		};

		$lock_price = absint( $sub['lock_price'] ?? 0 ) === 1;
		$out[] = $render_post_button(
			$lock_price ? 'Unlock price' : 'Lock price',
			'mmgwc_subscriptions_lock_price',
			array( 'id' => (string) $id, 'lock' => $lock_price ? '0' : '1' ),
			'mmgwc_subs_lock_' . $id
		);

		if ( $status === 'paused' ) {
			$out[] = $render_post_button( 'Resume', 'mmgwc_subscriptions_set_status', array( 'id' => (string) $id, 'status' => 'active' ), 'mmgwc_subs_status_' . $id );
		} elseif ( $status !== 'cancelled' ) {
			$out[] = $render_post_button( 'Pause', 'mmgwc_subscriptions_set_status', array( 'id' => (string) $id, 'status' => 'paused' ), 'mmgwc_subs_status_' . $id );
		}

		if ( $status !== 'cancelled' ) {
			$out[] = $render_post_button( 'Cancel', 'mmgwc_subscriptions_set_status', array( 'id' => (string) $id, 'status' => 'cancelled' ), 'mmgwc_subs_status_' . $id, 'color:#b32d2e' );
		}

		$edit_nonce = wp_create_nonce( 'mmgwc_subs_due_' . $id );
		$out[] = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block; margin-left: 8px;">'
			. '<input type="hidden" name="action" value="mmgwc_subscriptions_update_due" />'
			. '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $edit_nonce ) . '" />'
			. '<input type="date" name="next_due_date" value="' . esc_attr( $next_due ? substr( $next_due, 0, 10 ) : '' ) . '" />'
			. '<button class="button">Set due</button>'
			. '</form>';

		return implode( ' ', $out );
	}

	private static function render_settings(): void {
		$settings = MMGWC_Subscriptions::get_settings();

		$nonce = wp_create_nonce( 'mmgwc_subs_settings' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width: 900px;">';
		echo '<input type="hidden" name="action" value="mmgwc_subscriptions_save_settings" />';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">Enable reminders</th><td>';
		echo '<label><input type="checkbox" name="enabled" value="yes"' . checked( (string) $settings['enabled'], 'yes', false ) . '> Enabled</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Default interval</th><td>';
		echo '<select name="default_interval_type">';
		foreach ( array( 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly' ) as $k => $lbl ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) $settings['default_interval_type'], $k, false ) . '>' . esc_html( $lbl ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="number" name="default_interval_count" value="' . esc_attr( (string) $settings['default_interval_count'] ) . '" min="1" style="width:100px" />';
		echo '</td></tr>';

		echo '<tr><th scope="row">Default reminders (days)</th><td>';
		echo '<input type="text" name="default_reminders" value="' . esc_attr( (string) $settings['default_reminders'] ) . '" style="width: 260px;" /> ';
		echo '<p class="description">Example: 7,1,0,-3 (days before, due date, and after).</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Default grace days</th><td>';
		echo '<input type="number" name="default_grace_days" value="' . esc_attr( (string) $settings['default_grace_days'] ) . '" min="0" style="width:100px" />';
		echo '</td></tr>';

		echo '<tr><th scope="row">Renewal link expiry</th><td>';
		echo '<input type="number" name="renewal_link_expiry_days" value="' . esc_attr( (string) $settings['renewal_link_expiry_days'] ) . '" min="1" style="width:100px" /> days';
		echo '</td></tr>';

		echo '<tr><th scope="row">Stop after missed cycles</th><td>';
		echo '<input type="number" name="stop_after_cycles" value="' . esc_attr( (string) ( $settings['stop_after_cycles'] ?? 0 ) ) . '" min="0" style="width:100px" /> ';
		echo '<p class="description">0 means unlimited. A missed cycle is a full billing interval that passes without payment.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Stop after attempts</th><td>';
		echo '<input type="number" name="stop_after_attempts" value="' . esc_attr( (string) $settings['stop_after_attempts'] ) . '" min="0" style="width:100px" /> ';
		echo '<p class="description">0 means unlimited.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Email subject</th><td>';
		echo '<input type="text" name="email_subject" value="' . esc_attr( (string) $settings['email_subject'] ) . '" style="width: 100%;" />';
		echo '</td></tr>';

		echo '<tr><th scope="row">Email body</th><td>';
		echo '<textarea name="email_body" rows="8" style="width: 100%;">' . esc_textarea( (string) $settings['email_body'] ) . '</textarea>';
		echo '<p class="description">Placeholders: {customer_name} {product_name} {due_date} {amount} {pay_link}</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">From name</th><td>';
		echo '<input type="text" name="from_name" value="' . esc_attr( (string) $settings['from_name'] ) . '" style="width: 360px;" />';
		echo '</td></tr>';

		echo '<tr><th scope="row">From email</th><td>';
		echo '<input type="email" name="from_email" value="' . esc_attr( (string) $settings['from_email'] ) . '" style="width: 360px;" />';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( 'Save settings' );
		echo '</form>';
	}

	public static function handle_save_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'mmgwc_subs_settings' );

		$settings = MMGWC_Subscriptions::get_settings();

		$settings['enabled'] = isset( $_POST['enabled'] ) ? 'yes' : 'no';
		$settings['default_interval_type'] = isset( $_POST['default_interval_type'] ) ? sanitize_text_field( wp_unslash( $_POST['default_interval_type'] ) ) : $settings['default_interval_type'];
		$settings['default_interval_count'] = isset( $_POST['default_interval_count'] ) ? max( 1, absint( $_POST['default_interval_count'] ) ) : $settings['default_interval_count'];
		$settings['default_reminders'] = isset( $_POST['default_reminders'] ) ? sanitize_text_field( wp_unslash( $_POST['default_reminders'] ) ) : $settings['default_reminders'];
		$settings['default_grace_days'] = isset( $_POST['default_grace_days'] ) ? max( 0, absint( $_POST['default_grace_days'] ) ) : $settings['default_grace_days'];
		$settings['renewal_link_expiry_days'] = isset( $_POST['renewal_link_expiry_days'] ) ? max( 1, absint( $_POST['renewal_link_expiry_days'] ) ) : $settings['renewal_link_expiry_days'];
		$settings['stop_after_attempts'] = isset( $_POST['stop_after_attempts'] ) ? max( 0, absint( $_POST['stop_after_attempts'] ) ) : $settings['stop_after_attempts'];
		$settings['email_subject'] = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : $settings['email_subject'];
		$settings['email_body'] = isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : $settings['email_body'];
		$settings['from_name'] = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : $settings['from_name'];
		$settings['from_email'] = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : $settings['from_email'];

		MMGWC_Subscriptions::save_settings( $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-subscriptions&tab=settings&mmgwc_notice=settings_saved' ) );
		exit;
	}

	public static function handle_send_now(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'mmgwc_subs_send_' . $id );

		if ( $id > 0 ) {
			MMGWC_Subscriptions::send_reminder_now( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-subscriptions&mmgwc_notice=reminder_sent' ) );
		exit;
	}

	public static function handle_set_status(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden' );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		check_admin_referer( 'mmgwc_subs_status_' . $id );

		if ( $id > 0 && in_array( $status, array( 'active', 'paused', 'cancelled' ), true ) ) {
			MMGWC_Subscriptions::update_status( $id, $status );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-subscriptions' ) );
		exit;
	}
	public static function handle_lock_price(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden' );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$lock = isset( $_POST['lock'] ) ? absint( $_POST['lock'] ) : 0;
		check_admin_referer( 'mmgwc_subs_lock_' . $id );

		if ( $id > 0 ) {
			MMGWC_Subscriptions::set_lock_price( $id, $lock === 1 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-subscriptions' ) );
		exit;
	}

	public static function handle_update_due(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden' );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'mmgwc_subs_due_' . $id );

		$date = isset( $_POST['next_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['next_due_date'] ) ) : '';
		if ( $id > 0 && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			MMGWC_Subscriptions::update_next_due( $id, $date . ' 00:00:00' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-subscriptions&mmgwc_notice=due_updated' ) );
		exit;
	}

	public static function handle_export_csv(): void {
		check_admin_referer( 'mmgwc_subs_export' );
		MMGWC_Subscriptions::export_subscribers_csv();
	}

	public static function handle_bulk_product(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'mmgwc_subs_bulk_product' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( $product_id <= 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-subscriptions&mmgwc_notice=select_product' ) );
			exit;
		}

		$interval_type = isset( $_POST['interval_type'] ) ? sanitize_text_field( wp_unslash( $_POST['interval_type'] ) ) : 'month';
		$interval_count = isset( $_POST['interval_count'] ) ? max( 1, absint( $_POST['interval_count'] ) ) : 1;
		$reminders = isset( $_POST['reminders'] ) ? sanitize_text_field( wp_unslash( $_POST['reminders'] ) ) : '7,1,0,-3';
		$grace_days = isset( $_POST['grace_days'] ) ? max( 0, absint( $_POST['grace_days'] ) ) : 3;
		$lock_price = isset( $_POST['lock_price'] ) ? 'yes' : 'no';

		update_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_ENABLED, 'yes' );
		update_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_INTERVAL_TYPE, $interval_type );
		update_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_INTERVAL_COUNT, $interval_count );
		update_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_REMINDERS, $reminders );
		update_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_GRACE_DAYS, $grace_days );
		update_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_LOCK_PRICE, $lock_price );

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-subscriptions&mmgwc_notice=product_saved' ) );
		exit;
	}

	// Product data tab.
	public static function add_product_tab( array $tabs ): array {
		$tabs['mmgwc_subscription'] = array(
			'label'    => 'MMG Subscription',
			'target'   => 'mmgwc_subscription_data',
			'class'    => array(),
			'priority' => 90,
		);
		return $tabs;
	}

	public static function render_product_panel(): void {
		echo '<div id="mmgwc_subscription_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( array(
			'id'          => MMGWC_META_SUBSCRIPTION_ENABLED,
			'label'       => 'Enable subscription reminders',
			'description' => 'When enabled, paid orders create subscriber records and send renewal reminders.',
		) );

		woocommerce_wp_select( array(
			'id'      => MMGWC_META_SUBSCRIPTION_INTERVAL_TYPE,
			'label'   => 'Interval type',
			'options' => array(
				'week'  => 'Weekly',
				'month' => 'Monthly',
				'year'  => 'Yearly',
			),
		) );

		woocommerce_wp_text_input( array(
			'id'                => MMGWC_META_SUBSCRIPTION_INTERVAL_COUNT,
			'label'             => 'Interval count',
			'type'              => 'number',
			'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			'description'       => 'Example: 1 month, 3 months, 12 months.',
		) );

		woocommerce_wp_text_input( array(
			'id'          => MMGWC_META_SUBSCRIPTION_REMINDERS,
			'label'       => 'Reminder schedule (days)',
			'type'        => 'text',
			'description' => 'Comma-separated days. Example: 7,1,0,-3',
		) );

		woocommerce_wp_text_input( array(
			'id'                => MMGWC_META_SUBSCRIPTION_GRACE_DAYS,
			'label'             => 'Grace period (days)',
			'type'              => 'number',
			'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
			'description'       => 'Mark as overdue after this many days past due.',
		) );

		woocommerce_wp_checkbox( array(
			'id'          => MMGWC_META_SUBSCRIPTION_LOCK_PRICE,
			'label'       => 'Lock renewal price',
			'description' => 'If enabled, renewal orders keep the last paid price.',
		) );

		echo '</div></div>';
	}

	public static function save_product_panel( WC_Product $product ): void {
		$enabled = isset( $_POST[ MMGWC_META_SUBSCRIPTION_ENABLED ] ) ? 'yes' : 'no';
		$product->update_meta_data( MMGWC_META_SUBSCRIPTION_ENABLED, $enabled );

		$interval_type = isset( $_POST[ MMGWC_META_SUBSCRIPTION_INTERVAL_TYPE ] ) ? sanitize_text_field( wp_unslash( $_POST[ MMGWC_META_SUBSCRIPTION_INTERVAL_TYPE ] ) ) : '';
		$product->update_meta_data( MMGWC_META_SUBSCRIPTION_INTERVAL_TYPE, $interval_type );

		$interval_count = isset( $_POST[ MMGWC_META_SUBSCRIPTION_INTERVAL_COUNT ] ) ? max( 1, absint( $_POST[ MMGWC_META_SUBSCRIPTION_INTERVAL_COUNT ] ) ) : 1;
		$product->update_meta_data( MMGWC_META_SUBSCRIPTION_INTERVAL_COUNT, $interval_count );

		$reminders = isset( $_POST[ MMGWC_META_SUBSCRIPTION_REMINDERS ] ) ? sanitize_text_field( wp_unslash( $_POST[ MMGWC_META_SUBSCRIPTION_REMINDERS ] ) ) : '';
		$product->update_meta_data( MMGWC_META_SUBSCRIPTION_REMINDERS, $reminders );

		$grace = isset( $_POST[ MMGWC_META_SUBSCRIPTION_GRACE_DAYS ] ) ? max( 0, absint( $_POST[ MMGWC_META_SUBSCRIPTION_GRACE_DAYS ] ) ) : 0;
		$product->update_meta_data( MMGWC_META_SUBSCRIPTION_GRACE_DAYS, $grace );

		$lock = isset( $_POST[ MMGWC_META_SUBSCRIPTION_LOCK_PRICE ] ) ? 'yes' : 'no';
		$product->update_meta_data( MMGWC_META_SUBSCRIPTION_LOCK_PRICE, $lock );
	}
}
