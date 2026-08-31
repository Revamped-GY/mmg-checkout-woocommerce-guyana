/* global window */

( function() {
	'use strict';

	const wcObj = window.wc || {};
	const wpObj = window.wp || {};
	const registry = wcObj.wcBlocksRegistry || wcObj.blocksRegistry;
	const settingsApi = wcObj.wcSettings || wcObj.settings;
	if ( ! registry || ! registry.registerPaymentMethod || ! settingsApi || ! wpObj.element || ! wpObj.htmlEntities ) {
		return;
	}

	const { registerPaymentMethod } = registry;
	const { getSetting } = settingsApi;
	const { createElement, useEffect, useState } = wpObj.element;
	const { decodeEntities } = wpObj.htmlEntities;
	const settings = getSetting( 'mmg_initiated_data', {} );
	const title = decodeEntities( settings.title || 'Approve in the MMG app' );
	const description = decodeEntities( settings.description || 'Enter the phone number registered to your MMG account. Open the MMG app and approve the payment request.' );
	const iconUrl = settings.icon_url || '';
	const currencyAvailable = settings.currency_available !== false;
	const features = settings.supports && settings.supports.features ? settings.supports.features : [ 'products' ];

	const normalisePhone = ( value ) => {
		const digits = String( value || '' ).replace( /\D+/g, '' );
		if ( digits.length === 10 && digits.startsWith( '592' ) ) {
			return digits.slice( 3 );
		}
		return /^\d{7}$/.test( digits ) ? digits : '';
	};

	const Content = ( props ) => {
		const [ phone, setPhone ] = useState( '' );
		const eventRegistration = props.eventRegistration || {};
		const emitResponse = props.emitResponse || {};

		useEffect( () => {
			const registerSetup = eventRegistration.onPaymentSetup || eventRegistration.onPaymentProcessing;
			if ( ! registerSetup ) {
				return undefined;
			}
			const unsubscribe = registerSetup( () => {
				const normalised = normalisePhone( phone );
				if ( ! normalised ) {
					return {
						type: emitResponse.responseTypes ? emitResponse.responseTypes.ERROR : 'error',
						message: 'Enter the seven-digit phone number registered to the MMG account.',
					};
				}
				return {
					type: emitResponse.responseTypes ? emitResponse.responseTypes.SUCCESS : 'success',
					meta: {
						paymentMethodData: {
							mmgwc_initiated_phone: normalised,
						},
					},
				};
			} );
			return unsubscribe;
		}, [ phone, eventRegistration.onPaymentSetup, eventRegistration.onPaymentProcessing, emitResponse.responseTypes ] );

		return createElement(
			'div',
			{ className: 'mmgwc-initiated-fields' },
			createElement( 'p', { className: 'mmgwc-initiated-description' }, description ),
			createElement( 'label', { htmlFor: 'mmgwc-initiated-phone-blocks' }, 'MMG phone number' ),
			createElement( 'input', {
				id: 'mmgwc-initiated-phone-blocks',
				className: 'wc-block-components-text-input__input input-text',
				type: 'tel',
				inputMode: 'numeric',
				autoComplete: 'tel',
				maxLength: 18,
				value: phone,
				onChange: ( event ) => setPhone( event.target.value ),
				'required': true,
				'aria-describedby': 'mmgwc-initiated-phone-help',
			} ),
			createElement( 'p', { id: 'mmgwc-initiated-phone-help', className: 'mmgwc-initiated-privacy' }, 'Use the seven-digit number registered to MMG. The plugin stores only a masked ending.' )
		);
	};

	const Label = () => createElement(
		'span',
		{ className: 'mmgwc-gateway-label' },
		iconUrl ? createElement( 'img', {
			src: iconUrl,
			alt: '',
			className: 'mmgwc-gateway-icon',
		} ) : null,
		createElement( 'span', { className: 'mmgwc-gateway-text' }, title )
	);

	registerPaymentMethod( {
		name: 'mmg_initiated',
		label: createElement( Label, null ),
		ariaLabel: title,
		canMakePayment: () => currencyAvailable,
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		supports: { features },
	} );
} )();
