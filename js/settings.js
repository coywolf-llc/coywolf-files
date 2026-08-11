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

	// Show only the location field relevant to the chosen provider: Region for
	// Backblaze B2 / Amazon S3, Cloudflare account ID for R2.
	function initProviderToggle() {
		var select = document.getElementById( 'coywolf-files-provider' );
		if ( ! select ) {
			return;
		}
		var form = select.closest ? select.closest( 'form' ) : null;
		var region = document.querySelector( '.coywolf-files-loc-region' );
		var account = document.querySelector( '.coywolf-files-loc-account' );

		function apply() {
			var v = select.value;
			// Reveal all provider-dependent rows only once a provider is chosen.
			if ( form ) {
				if ( '' === v ) {
					form.classList.add( 'coywolf-files-no-provider' );
				} else {
					form.classList.remove( 'coywolf-files-no-provider' );
				}
			}
			// Within the (now visible) location row, show only the relevant field.
			if ( region ) {
				region.style.display = ( 'b2' === v || 's3' === v ) ? '' : 'none';
			}
			if ( account ) {
				account.style.display = ( 'r2' === v ) ? '' : 'none';
			}
		}

		select.addEventListener( 'change', apply );
		apply();
	}

	// Live-update the sample card as the appearance controls change.
	function initAppearancePreview() {
		var preview = document.getElementById( 'coywolf-files-preview' );
		if ( ! preview ) {
			return;
		}
		var scheme = document.getElementById( 'coywolf-files-scheme' );
		var accent = document.getElementById( 'coywolf-files-accent' );
		var toggles = document.querySelectorAll( '[data-cwf-preview]' );
		var i;

		function applyScheme() {
			if ( ! scheme ) {
				return;
			}
			preview.classList.remove( 'coywolf-files-scheme-auto', 'coywolf-files-scheme-light', 'coywolf-files-scheme-dark' );
			var v = scheme.value;
			if ( 'auto' === v || 'light' === v || 'dark' === v ) {
				preview.classList.add( 'coywolf-files-scheme-' + v );
			}
		}

		function applyAccent() {
			if ( ! accent ) {
				return;
			}
			var v = accent.value.trim();
			if ( /^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/.test( v ) ) {
				preview.style.setProperty( '--cwf-accent', v );
			} else {
				preview.style.removeProperty( '--cwf-accent' );
			}
		}

		function applyToggle( cb ) {
			var part = preview.querySelector( '[data-cwf-part="' + cb.getAttribute( 'data-cwf-preview' ) + '"]' );
			if ( part ) {
				part.style.display = cb.checked ? '' : 'none';
			}
		}

		if ( scheme ) {
			scheme.addEventListener( 'change', applyScheme );
		}
		if ( accent ) {
			accent.addEventListener( 'input', applyAccent );
			accent.addEventListener( 'change', applyAccent );
		}
		for ( i = 0; i < toggles.length; i++ ) {
			( function ( cb ) {
				cb.addEventListener( 'change', function () {
					applyToggle( cb );
				} );
				applyToggle( cb );
			} )( toggles[ i ] );
		}
		applyScheme();
		applyAccent();
	}

	function init() {
		initProviderToggle();
		initAppearancePreview();

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
