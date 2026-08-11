/**
 * Coywolf Files — front-end behavior (no build step).
 *
 * Wires the Copy-link button on each file card: copies the stable download URL
 * to the clipboard, swaps the icon to a checkmark, and announces the result in
 * the card's polite live region.
 */
( function ( window, document ) {
	'use strict';

	var data = window.coywolfFilesView || { i18n: {} };
	var i18n = data.i18n || {};

	function copyText( text ) {
		if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			return window.navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			try {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
				resolve();
			} catch ( e ) {
				reject( e );
			}
		} );
	}

	// Announce a message in the card's live region.
	function announce( btn, message ) {
		var card = btn.closest ? btn.closest( '.coywolf-files' ) : null;
		var region = card ? card.querySelector( '.coywolf-files-status' ) : null;
		if ( region ) {
			region.textContent = message;
		}
	}

	function onClick( e ) {
		var btn = e.target.closest ? e.target.closest( '.coywolf-files-copy' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var url = btn.getAttribute( 'data-url' );
		if ( ! url ) {
			return;
		}
		copyText( url ).then( function () {
			btn.classList.add( 'is-copied' );
			announce( btn, i18n.copied || 'Link copied' );
			// Clear any prior timer so a rapid second click doesn't restore early.
			if ( btn._cwfTimer ) {
				window.clearTimeout( btn._cwfTimer );
			}
			btn._cwfTimer = window.setTimeout( function () {
				btn.classList.remove( 'is-copied' );
				announce( btn, '' );
				btn._cwfTimer = null;
			}, 2000 );
		} ).catch( function () {
			announce( btn, i18n.failed || 'Could not copy the link.' );
		} );
	}

	document.addEventListener( 'click', onClick );
} )( window, document );
