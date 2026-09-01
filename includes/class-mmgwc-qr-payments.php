<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QR Payments
 *
 * Generates shareable links (and QR images) that redirect straight to MMG Checkout.
 * Link flow:
 * - Admin / shortcode creates a QR template token (requires manage_woocommerce).
 * - Customer opens / scans: ?mmgwc_qr=<token>
 * - Plugin creates (or reuses) a WooCommerce order then redirects to MMG.
 */
final class MMGWC_QR_Payments {
	private const TRANSIENT_PREFIX = 'mmgwc_qr_tpl_';
	private const ORDER_LOCK_PREFIX = 'mmgwc_qr_order_lock_';
	public const INDEX_OPTION      = 'mmgwc_qr_templates_index';

	private static $handled = false;

	public static function init(): void {
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle_public_parse' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_public' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'disable_canonical_redirect' ), 10, 2 );
		// Generator AJAX: privileged logged-in users only (no `nopriv`).
		add_action( 'wp_ajax_mmgwc_qr_create_template', array( __CLASS__, 'ajax_create_template' ) );

		add_shortcode( 'mmgwc_qr_pay', array( __CLASS__, 'shortcode' ) );
	}

	private static function maybe_mark_nocache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
		add_action( 'send_headers', function() {
			nocache_headers();
		}, 0 );
	}

	public static function maybe_handle_public(): void {
		if ( self::$handled ) {
			return;
		}

		$raw = null;
		if ( isset( $_GET['mmgwc_qr'] ) ) {
			$raw = wp_unslash( $_GET['mmgwc_qr'] );
		} else {
			$qv = function_exists( 'get_query_var' ) ? get_query_var( 'mmgwc_qr' ) : '';
			if ( $qv !== '' ) {
				$raw = $qv;
			}
		}

		if ( $raw === null ) {
			return;
		}

		self::$handled = true;
		self::maybe_mark_nocache();
		self::handle_public_token( $raw );
	}

	public static function maybe_handle_public_parse( $wp ): void {
		if ( self::$handled ) {
			return;
		}
		$raw = null;

		if ( is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) && ! empty( $wp->query_vars['mmgwc_qr'] ) ) {
			$raw = $wp->query_vars['mmgwc_qr'];
		} elseif ( isset( $_GET['mmgwc_qr'] ) ) {
			$raw = wp_unslash( $_GET['mmgwc_qr'] );
		}

		if ( is_array( $raw ) ) {
			$raw = reset( $raw );
		}

		if ( $raw === null ) {
			return;
		}

		self::$handled = true;
		self::maybe_mark_nocache();
		self::handle_public_token( $raw );
	}

	public static function disable_canonical_redirect( $redirect_url, $requested_url ) {
		if ( ! empty( $_GET['mmgwc_qr'] ) ) {
			return false;
		}
		if ( function_exists( 'get_query_var' ) ) {
			$qv = (string) get_query_var( 'mmgwc_qr' );
			if ( $qv !== '' ) {
				return false;
			}
		}
		return $redirect_url;
	}

	private static function handle_public_token( $token ): void {
		if ( is_array( $token ) ) {
			$token = reset( $token );
		}
		$token = sanitize_text_field( (string) $token );
		if ( ! preg_match( '/^[A-Za-z0-9]{10,128}$/', $token ) ) {
			self::render_public_message( 'Invalid payment link.' );
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			self::render_public_message( 'WooCommerce is required for this payment link.' );
			return;
		}

		$tpl = self::get_template( $token );
		if ( ! is_array( $tpl ) ) {
			self::render_public_message( 'This payment link has expired or is invalid.' );
			return;
		}

		$now = time();
		$expires_at = isset( $tpl['expires_at'] ) ? (int) $tpl['expires_at'] : 0;
		$one_time = isset( $tpl['one_time'] ) ? (string) $tpl['one_time'] : 'yes';

		$order_id = isset( $tpl['order_id'] ) ? absint( $tpl['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( $order && $order->is_paid() ) {
			if ( $one_time === 'yes' ) {
				self::render_public_message( 'This invoice is already paid. Thank you.' );
				return;
			}
			// A reusable link issues its next order only after the previous one is
			// paid. Repeated requests reuse one active unpaid order.
			$order = false;
		}

		if ( $expires_at && $now > $expires_at ) {
			self::render_public_message( 'This payment link has expired. Please request a new one.' );
			return;
		}

		if ( ! $order ) {
			$ttl = $expires_at ? max( 60, ( $expires_at - $now ) ) : DAY_IN_SECONDS * 7;
			$order = self::maybe_create_checkout_order_for_token( $token, $tpl, $ttl );
			if ( ! $order ) {
				self::render_public_message( 'This payment link is being opened. Please try again.' );
				return;
			}
			$order_id = (int) $order->get_id();
		}

		if ( self::should_use_checkout_fallback() ) {
			self::redirect_to_checkout_payment( (int) $order->get_id() );
			return;
		}
		self::redirect_to_mmg( (int) $order->get_id() );
	}

	/**
	 * AJAX: create a QR template and return a link + QR image.
	 *
	 * Requires a logged-in user with manage_woocommerce. The previous `nopriv` handler
	 * has been removed so unauthenticated visitors cannot flood the site with transients
	 * or create orders via the shortcode.
	 */
	public static function ajax_create_template(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mmgwc_qr_create' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
		}

		$type         = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'amount';
		$label        = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : 'MMG Payment';
		$amount       = isset( $_POST['amount'] ) ? wc_format_decimal( wp_unslash( (string) $_POST['amount'] ), wc_get_price_decimals() ) : '';
		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$qty          = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;

		$expires_days = isset( $_POST['expires_days'] ) ? max( 1, absint( $_POST['expires_days'] ) ) : 7;
		$expires_days = min( $expires_days, 30 );
		$one_time     = isset( $_POST['one_time'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['one_time'] ) ) : 'yes';

		$tpl = array(
			'type'         => $type,
			'label'        => $label,
			'amount'       => $amount,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'qty'          => $qty,
			'one_time'     => ( $one_time === 'no' ) ? 'no' : 'yes',
			'order_id'     => 0,
			'created_at'   => time(),
			'expires_at'   => time() + ( DAY_IN_SECONDS * $expires_days ),
		);

		$valid = self::validate_template( $tpl );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 400 );
		}

		$ttl   = (int) ( DAY_IN_SECONDS * $expires_days );
		$token = self::create_template( $tpl, $ttl );

		$link = self::public_link( $token );
		if ( self::should_use_checkout_fallback() ) {
			$order = self::maybe_create_checkout_order_for_token( $token, $tpl, $ttl );
			if ( $order && is_object( $order ) && method_exists( $order, 'get_checkout_payment_url' ) ) {
				$link = self::build_order_pay_url( (int) $order->get_id() );
			}
		}

		$qr_img = self::qr_image_url( $link );

		wp_send_json_success( array(
			'token'  => $token,
			'link'   => $link,
			'qr_img' => $qr_img,
		) );
	}

	public static function shortcode( $atts = array(), $content = '' ): string {
		// The shortcode is an admin convenience: only render the generator UI for privileged users.
		// Customers scanning a QR link do not hit the shortcode. They hit the public handler.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			return '';
		}

		$atts = shortcode_atts( array(
			'type'          => 'amount',
			'label'         => 'Pay with MMG',
			'description'   => 'Generate a secure MMG payment link and pay instantly.',
			'amount'        => '',
			'product_id'    => '',
			'variation_id'  => '',
			'qty'           => '1',
			'expires_days'  => '7',
			'one_time'      => 'yes',
			'auto_redirect' => 'no',
			'show_qr'       => 'yes',
		), $atts, 'mmgwc_qr_pay' );

		wp_enqueue_style( 'mmgwc-qr-frontend', MMGWC_PLUGIN_URL . 'assets/css/qr-frontend.css', array(), MMGWC_VERSION );
		wp_enqueue_script( 'mmgwc-qr-frontend', MMGWC_PLUGIN_URL . 'assets/js/qr-frontend.js', array(), MMGWC_VERSION, true );
		wp_localize_script( 'mmgwc-qr-frontend', 'MMGWC_QR', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'mmgwc_qr_create' ),
		) );

		$type = sanitize_text_field( (string) $atts['type'] );
		$label = sanitize_text_field( (string) $atts['label'] );
		$desc = sanitize_text_field( (string) $atts['description'] );

		$data = array(
			'type'         => $type,
			'label'        => $label,
			'amount'       => (string) $atts['amount'],
			'productId'    => (string) $atts['product_id'],
			'variationId'  => (string) $atts['variation_id'],
			'qty'          => (string) $atts['qty'],
			'expiresDays'  => (string) $atts['expires_days'],
			'oneTime'      => (string) $atts['one_time'],
			'autoRedirect' => (string) $atts['auto_redirect'],
			'showQr'       => (string) $atts['show_qr'],
		);

		ob_start();
		?>
		<div class="mmgwc-qr" data-type="<?php echo esc_attr( $data['type'] ); ?>"
			data-label="<?php echo esc_attr( $data['label'] ); ?>"
			data-amount="<?php echo esc_attr( $data['amount'] ); ?>"
			data-product-id="<?php echo esc_attr( $data['productId'] ); ?>"
			data-variation-id="<?php echo esc_attr( $data['variationId'] ); ?>"
			data-qty="<?php echo esc_attr( $data['qty'] ); ?>"
			data-expires-days="<?php echo esc_attr( $data['expiresDays'] ); ?>"
			data-one-time="<?php echo esc_attr( $data['oneTime'] ); ?>"
			data-auto-redirect="<?php echo esc_attr( $data['autoRedirect'] ); ?>">
			<h3><?php echo esc_html( $label ); ?></h3>
			<p><?php echo esc_html( $desc ); ?></p>

			<div class="mmgwc-qr-actions">
				<button type="button" class="mmgwc-qr-generate"><?php echo esc_html__( 'Generate Link', 'mmg-checkout-woocommerce' ); ?></button>
				<button type="button" class="mmgwc-qr-copy"><?php echo esc_html__( 'Copy link', 'mmg-checkout-woocommerce' ); ?></button>
			</div>

			<div class="mmgwc-qr-result">
				<?php if ( (string) $data['showQr'] !== 'no' ) : ?>
					<img class="mmgwc-qr-img" src="" alt="" />
				<?php endif; ?>
				<div style="flex:1;min-width:220px;">
					<a class="mmgwc-qr-link" href="#" target="_blank" rel="noopener noreferrer"></a>
					<div class="mmgwc-qr-msg"></div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function validate_template_for_admin( array $tpl ) {
		return self::validate_template( $tpl );
	}

	public static function create_template( array $tpl, int $ttl_seconds ): string {
		// Cryptographically random token.
		$token = bin2hex( random_bytes( 32 ) );
		self::set_template( $token, $tpl, $ttl_seconds );
		self::index_add( $token, $tpl );
		return $token;
	}

	public static function maybe_create_checkout_order_for_token( string $token, array $tpl, int $ttl_seconds ) {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'MMGWC_Atomic_Option' ) ) {
			return false;
		}

		$lock_key = self::ORDER_LOCK_PREFIX . hash( 'sha256', $token );
		$owned_lock = MMGWC_Atomic_Option::acquire_lock( $lock_key, 30 );
		if ( $owned_lock === '' ) {
			// Another request may have just published the winning order. Reuse it
			// when visible instead of creating a competing order.
			$fresh = self::get_template( $token );
			$fresh_id = is_array( $fresh ) ? absint( $fresh['order_id'] ?? 0 ) : 0;
			return $fresh_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $fresh_id ) : false;
		}

		try {
			$fresh = self::get_template( $token );
			if ( is_array( $fresh ) ) {
				$tpl = $fresh;
			}

			$existing_id = isset( $tpl['order_id'] ) ? absint( $tpl['order_id'] ) : 0;
			if ( $existing_id && function_exists( 'wc_get_order' ) ) {
				$existing = wc_get_order( $existing_id );
				if ( $existing && ( ! $existing->is_paid() || (string) ( $tpl['one_time'] ?? 'yes' ) === 'yes' ) ) {
					return $existing;
				}
			}

			$order = self::create_order_from_template( $token, $tpl );
			if ( $order && is_object( $order ) && method_exists( $order, 'get_id' ) ) {
				$tpl['order_id'] = (int) $order->get_id();
				self::set_template( $token, $tpl, max( 60, $ttl_seconds ) );
				return $order;
			}

			return false;
		} finally {
			MMGWC_Atomic_Option::release_lock( $lock_key, $owned_lock );
		}
	}

	private static function validate_template( array $tpl ) {
		$type = isset( $tpl['type'] ) ? (string) $tpl['type'] : 'amount';
		if ( $type === 'amount' ) {
			$amount = isset( $tpl['amount'] ) ? (string) $tpl['amount'] : '';
			if ( $amount === '' || (float) $amount <= 0 ) {
				return new WP_Error( 'mmgwc_qr_bad_amount', 'Please enter a valid amount.' );
			}
		} elseif ( $type === 'product' ) {
			$product_id = isset( $tpl['variation_id'] ) && absint( $tpl['variation_id'] ) ? absint( $tpl['variation_id'] ) : absint( $tpl['product_id'] ?? 0 );
			if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
				return new WP_Error( 'mmgwc_qr_bad_product', 'Please enter a valid product or variation ID.' );
			}
			$prod = wc_get_product( $product_id );
			if ( ! $prod || $prod->get_status() !== 'publish' ) {
				return new WP_Error( 'mmgwc_qr_bad_product', 'Product is not available for QR payments.' );
			}
		} else {
			return new WP_Error( 'mmgwc_qr_bad_type', 'Invalid QR payment type.' );
		}
		return true;
	}

	public static function public_link( string $token ): string {
		if ( self::should_use_checkout_fallback() && function_exists( 'wc_get_order' ) ) {
			$tpl = self::get_template( $token );
			if ( is_array( $tpl ) ) {
				$order_id = isset( $tpl['order_id'] ) ? absint( $tpl['order_id'] ) : 0;
				if ( $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						return self::build_order_pay_url( $order_id );
					}
				}
			}
		}

		$use_pretty = false;
		if ( (string) get_option( 'permalink_structure' ) !== '' ) {
			$use_pretty = (bool) apply_filters( 'mmgwc_qr_pretty_links', false );
		}
		if ( $use_pretty ) {
			return home_url( '/mmg-qr/' . rawurlencode( $token ) . '/' );
		}
		return add_query_arg( array( 'mmgwc_qr' => $token ), home_url( '/' ) );
	}

	/**
	 * Local QR image as a data: SVG URI. Tokens never leave the site.
	 * Returns an empty string on failure so callers can fall back gracefully.
	 */
	public static function qr_image_url( string $data ): string {
		if ( class_exists( 'MMGWC_QR_Generator' ) ) {
			$uri = MMGWC_QR_Generator::svg_data_uri( $data, 220 );
			if ( $uri !== '' ) {
				return $uri;
			}
		}
		return '';
	}

	private static function get_template( string $token ) {
		return get_transient( self::TRANSIENT_PREFIX . $token );
	}

	private static function set_template( string $token, array $tpl, int $ttl ): void {
		set_transient( self::TRANSIENT_PREFIX . $token, $tpl, $ttl );
	}

	public static function delete_template( string $token ): void {
		delete_transient( self::TRANSIENT_PREFIX . $token );
		self::index_remove( $token );
	}

	private static function index_add( string $token, array $tpl ): void {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$index[ $token ] = array(
			'type'       => isset( $tpl['type'] ) ? (string) $tpl['type'] : '',
			'label'      => isset( $tpl['label'] ) ? (string) $tpl['label'] : '',
			'amount'     => isset( $tpl['amount'] ) ? (string) $tpl['amount'] : '',
			'product_id' => isset( $tpl['product_id'] ) ? absint( $tpl['product_id'] ) : 0,
			'created_at' => isset( $tpl['created_at'] ) ? (int) $tpl['created_at'] : time(),
			'expires_at' => isset( $tpl['expires_at'] ) ? (int) $tpl['expires_at'] : 0,
		);

		if ( count( $index ) > 50 ) {
			uasort( $index, function( $a, $b ) {
				return ( (int) ( $a['created_at'] ?? 0 ) ) <=> ( (int) ( $b['created_at'] ?? 0 ) );
			} );
			$index = array_slice( $index, -50, null, true );
		}

		update_option( self::INDEX_OPTION, $index, false );
	}

	private static function index_remove( string $token ): void {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( is_array( $index ) && isset( $index[ $token ] ) ) {
			unset( $index[ $token ] );
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Create an order from a template then tag it.
	 */
	private static function create_order_from_template( $token, array $tpl ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return false;
		}
		$valid = self::validate_template( $tpl );
		if ( is_wp_error( $valid ) ) {
			return false;
		}

		if ( is_array( $token ) ) {
			$token = reset( $token );
		}
		$token = sanitize_text_field( (string) $token );
		if ( $token === '' ) {
			return false;
		}

		$user_id = get_current_user_id();
		$args    = array();

		// Only attach the current user as the customer when this is clearly a public front-end
		// visit. In admin / AJAX we leave the order as guest so the shareable QR link works
		// for whichever person the merchant ends up sending it to.
		$is_front_end = ! is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		$is_privileged = ( $user_id > 0 ) && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) );

		if ( $user_id > 0 && $is_front_end && ! $is_privileged ) {
			$args['customer_id'] = $user_id;
		}

		$order = false;
		try {
			$order = wc_create_order( $args );
			if ( ! $order ) {
				return false;
			}

			$type = (string) ( $tpl['type'] ?? 'amount' );
			$label = (string) ( $tpl['label'] ?? 'MMG Payment' );

			if ( $type === 'product' ) {
				$pid = absint( $tpl['variation_id'] ?? 0 );
				if ( ! $pid ) {
					$pid = absint( $tpl['product_id'] ?? 0 );
				}
				$qty = max( 1, absint( $tpl['qty'] ?? 1 ) );
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : false;
				if ( ! $product || $product->get_status() !== 'publish' ) {
					self::discard_uncommitted_order( $order );
					return false;
				}
				$order->add_product( $product, $qty );
			} else {
				$amount = isset( $tpl['amount'] ) ? (float) $tpl['amount'] : 0;
				if ( $amount <= 0 ) {
					self::discard_uncommitted_order( $order );
					return false;
				}
				$item = new WC_Order_Item_Fee();
				$item->set_name( $label );
				$item->set_amount( $amount );
				$item->set_total( $amount );
				$order->add_item( $item );
			}

			$order->calculate_totals();

			$order->update_meta_data( '_mmgwc_qr_token', $token );
			$order->update_meta_data( '_mmgwc_qr_created', time() );

			if ( ! self::should_use_checkout_fallback() ) {
				$order->set_payment_method( 'mmg_checkout' );
				$order->set_payment_method_title( 'MMG Checkout' );
			} else {
				$order->update_meta_data( '_mmgwc_qr_checkout_mode', 'order_pay' );
			}

			if ( $order->get_status() !== 'pending' ) {
				$order->set_status( 'pending' );
			}

			$order->save();
			return $order;
		} catch ( Exception $e ) {
			self::discard_uncommitted_order( $order );
			MMGWC_Logger::error( 'QR order creation failed: ' . $e->getMessage() );
		}

		return false;
	}

	private static function discard_uncommitted_order( $order ): void {
		if ( ! is_object( $order ) ) {
			return;
		}
		if ( method_exists( $order, 'delete' ) ) {
			$order->delete( true );
			return;
		}
		if ( method_exists( $order, 'update_status' ) ) {
			$order->update_status( 'cancelled', 'QR order creation did not complete.' );
		}
	}


	public static function should_use_checkout_fallback(): bool {
		if ( class_exists( 'MMGWC_Settings' ) ) {
			$val = (string) MMGWC_Settings::get( 'qr_checkout_fallback', 'no' );
			return $val === 'yes';
		}
		$settings = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
		$val = ( is_array( $settings ) && isset( $settings['qr_checkout_fallback'] ) ) ? (string) $settings['qr_checkout_fallback'] : 'no';
		return $val === 'yes';
	}

	private static function build_order_pay_url( int $order_id ): string {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_checkout_url' ) || ! function_exists( 'wc_get_endpoint_url' ) ) {
			return home_url( '/' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return home_url( '/' );
		}

		$base = wc_get_endpoint_url( 'order-pay', $order_id, wc_get_checkout_url() );

		return add_query_arg(
			array(
				'pay_for_order' => 'true',
				'key'           => $order->get_order_key(),
			),
			$base
		);
	}

	private static function redirect_to_checkout_payment( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			self::render_public_message( 'Could not load your order. Please contact the store.' );
			return;
		}

		$pay_url = self::build_order_pay_url( $order_id );
		self::maybe_mark_nocache();
		wp_safe_redirect( $pay_url );
		exit;
	}

	private static function redirect_to_mmg( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			self::render_public_message( 'Order not found.' );
			return;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		if ( ! function_exists( 'WC' ) ) {
			self::render_public_message( 'WooCommerce is required.' );
			return;
		}

		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = isset( $gateways['mmg_checkout'] ) ? $gateways['mmg_checkout'] : null;

		if ( ! $gateway || ! is_object( $gateway ) || ! method_exists( $gateway, 'process_payment' ) ) {
			self::render_public_message( 'MMG Checkout is not available. Please contact the store.' );
			return;
		}

		if ( method_exists( $gateway, 'get_option' ) ) {
			$mode = (string) $gateway->get_option( 'mode', 'sandbox' );
			$client = (string) $gateway->get_option( $mode === 'live' ? 'live_client_id' : 'sandbox_client_id', '' );
			if ( $client === '' ) {
				self::render_public_message( 'MMG Checkout is not configured yet. Please contact the store.' );
				return;
			}
		}

		$result = $gateway->process_payment( $order_id );
		$redirect = '';
		if ( is_array( $result ) && ! empty( $result['redirect'] ) ) {
			$redirect = (string) $result['redirect'];
		}
		if ( $redirect === '' ) {
			self::render_public_message( 'Unable to start MMG payment. Please try again or contact the store.' );
			return;
		}

		wp_redirect( esc_url_raw( $redirect ) );
		exit;
	}

	private static function render_public_message( string $message ): void {
		status_header( 200 );
		nocache_headers();
		?>
		<!doctype html>
		<html>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<title>MMG Payment</title>
			<style>
				body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f5f5ff;margin:0;padding:28px;color:#202224;}
				.box{max-width:640px;margin:0 auto;background:#fff;border:1px solid #edeff1;border-radius:14px;padding:18px 18px;box-shadow:0 10px 26px rgba(18,25,33,.06);}
				h1{font-size:20px;margin:0 0 10px 0;color:#101010;}
				p{margin:0 0 10px 0;line-height:1.5;color:#524f4f;}
				a{color:#3655f6;text-decoration:underline;}
			</style>
		</head>
		<body>
			<div class="box">
				<h1>MMG Payment</h1>
				<p><?php echo esc_html( $message ); ?></p>
				<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Return to store</a></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}
}
