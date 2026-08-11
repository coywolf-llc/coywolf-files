/**
 * Coywolf Files — Settings screen: the Check CORS button (no build step).
 *
 * Mints a presigned PUT for a throwaway object, then tries a real cross-origin
 * upload from the browser. A thrown fetch means the bucket's CORS rule is
 * blocking this site; a success without a readable ETag means ExposeHeaders is
 * missing (large files would fail); a success with an ETag means it's all set.
 * The throwaway object is deleted server-side afterward.
 */
( function ( window, document ) {
	'use strict';

	var data = window.coywolfFilesSettings || { i18n: {} };
	var i18n = data.i18n || {};

	function apiFetch( args ) {
		return window.wp.apiFetch( args );
	}

	function init() {
		var btn = document.getElementById( 'coywolf-files-cors-check' );
		var out = document.getElementById( 'coywolf-files-cors-result' );
		if ( ! btn || ! out ) {
			return;
		}

		function render( kind, message, json ) {
			out.className = 'coywolf-files-cors-result' + ( kind ? ' is-' + kind : '' );
			out.textContent = message || '';
			if ( json ) {
				var pre = document.createElement( 'pre' );
				pre.textContent = json;
				out.appendChild( pre );
			}
		}

		function cleanup( key ) {
			apiFetch( { path: '/coywolf-files/v1/cors-check/cleanup', method: 'POST', data: { key: key } } ).catch( function () {} );
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			render( '', i18n.checking || 'Checking…' );

			apiFetch( { path: '/coywolf-files/v1/cors-check', method: 'POST' } ).then( function ( res ) {
				var key = res.key;
				return window.fetch( res.url, {
					method: 'PUT',
					body: 'coywolf-files cors check'
				} ).then( function ( r ) {
					// The response is readable, so CORS is allowing this site.
					if ( r.ok ) {
						cleanup( key );
						var etag = r.headers.get( 'ETag' ) || r.headers.get( 'etag' );
						if ( etag ) {
							render( 'ok', i18n.ok || 'CORS is configured correctly.' );
						} else {
							render( 'warn', i18n.noEtag || 'Uploads work, but the ETag header is not exposed, so large files will fail.' );
						}
					} else {
						render( 'warn', ( i18n.rejected || 'CORS is set, but the test upload was rejected.' ) + ' (HTTP ' + r.status + ')' );
					}
				} ).catch( function () {
					// A thrown fetch on a cross-origin request = CORS is blocking it.
					render( 'fail', i18n.fail || 'CORS is not configured for this site.', data.corsJson );
				} );
			} ).catch( function ( err ) {
				render( 'fail', ( err && err.message ) ? err.message : ( i18n.serverErr || 'Could not start the check.' ) );
			} ).then( function () {
				btn.disabled = false;
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
