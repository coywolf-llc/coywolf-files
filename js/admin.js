/**
 * Coywolf Files — admin screens (no build step).
 *
 * Drives the Upload File screen (presigned direct upload) and the Copy-link /
 * Delete row actions on the All Files table.
 */
( function ( window, document ) {
	'use strict';

	var data = window.coywolfFilesAdmin || { i18n: {} };
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

	// Copy-link row action.
	document.addEventListener( 'click', function ( e ) {
		var copy = e.target.closest ? e.target.closest( '.coywolf-files-copy' ) : null;
		if ( copy ) {
			e.preventDefault();
			var url = copy.getAttribute( 'data-url' );
			if ( ! url ) {
				return;
			}
			var original = copy.getAttribute( 'data-label' ) || copy.textContent;
			copy.setAttribute( 'data-label', original );
			copyText( url ).then( function () {
				copy.textContent = i18n.copied || 'Copied!';
			} ).catch( function () {
				copy.textContent = i18n.copyFailed || 'Copy failed';
			} ).then( function () {
				window.setTimeout( function () {
					copy.textContent = original;
				}, 2000 );
			} );
			return;
		}

		// Delete confirmation.
		var del = e.target.closest ? e.target.closest( '.coywolf-files-delete' ) : null;
		if ( del ) {
			if ( ! window.confirm( i18n.confirmDelete || 'Delete this file?' ) ) {
				e.preventDefault();
			}
		}
	} );

	// Upload File screen.
	function initUploader() {
		var wrap = document.querySelector( '.coywolf-files-uploader' );
		if ( ! wrap ) {
			return;
		}
		var input = wrap.querySelector( '#coywolf-files-file' );
		var drop = wrap.querySelector( '#coywolf-files-drop' );
		var startBtn = wrap.querySelector( '#coywolf-files-upload-start' );
		var progress = wrap.querySelector( '.coywolf-files-progress' );
		var bar = wrap.querySelector( '.coywolf-files-progress-bar' );
		var status = wrap.querySelector( '.coywolf-files-upload-status' );

		function setStatus( text ) {
			status.textContent = text;
		}

		function statusText( state ) {
			switch ( state ) {
				case 'preparing': return i18n.preparing || 'Preparing…';
				case 'uploading': return i18n.uploading || 'Uploading…';
				case 'finishing': return i18n.finishing || 'Finishing…';
				default: return '';
			}
		}

		if ( drop ) {
			[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
				drop.addEventListener( ev, function ( e ) {
					e.preventDefault();
					drop.classList.add( 'is-dragover' );
				} );
			} );
			[ 'dragleave', 'drop' ].forEach( function ( ev ) {
				drop.addEventListener( ev, function ( e ) {
					e.preventDefault();
					drop.classList.remove( 'is-dragover' );
				} );
			} );
			drop.addEventListener( 'drop', function ( e ) {
				if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length ) {
					input.files = e.dataTransfer.files;
				}
			} );
		}

		var busy = false;
		var lastMilestone = -1;

		function setProgress( fraction ) {
			var pct = Math.round( fraction * 100 );
			bar.style.width = pct + '%';
			progress.setAttribute( 'aria-valuenow', String( pct ) );
			// Announce at 25% steps so long uploads give occasional audible cues.
			var milestone = Math.floor( pct / 25 ) * 25;
			if ( milestone > lastMilestone && milestone > 0 && milestone < 100 ) {
				lastMilestone = milestone;
				setStatus( ( i18n.uploading || 'Uploading…' ) + ' ' + milestone + '%' );
			}
		}

		startBtn.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}
			var file = input.files && input.files[ 0 ];
			if ( ! file ) {
				setStatus( i18n.pickFile || 'Choose a file first.' );
				return;
			}
			if ( ! window.coywolfFilesUpload ) {
				setStatus( i18n.uploadFailed || 'Upload failed.' );
				return;
			}
			// aria-disabled (not disabled) so keyboard focus stays on the button.
			busy = true;
			startBtn.setAttribute( 'aria-disabled', 'true' );
			lastMilestone = -1;
			progress.hidden = false;
			setProgress( 0 );
			window.coywolfFilesUpload.upload( file, {
				onStatus: function ( s ) {
					setStatus( statusText( s ) );
				},
				onProgress: setProgress,
				onDone: function () {
					setProgress( 1 );
					setStatus( i18n.uploaded || 'Uploaded!' );
					busy = false;
					startBtn.removeAttribute( 'aria-disabled' );
					input.value = '';
				},
				onError: function ( message ) {
					busy = false;
					startBtn.removeAttribute( 'aria-disabled' );
					setStatus( message || i18n.uploadFailed || 'Upload failed.' );
				}
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initUploader );
	} else {
		initUploader();
	}
} )( window, document );
