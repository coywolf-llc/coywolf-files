/**
 * Coywolf Files — shared presigned-upload client (no build step).
 *
 * Uploads a file directly from the browser to object storage: the WordPress
 * REST API mints presigned URLs (single PUT for small files, one per part for
 * large ones) and this client PUTs the bytes straight to the storage host, so
 * the file never passes through PHP and there is no size limit. Progress and
 * result are reported through callbacks. Used by both the block's in-editor
 * uploader and the Upload File admin screen.
 */
( function ( window ) {
	'use strict';

	function apiFetch( args ) {
		return window.wp.apiFetch( args );
	}

	// PUT a blob to a presigned URL, reporting byte progress; resolves with ETag.
	function putBlob( url, blob, onBytes ) {
		return new Promise( function ( resolve, reject ) {
			var xhr = new XMLHttpRequest();
			xhr.open( 'PUT', url, true );
			xhr.upload.onprogress = function ( e ) {
				if ( e.lengthComputable && onBytes ) {
					onBytes( e.loaded );
				}
			};
			xhr.onload = function () {
				if ( xhr.status >= 200 && xhr.status < 300 ) {
					var etag = xhr.getResponseHeader( 'ETag' );
					resolve( etag || '' );
				} else {
					reject( new Error( 'HTTP ' + xhr.status ) );
				}
			};
			xhr.onerror = function () {
				reject( new Error( 'network' ) );
			};
			xhr.send( blob );
		} );
	}

	// Retry a PUT a few times; re-fetch the signed URL each attempt via signFn.
	function putWithRetry( signFn, blob, onBytes ) {
		var attempt = 0;
		function tryOnce() {
			return signFn().then( function ( url ) {
				return putBlob( url, blob, onBytes );
			} ).catch( function ( err ) {
				attempt++;
				if ( attempt >= 3 ) {
					throw err;
				}
				return new Promise( function ( r ) {
					window.setTimeout( r, 1000 * attempt );
				} ).then( tryOnce );
			} );
		}
		return tryOnce();
	}

	function upload( file, cb ) {
		cb = cb || {};
		var onProgress = cb.onProgress || function () {};
		var onStatus = cb.onStatus || function () {};
		var onDone = cb.onDone || function () {};
		var onError = cb.onError || function () {};
		var total = file.size || 1;
		var fileId = '';

		function fail( err ) {
			if ( fileId ) {
				apiFetch( { path: '/coywolf-files/v1/upload/abort', method: 'POST', data: { fileId: fileId } } ).catch( function () {} );
			}
			onError( ( err && err.message ) ? err.message : 'error' );
		}

		function complete( parts ) {
			onStatus( 'finishing' );
			apiFetch( {
				path: '/coywolf-files/v1/upload/complete',
				method: 'POST',
				data: parts ? { fileId: fileId, parts: parts } : { fileId: fileId }
			} ).then( function ( record ) {
				onDone( record );
			} ).catch( fail );
		}

		function doSinglePut( url ) {
			onStatus( 'uploading' );
			putBlob( url, file, function ( loaded ) {
				onProgress( Math.min( 1, loaded / total ) );
			} ).then( function () {
				complete( null );
			} ).catch( fail );
		}

		function doMultipart( partSize, partCount ) {
			onStatus( 'uploading' );
			var parts = [];
			var uploadedBase = 0;

			function nextPart( n ) {
				if ( n > partCount ) {
					complete( parts );
					return;
				}
				var start = ( n - 1 ) * partSize;
				var end = Math.min( file.size, start + partSize );
				var blob = file.slice( start, end );
				var partBytes = end - start;

				putWithRetry(
					function () {
						return apiFetch( {
							path: '/coywolf-files/v1/upload/sign-part',
							method: 'POST',
							data: { fileId: fileId, partNumber: n }
						} ).then( function ( res ) {
							return res.url;
						} );
					},
					blob,
					function ( loaded ) {
						onProgress( Math.min( 1, ( uploadedBase + loaded ) / total ) );
					}
				).then( function ( etag ) {
					if ( ! etag ) {
						throw new Error( 'Missing ETag — check the bucket CORS ExposeHeaders setting.' );
					}
					parts.push( { partNumber: n, etag: etag } );
					uploadedBase += partBytes;
					onProgress( Math.min( 1, uploadedBase / total ) );
					nextPart( n + 1 );
				} ).catch( fail );
			}

			nextPart( 1 );
		}

		onStatus( 'preparing' );
		apiFetch( {
			path: '/coywolf-files/v1/upload/start',
			method: 'POST',
			data: {
				filename: file.name,
				mime: file.type || 'application/octet-stream',
				size: file.size
			}
		} ).then( function ( res ) {
			fileId = res.fileId;
			if ( 'put' === res.mode ) {
				doSinglePut( res.url );
			} else if ( 'multipart' === res.mode ) {
				doMultipart( res.partSize, res.partCount );
			} else {
				fail( new Error( 'Unexpected upload response.' ) );
			}
		} ).catch( fail );
	}

	window.coywolfFilesUpload = { upload: upload };
} )( window );
