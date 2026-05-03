<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MMGWC_Rewrites {

	public static function init() : void {
		add_action( 'init', array( __CLASS__, 'add_rules' ), 5 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'mmg_token';
		$vars[] = 'mmgwc_qr';
		return $vars;
	}

	public static function add_rules() : void {
		// Support MMG redirect format: /wc-api/mmg-checkout/{TOKEN}
		add_rewrite_rule(
			'^wc-api/' . preg_quote( MMGWC_WC_API_ENDPOINT, '/' ) . '/([A-Za-z0-9\-_\.]+)/?$',
			'index.php?wc-api=' . MMGWC_WC_API_ENDPOINT . '&mmg_token=$matches[1]',
			'top'
		);

		// Support QR payment links: /mmg-qr/{TOKEN}
		add_rewrite_rule(
			'^mmg-qr/([A-Za-z0-9]+)/?$',
			'index.php?mmgwc_qr=$matches[1]',
			'top'
		);

	}

	public static function activate() : void {
		self::add_rules();
		flush_rewrite_rules();
		update_option( 'mmgwc_version', MMGWC_VERSION, false );
	}

	public static function deactivate() : void {
		flush_rewrite_rules();
	}
}
