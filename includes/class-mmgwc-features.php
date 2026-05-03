<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feature flags + capability map for MMG Checkout.
 *
 * - Feature flags control whether modules are enabled at all.
 * - Capabilities control who can access each module in WP Admin.
 *
 * Deactivating the plugin no longer strips the custom caps from every role.
 * That behaviour caused admins to lose all per-role access mappings after
 * a routine update. Uninstall still cleans up fully (see uninstall.php).
 */
final class MMGWC_Features {
	public const OPTION_FLAGS = 'mmgwc_feature_flags';
	public const CAP_MENU     = 'mmgwc_access_menu';

	/**
	 * Capabilities that should only ever be granted to roles that also hold
	 * `manage_options`. These expose credentials, logs, or administrative
	 * actions that could lead to privilege escalation if granted to lower roles.
	 */
	public const RESTRICTED_CAPS = array(
		'mmgwc_access_importer',
		'mmgwc_access_logs',
		'mmgwc_manage_features',
		'mmgwc_manage_roles',
	);

	/**
	 * Registry of features.
	 * locked = cannot be disabled (prevents locking yourself out).
	 */
	public static function registry(): array {
		return array(
			// Core.
			'gateway' => array(
				'label'   => 'Payment Gateway',
				'desc'    => 'Enables MMG as a WooCommerce payment method.',
				'default' => true,
				'locked'  => true,
				'cap'     => self::CAP_MENU,
			),

			// Admin modules.
			'settings' => array(
				'label'   => 'Settings',
				'desc'    => 'Configure Sandbox and Live MMG credentials.',
				'default' => true,
				'locked'  => true,
				'cap'     => 'mmgwc_access_settings',
			),
			'importer' => array(
				'label'   => 'Importer',
				'desc'    => 'Upload MMG files and auto-fill credentials.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_importer',
			),
			'order_tools' => array(
				'label'   => 'Order Tools',
				'desc'    => 'Verify payment and resend MMG links on orders.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_order_tools',
			),
			'diagnostics' => array(
				'label'   => 'Diagnostics',
				'desc'    => 'Run checks and view environment details.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_diagnostics',
			),
			'exports' => array(
				'label'   => 'Exports',
				'desc'    => 'Download CSV exports and summaries.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_exports',
			),
			'analytics' => array(
				'label'   => 'Analytics',
				'desc'    => 'View payment stats and top products.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_analytics',
			),
			'payment_requests' => array(
				'label'   => 'Payment Requests',
				'desc'    => 'Create invoices and send pay links.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_payment_requests',
			),

			'qr_payments' => array(
				'label'   => 'QR Payments',
				'desc'    => 'Create QR links that redirect straight to MMG Checkout.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_qr_payments',
			),
			'subscriptions' => array(
				'label'   => 'Subscriptions',
				'desc'    => 'Renewal reminders with subscriber tracking.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_subscriptions',
			),
			'support_bundle' => array(
				'label'   => 'Support Bundle',
				'desc'    => 'Download a redacted support zip.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_support_bundle',
			),
			'logs' => array(
				'label'   => 'Logs',
				'desc'    => 'Quick view of recent MMG Checkout logs.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_logs',
			),
			'help' => array(
				'label'   => 'Help',
				'desc'    => 'Guides, FAQ and go-live checklist.',
				'default' => true,
				'locked'  => false,
				'cap'     => 'mmgwc_access_help',
			),

			// Control panels.
			'features_manager' => array(
				'label'   => 'Features Manager',
				'desc'    => 'Enable or disable plugin modules.',
				'default' => true,
				'locked'  => true,
				'cap'     => 'mmgwc_manage_features',
			),
			'role_manager' => array(
				'label'   => 'Role Manager',
				'desc'    => 'Control which roles can access each feature.',
				'default' => true,
				'locked'  => true,
				'cap'     => 'mmgwc_manage_roles',
			),
		);
	}

	public static function activate(): void {
		// Initialise feature flags.
		$flags = get_option( self::OPTION_FLAGS );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		foreach ( self::registry() as $slug => $def ) {
			if ( ! array_key_exists( $slug, $flags ) ) {
				$flags[ $slug ] = ! empty( $def['default'] );
			}
		}
		update_option( self::OPTION_FLAGS, $flags, false );

		// Ensure Administrator has all MMG caps.
		self::ensure_admin_caps();
	}

	/**
	 * Deactivation is intentionally a no-op for capabilities.
	 *
	 * Stripping caps from every role on deactivate meant that any routine plugin
	 * update (deactivate + reactivate) wiped all custom per-role access mappings.
	 * Custom caps are only removed on full uninstall (see uninstall.php).
	 */
	public static function deactivate(): void {
		// Reserved for future clean-up that should run on deactivate.
	}

	public static function ensure_admin_caps(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		foreach ( self::all_caps() as $cap ) {
			if ( ! $admin->has_cap( $cap ) ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function all_caps(): array {
		$registry = self::registry();
		$caps = array( self::CAP_MENU );
		foreach ( $registry as $def ) {
			if ( ! empty( $def['cap'] ) ) {
				$caps[] = (string) $def['cap'];
			}
		}
		return array_values( array_unique( array_filter( $caps ) ) );
	}

	public static function cap_for( string $feature ): string {
		$registry = self::registry();
		return isset( $registry[ $feature ]['cap'] ) ? (string) $registry[ $feature ]['cap'] : self::CAP_MENU;
	}

	public static function is_locked( string $feature ): bool {
		$registry = self::registry();
		return ! empty( $registry[ $feature ]['locked'] );
	}

	public static function is_enabled( string $feature ): bool {
		$registry = self::registry();
		if ( isset( $registry[ $feature ] ) && ! empty( $registry[ $feature ]['locked'] ) ) {
			return true;
		}
		$flags = get_option( self::OPTION_FLAGS );
		if ( ! is_array( $flags ) ) {
			return ! empty( $registry[ $feature ]['default'] );
		}
		if ( array_key_exists( $feature, $flags ) ) {
			return (bool) $flags[ $feature ];
		}
		return ! empty( $registry[ $feature ]['default'] );
	}

	/**
	 * Whitelisted update of feature flags: any slug not in the registry is dropped,
	 * and locked features are always forced on.
	 */
	public static function update_flags( array $new_flags ): void {
		$flags = array();
		foreach ( self::registry() as $slug => $def ) {
			if ( ! empty( $def['locked'] ) ) {
				$flags[ $slug ] = true;
				continue;
			}
			$flags[ $slug ] = ! empty( $new_flags[ $slug ] );
		}
		update_option( self::OPTION_FLAGS, $flags, false );
	}

	public static function user_can( string $feature ): bool {
		// Admin always allowed.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$cap = self::cap_for( $feature );
		return $cap ? current_user_can( $cap ) : false;
	}

	/**
	 * Whether a given capability may be granted to a non-administrator role.
	 */
	public static function is_restricted_cap( string $cap ): bool {
		return in_array( $cap, self::RESTRICTED_CAPS, true );
	}
}
