<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_QR_Payments_Admin {
	private const CAP = 'mmgwc_access_qr_payments';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_mmgwc_qr_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_mmgwc_qr_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_mmgwc_qr_settings', array( __CLASS__, 'handle_settings' ) );
	}

	public static function handle_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mmg-checkout-woocommerce' ) );
		}
		check_admin_referer( 'mmgwc_qr_create' );

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'amount';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : 'MMG Payment';
		$amount = isset( $_POST['amount'] ) ? wc_format_decimal( wp_unslash( (string) $_POST['amount'] ), wc_get_price_decimals() ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$qty = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;

		$expires_days = isset( $_POST['expires_days'] ) ? max( 1, absint( $_POST['expires_days'] ) ) : 7;
		$expires_days = min( $expires_days, 30 );
		$one_time = isset( $_POST['one_time'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['one_time'] ) ) : 'yes';

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

		$valid = MMGWC_QR_Payments::validate_template_for_admin( $tpl );
		if ( is_wp_error( $valid ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-qr-payments&notice=error&msg=' . rawurlencode( $valid->get_error_message() ) ) );
			exit;
		}

		$ttl   = (int) ( DAY_IN_SECONDS * $expires_days );
		$token = MMGWC_QR_Payments::create_template( $tpl, $ttl );

		// If checkout fallback mode is enabled, create the WooCommerce order now so the generated link is a normal
		// /checkout/order-pay/... URL instead of the custom mmgwc_qr query token.
		if ( MMGWC_QR_Payments::should_use_checkout_fallback() ) {
			$order = MMGWC_QR_Payments::maybe_create_checkout_order_for_token( $token, $tpl, $ttl );
			if ( ! $order ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-qr-payments&notice=error&msg=' . rawurlencode( 'Could not create the checkout order for this QR. Please try again or contact support.' ) ) );
				exit;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-qr-payments&notice=created&token=' . rawurlencode( $token ) ) );
		exit;
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mmg-checkout-woocommerce' ) );
		}
		check_admin_referer( 'mmgwc_qr_delete' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		if ( $token ) {
			MMGWC_QR_Payments::delete_template( $token );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-qr-payments&notice=deleted' ) );
		exit;
	}

	
	public static function handle_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mmg-checkout-woocommerce' ) );
		}
		check_admin_referer( 'mmgwc_qr_settings' );

		$enabled = isset( $_POST['qr_checkout_fallback'] ) ? 'yes' : 'no';

		// Merge-style update so gateway/secret settings are never overwritten.
		MMGWC_Settings::update_partial( array( 'qr_checkout_fallback' => $enabled ) );

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-qr-payments&notice=settings_saved' ) );
		exit;
	}

public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$notice = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Only allow a fixed set of notice keys so an attacker can't plant arbitrary content via URL.
		if ( ! in_array( $notice, array( 'created', 'settings_saved', 'deleted', 'error' ), true ) ) {
			$notice = '';
		}
		$msg = '';
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $token !== '' && ! preg_match( '/^[A-Za-z0-9]{10,128}$/', $token ) ) {
			$token = '';
		}

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'QR Payments' );
		}
		echo '<h1>QR MMG Payments</h1>';

		// Redirect mode settings.
		$settings = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
		$current = ( is_array( $settings ) && isset( $settings['qr_checkout_fallback'] ) ) ? (string) $settings['qr_checkout_fallback'] : 'no';

		echo '<div class="mmgwc-card" style="margin-top:12px;">';
		echo '<h2>QR redirect mode</h2>';
		echo '<p style="max-width:980px;">If the direct QR method is not working on your site, it is almost always due to a security plugin, caching, or hosting security rules stripping custom query parameters or blocking the redirect flow. Enable the option below to generate standard WooCommerce checkout links instead.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_qr_settings">';
		wp_nonce_field( 'mmgwc_qr_settings' );

		$checked = ( $current === 'yes' ) ? 'checked' : '';
		echo '<label style="display:flex;gap:10px;align-items:flex-start;">';
		echo '<input type="checkbox" name="qr_checkout_fallback" value="1" ' . $checked . '>';
		echo '<span><strong>Use WooCommerce checkout links (recommended for hardened sites)</strong><br><span style="color:#524F4F;">When enabled, newly generated QR links will be normal WooCommerce /checkout/order-pay/... links (no custom mmgwc_qr query token). This behaves like Payment Requests: the order is created first, then the customer opens checkout and chooses a payment method to complete payment.</span></span>';
		echo '</label>';

		echo '<p style="margin-top:12px;"><button class="button button-primary" type="submit">Save</button></p>';
		echo '</form>';
		echo '</div>';



		echo '<div class="mmgwc-card">';
		echo '<h2>How it works</h2>';
		echo '<ol style="margin-left:18px">';
		echo '<li>Create a QR payment link below (amount based or product based).</li>';
		echo '<li>Share the link or QR with your customer.</li>';
		echo '<li>Default mode: the plugin creates an order then redirects straight to MMG Checkout. Checkout link mode (enabled above): the QR link is a WooCommerce checkout pay link so the customer lands on checkout first, then selects a payment method.</li>';
		echo '<li>After payment, the order updates in WooCommerce like normal.</li>';
		echo '</ol>';
		echo '<p><strong>Tip:</strong> Use QR payments for fixed amount services, deposits, invoices, or simple products.</p>';
		echo '</div>';

		if ( $notice === 'created' && $token ) {
			$link = MMGWC_QR_Payments::public_link( $token );
			$qr   = MMGWC_QR_Payments::qr_image_url( $link );
			echo '<div class="notice notice-success"><p>QR payment link created.</p></div>';
			echo '<div class="mmgwc-card">';
			echo '<h2>Your QR link</h2>';
			echo '<p><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link ) . '</a></p>';
			// Allow the local SVG data: URI for the QR image only.
			$qr_src = '';
			if ( strpos( $qr, 'data:image/svg+xml;base64,' ) === 0 ) {
				$qr_src = $qr;
			} else {
				$qr_src = esc_url( $qr );
			}
			if ( $qr_src !== '' ) {
				echo '<p><img src="' . esc_attr( $qr_src ) . '" alt="MMG Payment QR" style="max-width:220px;border-radius:12px;border:1px solid #EDEFF1;"></p>';
			}
			echo '</div>';
		} elseif ( $notice === 'error' ) {
			echo '<div class="notice notice-error"><p>QR action could not be completed. Please try again.</p></div>';
		} elseif ( $notice === 'deleted' ) {
			echo '<div class="notice notice-success"><p>QR link deleted.</p></div>';
		}

		echo '<div class="mmgwc-grid">';

		// Generator.
		echo '<div class="mmgwc-card">';
		echo '<h2>Create a QR payment</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_qr_create" />';
		wp_nonce_field( 'mmgwc_qr_create' );

		echo '<p><label><strong>Type</strong></label><br>';
		echo '<select name="type" class="regular-text">';
		echo '<option value="amount">Fixed Amount</option>';
		echo '<option value="product">Product</option>';
		echo '</select></p>';

		echo '<p><label><strong>Label</strong></label><br>';
		echo '<input type="text" name="label" class="regular-text" value="MMG Payment" /></p>';

		echo '<p><label><strong>Amount</strong> (store currency)</label><br>';
		echo '<input type="text" name="amount" class="regular-text" placeholder="e.g. 5000" /></p>';

		echo '<p><label><strong>Product ID</strong></label><br>';
		echo '<input type="number" name="product_id" class="regular-text" placeholder="e.g. 123" /></p>';

		echo '<p><label><strong>Variation ID</strong> (optional)</label><br>';
		echo '<input type="number" name="variation_id" class="regular-text" placeholder="e.g. 456" /></p>';

		echo '<p><label><strong>Quantity</strong></label><br>';
		echo '<input type="number" name="qty" class="small-text" value="1" min="1" /></p>';

		echo '<p><label><strong>Expires in</strong> (days)</label><br>';
		echo '<input type="number" name="expires_days" class="small-text" value="7" min="1" /></p>';

		echo '<p><label><strong>Reuse the same order until paid</strong></label><br>';
		echo '<select name="one_time" class="regular-text">';
		echo '<option value="yes">Yes (recommended)</option>';
		echo '<option value="no">No (create a new order each scan)</option>';
		echo '</select></p>';

		echo '<p><button class="button button-primary" type="submit">Create QR Link</button></p>';
		echo '</form>';

		echo '<hr>';
		echo '<h3>Shortcodes</h3>';
		echo '<p>Amount based:</p>';
		echo '<code>[mmgwc_qr_pay type="amount" label="Pay with MMG" amount="5000" auto_redirect="no" show_qr="yes"]</code>';
		echo '<p style="margin-top:10px">Product based:</p>';
		echo '<code>[mmgwc_qr_pay type="product" label="Buy now" product_id="123" qty="1" auto_redirect="no" show_qr="yes"]</code>';

		echo '</div>';

		// Recent links.
		echo '<div class="mmgwc-card">';
		echo '<h2>Recent QR links</h2>';
		$index = get_option( MMGWC_QR_Payments::INDEX_OPTION, array() );
		if ( ! is_array( $index ) || empty( $index ) ) {
			echo '<p>No QR links created yet.</p>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr><th>Label</th><th>Type</th><th>Expires</th><th>Link</th><th>Action</th></tr></thead><tbody>';
			foreach ( array_reverse( $index, true ) as $tok => $row ) {
				$link = MMGWC_QR_Payments::public_link( (string) $tok );
				$expires = isset( $row['expires_at'] ) && (int) $row['expires_at'] ? date_i18n( 'Y-m-d H:i', (int) $row['expires_at'] ) : '-';
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['type'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $expires ) . '</td>';
				echo '<td><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">Open</a></td>';
				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				echo '<input type="hidden" name="action" value="mmgwc_qr_delete" />';
				echo '<input type="hidden" name="token" value="' . esc_attr( (string) $tok ) . '" />';
				wp_nonce_field( 'mmgwc_qr_delete' );
				echo '<button class="button button-small" type="submit">Delete</button>';
				echo '</form>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '</div>'; // grid

		echo '</div>';
	}
}
