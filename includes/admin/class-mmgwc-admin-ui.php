<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI helpers (styles + shared header)
 */
final class MMGWC_Admin_UI {
	private const STYLE_HANDLE = 'mmgwc-admin-ui';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( string $hook = '' ): void {
		if ( ! self::is_mmgwc_page() ) {
			return;
		}
		wp_enqueue_style(
			self::STYLE_HANDLE,
			MMGWC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MMGWC_VERSION
		);
	}

	/**
	 * Only load assets on our admin pages.
	 */
	private static function is_mmgwc_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== '' && strpos( $page, 'mmgwc' ) === 0 ) {
			return true;
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) ) {
				$id = (string) $screen->id;
				if ( strpos( $id, 'mmgwc' ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Brand header used on all plugin pages.
	 */
	public static function brandbar( string $page_title, array $actions = array() ): void {
		// Revamped GY branded logo, shipped locally as a .webp. Filterable via
		// `mmgwc_brandbar_logo_url` so it can be pointed elsewhere if needed.
		$logo = apply_filters( 'mmgwc_brandbar_logo_url', MMGWC_PLUGIN_URL . 'assets/images/revamped-gy-logo.webp' );
		echo '<div class="mmgwc-brandbar">';
		echo '<div class="mmgwc-brand-left">';
		echo '<a class="mmgwc-brand-logo" href="' . esc_url( 'https://revamped.gy' ) . '" target="_blank" rel="noopener">';
		echo '<img src="' . esc_url( $logo ) . '" alt="Revamped GY" loading="lazy" decoding="async">';
		echo '</a>';
		echo '<div class="mmgwc-brand-titles">';
		echo '<div class="mmgwc-brand-kicker">MMG Checkout for WooCommerce</div>';
		echo '<div class="mmgwc-brand-title">' . esc_html( $page_title ) . '</div>';
		echo '</div>';
		echo '</div>';

		if ( ! empty( $actions ) ) {
			echo '<div class="mmgwc-brand-actions">';
			foreach ( $actions as $action ) {
				$label = isset( $action['label'] ) ? (string) $action['label'] : '';
				$url   = isset( $action['url'] ) ? (string) $action['url'] : '';
				$class = isset( $action['class'] ) ? (string) $action['class'] : 'button';
				if ( $label === '' || $url === '' ) {
					continue;
				}
				echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '</div>';
		}

		echo '</div>';
	}
}
