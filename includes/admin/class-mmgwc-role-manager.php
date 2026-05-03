<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Role_Manager {
	private const CAP = 'mmgwc_manage_roles';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_mmgwc_save_role_access', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Render a role x feature matrix.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		MMGWC_Features::ensure_admin_caps();
		$roles_obj = wp_roles();
		$roles = ( $roles_obj && isset( $roles_obj->roles ) ) ? (array) $roles_obj->roles : array();

		$registry = MMGWC_Features::registry();
		// Features we want to expose in the access UI.
		$feature_slugs = array();
		foreach ( $registry as $slug => $def ) {
			if ( in_array( $slug, array( 'gateway' ), true ) ) {
				continue;
			}
			$feature_slugs[] = $slug;
		}

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Role Manager' );
		}
		echo '<h1>Role Manager</h1>';
		echo '<p>Control which user roles can access each MMG Checkout feature in WP Admin. By default, only Administrators can see the MMG Checkout menu.</p>';
		echo '<p><strong>Note:</strong> This controls access to MMG Checkout pages, not customer checkout.</p>';
		echo '<p><em>For safety, Importer, Logs, Features Manager and Role Manager can only be granted by an Administrator.</em></p>';

		if ( isset( $_GET['updated'] ) && $_GET['updated'] === '1' ) {
			echo '<div class="notice notice-success"><p>Role access updated.</p></div>';
		}

		$action = admin_url( 'admin-post.php' );
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_save_role_access">';
		wp_nonce_field( 'mmgwc_save_role_access', 'mmgwc_nonce' );

		echo '<table class="widefat striped" style="max-width: 1200px;">';
		echo '<thead><tr>';
		echo '<th style="width:220px;">Role</th>';
		echo '<th style="width:120px;">Show menu</th>';
		echo '<th>Feature access</th>';
		echo '</tr></thead><tbody>';

		foreach ( $roles as $role_key => $role_def ) {
			$role_key = (string) $role_key;
			$role_name = (string) ( $role_def['name'] ?? $role_key );
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}

			$is_admin_role = ( $role_key === 'administrator' );
			$has_menu = $role->has_cap( MMGWC_Features::CAP_MENU );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $role_name ) . '</strong><br><code>' . esc_html( $role_key ) . '</code>';
			if ( $is_admin_role ) {
				echo '<p class="description">Administrator always has access.</p>';
			}
			echo '</td>';

			echo '<td>';
			if ( $is_admin_role ) {
				echo '<span class="dashicons dashicons-lock" style="vertical-align:middle;"></span> Always on';
				echo '<input type="hidden" name="roles[' . esc_attr( $role_key ) . '][menu]" value="1">';
			} else {
				echo '<label><input type="checkbox" name="roles[' . esc_attr( $role_key ) . '][menu]" value="1" ' . checked( $has_menu, true, false ) . '> Enabled</label>';
			}
			echo '</td>';

			echo '<td>';
			echo '<div style="display:flex; flex-wrap:wrap; gap:10px 18px;">';
			foreach ( $feature_slugs as $slug ) {
				$def = $registry[ $slug ] ?? array();
				$cap = MMGWC_Features::cap_for( $slug );
				$label = (string) ( $def['label'] ?? $slug );
				$locked = ! empty( $def['locked'] );
				$checked = $role->has_cap( $cap );
				$disabled = $is_admin_role ? ' disabled' : '';
				if ( $is_admin_role ) {
					$checked = true;
				}

				echo '<label style="min-width: 240px;">';
				if ( $is_admin_role ) {
					echo '<input type="checkbox" checked disabled> ' . esc_html( $label );
					echo '<input type="hidden" name="roles[' . esc_attr( $role_key ) . '][caps][' . esc_attr( $cap ) . ']" value="1">';
				} else {
					// Do not let users disable role manager unless they still have manage_options.
					echo '<input type="checkbox" name="roles[' . esc_attr( $role_key ) . '][caps][' . esc_attr( $cap ) . ']" value="1" ' . checked( $checked, true, false ) . $disabled . '> ' . esc_html( $label );
				}
				echo '</label>';
			}
			echo '</div>';
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
		submit_button( 'Save role access', 'primary' );
		echo '</form>';
		echo '</div>';
	}

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mmg-checkout-woocommerce' ) );
		}
		// Only administrators can grant the more powerful caps (importer, logs, feature/role managers).
		$can_grant_restricted = current_user_can( 'manage_options' );
		check_admin_referer( 'mmgwc_save_role_access', 'mmgwc_nonce' );

		$roles_in = isset( $_POST['roles'] ) ? (array) wp_unslash( $_POST['roles'] ) : array();
		$all_caps = MMGWC_Features::all_caps();
		$roles_obj = wp_roles();
		$roles = ( $roles_obj && isset( $roles_obj->roles ) ) ? (array) $roles_obj->roles : array();

		foreach ( $roles as $role_key => $role_def ) {
			$role_key = (string) $role_key;
			if ( $role_key === 'administrator' ) {
				continue; // Always keep admin intact.
			}
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}

			$posted = isset( $roles_in[ $role_key ] ) ? (array) $roles_in[ $role_key ] : array();
			$posted_caps = isset( $posted['caps'] ) ? (array) $posted['caps'] : array();
			$posted_menu = ! empty( $posted['menu'] );

			// Apply caps.
			$has_any_feature_cap = false;
			foreach ( $all_caps as $cap ) {
				$cap = (string) $cap;
				if ( $cap === 'manage_options' ) {
					continue;
				}
				if ( $cap === MMGWC_Features::CAP_MENU ) {
					continue;
				}
				$want = ! empty( $posted_caps[ $cap ] );

				// Privilege-escalation guard: restricted caps require manage_options.
				if ( $want && MMGWC_Features::is_restricted_cap( $cap ) && ! $can_grant_restricted ) {
					// Silently drop the request; the checkbox stays unchecked after reload.
					$want = false;
				}

				if ( $want ) {
					$has_any_feature_cap = true;
					if ( ! $role->has_cap( $cap ) ) {
						$role->add_cap( $cap );
					}
				} else {
					if ( $role->has_cap( $cap ) ) {
						$role->remove_cap( $cap );
					}
				}
			}

			// Menu cap: auto enable if any feature cap is selected.
			$want_menu = $posted_menu || $has_any_feature_cap;
			if ( $want_menu ) {
				if ( ! $role->has_cap( MMGWC_Features::CAP_MENU ) ) {
					$role->add_cap( MMGWC_Features::CAP_MENU );
				}
			} else {
				if ( $role->has_cap( MMGWC_Features::CAP_MENU ) ) {
					$role->remove_cap( MMGWC_Features::CAP_MENU );
				}
			}
		}

		// Always re-ensure admin has everything.
		MMGWC_Features::ensure_admin_caps();

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-roles&updated=1' ) );
		exit;
	}
}
