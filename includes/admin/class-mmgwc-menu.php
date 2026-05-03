<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Menu {
	private const CAP = MMGWC_Features::CAP_MENU;

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 58 );
	}

	public static function register_menu(): void {
		if ( ! function_exists( 'add_menu_page' ) ) {
			return;
		}

		add_menu_page(
			'MMG Checkout',
			'MMG Checkout',
			self::CAP,
			'mmgwc',
			array( __CLASS__, 'render_overview' ),
			'dashicons-cart',
			56
		);

		// Overview entry.
		add_submenu_page(
			'mmgwc',
			'MMG Checkout',
			'Overview',
			self::CAP,
			'mmgwc',
			array( __CLASS__, 'render_overview' )
		);

		add_submenu_page(
			'mmgwc',
			'MMG Checkout Settings',
			'Settings',
			MMGWC_Features::cap_for( 'settings' ),
			'mmgwc-settings',
			array( __CLASS__, 'render_settings' )
		);

		if ( MMGWC_Features::is_enabled( 'importer' ) ) {
			add_submenu_page(
			'mmgwc',
			'MMG Checkout Importer',
			'Importer',
			MMGWC_Features::cap_for( 'importer' ),
			'mmgwc-import',
			array( 'MMGWC_Importer', 'render_page' )
			);
		}

		
		if ( MMGWC_Features::is_enabled( 'exports' ) ) {
			add_submenu_page(
			'mmgwc',
			'MMG Checkout Exports',
			'Exports',
			MMGWC_Features::cap_for( 'exports' ),
			'mmgwc-exports',
			array( 'MMGWC_Exports', 'render_page' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'analytics' ) ) {
			add_submenu_page(
			'mmgwc',
			'MMG Checkout Analytics',
			'Analytics',
			MMGWC_Features::cap_for( 'analytics' ),
			'mmgwc-analytics',
			array( 'MMGWC_Analytics', 'render_page' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'payment_requests' ) ) {
			add_submenu_page(
			'mmgwc',
			'MMG Checkout Payment Requests',
			'Payment Requests',
			MMGWC_Features::cap_for( 'payment_requests' ),
			'mmgwc-payment-requests',
			array( 'MMGWC_Payment_Requests', 'render_list' )
			);

			add_submenu_page(
			'mmgwc',
			'Create Payment Request',
			'Create Request',
			MMGWC_Features::cap_for( 'payment_requests' ),
			'mmgwc-payment-request-create',
			array( 'MMGWC_Payment_Requests', 'render_create' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'qr_payments' ) ) {
			add_submenu_page(
				'mmgwc',
				'MMG Checkout QR Payments',
				'QR Payments',
				MMGWC_Features::cap_for( 'qr_payments' ),
				'mmgwc-qr-payments',
				array( 'MMGWC_QR_Payments_Admin', 'render' )
			);
		}


		if ( MMGWC_Features::is_enabled( 'subscriptions' ) ) {
			add_submenu_page(
				'mmgwc',
				'MMG Checkout Subscriptions',
				'Subscriptions',
				MMGWC_Features::cap_for( 'subscriptions' ),
				'mmgwc-subscriptions',
				array( 'MMGWC_Subscriptions_Admin', 'render_page' )
			);
		}


		

		if ( MMGWC_Features::is_enabled( 'diagnostics' ) ) {
			add_submenu_page(
				'mmgwc',
				'MMG Checkout Diagnostics',
				'Diagnostics',
				MMGWC_Features::cap_for( 'diagnostics' ),
				'mmgwc-diagnostics',
				array( 'MMGWC_Diagnostics', 'render_page' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'support_bundle' ) ) {
			add_submenu_page(
			'mmgwc',
			'MMG Checkout Support Bundle',
			'Support Bundle',
			MMGWC_Features::cap_for( 'support_bundle' ),
			'mmgwc-support-bundle',
			array( 'MMGWC_Support_Bundle', 'render_page' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'features_manager' ) ) {
			add_submenu_page(
				'mmgwc',
				'MMG Checkout Features',
				'Features Manager',
				MMGWC_Features::cap_for( 'features_manager' ),
				'mmgwc-features',
				array( 'MMGWC_Feature_Manager', 'render_page' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'role_manager' ) ) {
			add_submenu_page(
				'mmgwc',
				'MMG Checkout Role Manager',
				'Role Manager',
				MMGWC_Features::cap_for( 'role_manager' ),
				'mmgwc-roles',
				array( 'MMGWC_Role_Manager', 'render_page' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'logs' ) ) {
			add_submenu_page(
			'mmgwc',
			'MMG Checkout Logs',
			'Logs',
			MMGWC_Features::cap_for( 'logs' ),
			'mmgwc-logs',
			array( __CLASS__, 'render_logs' )
			);
		}

		if ( MMGWC_Features::is_enabled( 'help' ) ) {
			add_submenu_page(
			'mmgwc',
			'MMG Checkout Help',
			'Help',
			MMGWC_Features::cap_for( 'help' ),
			'mmgwc-help',
			array( __CLASS__, 'render_help' )
			);
		}
	}

	public static function render_overview(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$settings = MMGWC_Settings::get_all();
		$enabled  = ( $settings['enabled'] ?? 'no' ) === 'yes';
		$mode     = $settings['mode'] ?? 'sandbox';

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Overview' );
		}
		echo '<h1>MMG Checkout</h1>';

		echo '<p>MMG Checkout adds MMG Merchant Checkout to WooCommerce, including payment requests, subscriptions, exports, diagnostics, and order tools.</p>';

		echo '<p><strong>Status:</strong> ' . ( $enabled ? '<span style="color:#1a7f37">Enabled</span>' : '<span style="color:#b32d2e">Disabled</span>' ) . '</p>';
		echo '<p><strong>Mode:</strong> ' . esc_html( ucfirst( (string) $mode ) ) . '</p>';

		echo '<h2>Quick Start</h2>';
		echo '<ol style="max-width:900px">';
		echo '<li>Request your <strong>Sandbox</strong> (UAT) MMG credential package from MMG Merchant Services. Copy the callback URL from <strong>Diagnostics</strong> and include it in your request. MMG will send a zip named <strong>MMG Checkout UAT (#######)</strong>.</li>';
		echo '<li>Install this plugin, then open <strong>Importer</strong> and upload the UAT zip, or upload setup.cfg plus the public and private keys separately.</li>';
		echo '<li>Enable <strong>MMG Checkout</strong> in WooCommerce → Settings → Payments and keep the plugin mode set to <strong>Sandbox</strong>.</li>';
		echo '<li>Run your tests and confirm: redirect to MMG, return to your site, order status updates, emails sent, and MMG IDs saved on the order.</li>';
		echo '<li>Record a short screen video showing a successful Sandbox payment and send it to the MMG Merchant Services contact who issued your Sandbox package.</li>';
		echo '</ol>';

		echo '<h2>Go Live Checklist</h2>';
		echo '<ul style="max-width:900px;list-style:disc;padding-left:18px">';
		echo '<li>After MMG confirms your Sandbox test, request your <strong>Live</strong> credential package from MMG Merchant Services.</li>';
		echo '<li>Import the <strong>Live</strong> zip in <strong>Importer</strong> (do not reuse Sandbox keys).</li>';
		echo '<li>Switch the plugin mode to <strong>Live</strong> in Settings.</li>';
		echo '<li>If MMG asks for a response or callback URL, copy it from <strong>Diagnostics</strong> and include it in your email request to MMG Merchant Services. They will configure it on their side.</li>';
		echo '<li>Run a small live test payment and confirm: order status updates, customer email sent, and MMG IDs saved on the order.</li>';
		echo '</ul>';

		echo '<h2>Quick Links</h2>';
		echo '<p>';

		if ( MMGWC_Features::user_can( 'settings' ) ) {
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-settings' ) ) . '">Settings</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'importer' ) && MMGWC_Features::user_can( 'importer' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-import' ) ) . '">Importer</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'diagnostics' ) && MMGWC_Features::user_can( 'diagnostics' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-diagnostics' ) ) . '">Diagnostics</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'payment_requests' ) && MMGWC_Features::user_can( 'payment_requests' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-payment-requests' ) ) . '">Payment Requests</a> ';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-payment-request-create' ) ) . '">Create Request</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'subscriptions' ) && MMGWC_Features::user_can( 'subscriptions' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-subscriptions' ) ) . '">Subscriptions</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'exports' ) && MMGWC_Features::user_can( 'exports' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-exports' ) ) . '">Exports</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'analytics' ) && MMGWC_Features::user_can( 'analytics' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-analytics' ) ) . '">Analytics</a> ';
		}
				if ( MMGWC_Features::is_enabled( 'qr_payments' ) && MMGWC_Features::user_can( 'qr_payments' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-qr-payments' ) ) . '">QR Payments</a> ';
		}

		if ( MMGWC_Features::is_enabled( 'support_bundle' ) && MMGWC_Features::user_can( 'support_bundle' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-support-bundle' ) ) . '">Support Bundle</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'features_manager' ) && MMGWC_Features::user_can( 'features_manager' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-features' ) ) . '">Features Manager</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'role_manager' ) && MMGWC_Features::user_can( 'role_manager' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-roles' ) ) . '">Role Manager</a> ';
		}
		if ( MMGWC_Features::is_enabled( 'help' ) && MMGWC_Features::user_can( 'help' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-help' ) ) . '">Help</a> ';
		}

		echo '</p>';

		echo '<h2>Support Workflow</h2>';
		echo '<ol style="max-width:900px">';
		echo '<li>Collect the <strong>WooCommerce Order ID</strong> and the <strong>MMG Transaction ID</strong>.</li>';
		echo '<li>Check the MMG dashboard for the transaction status.</li>';
		echo '<li>Open the order in WooCommerce and use <strong>Verify payment</strong>.</li>';
		echo '<li>If still stuck, check the plugin logs and generate a <strong>Support Bundle</strong>.</li>';
		echo '</ol>';

		echo '<h2>Security Tips</h2>';
		echo '<ul style="max-width:900px;list-style:disc;padding-left:18px">';
		echo '<li>Do not email keys or share the MMG zip. Use the Importer and limit admin users.</li>';
		echo '<li>Consider using wp-config.php constants for production sites to keep secrets out of the database.</li>';
		echo '<li>Keep WordPress, WooCommerce, and this plugin updated.</li>';
		echo '</ul>';

			echo '</div>';
	}

	public static function render_settings(): void {
		if ( ! current_user_can( MMGWC_Features::cap_for( 'settings' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		if ( ! class_exists( 'WC_Payment_Gateway' ) || ! class_exists( 'WC_Gateway_MMGWC' ) ) {
			echo '<div class="wrap mmgwc-wrap">';
			if ( class_exists( 'MMGWC_Admin_UI' ) ) {
				MMGWC_Admin_UI::brandbar( 'Settings' );
			}
			echo '<h1>MMG Checkout Settings</h1><p>WooCommerce is not active. Please activate WooCommerce to configure MMG Checkout.</p>';
			echo '</div>';
			return;
		}

		$gateway = new WC_Gateway_MMGWC();
		$gateway->init_form_fields();
		$gateway->init_settings();

		if ( isset( $_POST['mmgwc_save_settings'] ) ) {
			check_admin_referer( 'mmgwc_save_settings', 'mmgwc_nonce' );

			$post_data = wp_unslash( $_POST );
			$new_values = array();
			foreach ( $gateway->form_fields as $key => $field ) {
				$type = $field['type'] ?? 'text';
				if ( in_array( $type, array( 'title' ), true ) ) {
					continue;
				}
				$new_values[ $key ] = $gateway->get_field_value( $key, $field, $post_data );
			}

			// Merge with the existing option so unrelated keys (for example qr_checkout_fallback,
			// stored by the QR admin page) are not silently wiped. Encryption of protected
			// fields and autoload=no are handled by MMGWC_Settings::update_partial().
			MMGWC_Settings::update_partial( $new_values );

			MMGWC_Logger::info( 'MMG gateway settings saved', array( 'user_id' => get_current_user_id() ) );

			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';

			$gateway->init_settings();
		}

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Settings' );
		}
		echo '<h1>MMG Checkout Settings</h1>';
		echo '<p>Configure your Sandbox (UAT) and Live (Production) MMG credentials below. You can also import these automatically from the MMG zip package using <strong>MMG Checkout → Importer</strong>.</p>';

		echo '<form method="post" action="">';
		wp_nonce_field( 'mmgwc_save_settings', 'mmgwc_nonce' );
		echo '<table class="form-table">';
		// Render the same fields as WooCommerce uses for the gateway.
		$gateway->generate_settings_html();
		echo '</table>';
		submit_button( 'Save changes', 'primary', 'mmgwc_save_settings' );
		echo '</form>';

		echo '<p style="margin-top:16px;">Need help? <a href="' . esc_url( admin_url( 'admin.php?page=mmgwc-help' ) ) . '">Open the Help page</a>.</p>';
		echo '</div>';
	}

	public static function render_logs(): void {
		if ( ! current_user_can( MMGWC_Features::cap_for( 'logs' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$logs_url = admin_url( 'admin.php?page=wc-status&tab=logs' );

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Logs' );
		}
		echo '<h1>MMG Checkout Logs</h1>';
		echo '<p>MMG Checkout writes logs using WooCommerce logging. You can view logs at <a href="' . esc_url( $logs_url ) . '">WooCommerce → Status → Logs</a>.</p>';
		echo '<p><strong>Log source:</strong> <code>' . esc_html( MMGWC_LOG_SOURCE ) . '</code></p>';

		if ( function_exists( 'wc_get_log_file_path' ) ) {
			$path = wc_get_log_file_path( MMGWC_LOG_SOURCE );
			if ( $path && file_exists( $path ) && is_readable( $path ) ) {
				$tail = self::tail_file( $path, 200 );
				echo '<h2>Latest entries</h2>';
				echo '<pre style="max-width: 1100px; white-space: pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:12px; max-height: 420px; overflow:auto;">' . esc_html( $tail ) . '</pre>';
			} else {
				echo '<p>No readable log file found yet. Enable Debug in settings, run a test payment, then check again.</p>';
			}
		}

		echo '</div>';
	}

	private static function tail_file( string $path, int $lines = 200 ): string {
		// Hard safety cap so a pathological log file can't exhaust memory while tailing.
		$MAX_BYTES = 2 * 1024 * 1024;

		$fp = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return '';
		}
		$buffer = '';
		$chunk_size = 4096;
		$line_count = 0;

		if ( fseek( $fp, 0, SEEK_END ) !== 0 ) {
			fclose( $fp );
			return '';
		}
		$filesize = ftell( $fp );
		$pos = (int) $filesize;

		$read_total = 0;
		while ( $pos > 0 && $line_count <= $lines && $read_total < $MAX_BYTES ) {
			$read = min( $chunk_size, $pos, $MAX_BYTES - $read_total );
			$pos -= $read;
			if ( fseek( $fp, $pos ) !== 0 ) { break; }
			$data = fread( $fp, $read );
			if ( $data === false ) { break; }
			$buffer = $data . $buffer;
			$read_total += $read;
			$line_count = substr_count( $buffer, "\n" );
		}
		fclose( $fp );

		$parts = explode( "\n", $buffer );
		$parts = array_slice( $parts, max( 0, count( $parts ) - $lines ) );
		return implode( "\n", $parts );
	}

	public static function render_help(): void {
		if ( ! current_user_can( MMGWC_Features::cap_for( 'help' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Help' );
		}
		echo '<h1>MMG Checkout Help</h1>';

		echo '<h2>Where to find everything</h2>';
		echo '<ul style="list-style:disc;padding-left:18px;max-width:900px">';
		echo '<li><strong>Settings:</strong> WP Admin → MMG Checkout → Settings</li>';
		echo '<li><strong>Importer:</strong> WP Admin → MMG Checkout → Importer</li>';
		echo '<li><strong>Diagnostics and Logs:</strong> WP Admin → MMG Checkout → Diagnostics</li>';
		echo '<li><strong>Payment Requests:</strong> WP Admin → MMG Checkout → Payment Requests</li>';
		echo '<li><strong>Subscriptions:</strong> WP Admin → MMG Checkout → Subscriptions</li>';
		echo '<li><strong>QR Payments:</strong> WP Admin → MMG Checkout → QR Payments</li>';
		echo '<li><strong>Exports:</strong> WP Admin → MMG Checkout → Exports</li>';
		echo '<li><strong>Support Bundle:</strong> WP Admin → MMG Checkout → Support Bundle</li>';
		echo '</ul>';

		echo '<h2>Getting your MMG merchant files</h2>';
		echo '<p>The normal flow is: request your Sandbox (UAT) files, import and test, then submit proof to MMG, then go live.</p>';
		echo '<ol style="max-width:900px">';
		echo '<li>Request the <strong>Sandbox</strong> (UAT) credential package from MMG Merchant Services. The zip is commonly named like <strong>MMG Checkout UAT (#######)</strong>.</li>';
		echo '<li>Upload the zip in <strong>Importer</strong>. If MMG sends files separately, upload setup.cfg plus your public and private key files.</li>';
		echo '<li>Run your Sandbox tests until everything works end to end.</li>';
		echo '<li>Record a short video of a successful Sandbox payment and send it to the MMG Merchant Services contact who issued your Sandbox package.</li>';
		echo '<li>MMG will confirm and send your <strong>Live</strong> credential files. Import the Live files, then switch the plugin mode to <strong>Live</strong> in Settings.</li>';
		echo '</ol>';
		echo '<p>If MMG asks for a response or callback URL, you can find it in <strong>Diagnostics</strong>.</p>';

		echo '<h2>QR Payments</h2>';
		echo '<p>QR Payments lets you generate a shareable link and QR code that redirects straight to MMG Checkout. When the customer scans it, the plugin creates a WooCommerce order then completes it like a normal MMG payment.</p>';
		echo '<ol style="max-width:900px">';
		echo '<li>Go to <strong>MMG Checkout → QR Payments</strong>.</li>';
		echo '<li>Create a QR link (Fixed Amount or Product) and copy the link or share the QR.</li>';
		echo '<li>Customer scans and pays in MMG then the order is marked paid in WooCommerce.</li>';
		echo '</ol>';
		echo '<p><strong>Shortcode:</strong> <code>[mmgwc_qr_pay type="amount" label="Pay with MMG" amount="5000" auto_redirect="no" show_qr="yes"]</code></p>';


		echo '<h2>Common issues</h2>';
		echo '<ul style="list-style:disc;padding-left:18px;max-width:900px">';
		echo '<li><strong>Order stuck on Pending payment:</strong> open the order and use Verify payment, then check logs if needed.</li>';
		echo '<li><strong>Customer paid but closed the tab:</strong> Verify payment will update the order using MMG transaction lookup.</li>';
		echo '<li><strong>MMG shows paid but WooCommerce not updated:</strong> confirm MMG Merchant Services has the correct callback URL on file (copy it from Diagnostics and resend if needed).</li>';
		echo '<li><strong>Changed modes and now decryption fails:</strong> make sure you imported the correct keys for the selected mode.</li>';
		echo '</ul>';

		echo '<h2>Need support?</h2>';
		echo '<p>Collect the WooCommerce Order ID and MMG Transaction ID, then generate a Support Bundle and send it to Revamped GY.</p>';

		echo '<p style="margin-top:16px">';
		echo '<a class="button button-primary" href="' . esc_url( 'https://revamped.gy/mmg-woocommerce-plugin-guyana' ) . '" target="_blank" rel="noopener">View Details</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-support-bundle' ) ) . '">Create Support Bundle</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=mmgwc-diagnostics' ) ) . '">Open Diagnostics</a>';
		echo '</p>';

		echo '<div class="mmgwc-help-footer">';
		echo '<div>Built, powered and updated by <a href="' . esc_url( 'https://revamped.gy' ) . '" target="_blank" rel="noopener">Revamped GY</a></div>';
		echo '<div>Email: <a href="mailto:contact@revamped.gy">contact@revamped.gy</a> | WhatsApp: <a href="' . esc_url( 'https://wa.me/5927203747' ) . '" target="_blank" rel="noopener">+592-720-3747</a></div>';
		echo '</div>';

		echo '</div>';
	}
}