<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Subscriptions {
	private const OPTION_KEY = 'mmgwc_subscriptions_settings';
	private const CRON_HOOK  = 'mmgwc_subscriptions_cron';

	public static function init(): void {
		// Track paid orders to create / update subscriber records.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'handle_paid_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_paid_order' ), 20, 1 );

		// Cron for reminders.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );

		// Restrict gateways on renewal order-pay pages.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways_for_renewal' ), 20 );
	}

	public static function activate(): void {
		self::maybe_create_table();
		self::schedule_cron();
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmgwc_subscriptions';
	}

	private static function maybe_create_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$table   = self::table_name();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NULL,
			email VARCHAR(190) NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			quantity INT(11) NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			start_date DATETIME NULL,
			last_paid DATETIME NULL,
			next_due DATETIME NULL,
			interval_type VARCHAR(10) NOT NULL DEFAULT 'month',
			interval_count INT(11) NOT NULL DEFAULT 1,
			reminders VARCHAR(100) NULL,
			grace_days INT(11) NOT NULL DEFAULT 0,
			lock_price TINYINT(1) NOT NULL DEFAULT 0,
			locked_unit_price DECIMAL(26,8) NULL,
			last_order_id BIGINT(20) UNSIGNED NULL,
			last_txn_id VARCHAR(190) NULL,
			renewal_order_id BIGINT(20) UNSIGNED NULL,
			renewal_order_created_at DATETIME NULL,
			attempts INT(11) NOT NULL DEFAULT 0,
			missed_cycles INT(11) NOT NULL DEFAULT 0,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY user_id (user_id),
			KEY product_id (product_id),
			KEY next_due (next_due),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	public static function get_settings(): array {
		$defaults = array(
			'enabled'                  => 'yes',
			'default_interval_type'    => 'month',
			'default_interval_count'   => 1,
			'default_reminders'        => '7,1,0,-3',
			'default_grace_days'       => 3,
			'default_lock_price'       => 'no',
			'renewal_link_expiry_days' => 7,
			'stop_after_attempts'      => 0,
			'stop_after_cycles'        => 0,
			'email_subject'            => 'Subscription renewal reminder: {product_name}',
			'email_body'               => "Hi {customer_name},\n\nYour subscription for {product_name} is due on {due_date}.\n\nPay here: {pay_link}\n\nThank you.",
			'from_name'                => '',
			'from_email'               => '',
		);

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( $defaults, $saved );
	}

	public static function save_settings( array $settings ): void {
		update_option( self::OPTION_KEY, $settings );
	}

	public static function filter_gateways_for_renewal( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return $gateways;
		}
		if ( ! function_exists( 'is_order_pay_page' ) || ! is_order_pay_page() ) {
			return $gateways;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id <= 0 ) {
			return $gateways;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $gateways;
		}

		$is_renewal = (string) $order->get_meta( MMGWC_META_SUBSCRIPTION_RENEWAL ) === 'yes';
		if ( ! $is_renewal ) {
			return $gateways;
		}
		if ( ! self::is_active_renewal_order( $order ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'This renewal link has expired or was replaced. Please create a new renewal from My Account.', 'error' );
			}
			return array();
		}

		// Only allow MMG on renewal pay pages.
		foreach ( $gateways as $id => $gw ) {
			if ( $id !== 'mmg_checkout' ) {
				unset( $gateways[ $id ] );
			}
		}
		return $gateways;
	}

	private static function product_subscription_config( int $product_id ): array {
		$settings = self::get_settings();

		$enabled = get_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_ENABLED, true );
		if ( $enabled === '' ) {
			$enabled = 'no';
		}

		$interval_type  = get_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_INTERVAL_TYPE, true );
		$interval_count = absint( get_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_INTERVAL_COUNT, true ) );
		$reminders      = get_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_REMINDERS, true );
		$grace_days     = absint( get_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_GRACE_DAYS, true ) );
		$lock_price     = get_post_meta( $product_id, MMGWC_META_SUBSCRIPTION_LOCK_PRICE, true );

		if ( $interval_type === '' ) {
			$interval_type = (string) $settings['default_interval_type'];
		}
		if ( $interval_count <= 0 ) {
			$interval_count = absint( $settings['default_interval_count'] );
		}
		if ( $reminders === '' ) {
			$reminders = (string) $settings['default_reminders'];
		}
		if ( $grace_days <= 0 ) {
			$grace_days = absint( $settings['default_grace_days'] );
		}
		if ( $lock_price === '' ) {
			$lock_price = (string) $settings['default_lock_price'];
		}

		$valid_types = array( 'week', 'month', 'year' );
		if ( ! in_array( $interval_type, $valid_types, true ) ) {
			$interval_type = 'month';
		}

		return array(
			'enabled'        => $enabled === 'yes',
			'interval_type'  => $interval_type,
			'interval_count' => max( 1, $interval_count ),
			'reminders'      => self::parse_reminders( $reminders ),
			'grace_days'     => max( 0, $grace_days ),
			'lock_price'     => $lock_price === 'yes',
		);
	}

	private static function parse_reminders( string $csv ): array {
		$out = array();
		foreach ( explode( ',', $csv ) as $p ) {
			$p = trim( (string) $p );
			if ( $p === '' ) {
				continue;
			}
			$out[] = (int) $p;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	public static function handle_paid_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only act once per status transition.
		if ( (string) $order->get_meta( '_mmgwc_subscription_processed' ) === 'yes' ) {
			return;
		}

		$paid_date = $order->get_date_paid();
		if ( ! $paid_date ) {
			$paid_date = $order->get_date_created();
		}
		if ( ! $paid_date ) {
			return;
		}

		$paid_dt = $paid_date->date( 'Y-m-d H:i:s' );

		$customer_id = absint( $order->get_customer_id() );
		$email       = (string) $order->get_billing_email();
		if ( $email === '' ) {
			$email = (string) $order->get_meta( '_billing_email' );
		}
		if ( $email === '' ) {
			return;
		}

		$txn_id = (string) $order->get_meta( MMGWC_META_TXN_ID );

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$variation_id = absint( $item->get_variation_id() );
			$parent_id    = absint( $product->get_parent_id() );
			$product_id   = $parent_id > 0 ? $parent_id : absint( $product->get_id() );

			$config = self::product_subscription_config( $product_id );
			if ( ! $config['enabled'] ) {
				continue;
			}

			$qty = max( 1, absint( $item->get_quantity() ) );

			$locked_unit_price = null;
			if ( $config['lock_price'] ) {
				$line_total = (float) $item->get_total();
				$locked_unit_price = $qty > 0 ? ( $line_total / $qty ) : $line_total;
			}

			$next_due = self::add_interval( $paid_dt, $config['interval_type'], $config['interval_count'] );

			self::upsert_subscription( array(
				'user_id'           => $customer_id,
				'email'             => $email,
				'product_id'         => $product_id,
				'variation_id'       => $variation_id,
				'quantity'           => $qty,
				'status'             => 'active',
				'start_date'         => $paid_dt,
				'last_paid'          => $paid_dt,
				'next_due'           => $next_due,
				'interval_type'      => $config['interval_type'],
				'interval_count'     => $config['interval_count'],
				'reminders'          => implode( ',', $config['reminders'] ),
				'grace_days'         => $config['grace_days'],
				'lock_price'         => $config['lock_price'] ? 1 : 0,
				'locked_unit_price'  => $locked_unit_price,
				'last_order_id'      => $order_id,
				'last_txn_id'        => $txn_id,
				'attempts'           => 0,
				'missed_cycles'      => 0,
			) );
		}

		$order->update_meta_data( '_mmgwc_subscription_processed', 'yes' );
		$order->save();
	}

	private static function add_interval( string $date_mysql, string $type, int $count ): string {
		try {
			$dt = new DateTime( $date_mysql );
		} catch ( Exception $e ) {
			return $date_mysql;
		}

		$count = max( 1, absint( $count ) );

		if ( $type === 'week' ) {
			$dt->add( new DateInterval( 'P' . $count . 'W' ) );
		} elseif ( $type === 'year' ) {
			$dt->add( new DateInterval( 'P' . $count . 'Y' ) );
		} else {
			$dt->add( new DateInterval( 'P' . $count . 'M' ) );
		}

		return $dt->format( 'Y-m-d H:i:s' );
	}


private static function compute_missed_cycles( string $due_mysql, string $today_ymd, string $interval_type, int $interval_count ): int {
	try {
		$due = new DateTime( (string) $due_mysql );
		$today = new DateTime( (string) $today_ymd );
	} catch ( Exception $e ) {
		return 0;
	}

	// Compare by date only.
	$due_date = new DateTime( $due->format( 'Y-m-d' ) );
	if ( $today <= $due_date ) {
		return 0;
	}

	$missed = 0;
	$cursor = $due_date;

	// Count how many billing intervals have started since the due date (includes the current missed cycle).
	while ( $cursor < $today ) {
		$missed++;
		// Safety valve.
		if ( $missed > 120 ) {
			break;
		}
		$cursor = new DateTime( self::add_interval( $cursor->format( 'Y-m-d' ) . ' 00:00:00', $interval_type, $interval_count ) );
		$cursor = new DateTime( $cursor->format( 'Y-m-d' ) );
	}

	return max( 0, (int) $missed );
}
	private static function upsert_subscription( array $data ): void {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql' );

		$user_id     = absint( $data['user_id'] ?? 0 );
		$email       = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$product_id  = absint( $data['product_id'] ?? 0 );
		$variation_id = absint( $data['variation_id'] ?? 0 );

		if ( $email === '' || $product_id <= 0 ) {
			return;
		}

		$existing = null;

		if ( $user_id > 0 ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND product_id = %d AND variation_id = %d LIMIT 1",
				$user_id, $product_id, $variation_id
			) );
		}

		if ( ! $existing ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE email = %s AND product_id = %d AND variation_id = %d LIMIT 1",
				$email, $product_id, $variation_id
			) );
		}

		$fields = array(
			'user_id'              => $user_id > 0 ? $user_id : null,
			'email'                => $email,
			'product_id'           => $product_id,
			'variation_id'         => $variation_id,
			'quantity'             => max( 1, absint( $data['quantity'] ?? 1 ) ),
			'status'               => sanitize_text_field( (string) ( $data['status'] ?? 'active' ) ),
			'interval_type'        => sanitize_text_field( (string) ( $data['interval_type'] ?? 'month' ) ),
			'interval_count'       => max( 1, absint( $data['interval_count'] ?? 1 ) ),
			'reminders'            => isset( $data['reminders'] ) ? sanitize_text_field( (string) $data['reminders'] ) : null,
			'grace_days'           => max( 0, absint( $data['grace_days'] ?? 0 ) ),
			'lock_price'           => absint( $data['lock_price'] ?? 0 ),
			'locked_unit_price'    => isset( $data['locked_unit_price'] ) ? $data['locked_unit_price'] : null,
			'last_order_id'         => isset( $data['last_order_id'] ) ? absint( $data['last_order_id'] ) : null,
			'last_txn_id'           => isset( $data['last_txn_id'] ) ? sanitize_text_field( (string) $data['last_txn_id'] ) : null,
			'attempts'              => isset( $data['attempts'] ) ? max( 0, absint( $data['attempts'] ) ) : null,
			'missed_cycles'         => isset( $data['missed_cycles'] ) ? max( 0, absint( $data['missed_cycles'] ) ) : null,
			'updated_at'           => $now,
		);

		$dates = array( 'start_date', 'last_paid', 'next_due' );
		foreach ( $dates as $k ) {
			if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) && $data[ $k ] !== '' ) {
				$fields[ $k ] = $data[ $k ];
			}
		}

				// Remove null values so we do not overwrite existing data unintentionally.
		foreach ( $fields as $k => $v ) {
			if ( $v === null ) {
				unset( $fields[ $k ] );
			}
		}

		if ( $existing ) {
			// If we found a guest record but now have a user id, attach it.
			if ( $user_id > 0 && (int) $existing->user_id <= 0 ) {
				$fields['user_id'] = $user_id;
			}

			$wpdb->update( $table, $fields, array( 'id' => absint( $existing->id ) ) );
		} else {
			$fields['created_at'] = $now;
			$wpdb->insert( $table, $fields );
		}
	}

	
	public static function get_customer_subscriptions( int $user_id, string $email ): array {
		global $wpdb;
		$table = self::table_name();

		$user_id = absint( $user_id );
		$email   = sanitize_email( $email );

		$where  = array();
		$params = array();

		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}
		if ( $email !== '' ) {
			$where[]  = 'email = %s';
			$params[] = $email;
		}

		if ( empty( $where ) ) {
			return array();
		}

		$where_sql = '(' . implode( ' OR ', $where ) . ')';

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY next_due ASC, id DESC LIMIT 200";
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, ...$params ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public static function get_renewal_payment_url( int $subscription_id ): ?string {
		$subscription_id = absint( $subscription_id );
		if ( $subscription_id <= 0 ) {
			return null;
		}

		$sub = self::get_subscription( $subscription_id );
		if ( ! $sub ) {
			return null;
		}

		$order_id = self::get_or_create_renewal_order( $sub );
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::is_active_renewal_order( $order ) ) {
			return null;
		}

		return $order->get_checkout_payment_url();
	}

	public static function customer_get_renewal_payment_url( int $subscription_id, int $user_id ): ?string {
		$subscription_id = absint( $subscription_id );
		$user_id = absint( $user_id );
		$sub = $subscription_id > 0 ? self::get_subscription( $subscription_id ) : null;
		if ( ! is_array( $sub ) || ! self::customer_owns_subscription( $sub, $user_id ) ) {
			return null;
		}
		if ( ! in_array( (string) ( $sub['status'] ?? '' ), array( 'active', 'due', 'overdue' ), true ) ) {
			return null;
		}
		return self::get_renewal_payment_url( $subscription_id );
	}

public static function get_subscriptions( array $args = array() ): array {
		global $wpdb;
		$table = self::table_name();

		$limit = isset( $args['limit'] ) ? max( 1, absint( $args['limit'] ) ) : 50;
		$page  = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$offset = ( $page - 1 ) * $limit;

		$where = array( '1=1' );
		$params = array();

		if ( isset( $args['status'] ) && $args['status'] !== '' ) {
			$where[] = 'status = %s';
			$params[] = sanitize_text_field( (string) $args['status'] );
		}

		if ( isset( $args['q'] ) && $args['q'] !== '' ) {
			$q = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$where[] = '(email LIKE %s)';
			$params[] = $q;
		}

		$where_sql = implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = empty( $params )
			? (int) $wpdb->get_var( $total_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) );

		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY next_due ASC, id DESC LIMIT %d OFFSET %d";
		$merged_params = array_merge( $params, array( $limit, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$merged_params ), ARRAY_A );

		$pages = $limit > 0 ? (int) ceil( $total / $limit ) : 1;

		return array(
			'rows' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => max( 1, $pages ),
			'page' => $page,
		);
	}

	public static function get_subscription( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function update_status( int $id, string $status ): void {
		$allowed = array( 'active', 'due', 'overdue', 'paused', 'cancelled', 'stopped' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$now = current_time( 'mysql' );
		$wpdb->update( $table, array( 'status' => $status, 'updated_at' => $now ), array( 'id' => $id ) );
	}

	public static function update_next_due( int $id, string $next_due ): void {
		global $wpdb;
		$table = self::table_name();
		$now = current_time( 'mysql' );
		$wpdb->update( $table, array( 'next_due' => $next_due, 'updated_at' => $now ), array( 'id' => $id ) );
	}

	private static function meta_get( array $row ): array {
		$meta = array();
		if ( isset( $row['meta'] ) && is_string( $row['meta'] ) && $row['meta'] !== '' ) {
			$decoded = json_decode( (string) $row['meta'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		return $meta;
	}

	private static function meta_save( int $id, array $meta ): void {
		global $wpdb;
		$table = self::table_name();
		$now = current_time( 'mysql' );
		$wpdb->update( $table, array(
			'meta' => wp_json_encode( $meta ),
			'updated_at' => $now,
		), array( 'id' => $id ) );
	}

	private static function stage_key( int $days_until_due ): string {
		if ( $days_until_due > 0 ) {
			return 'before_' . absint( $days_until_due );
		}
		if ( $days_until_due === 0 ) {
			return 'due';
		}
		return 'after_' . absint( $days_until_due );
	}

	private static function should_send_stage( array $sub, int $days_until_due ): bool {
		$reminders = isset( $sub['reminders'] ) ? (string) $sub['reminders'] : '';
		$list = self::parse_reminders( $reminders !== '' ? $reminders : (string) self::get_settings()['default_reminders'] );
		return in_array( $days_until_due, $list, true );
	}

	public static function run_cron(): void {
		$settings = self::get_settings();
		if ( (string) $settings['enabled'] !== 'yes' ) {
			return;
		}

		// Cron lock: prevent double-sends when wp-cron and system cron overlap.
		$lock_key = 'mmgwc_subs_cron_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, time(), 5 * MINUTE_IN_SECONDS );

		try {
			self::run_cron_inner();
		} finally {
			delete_transient( $lock_key );
		}
	}

	private static function run_cron_inner(): void {
		$settings = self::get_settings();
		global $wpdb;
		$table = self::table_name();

		// Active, due, or overdue only (paused/cancelled/stopped skipped).
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status IN ('active','due','overdue') AND next_due IS NOT NULL ORDER BY next_due ASC LIMIT 500",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		$today = new DateTime( current_time( 'Y-m-d' ) );

		foreach ( $rows as $sub ) {
			$id = absint( $sub['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			$next_due = isset( $sub['next_due'] ) ? (string) $sub['next_due'] : '';
			if ( $next_due === '' ) {
				continue;
			}

			try {
				$due_dt = new DateTime( $next_due );
			} catch ( Exception $e ) {
				continue;
			}

			$due_date = new DateTime( $due_dt->format( 'Y-m-d' ) );
			$diff_days = (int) $today->diff( $due_date )->format( '%r%a' ); // positive if today before due date
			$days_until_due = $diff_days;

			
$grace_days = max( 0, absint( $sub['grace_days'] ?? absint( $settings['default_grace_days'] ) ) );

// Grace period logic: due (past due but within grace), overdue (past grace).
$current_status = (string) ( $sub['status'] ?? 'active' );
if ( $days_until_due < 0 ) {
	if ( abs( $days_until_due ) > $grace_days ) {
		if ( $current_status !== 'overdue' ) {
			self::update_status( $id, 'overdue' );
			$current_status = 'overdue';
		}
	} else {
		if ( $current_status === 'active' ) {
			self::update_status( $id, 'due' );
			$current_status = 'due';
		}
	}
} else {
	// Not due yet, keep active.
	if ( $current_status === 'due' || $current_status === 'overdue' ) {
		self::update_status( $id, 'active' );
		$current_status = 'active';
	}
}

// Missed cycles tracking (used for "stop after X cycles").
$interval_type  = isset( $sub['interval_type'] ) ? (string) $sub['interval_type'] : (string) $settings['default_interval_type'];
$interval_count = max( 1, absint( $sub['interval_count'] ?? absint( $settings['default_interval_count'] ) ) );
$missed_cycles  = self::compute_missed_cycles( $next_due, $today->format( 'Y-m-d' ), $interval_type, $interval_count );

$stored_missed = absint( $sub['missed_cycles'] ?? 0 );
if ( $missed_cycles !== $stored_missed ) {
	$wpdb->update( self::table_name(), array(
		'missed_cycles' => $missed_cycles,
		'updated_at' => current_time( 'mysql' ),
	), array( 'id' => $id ) );
}

$stop_cycles = absint( $settings['stop_after_cycles'] ?? 0 );
if ( $stop_cycles > 0 && $missed_cycles >= $stop_cycles ) {
	if ( $current_status !== 'stopped' ) {
		self::update_status( $id, 'stopped' );
	}
	continue;
}

			if ( ! self::should_send_stage( $sub, $days_until_due ) ) {
				continue;
			}

			$stop_after = absint( $settings['stop_after_attempts'] ?? 0 );
			$attempts = absint( $sub['attempts'] ?? 0 );
			if ( $stop_after > 0 && $attempts >= $stop_after ) {
				continue;
			}

			$meta = self::meta_get( $sub );
			$cycle = $due_date->format( 'Y-m-d' );
			$stage = self::stage_key( $days_until_due );

			if ( isset( $meta['sent'][ $cycle ][ $stage ] ) ) {
				continue;
			}

			$sent = self::send_renewal_email( $sub, $stage );
			if ( ! $sent ) {
				continue;
			}

			if ( ! isset( $meta['sent'] ) || ! is_array( $meta['sent'] ) ) {
				$meta['sent'] = array();
			}
			if ( ! isset( $meta['sent'][ $cycle ] ) || ! is_array( $meta['sent'][ $cycle ] ) ) {
				$meta['sent'][ $cycle ] = array();
			}
			$meta['sent'][ $cycle ][ $stage ] = current_time( 'mysql' );
			self::meta_save( $id, $meta );

			// Count attempts for reminders (especially after due).
			global $wpdb;
			$wpdb->update( self::table_name(), array(
				'attempts' => $attempts + 1,
				'updated_at' => current_time( 'mysql' ),
			), array( 'id' => $id ) );
		}
	}

	public static function send_reminder_now( int $subscription_id ): bool {
		$sub = self::get_subscription( $subscription_id );
		if ( ! $sub ) {
			return false;
		}
		return self::send_renewal_email( $sub, 'manual' );
	}

	private static function send_renewal_email( array $sub, string $stage ): bool {
		$id = absint( $sub['id'] ?? 0 );
		$email = isset( $sub['email'] ) ? sanitize_email( (string) $sub['email'] ) : '';
		if ( $email === '' ) {
			return false;
		}

		$settings = self::get_settings();

		$order_id = self::get_or_create_renewal_order( $sub );
		if ( $order_id <= 0 ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$pay_link = $order->get_checkout_payment_url();

		$product_name = '';
		$product_id = absint( $sub['product_id'] ?? 0 );
		$prod = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( $prod ) {
			$product_name = $prod->get_name();
		} else {
			$product_name = 'Subscription';
		}

		$customer_name = '';
		$user_id = absint( $sub['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				$customer_name = (string) $user->display_name;
			}
		}
		if ( $customer_name === '' ) {
			$customer_name = 'there';
		}

		$due = isset( $sub['next_due'] ) ? (string) $sub['next_due'] : '';
		$due_date = $due !== '' ? wp_date( 'F j, Y', strtotime( $due ) ) : '';

		$amount = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );

		$replacements = array(
			'{customer_name}' => $customer_name,
			'{product_name}'  => $product_name,
			'{due_date}'      => $due_date,
			'{amount}'        => wp_strip_all_tags( $amount ),
			'{pay_link}'      => esc_url( $pay_link ),
			'{stage}'         => $stage,
		);

		$subject = strtr( (string) $settings['email_subject'], $replacements );
		$body    = strtr( (string) $settings['email_body'], $replacements );

		$headers = array();

		if ( ! empty( $settings['from_email'] ) && is_email( (string) $settings['from_email'] ) ) {
			$from_name  = (string) $settings['from_name'];
			$from_email = (string) $settings['from_email'];
			$headers[] = 'From: ' . ( $from_name !== '' ? $from_name : get_bloginfo( 'name' ) ) . ' <' . $from_email . '>';
		}

		$sent = wp_mail( $email, $subject, $body, $headers );
		if ( ! $sent ) {
			MMGWC_Logger::warning( 'Subscription reminder email failed to send', array(
				'subscription_id' => $id,
				'order_id' => $order_id,
				'email' => $email,
			) );
			return false;
		}

		MMGWC_Logger::info( 'Subscription reminder email sent', array(
			'subscription_id' => $id,
			'order_id' => $order_id,
			'email' => $email,
			'stage' => $stage,
		) );

		return true;
	}

	private static function get_or_create_renewal_order( array $sub ): int {
		$sub_id = absint( $sub['id'] ?? 0 );
		if ( $sub_id <= 0 || ! class_exists( 'MMGWC_Atomic_Option' ) ) {
			return 0;
		}

		$lock_key = 'mmgwc_sub_renew_lock_' . hash( 'sha256', (string) $sub_id );
		$owned_lock = MMGWC_Atomic_Option::acquire_lock( $lock_key, 60 );
		if ( $owned_lock === '' ) {
			$fresh = self::get_subscription( $sub_id );
			$settings = self::get_settings();
			$expiry_days = max( 1, absint( $settings['renewal_link_expiry_days'] ?? 7 ) );
			return is_array( $fresh ) ? self::reusable_renewal_order_id( $fresh, $expiry_days ) : 0;
		}

		try {
			// Re-read fresh to avoid acting on stale data from the caller's local copy.
			$fresh = self::get_subscription( $sub_id );
			if ( is_array( $fresh ) ) {
				$sub = $fresh;
			}

			$settings = self::get_settings();
			$expiry_days = max( 1, absint( $settings['renewal_link_expiry_days'] ?? 7 ) );
			$existing_order_id = self::reusable_renewal_order_id( $sub, $expiry_days );
			if ( $existing_order_id > 0 ) {
				return $existing_order_id;
			}

			$previous_order_id = absint( $sub['renewal_order_id'] ?? 0 );
			if ( $previous_order_id > 0 ) {
				$previous = wc_get_order( $previous_order_id );
				if ( $previous && ! $previous->is_paid() && in_array( (string) $previous->get_status(), array( 'pending', 'failed' ), true ) ) {
					$previous->update_status( 'cancelled', 'Renewal link replaced after its configured expiry.' );
				}
			}

			$expires_at = time() + ( $expiry_days * DAY_IN_SECONDS );
			$order_id = self::create_renewal_order( $sub, $expires_at );
			if ( $order_id > 0 ) {
				global $wpdb;
				$wpdb->update( self::table_name(), array(
					'renewal_order_id' => $order_id,
					'renewal_order_created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				), array( 'id' => $sub_id ) );
			}

			return $order_id;
		} finally {
			MMGWC_Atomic_Option::release_lock( $lock_key, $owned_lock );
		}
	}

	private static function reusable_renewal_order_id( array $sub, int $expiry_days ): int {
		$order_id = absint( $sub['renewal_order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return 0;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() || ! in_array( (string) $order->get_status(), array( 'pending', 'failed' ), true ) ) {
			return 0;
		}
		$created_at = isset( $sub['renewal_order_created_at'] ) ? (string) $sub['renewal_order_created_at'] : '';
		if ( $created_at !== '' ) {
			$created_timestamp = strtotime( $created_at );
			if ( $created_timestamp === false || ( time() - $created_timestamp ) > ( max( 1, $expiry_days ) * DAY_IN_SECONDS ) ) {
				return 0;
			}
		}
		return $order_id;
	}

	private static function create_renewal_order( array $sub, int $expires_at ): int {
		$user_id = absint( $sub['user_id'] ?? 0 );
		$email   = isset( $sub['email'] ) ? sanitize_email( (string) $sub['email'] ) : '';
		$product_id = absint( $sub['product_id'] ?? 0 );
		$variation_id = absint( $sub['variation_id'] ?? 0 );
		$qty = max( 1, absint( $sub['quantity'] ?? 1 ) );

		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return 0;
		}

		try {
			$order = wc_create_order( array(
				'customer_id' => $user_id > 0 ? $user_id : 0,
			) );
		} catch ( Exception $e ) {
			MMGWC_Logger::error( 'Failed creating renewal order', array( 'error' => $e->getMessage() ) );
			return 0;
		}

		if ( $email !== '' ) {
			$order->set_billing_email( $email );
		}

		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$item_id = $order->add_product( $variation, $qty );
			} else {
				$item_id = $order->add_product( $product, $qty );
			}
		} else {
			$item_id = $order->add_product( $product, $qty );
		}

		// Optional: lock price by overriding the item totals.
		$lock_price = absint( $sub['lock_price'] ?? 0 ) === 1;
		$locked_unit_price = isset( $sub['locked_unit_price'] ) ? $sub['locked_unit_price'] : null;

		if ( $lock_price && $locked_unit_price !== null && $item_id ) {
			$item = $order->get_item( $item_id );
			if ( $item && is_a( $item, 'WC_Order_Item_Product' ) ) {
				$subtotal = (float) $locked_unit_price * $qty;
				$item->set_subtotal( $subtotal );
				$item->set_total( $subtotal );
				$item->save();
			}
		}

		$order->calculate_totals();

		$order->set_status( 'pending' );
		$order->set_payment_method( 'mmg_checkout' );

		$order->update_meta_data( MMGWC_META_SUBSCRIPTION_RENEWAL, 'yes' );
		$order->update_meta_data( MMGWC_META_SUBSCRIPTION_ID, absint( $sub['id'] ?? 0 ) );
		$order->update_meta_data( MMGWC_META_SUBSCRIPTION_RENEWAL_EXPIRES_AT, max( time() + 60, $expires_at ) );
		$order->save();

		return absint( $order->get_id() );
	}

	private static function is_active_renewal_order( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		if ( (string) $order->get_meta( MMGWC_META_SUBSCRIPTION_RENEWAL ) !== 'yes' ) {
			return false;
		}
		$subscription_id = absint( $order->get_meta( MMGWC_META_SUBSCRIPTION_ID ) );
		$sub = $subscription_id > 0 ? self::get_subscription( $subscription_id ) : null;
		if ( ! is_array( $sub ) || absint( $sub['renewal_order_id'] ?? 0 ) !== absint( $order->get_id() ) ) {
			return false;
		}
		$expires_at = absint( $order->get_meta( MMGWC_META_SUBSCRIPTION_RENEWAL_EXPIRES_AT ) );
		return $expires_at <= 0 || time() <= $expires_at;
	}

	private static function customer_owns_subscription( array $sub, int $user_id ): bool {
		$user_id = absint( $user_id );
		$sub_user_id = absint( $sub['user_id'] ?? 0 );
		return $user_id > 0 && $sub_user_id > 0 && $sub_user_id === $user_id;
	}

	
public static function set_lock_price( int $subscription_id, bool $lock ): void {
	global $wpdb;
	$table = self::table_name();
	$subscription_id = absint( $subscription_id );
	if ( $subscription_id <= 0 ) {
		return;
	}
	$sub = self::get_subscription( $subscription_id );
	if ( ! $sub ) {
		return;
	}

	$fields = array(
		'lock_price' => $lock ? 1 : 0,
		'updated_at' => current_time( 'mysql' ),
	);

	if ( $lock ) {
		// Use existing locked_unit_price if present. Otherwise, attempt to derive from last paid order or product price.
		$locked = $sub['locked_unit_price'] ?? null;
		if ( $locked === null || $locked === '' ) {
			$qty = max( 1, absint( $sub['quantity'] ?? 1 ) );
			$last_order_id = absint( $sub['last_order_id'] ?? 0 );
			if ( $last_order_id > 0 ) {
				$order = wc_get_order( $last_order_id );
				if ( $order ) {
					foreach ( $order->get_items() as $item ) {
						if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
							continue;
						}
						$pid = absint( $item->get_product_id() );
						$vid = absint( $item->get_variation_id() );
						if ( $pid === absint( $sub['product_id'] ) && ( $vid === absint( $sub['variation_id'] ) || absint( $sub['variation_id'] ) === 0 ) ) {
							$line_total = (float) $item->get_total();
							$locked = $qty > 0 ? ( $line_total / $qty ) : $line_total;
							break;
						}
					}
				}
			}

			if ( $locked === null || $locked === '' ) {
				$product_id = absint( $sub['variation_id'] ?? 0 );
				if ( $product_id <= 0 ) {
					$product_id = absint( $sub['product_id'] ?? 0 );
				}
				$prod = $product_id > 0 ? wc_get_product( $product_id ) : null;
				if ( $prod ) {
					$locked = (float) $prod->get_price();
				}
			}
		}

		if ( $locked !== null && $locked !== '' ) {
			$fields['locked_unit_price'] = $locked;
		}
	} else {
		$fields['locked_unit_price'] = null;
	}

	$wpdb->update( $table, $fields, array( 'id' => $subscription_id ) );
}

public static function customer_set_status( int $subscription_id, int $user_id, string $email, string $status ): bool {
	$subscription_id = absint( $subscription_id );
	$user_id = absint( $user_id );
	$email = sanitize_email( $email );
	$status = sanitize_text_field( $status );

	if ( $subscription_id <= 0 || $user_id <= 0 || $email === '' ) {
		return false;
	}
	if ( ! in_array( $status, array( 'paused', 'active' ), true ) ) {
		return false;
	}

	$sub = self::get_subscription( $subscription_id );
	if ( ! $sub ) {
		return false;
	}

	// Ownership check. For subscriptions attached to a WP user, require the user_id match.
	// For guest subscriptions, refuse modifications via this path. The customer portal only
	// shows My Subscriptions to logged-in users, and email-only matching is too weak as an
	// authorisation primary key.
	if ( ! self::customer_owns_subscription( $sub, $user_id ) ) {
		return false;
	}

	$meta = self::meta_get( $sub );
	$meta['customer_action'] = array(
		'time' => current_time( 'mysql' ),
		'user_id' => $user_id,
		'action' => $status,
	);
	self::meta_save( $subscription_id, $meta );

	global $wpdb;
	$wpdb->update( self::table_name(), array(
		'status' => $status,
		'updated_at' => current_time( 'mysql' ),
	), array( 'id' => $subscription_id ) );

	return true;
}

public static function export_subscribers_csv(): void {
		if ( ! current_user_can( MMGWC_Features::cap_for( 'subscriptions' ) ) ) {
			wp_die( 'Forbidden' );
		}
		$results = self::get_subscriptions( array( 'limit' => 2000, 'page' => 1 ) );
		$rows = $results['rows'] ?? array();

		$filename = 'mmg-subscribers-' . gmdate( 'Y-m-d' ) . '.csv';

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'status', 'email', 'customer_id', 'product_id', 'variation_id', 'quantity', 'interval', 'last_paid', 'next_due', 'last_order_id', 'last_txn_id' ) );

		foreach ( $rows as $r ) {
			fputcsv( $out, array(
				$r['id'] ?? '',
				$r['status'] ?? '',
				$r['email'] ?? '',
				$r['user_id'] ?? '',
				$r['product_id'] ?? '',
				$r['variation_id'] ?? '',
				$r['quantity'] ?? '',
				(string) ( $r['interval_count'] ?? '' ) . ' ' . (string) ( $r['interval_type'] ?? '' ),
				$r['last_paid'] ?? '',
				$r['next_due'] ?? '',
				$r['last_order_id'] ?? '',
				$r['last_txn_id'] ?? '',
			) );
		}

		fclose( $out );
		exit;
	}
}
