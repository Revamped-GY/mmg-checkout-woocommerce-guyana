/* global window */

( function() {
	const wcObj = window.wc || {};
	const wpObj = window.wp || {};

	const registry = wcObj.wcBlocksRegistry || wcObj.blocksRegistry;
	const settingsApi = wcObj.wcSettings || wcObj.settings;

	if ( ! registry || ! registry.registerPaymentMethod || ! settingsApi || ! settingsApi.getSetting ) {
		return;
	}

	if ( ! wpObj.element || ! wpObj.htmlEntities ) {
		return;
	}

	const { registerPaymentMethod } = registry;
	const { getSetting } = settingsApi;
	const { createElement } = wpObj.element;
	const { decodeEntities } = wpObj.htmlEntities;

	const settings = getSetting( 'mmg_checkout_data', {} );
	const label = decodeEntities( settings.title || 'MMG' );
	const description = decodeEntities( settings.description || 'You will be redirected to MMG to complete your payment.' );
	const iconUrl = settings.icon_url || '';

	const features = ( settings.supports && settings.supports.features ) ? settings.supports.features : [ 'products' ];

	const Content = () => createElement( 'div', null, description );

	const Label = () => createElement(
		'span',
		{ className: 'mmgwc-gateway-label' },
		iconUrl ? createElement( 'img', {
			src: iconUrl,
			alt: '',
			className: 'mmgwc-gateway-icon',
			style: { width: '20px', height: '20px', objectFit: 'contain', borderRadius: '4px', marginRight: '8px' },
		} ) : null,
		createElement( 'span', { className: 'mmgwc-gateway-text' }, label )
	);

	registerPaymentMethod( {
		name: 'mmg_checkout',
		label: createElement( Label, null ),
		ariaLabel: label,
		canMakePayment: () => true,
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		supports: {
			features,
		},
	} );
} )();
