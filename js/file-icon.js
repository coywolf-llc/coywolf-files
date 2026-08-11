/**
 * Coywolf Files — shared file-type icon renderer (no build step).
 *
 * Mirrors Coywolf_Files_Block::file_icon()/file_icon_svg() in PHP so the editor
 * and admin screens draw the same parametric document badge the front end does.
 */
( function ( window ) {
	'use strict';

	var COLORS = {
		pdf: '#B42318',
		doc: '#155EEF', docx: '#155EEF', rtf: '#155EEF',
		txt: '#475467', md: '#475467',
		xls: '#067647', xlsx: '#067647', csv: '#067647',
		ppt: '#C4320A', pptx: '#C4320A', key: '#C4320A',
		png: '#C11574', jpg: '#C11574', jpeg: '#C11574', gif: '#C11574', webp: '#C11574', svg: '#C11574', heic: '#C11574', bmp: '#C11574',
		mp4: '#93264A', mov: '#93264A', webm: '#93264A', mkv: '#93264A', avi: '#93264A', m4v: '#93264A',
		mp3: '#026AA2', wav: '#026AA2', m4a: '#026AA2', aac: '#026AA2', ogg: '#026AA2', flac: '#026AA2',
		zip: '#6941E0', rar: '#6941E0', '7z': '#6941E0', tar: '#6941E0', gz: '#6941E0',
		json: '#B54708', js: '#B54708', jsx: '#B54708', ts: '#B54708', html: '#B54708', css: '#B54708', xml: '#B54708'
	};
	var DEFAULT_COLOR = '#667085';

	function extensionOf( name ) {
		var m = String( name || '' ).toLowerCase().match( /\.([a-z0-9]+)$/ );
		return m ? m[ 1 ] : '';
	}

	function iconFor( ext ) {
		ext = String( ext || '' ).toLowerCase().replace( /[^a-z0-9]/g, '' );
		return {
			color: COLORS[ ext ] || DEFAULT_COLOR,
			label: ext ? ext.toUpperCase().slice( 0, 4 ) : 'FILE'
		};
	}

	function escapeHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	// Returns an SVG string identical to the server-rendered badge.
	function svg( ext ) {
		var icon = iconFor( ext );
		return '<svg class="coywolf-files-icon-svg" width="40" height="48" viewBox="0 0 40 48" fill="none" aria-hidden="true" focusable="false">'
			+ '<path d="M10 3 h14 l10 10 v28 a4 4 0 0 1 -4 4 H10 a4 4 0 0 1 -4 -4 V7 a4 4 0 0 1 4 -4 z" fill="var(--cwf-icon-page)" stroke="var(--cwf-icon-stroke)" stroke-width="1.5"/>'
			+ '<path d="M24 3 L24 13 L34 13" fill="var(--cwf-icon-fold)" stroke="var(--cwf-icon-stroke)" stroke-width="1.5" stroke-linejoin="round"/>'
			+ '<rect x="2" y="26" width="27" height="15" rx="3.5" fill="' + icon.color + '"/>'
			+ '<text x="15.5" y="36.5" text-anchor="middle" font-size="8.5" font-weight="700" fill="#ffffff" style="letter-spacing:0.02em">' + escapeHtml( icon.label ) + '</text>'
			+ '</svg>';
	}

	function formatSize( bytes ) {
		bytes = parseInt( bytes, 10 ) || 0;
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		var units = [ 'KB', 'MB', 'GB', 'TB', 'PB' ];
		var value = bytes / 1024;
		var i = 0;
		while ( value >= 1024 && i < units.length - 1 ) {
			value /= 1024;
			i++;
		}
		return ( Math.round( value * 10 ) / 10 ) + ' ' + units[ i ];
	}

	window.coywolfFilesIcon = {
		extensionOf: extensionOf,
		iconFor: iconFor,
		svg: svg,
		formatSize: formatSize
	};
} )( window );
