<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional merchant-initiated MMG approval request payment method.
 */
final class WC_Gateway_MMGWC_Initiated extends WC_Payment_Gateway {
	public function __construct() {
		$this->id = 'mmg_initiated';
		$this->method_title = 'MMG App Approval';
		$this->method_description = 'Send a payment approval request to the customer’s MMG app. Configure this method under MMG Checkout settings.';
		$this->has_fields = true;
		$this->supports = array( 'products', 'pay_for_order' );
		$this->enabled = (string) MMGWC_Settings::get( 'initiated_enabled', 'no' );
		$this->title = (string) MMGWC_Settings::get( 'initiated_title', 'Approve in the MMG app' );
		$this->description = (string) MMGWC_Settings::get(
			'initiated_description',
			'Enter the phone number registered to your MMG account. Open the MMG app and approve the payment request before it expires.'
		);
		$this->icon = apply_filters( 'mmgwc_gateway_icon_url', defined( 'MMGWC_GATEWAY_ICON_URL' ) ? MMGWC_GATEWAY_ICON_URL : '' );
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$config = MMGWC_Settings::get_config( MMGWC_Settings::get_mode() );
		return empty( MMGWC_Payment_Verifier::missing_api_fields( $config ) )
			&& trim( (string) ( $config['credit_account_id'] ?? '' ) ) !== ''
			&& MMGWC_Payment_Context::can_process_currency( MMGWC_Payment_Context::current_checkout_currency() );
	}

	public function payment_fields() {
		if ( trim( (string) $this->description ) !== '' ) {
			echo '<p class="mmgwc-initiated-description">' . wp_kses_post( $this->description ) . '</p>';
		}
		woocommerce_form_field(
			'mmgwc_initiated_phone',
			array(
				'type' => 'tel',
				'label' => 'MMG phone number',
				'required' => true,
				'class' => array( 'form-row-wide', 'mmgwc-initiated-phone-field' ),
				'input_class' => array( 'input-text' ),
				'custom_attributes' => array(
					'inputmode' => 'numeric',
					'autocomplete' => 'tel',
					'maxlength' => '18',
				),
				'description' => 'Use the seven-digit number registered to the MMG account.',
			),
			isset( $_POST['mmgwc_initiated_phone'] ) ? wc_clean( wp_unslash( (string) $_POST['mmgwc_initiated_phone'] ) ) : ''
		);
		echo '<p class="mmgwc-initiated-privacy">The number is sent to MMG to create this request. The plugin stores only a masked ending.</p>';
	}

	public function validate_fields() {
		$phone = isset( $_POST['mmgwc_initiated_phone'] ) ? wc_clean( wp_unslash( (string) $_POST['mmgwc_initiated_phone'] ) ) : '';
		if ( MMGWC_API::normalise_customer_account( $phone ) === '' ) {
			wc_add_notice( 'Enter the seven-digit phone number registered to the MMG account.', 'error' );
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failure' );
		}
		if ( ! $order->needs_payment() ) {
			if ( ! $order->is_paid() ) {
				$resumed = MMGWC_Initiated_Payments::resume_existing_request( $order );
				if ( is_wp_error( $resumed ) ) {
					wc_add_notice( $resumed->get_error_message(), 'error' );
					return array( 'result' => 'failure' );
				}
			}
			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$phone = isset( $_POST['mmgwc_initiated_phone'] ) ? wc_clean( wp_unslash( (string) $_POST['mmgwc_initiated_phone'] ) ) : '';
		try {
			$result = MMGWC_Initiated_Payments::start( $order, $phone );
		} catch ( Throwable $e ) {
			MMGWC_Logger::warning( 'MMG approval request validation failed', array( 'order_id' => $order->get_id(), 'reason' => $e->getMessage() ) );
			wc_add_notice( 'Could not start the MMG approval request. Check the order currency and try again.', 'error' );
			return array( 'result' => 'failure' );
		}
		if ( is_wp_error( $result ) ) {
			MMGWC_Logger::warning( 'MMG approval request could not start', array( 'order_id' => $order->get_id(), 'reason' => $result->get_error_code() ) );
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result' => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return new WP_Error( 'mmgwc_refunds_not_supported', 'Refunds must be processed in MMG.' );
	}
}
