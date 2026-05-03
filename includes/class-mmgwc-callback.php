<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Callback {

	private static $handled = false;

	public static function init() {
		add_action( 'woocommerce_api_' . MMGWC_WC_API_ENDPOINT, array( __CLASS__, 'handle' ) );

		// Fallback for themes or routing layers that do not reach WooCommerce API actions reliably.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_fallback' ), 0 );

		// Prevent canonical redirects from rewriting callback requests or stripping query formats.
		add_filter( 'redirect_canonical', array( __CLASS__, 'disable_canonical_for_callback' ), 10, 2 );
	}

	public static function maybe_handle_fallback() {
		if ( self::$handled ) {
			return;
		}
		if ( ! self::is_callback_request() ) {
			return;
		}
		self::handle();
	}

	public static function disable_canonical_for_callback( $redirect_url, $requested_url ) {
		if ( self::is_callback_request() ) {
			return false;
		}
		return $redirect_url;
	}

	private static function is_callback_request(): bool {
		if ( isset( $_GET['wc-api'] ) && is_scalar( $_GET['wc-api'] ) ) {
			$wc_api = (string) wp_unslash( $_GET['wc-api'] );
			if ( $wc_api === MMGWC_WC_API_ENDPOINT ) {
				return true;
			}
			if ( strpos( $wc_api, MMGWC_WC_API_ENDPOINT . '/' ) === 0 ) {
				return true;
			}
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( $uri !== '' && strpos( $uri, '/wc-api/' . MMGWC_WC_API_ENDPOINT ) !== false ) {
			return true;
		}
		if ( $uri !== '' && strpos( $uri, 'wc-api=' . MMGWC_WC_API_ENDPOINT ) !== false ) {
			return true;
		}

		return false;
	}

	private static function get_gateway() {
		$available_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return $available_gateways['mmg_checkout'] ?? null;
	}

	public static function handle() {
		if ( self::$handled ) {
			return;
		}
		self::$handled = true;

		nocache_headers();
		@header( 'X-Robots-Tag: noindex, nofollow', true );

		// MMG may return token via GET or POST, and some setups append it as a path segment.
		$token = '';
		foreach ( array( 'token', 'Token', 'paymentToken', 'payment_token' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && is_scalar( $_REQUEST[ $k ] ) ) {
				$token = sanitize_text_field( wp_unslash( (string) $_REQUEST[ $k ] ) );
				break;
			}
		}

		// Handle query callback as: ?wc-api=mmg-checkout/<TOKEN>
		if ( $token === '' && isset( $_GET['wc-api'] ) && is_scalar( $_GET['wc-api'] ) ) {
			$wc_api = sanitize_text_field( wp_unslash( (string) $_GET['wc-api'] ) );
			$prefix = MMGWC_WC_API_ENDPOINT . '/';
			if ( strpos( $wc_api, $prefix ) === 0 ) {
				$maybe = substr( $wc_api, strlen( $prefix ) );
				$maybe = trim( (string) $maybe, "/ \t\n\r\0\x0B" );
				$maybe = explode( '/', (string) $maybe, 2 )[0];
				$maybe = rawurldecode( (string) $maybe );
				$maybe = preg_replace( '/[^A-Za-z0-9\-_\.]/', '', (string) $maybe );
				if ( $maybe !== '' ) {
					$token = (string) $maybe;
				}
			}
		}

		if ( $token === '' ) {
			$qv = get_query_var( 'mmg_token' );
			if ( is_string( $qv ) && $qv !== '' ) {
				$token = sanitize_text_field( $qv );
			}
		}

		if ( $token === '' ) {
			$token = self::extract_token_from_path();
		}

		if ( $token === '' ) {
			MMGWC_Logger::warning( 'MMG callback hit with no token' );
			self::render_error( 'No payment token was found in the return request.', 400 );
			return;
		}

		// Ensure WooCommerce is active for order updates.
		if ( ! function_exists( 'wc_get_order' ) ) {
			MMGWC_Logger::error( 'MMG callback: WooCommerce functions not available' );
			self::render_error( 'WooCommerce is not available to process this payment.', 503 );
			return;
		}

		$gateway = self::get_gateway();
		if ( ! $gateway || ! is_a( $gateway, 'WC_Gateway_MMGWC' ) ) {
			// Fallback: instantiate the gateway directly (payment gateways may not be initialised yet).
			if ( class_exists( 'WC_Gateway_MMGWC' ) ) {
				$gateway = new WC_Gateway_MMGWC();
			}
		}

		if ( ! $gateway || ! is_a( $gateway, 'WC_Gateway_MMGWC' ) ) {
			MMGWC_Logger::error( 'MMG callback: gateway not available' );
			self::render_error( 'MMG gateway is not available on this site.', 503 );
			return;
		}

		try {
			$response = $gateway->decrypt_mmg_response( $token );
		} catch ( Exception $e ) {
			MMGWC_Logger::error( 'MMG callback: decrypt failed' );
			self::render_error( 'We could not read the MMG response.', 400 );
			return;
		}

		MMGWC_Logger::debug( 'MMG callback decrypted' );

		$order = $gateway->resolve_order_from_mmg_response( $response );
		if ( ! $order ) {
			MMGWC_Logger::error( 'MMG callback: could not resolve order' );
			self::render_error( 'We could not match this payment to an order.', 404 );
			return;
		}

		$redirect_url = $gateway->handle_mmg_response_for_order( $order, $response );
		if ( is_string( $redirect_url ) && $redirect_url !== '' ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}

		status_header( 200 );
		echo 'OK';
		exit;
	}

	private static function extract_token_from_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( $uri === '' ) {
			return '';
		}

		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];

		$needle = '/wc-api/' . MMGWC_WC_API_ENDPOINT . '/';
		$pos = strpos( $path, $needle );
		if ( $pos === false ) {
			return '';
		}

		$after = substr( $path, $pos + strlen( $needle ) );
		$after = trim( $after, "/ \t\n\r\0\x0B" );
		if ( $after === '' ) {
			return '';
		}

		$seg = explode( '/', $after, 2 )[0];
		$seg = rawurldecode( $seg );
		$seg = preg_replace( '/[^A-Za-z0-9\-_\.]/', '', (string) $seg );
		return is_string( $seg ) ? $seg : '';
	}

	private static function render_error( string $message, int $status = 400 ) {
		status_header( $status );
		@header( 'Content-Type: text/html; charset=UTF-8' );
		$home = esc_url( home_url( '/' ) );
		$message = esc_html( $message );
		echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>MMG Payment</title></head><body style='font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;padding:24px;'>";
		echo "<h2>MMG Payment</h2>";
		echo "<p>{$message}</p>";
		echo "<p><a href='{$home}'>Back to site</a></p>";
		echo '</body></html>';
		exit;
	}
}
