<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Feature_Manager {
	private const CAP = 'mmgwc_manage_features';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_mmgwc_save_features', array( __CLASS__, 'handle_save' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mmg-checkout-woocommerce' ) );
		}

		$registry = MMGWC_Features::registry();
		$flags    = get_option( MMGWC_Features::OPTION_FLAGS );
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}

		echo '<div class="wrap mmgwc-wrap">';
		if ( class_exists( 'MMGWC_Admin_UI' ) ) {
			MMGWC_Admin_UI::brandbar( 'Features Manager' );
		}
		echo '<h1>Features Manager</h1>';
		echo '<p>Enable or disable plugin modules. Disabled modules stop running and are removed from the MMG Checkout menu.</p>';
		echo '<p><strong>Tip:</strong> If you disable a module, any existing data stays in WooCommerce, but the module UI and hooks stop.</p>';

		if ( isset( $_GET['updated'] ) && $_GET['updated'] === '1' ) {
			echo '<div class="notice notice-success"><p>Features updated.</p></div>';
		}

		$action = admin_url( 'admin-post.php' );
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="action" value="mmgwc_save_features">';
		wp_nonce_field( 'mmgwc_save_features', 'mmgwc_nonce' );

		echo '<table class="widefat striped" style="max-width: 1100px;">';
		echo '<thead><tr>';
		echo '<th style="width:280px;">Feature</th>';
		echo '<th>Description</th>';
		echo '<th style="width:140px;">Enabled</th>';
		echo '</tr></thead><tbody>';

		foreach ( $registry as $slug => $def ) {
			$label  = (string) ( $def['label'] ?? $slug );
			$desc   = (string) ( $def['desc'] ?? '' );
			$locked = ! empty( $def['locked'] );
			$enabled = $locked ? true : ( isset( $flags[ $slug ] ) ? (bool) $flags[ $slug ] : ! empty( $def['default'] ) );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong><br><code>' . esc_html( $slug ) . '</code></td>';
			echo '<td>' . esc_html( $desc ) . '</td>';
			echo '<td>';
			if ( $locked ) {
				echo '<span class="dashicons dashicons-lock" style="vertical-align:middle;"></span> <span>Always on</span>';
				echo '<input type="hidden" name="features[' . esc_attr( $slug ) . ']" value="1">';
			} else {
				echo '<label><input type="checkbox" name="features[' . esc_attr( $slug ) . ']" value="1" ' . checked( $enabled, true, false ) . '> Enabled</label>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		submit_button( 'Save features', 'primary' );
		echo '</form>';
		echo '</div>';
	}

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mmg-checkout-woocommerce' ) );
		}
		check_admin_referer( 'mmgwc_save_features', 'mmgwc_nonce' );

		$features = isset( $_POST['features'] ) ? (array) wp_unslash( $_POST['features'] ) : array();
		$registry = MMGWC_Features::registry();
		$new = array();
		foreach ( $features as $k => $v ) {
			$slug = sanitize_key( (string) $k );
			if ( ! array_key_exists( $slug, $registry ) ) {
				// Drop unknown slugs so the database only ever stores known flags.
				continue;
			}
			$new[ $slug ] = (bool) $v;
		}
		MMGWC_Features::update_flags( $new );

		MMGWC_Logger::info( 'MMG feature flags updated', array( 'user_id' => get_current_user_id() ) );

		wp_safe_redirect( admin_url( 'admin.php?page=mmgwc-features&updated=1' ) );
		exit;
	}
}
