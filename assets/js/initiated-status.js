/* global window, document, FormData, fetch, AbortController */

( function() {
	'use strict';

	const root = document.querySelector( '[data-mmgwc-initiated-status]' );
	if ( ! root ) {
		return;
	}

	const message = root.querySelector( '.mmgwc-initiated-status__message' );
	const expiryText = root.querySelector( '[data-mmgwc-expiry]' );
	const endpoint = root.dataset.endpoint || '';
	const orderId = root.dataset.orderId || '';
	const orderKey = root.dataset.orderKey || '';
	const expiry = Number.parseInt( root.dataset.expiry || '0', 10 );
	let timer = null;
	let countdownTimer = null;
	let requestRunning = false;
	let stopped = false;
	let nextDelay = 10000;

	const updateCountdown = () => {
		if ( ! expiryText || ! Number.isFinite( expiry ) || expiry <= 0 ) {
			return;
		}
		const remaining = Math.max( 0, expiry - Math.floor( Date.now() / 1000 ) );
		if ( remaining === 0 ) {
			expiryText.textContent = 'This request has reached its expiry time.';
			return;
		}
		const minutes = Math.floor( remaining / 60 );
		const seconds = String( remaining % 60 ).padStart( 2, '0' );
		expiryText.textContent = `Request expires in ${ minutes }:${ seconds }.`;
	};

	const stop = () => {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}
		if ( countdownTimer ) {
			window.clearInterval( countdownTimer );
			countdownTimer = null;
		}
		stopped = true;
	};

	const schedule = ( delay ) => {
		if ( stopped ) {
			return;
		}
		if ( timer ) {
			window.clearTimeout( timer );
		}
		timer = window.setTimeout( check, delay );
	};

	const check = async () => {
		if ( requestRunning || ! endpoint || ! orderId || ! orderKey ) {
			return;
		}
		requestRunning = true;
		const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		const timeout = window.setTimeout( () => controller && controller.abort(), 12000 );
		try {
			const body = new FormData();
			body.append( 'action', 'mmgwc_initiated_status' );
			body.append( 'order_id', orderId );
			body.append( 'order_key', orderKey );
			const response = await fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				body,
				signal: controller ? controller.signal : undefined,
			} );
			const payload = await response.json();
			if ( ! response.ok || ! payload || ! payload.success || ! payload.data ) {
				throw new Error( 'Status check failed' );
			}

			if ( message && payload.data.message ) {
				message.textContent = payload.data.message;
			}
			const serverDelay = Number.parseInt( payload.data.retry_after_seconds || '10', 10 ) * 1000;
			nextDelay = Number.isFinite( serverDelay ) ? Math.max( 10000, Math.min( 120000, serverDelay ) ) : 10000;
			root.dataset.state = payload.data.state || 'pending';
			if ( payload.data.state === 'paid' ) {
				stop();
				window.setTimeout( () => {
					window.location.assign( payload.data.redirect || window.location.href );
				}, 900 );
			} else if ( [ 'failed', 'expired', 'review', 'invalid' ].includes( payload.data.state ) ) {
				stop();
			}
		} catch ( error ) {
			nextDelay = Math.min( 60000, nextDelay * 2 );
			if ( message ) {
				message.textContent = 'The status check is temporarily unavailable. Keep this page open while the store checks again.';
			}
		} finally {
			window.clearTimeout( timeout );
			requestRunning = false;
			if ( ! stopped ) {
				schedule( nextDelay );
			}
		}
	};

	updateCountdown();
	countdownTimer = window.setInterval( updateCountdown, 1000 );
	schedule( 1500 );
} )();
