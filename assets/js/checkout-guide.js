/* global document, HTMLDialogElement */

( function() {
	'use strict';

	let dialog = null;
	let image = null;
	let previousFocus = null;

	function ensureDialog() {
		if ( dialog ) {
			return dialog;
		}

		dialog = document.createElement( 'dialog' );
		dialog.className = 'mmgwc-lightbox';
		dialog.setAttribute( 'aria-label', 'MMG checkout image preview' );

		const closeButton = document.createElement( 'button' );
		closeButton.type = 'button';
		closeButton.className = 'mmgwc-lightbox-close';
		closeButton.textContent = 'Close';
		closeButton.addEventListener( 'click', function() {
			dialog.close();
		} );

		image = document.createElement( 'img' );
		image.alt = '';

		dialog.appendChild( closeButton );
		dialog.appendChild( image );
		dialog.addEventListener( 'click', function( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'close', function() {
			if ( previousFocus && typeof previousFocus.focus === 'function' ) {
				previousFocus.focus();
			}
		} );
		document.body.appendChild( dialog );
		return dialog;
	}

	document.addEventListener( 'click', function( event ) {
		const link = event.target.closest ? event.target.closest( '[data-mmgwc-lightbox]' ) : null;
		if ( ! link ) {
			return;
		}
		if ( typeof HTMLDialogElement === 'undefined' ) {
			return;
		}

		event.preventDefault();
		const preview = ensureDialog();
		const thumbnail = link.querySelector( 'img' );
		image.src = link.href;
		image.alt = thumbnail ? thumbnail.alt : 'MMG checkout preview';
		previousFocus = link;
		preview.showModal();
	} );
} )();
