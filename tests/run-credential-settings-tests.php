<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'AUTH_KEY', 'credential-settings-test-auth-key' );
define( 'SECURE_AUTH_SALT', 'credential-settings-test-secure-salt' );
define( 'MMGWC_SETTINGS_OPTION_KEY', 'woocommerce_mmg_checkout_settings' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['mmgwc_test_options'] = array();
$GLOBALS['mmgwc_test_errors'] = array();
$GLOBALS['mmgwc_test_checks'] = 0;
$GLOBALS['mmgwc_test_update_option_should_fail'] = false;

function get_option( string $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['mmgwc_test_options'] )
		? $GLOBALS['mmgwc_test_options'][ $key ]
		: $default;
}

function update_option( string $key, $value, $autoload = null ): bool {
	if ( ! empty( $GLOBALS['mmgwc_test_update_option_should_fail'] ) ) {
		return false;
	}
	$GLOBALS['mmgwc_test_options'][ $key ] = $value;
	return true;
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'credential-settings-test-' . $scheme;
}

function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	$GLOBALS['mmgwc_test_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}

function esc_html( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

class MMGWC_Features {
	public const CAP_MENU = 'manage_woocommerce';
}

class WC_Payment_Gateway {
	public $id = '';
	public $settings = array();
	public $form_fields = array();

	public function get_option( $key, $empty_value = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $empty_value;
	}

	public function get_option_key(): string {
		return 'woocommerce_' . $this->id . '_settings';
	}

	public function generate_settings_html( $form_fields = array(), $echo = true ) {
		$fields = empty( $form_fields ) ? $this->form_fields : $form_fields;
		$html = '';
		foreach ( $fields as $key => $field ) {
			if ( isset( $field['type'] ) && $field['type'] === 'title' ) {
				continue;
			}
			$value = htmlspecialchars( (string) $this->get_option( $key, '' ), ENT_QUOTES, 'UTF-8' );
			$html .= isset( $field['type'] ) && $field['type'] === 'textarea'
				? '<textarea>' . $value . '</textarea>'
				: '<input value="' . $value . '">';
		}
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}
}

function credential_check( bool $condition, string $message ): void {
	$GLOBALS['mmgwc_test_checks']++;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

require_once dirname( __DIR__ ) . '/includes/class-mmgwc-secure-store.php';
require_once dirname( __DIR__ ) . '/includes/class-mmgwc-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-gateway-mmgwc.php';

$plaintext = "-----BEGIN PRIVATE KEY-----\nprivate-material\n-----END PRIVATE KEY-----";
$encrypted = MMGWC_Secure_Store::encrypt( $plaintext );

credential_check( strpos( $encrypted, MMGWC_Secure_Store::PREFIX ) === 0, 'Protected values are stored in an encrypted envelope.' );
credential_check( MMGWC_Secure_Store::can_decrypt( $encrypted ), 'A current-site encrypted envelope is recognised as readable.' );
credential_check( MMGWC_Secure_Store::decrypt( $encrypted ) === $plaintext, 'A readable envelope returns its original plaintext.' );
credential_check( MMGWC_Secure_Store::decrypt( 'legacy-secret' ) === 'legacy-secret', 'Legacy plaintext remains readable until it is replaced or saved.' );

$unreadable = MMGWC_Secure_Store::PREFIX . base64_encode( random_bytes( 64 ) );
credential_check( ! MMGWC_Secure_Store::can_decrypt( $unreadable ), 'An envelope that does not match the site key is recognised as unreadable.' );
credential_check( MMGWC_Secure_Store::decrypt( $unreadable ) === '', 'Unreadable encrypted text fails closed instead of becoming a runtime credential.' );
credential_check( MMGWC_Secure_Store::encrypt_preserving_existing( '', $encrypted ) === $encrypted, 'A blank replacement preserves an existing protected value.' );
credential_check( MMGWC_Secure_Store::encrypt_preserving_existing( '', $unreadable ) === $unreadable, 'A blank replacement preserves unreadable evidence for manual recovery.' );
credential_check( MMGWC_Secure_Store::encrypt_preserving_existing( $unreadable, $encrypted ) === $encrypted, 'Pasted encrypted storage text cannot replace a credential.' );

$replacement = 'replacement-secret';
$replacement_encrypted = MMGWC_Secure_Store::encrypt_preserving_existing( $replacement, $encrypted );
credential_check( $replacement_encrypted !== $encrypted, 'An entered plaintext credential replaces the previous envelope.' );
credential_check( MMGWC_Secure_Store::decrypt( $replacement_encrypted ) === $replacement, 'A replacement credential is encrypted with the current site key.' );

$legacy_lookup_key = MMGWC_Secure_Store::encrypt( 'legacy-lookup-key' );
$GLOBALS['mmgwc_test_options'][ MMGWC_SETTINGS_OPTION_KEY ] = array(
	'api_key' => $legacy_lookup_key,
);
MMGWC_Settings::update_partial( array( 'sandbox_api_key' => '' ) );
$legacy_saved = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
credential_check( ! array_key_exists( 'sandbox_api_key', $legacy_saved ), 'A blank new Sandbox field does not block a legacy global credential fallback.' );
credential_check( MMGWC_Settings::get( 'sandbox_api_key', '' ) === 'legacy-lookup-key', 'An upgraded site keeps using its readable legacy Sandbox credential after a blank settings save.' );

MMGWC_Settings::update_partial( array( 'sandbox_api_key' => $unreadable ) );
$legacy_saved = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
credential_check( ! array_key_exists( 'sandbox_api_key', $legacy_saved ), 'A rejected Sandbox replacement does not create an empty key over a legacy fallback.' );
credential_check( MMGWC_Settings::get( 'sandbox_api_key', '' ) === 'legacy-lookup-key', 'A rejected Sandbox replacement keeps the readable legacy credential available.' );

$GLOBALS['mmgwc_test_options'][ MMGWC_SETTINGS_OPTION_KEY ] = array(
	'live_private_key' => $encrypted,
	'live_secret_key' => $unreadable,
	'live_api_key' => 'legacy-api-key',
);

credential_check( MMGWC_Settings::protected_value_state( 'live_private_key' ) === 'stored', 'Settings report readable encrypted credentials without exposing them.' );
credential_check( MMGWC_Settings::protected_value_state( 'live_secret_key' ) === 'unreadable', 'Settings identify encrypted credentials that require re-entry.' );
credential_check( MMGWC_Settings::protected_value_state( 'live_api_key' ) === 'legacy_plaintext', 'Settings identify legacy plaintext that can be migrated safely.' );
credential_check( MMGWC_Settings::get( 'live_secret_key', 'fallback' ) === '', 'An unreadable setting does not fall back to encrypted database text.' );
credential_check( MMGWC_Settings::unreadable_protected_keys() === array( 'live_secret_key' ), 'Unreadable credential reporting returns only affected setting names.' );

MMGWC_Settings::update_partial(
	array(
		'live_private_key' => '',
		'live_secret_key' => '',
	)
);
$saved = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
credential_check( $saved['live_private_key'] === $encrypted, 'A blank settings save preserves a readable encrypted credential.' );
credential_check( $saved['live_secret_key'] === $unreadable, 'A blank settings save does not destroy unreadable recovery evidence.' );

$gateway = ( new ReflectionClass( 'WC_Gateway_MMGWC' ) )->newInstanceWithoutConstructor();
$gateway->id = 'mmg_checkout';
$gateway->settings = $saved;
$gateway->form_fields = array(
	'live_private_key' => array( 'type' => 'textarea' ),
	'live_secret_key' => array( 'type' => 'password' ),
);

$rendered = $gateway->generate_settings_html( array(), false );
credential_check( strpos( $rendered, MMGWC_Secure_Store::PREFIX ) === false, 'Settings HTML never renders encrypted storage envelopes.' );
credential_check( strpos( $rendered, 'private-material' ) === false, 'Settings HTML never renders protected plaintext.' );
credential_check( $gateway->get_option( 'live_private_key', '' ) === $plaintext, 'Runtime gateway access still receives readable plaintext.' );

$sanitized = $gateway->filter_sanitize_sensitive_fields(
	array(
		'live_private_key' => '',
		'live_secret_key' => 'new-secret',
	)
);
credential_check( $sanitized['live_private_key'] === $encrypted, 'WooCommerce blank credential fields preserve the stored value.' );
credential_check( MMGWC_Secure_Store::decrypt( $sanitized['live_secret_key'] ) === 'new-secret', 'WooCommerce plaintext replacements are encrypted before persistence.' );

$GLOBALS['mmgwc_test_options'][ MMGWC_SETTINGS_OPTION_KEY ] = array(
	'api_key' => $legacy_lookup_key,
);
$gateway->settings = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
$upgrade_sanitized = $gateway->filter_sanitize_sensitive_fields( array( 'sandbox_api_key' => '' ) );
credential_check( ! array_key_exists( 'sandbox_api_key', $upgrade_sanitized ), 'WooCommerce does not create a blank mode-specific value over a legacy Sandbox fallback.' );
$upgrade_sanitized = $gateway->filter_sanitize_sensitive_fields( array( 'sandbox_api_key' => $unreadable ) );
credential_check( ! array_key_exists( 'sandbox_api_key', $upgrade_sanitized ), 'WooCommerce does not create an empty mode-specific value after rejecting encrypted storage text.' );

MMGWC_Secure_Store::reset_operation_errors();
$GLOBALS['mmgwc_test_options'][ MMGWC_SETTINGS_OPTION_KEY ] = array(
	'api_key' => $legacy_lookup_key,
);
MMGWC_Settings::update_partial( array( 'sandbox_api_key' => $unreadable ) );
$storage_errors = MMGWC_Secure_Store::operation_errors();
credential_check( count( $storage_errors ) === 1, 'A rejected protected replacement records one custom-page operation error.' );

$original_checked = array(
	'mode' => 'live',
	'live_api_key' => MMGWC_Secure_Store::encrypt( 'old-imported-api-key' ),
);
$GLOBALS['mmgwc_test_options'][ MMGWC_SETTINGS_OPTION_KEY ] = $original_checked;
$checked_saved = MMGWC_Settings::update_partial_checked(
	array(
		'live_api_key' => 'new-imported-api-key',
		'live_api_wss_mid' => '6991234',
	)
);
$checked_values = get_option( MMGWC_SETTINGS_OPTION_KEY, array() );
credential_check( $checked_saved, 'The checked importer settings path reports a successful secure save.' );
credential_check( MMGWC_Secure_Store::decrypt( $checked_values['live_api_key'] ) === 'new-imported-api-key', 'The checked importer path encrypts a plaintext API credential.' );
credential_check( $checked_values['mode'] === 'live', 'The checked importer path preserves unrelated existing settings.' );

$before_failed_write = $checked_values;
$GLOBALS['mmgwc_test_update_option_should_fail'] = true;
$failed_write = MMGWC_Settings::update_partial_checked( array( 'live_api_wss_mid' => '6999999' ) );
$GLOBALS['mmgwc_test_update_option_should_fail'] = false;
credential_check( ! $failed_write, 'The checked importer path reports a changed-value database write failure.' );
credential_check( get_option( MMGWC_SETTINGS_OPTION_KEY, array() ) === $before_failed_write, 'A failed importer database write leaves the complete settings option unchanged.' );

$GLOBALS['mmgwc_test_update_option_should_fail'] = true;
$unchanged_write = MMGWC_Settings::update_partial_checked( array() );
$GLOBALS['mmgwc_test_update_option_should_fail'] = false;
credential_check( $unchanged_write, 'An unchanged checked import remains successful when WordPress reports no update.' );

$before_rejected_checked = $checked_values;
$checked_rejected = MMGWC_Settings::update_partial_checked(
	array(
		'live_api_key' => $unreadable,
		'live_merchant_id' => 'must-not-save-partially',
	)
);
credential_check( ! $checked_rejected, 'The checked importer path reports a rejected protected replacement.' );
credential_check( get_option( MMGWC_SETTINGS_OPTION_KEY, array() ) === $before_rejected_checked, 'A rejected imported secret leaves the complete settings option unchanged.' );
credential_check( count( MMGWC_Secure_Store::operation_errors() ) === 1, 'A rejected checked import records one safe storage error.' );

$GLOBALS['mmgwc_test_options'][ MMGWC_SETTINGS_OPTION_KEY ] = array( 'sandbox_api_wss_mid' => '6991234' );
$sandbox_config = MMGWC_Settings::get_config( 'sandbox' );
credential_check( $sandbox_config['credit_account_id'] === '6991234', 'Merchant Initiated creditParty.accountid defaults to x-wss-mid.' );

require_once dirname( __DIR__ ) . '/includes/admin/class-mmgwc-menu.php';
$notice_method = new ReflectionMethod( 'MMGWC_Menu', 'render_settings_save_notices' );
$notice_method->setAccessible( true );
ob_start();
$notice_method->invoke( null, $storage_errors );
$notice_html = (string) ob_get_clean();
credential_check( strpos( $notice_html, 'notice-error' ) !== false, 'The custom settings page renders the protected credential error.' );
credential_check( strpos( $notice_html, 'Settings saved.' ) === false, 'The custom settings page suppresses its success notice after a protected credential error.' );

$gateway_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wc-gateway-mmgwc.php' );
credential_check( is_string( $gateway_source ) && strpos( $gateway_source, 'Optional Live Merchant Initiated API' ) !== false, 'The shared optional fields use MMG\'s documented Merchant Initiated API name.' );
credential_check( is_string( $gateway_source ) && strpos( $gateway_source, 'These fields do not control the standard hosted checkout.' ) !== false, 'The settings state that Merchant Initiated API values do not gate hosted checkout.' );
credential_check( is_string( $gateway_source ) && strpos( $gateway_source, "'initiated_authorised'" ) === false, 'The extra MMG authorisation checkbox is removed.' );
$sandbox_order = array_map(
	static function ( string $needle ) use ( $gateway_source ): int {
		return is_string( $gateway_source ) ? (int) strpos( $gateway_source, "'sandbox_api_{$needle}'" ) : -1;
	},
	array( 'key', 'wss_mid', 'password', 'wss_msecret', 'wss_mkey', 'mwallet_base_url' )
);
credential_check( $sandbox_order === array_values( $sandbox_order ) && $sandbox_order === array_unique( $sandbox_order ) && $sandbox_order === array_values( array_filter( $sandbox_order, static function ( int $position ): bool { return $position > 0; } ) ) && $sandbox_order === ( function ( array $positions ): array { $sorted = $positions; sort( $sorted ); return $sorted; } )( $sandbox_order ), 'Sandbox API settings follow the supplied Postman environment order.' );
credential_check( count( $GLOBALS['mmgwc_test_errors'] ) === 5, 'Each rejected encrypted replacement produces one administrator error.' );

echo 'Credential settings assertions passed: ' . $GLOBALS['mmgwc_test_checks'] . PHP_EOL;
