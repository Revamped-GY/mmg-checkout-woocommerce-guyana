<?php
/**
 * Plugin Name: MMG Checkout for WooCommerce
 * Plugin URI: https://revamped.gy/mmg-woocommerce-plugin-guyana
 * Description: Accept MMG payments in WooCommerce (Classic and Block Checkout). Includes Importer, Diagnostics, Exports, Payment Requests, Subscriptions, Support Bundle, and admin tools. Configure via WP Admin → MMG Checkout.
 * Version: 2.16.0
 * Author: Revamped GY
 * Author URI: https://revamped.gy
 * Text Domain: mmg-checkout-woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMGWC_VERSION', '2.16.0' );
define( 'MMGWC_PLUGIN_FILE', __FILE__ );
define( 'MMGWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMGWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Default MMG logo used for checkout payment method icons. Shipped as a local .webp
// so checkout pages never need an external request for the logo. Filterable via
// `mmgwc_gateway_icon_url` if you want to point it at a CDN or another image.
define( 'MMGWC_GATEWAY_ICON_URL', MMGWC_PLUGIN_URL . 'assets/images/mmg-logo.webp' );

// GitHub repository that hosts plugin releases. The updater reads the "latest release"
// from this repository to discover new versions and download the attached ZIP asset.
// Filterable via `mmgwc_github_repo` if the repo is ever moved or forked.
define( 'MMGWC_GITHUB_REPO', 'Revamped-GY/MMG-Checkout-Woocommerce-Guyana' );

// Legacy JSON manifest. The updater falls back to this only if the GitHub Releases
// check fails (network error, rate limit, repo unavailable). Existing installs that
// shipped before the GitHub move will continue to read this URL until they update once.
define( 'MMGWC_UPDATE_JSON_URL', 'https://revamped.gy/updates/mmg-checkout.json' );

define( 'MMGWC_WC_API_ENDPOINT', 'mmg-checkout' );

define( 'MMGWC_META_MERCHANT_TXN_ID', '_mmg_merchant_transaction_id' );
define( 'MMGWC_META_TXN_ID', '_mmg_transaction_id' );
define( 'MMGWC_META_RESULT_CODE', '_mmg_result_code' );
define( 'MMGWC_META_RESULT_MESSAGE', '_mmg_result_message' );
define( 'MMGWC_META_RAW_RESPONSE', '_mmg_raw_response' );

define( 'MMGWC_META_PROCESSED_TXN_ID', '_mmg_processed_transaction_id' );
define( 'MMGWC_META_LAST_VERIFIED_AT', '_mmg_last_verified_at' );
define( 'MMGWC_META_MODE', '_mmg_mode' );
define( 'MMGWC_META_EXPECTED_AMOUNT', '_mmg_expected_amount_gyd' );
define( 'MMGWC_META_EXPECTED_CURRENCY', '_mmg_expected_currency' );
define( 'MMGWC_META_EXPECTED_MERCHANT_ID', '_mmg_expected_merchant_id' );
define( 'MMGWC_META_EXPECTED_ORDER_TOTAL', '_mmg_expected_order_total' );
define( 'MMGWC_META_EXPECTED_ORDER_CURRENCY', '_mmg_expected_order_currency' );
define( 'MMGWC_META_VERIFICATION_STATUS', '_mmg_verification_status' );

define( 'MMGWC_META_INITIATED_REFERENCE', '_mmg_initiated_reference' );
define( 'MMGWC_META_INITIATED_STATUS', '_mmg_initiated_status' );
define( 'MMGWC_META_INITIATED_EXPIRES_AT', '_mmg_initiated_expires_at' );
define( 'MMGWC_META_INITIATED_LAST_CHECK', '_mmg_initiated_last_check' );
define( 'MMGWC_META_INITIATED_ATTEMPTS', '_mmg_initiated_attempts' );
define( 'MMGWC_META_INITIATED_CUSTOMER_HINT', '_mmg_initiated_customer_hint' );
define( 'MMGWC_META_INITIATED_CORRELATION', '_mmg_initiated_correlation_id' );
define( 'MMGWC_META_REVIEW_TXN_ID', '_mmg_review_transaction_id' );

// Currency conversion metadata.
define( 'MMGWC_META_ORIGINAL_CURRENCY', '_mmg_original_currency' );
define( 'MMGWC_META_ORIGINAL_TOTAL', '_mmg_original_total' );
define( 'MMGWC_META_FX_RATE', '_mmg_fx_rate_to_gyd' );
define( 'MMGWC_META_FX_DATE', '_mmg_fx_date' );
define( 'MMGWC_META_MMG_AMOUNT_GYD_RAW', '_mmg_amount_gyd_raw' );
define( 'MMGWC_META_MMG_AMOUNT_GYD', '_mmg_amount_gyd' );
define( 'MMGWC_META_MMG_ROUNDING_DELTA', '_mmg_amount_gyd_rounding_delta' );

define( 'MMGWC_META_PAYMENT_REQUEST', '_mmg_payment_request' );
define( 'MMGWC_META_PR_EXPIRES_AT', '_mmg_payment_request_expires_at' );
define( 'MMGWC_META_PR_LOCK_PRICES', '_mmg_payment_request_lock_prices' );

// Subscriptions metadata.
define( 'MMGWC_META_SUBSCRIPTION_ENABLED', '_mmgwc_subscription_enabled' );
define( 'MMGWC_META_SUBSCRIPTION_INTERVAL_TYPE', '_mmgwc_subscription_interval_type' );
define( 'MMGWC_META_SUBSCRIPTION_INTERVAL_COUNT', '_mmgwc_subscription_interval_count' );
define( 'MMGWC_META_SUBSCRIPTION_REMINDERS', '_mmgwc_subscription_reminders' );
define( 'MMGWC_META_SUBSCRIPTION_GRACE_DAYS', '_mmgwc_subscription_grace_days' );
define( 'MMGWC_META_SUBSCRIPTION_LOCK_PRICE', '_mmgwc_subscription_lock_price' );

define( 'MMGWC_META_SUBSCRIPTION_RENEWAL', '_mmg_subscription_renewal' );
define( 'MMGWC_META_SUBSCRIPTION_ID', '_mmg_subscription_id' );


define( 'MMGWC_LOG_SOURCE', 'mmg-checkout-woocommerce' );

define( 'MMGWC_SETTINGS_OPTION_KEY', 'woocommerce_mmg_checkout_settings' );

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// Always load lightweight helpers and register the callback hook early.
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-features.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-secure-store.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-settings.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-atomic-option.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-logger.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-crypto.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-qr-generator.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-fx.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-payment-context.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-callback.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-rewrites.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-maintenance.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-checkout-compat.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-updater.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-subscriptions.php';
require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-initiated-payments.php';

MMGWC_Rewrites::init();
MMGWC_Callback::init();
MMGWC_Maintenance::init();
MMGWC_Checkout_Compat::init();

// Flush rewrite rules once after updates, and keep admin caps in sync.
add_action( 'admin_init', function() {
	if ( ! is_admin() ) { return; }
	if ( class_exists( 'MMGWC_Features' ) ) {
		MMGWC_Features::ensure_admin_caps();
	}
	$stored = get_option( 'mmgwc_version' );
	if ( $stored !== MMGWC_VERSION ) {
		flush_rewrite_rules();
		update_option( 'mmgwc_version', MMGWC_VERSION, false );
	}
} );

register_activation_hook( __FILE__, array( 'MMGWC_Rewrites', 'activate' ) );
register_activation_hook( __FILE__, array( 'MMGWC_Maintenance', 'activate' ) );
register_activation_hook( __FILE__, array( 'MMGWC_Subscriptions', 'activate' ) );
register_activation_hook( __FILE__, array( 'MMGWC_Features', 'activate' ) );
register_activation_hook( __FILE__, array( 'MMGWC_Initiated_Payments', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MMGWC_Rewrites', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MMGWC_Maintenance', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MMGWC_Subscriptions', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MMGWC_Features', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MMGWC_Initiated_Payments', 'deactivate' ) );

// Plugin list page links.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
	$settings_url = admin_url( 'admin.php?page=mmgwc-settings' );
	$details_url  = 'https://revamped.gy/mmg-woocommerce-plugin-guyana';
	array_unshift( $links, '<a href="' . esc_url( $settings_url ) . '">Settings</a>' );
	array_unshift( $links, '<a href="' . esc_url( $details_url ) . '" target="_blank" rel="noopener">View Details</a>' );
	return $links;
} );

// Remove duplicate "Visit plugin site" links if another plugin/theme filters row meta.
add_filter( 'plugin_row_meta', function( $links, $file ) {
	if ( $file !== plugin_basename( __FILE__ ) ) {
		return $links;
	}
	if ( ! is_array( $links ) || empty( $links ) ) {
		return $links;
	}
	$seen = array();
	$out  = array();
	foreach ( $links as $link ) {
		$href = '';
		if ( preg_match( '/href=(["\'])([^"\']+)\1/i', (string) $link, $m ) ) {
			$href = strtolower( (string) $m[2] );
		}
		$key = $href !== '' ? $href : strtolower( wp_strip_all_tags( (string) $link ) );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[] = $link;
	}
	return $out;
}, 10, 2 );

// Bootstrap Blocks integration early so we never miss Blocks hooks.
// Self-hosted updater (revamped.gy)
MMGWC_Updater::init( MMGWC_PLUGIN_FILE );

add_action( 'plugins_loaded', function() {
	require_once MMGWC_PLUGIN_DIR . 'includes/blocks/class-mmgwc-blocks.php';
	MMGWC_Blocks::init();
}, 1 );

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		// WooCommerce not active. Nothing else to do.
		return;
	}

	require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-api.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-payment-verifier.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/class-wc-gateway-mmgwc.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/class-wc-gateway-mmgwc-initiated.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-admin.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-admin-ui.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-diagnostics.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-menu.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-feature-manager.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-role-manager.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-importer.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-exports.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-analytics.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-qr-payments.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-qr-payments.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-support-bundle.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-payment-requests.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-subscriptions.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/class-mmgwc-myaccount.php';
	require_once MMGWC_PLUGIN_DIR . 'includes/admin/class-mmgwc-subscriptions.php';


	add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
		$gateways[] = 'WC_Gateway_MMGWC';
		$gateways[] = 'WC_Gateway_MMGWC_Initiated';
		return $gateways;
	} );
	WC_Gateway_MMGWC::init_background_callbacks();
	MMGWC_Initiated_Payments::init();
	// WooCommerce Blocks integration is bootstrapped earlier for Blocks compatibility.
	MMGWC_Admin_UI::init();
	MMGWC_Admin::init();
	MMGWC_Feature_Manager::init();
	MMGWC_Role_Manager::init();

	// Module bootstrap (feature flags control hooks + menus).
	if ( MMGWC_Features::is_enabled( 'importer' ) ) {
		MMGWC_Importer::init();
	}
	if ( MMGWC_Features::is_enabled( 'exports' ) ) {
		MMGWC_Exports::init();
	}
	if ( MMGWC_Features::is_enabled( 'analytics' ) ) {
		MMGWC_Analytics::init();
	}
	if ( MMGWC_Features::is_enabled( 'qr_payments' ) ) {
			MMGWC_QR_Payments::init();
			MMGWC_QR_Payments_Admin::init();
		}

		if ( MMGWC_Features::is_enabled( 'support_bundle' ) ) {
		MMGWC_Support_Bundle::init();
	}
	if ( MMGWC_Features::is_enabled( 'payment_requests' ) ) {
		MMGWC_Payment_Requests::init();
	}
	if ( MMGWC_Features::is_enabled( 'subscriptions' ) ) {
		MMGWC_Subscriptions::init();
		MMGWC_Subscriptions_Admin::init();
	}
	// Customer portal is driven by these features.
	if ( MMGWC_Features::is_enabled( 'payment_requests' ) || MMGWC_Features::is_enabled( 'subscriptions' ) ) {
		MMGWC_MyAccount::init();
	}
	if ( MMGWC_Features::is_enabled( 'diagnostics' ) ) {
		MMGWC_Diagnostics::init();
	}
	MMGWC_Menu::init();
}, 20 );
