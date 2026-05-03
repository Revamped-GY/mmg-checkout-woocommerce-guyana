<?php
/**
 * Uninstall handler for MMG Checkout for WooCommerce.
 *
 * Runs when the user deletes (not just deactivates) the plugin.
 * Cleans up plugin data: options, feature flags, version marker, cached transients,
 * the subscriptions table, and any scheduled cron events. Order meta is left intact
 * so the store keeps its MMG payment audit history even after uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop plugin options.
$options_to_delete = array(
	'woocommerce_mmg_checkout_settings',
	'mmgwc_feature_flags',
	'mmgwc_version',
	'mmgwc_qr_templates_index',
);
foreach ( $options_to_delete as $opt ) {
	delete_option( $opt );
	delete_site_option( $opt );
}

// Delete cached transients created by the plugin.
$transient_prefixes = array( 'mmgwc_', '_transient_mmgwc_', '_transient_timeout_mmgwc_', '_site_transient_mmgwc_', '_site_transient_timeout_mmgwc_' );
foreach ( $transient_prefixes as $prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
}

// Drop the subscriptions table (schema is recreated on next activation).
$table = $wpdb->prefix . 'mmgwc_subscriptions';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Unschedule any pending cron events.
foreach ( array( 'mmgwc_rotate_logs', 'mmgwc_subscriptions_cron' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}

// Remove custom capabilities from every role.
$custom_caps = array(
	'mmgwc_access_menu',
	'mmgwc_access_settings',
	'mmgwc_access_importer',
	'mmgwc_access_order_tools',
	'mmgwc_access_diagnostics',
	'mmgwc_access_exports',
	'mmgwc_access_analytics',
	'mmgwc_access_payment_requests',
	'mmgwc_access_qr_payments',
	'mmgwc_access_subscriptions',
	'mmgwc_access_support_bundle',
	'mmgwc_access_logs',
	'mmgwc_access_help',
	'mmgwc_manage_features',
	'mmgwc_manage_roles',
);
$roles = wp_roles();
if ( $roles && isset( $roles->roles ) && is_array( $roles->roles ) ) {
	foreach ( array_keys( $roles->roles ) as $role_key ) {
		$role = get_role( $role_key );
		if ( ! $role ) { continue; }
		foreach ( $custom_caps as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
