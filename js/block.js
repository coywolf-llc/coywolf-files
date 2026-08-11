/**
 * Coywolf Files — editor block (no build step; uses window.wp.*).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var ServerSideRender = wp.serverSideRender;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;

	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var Button = components.Button;
	var Modal = components.Modal;
	var Spinner = components.Spinner;
	var Placeholder = components.Placeholder;

	var cfg = window.coywolfFilesBlock || { defaults: {} };
	var defaults = cfg.defaults || {};
	var icons = window.coywolfFilesIcon;

	var defaultKey = {
		showIcon: 'show_icon',
		showDescription: 'show_description',
		showMeta: 'show_meta',
		showDownload: 'show_download',
		showCopyLink: 'show_copy_link'
	};

	function defaultBool( attr ) {
		return !! defaults[ defaultKey[ attr ] ];
	}

	/**
	 * File library picker modal.
	 */
	function Picker( props ) {
		var queryState = useState( '' );
		var query = queryState[ 0 ];
		var setQuery = queryState[ 1 ];
		var itemsState = useState( null );
		var items = itemsState[ 0 ];
		var setItems = itemsState[ 1 ];
		var searchRef = useRef( null );

		useEffect( function () {
			var t = setTimeout( function () {
				if ( searchRef.current ) {
					searchRef.current.focus();
				}
			}, 0 );
			return function () {
				clearTimeout( t );
			};
		}, [] );

		useEffect( function () {
			var t = setTimeout( function () {
				apiFetch( { path: '/coywolf-files/v1/files?s=' + encodeURIComponent( query ) } )
					.then( function ( res ) {
						setItems( res && res.files ? res.files : [] );
					} )
					.catch( function () {
						setItems( [] );
					} );
			}, 250 );
			return function () {
				clearTimeout( t );
			};
		}, [ query ] );

		var grid;
		if ( null === items ) {
			grid = el( Spinner, {} );
		} else if ( items.length ) {
			grid = el(
				'div',
				{ className: 'coywolf-files-picker-grid' },
				items.map( function ( f ) {
					return el(
						'button',
						{
							key: f.fileId,
							type: 'button',
							className: 'coywolf-files-picker-item',
							onClick: function () {
								props.onSelect( f );
							}
						},
						el( 'span', {
							className: 'coywolf-files-picker-icon',
							dangerouslySetInnerHTML: { __html: icons ? icons.svg( f.ext ) : '' }
						} ),
						el( 'span', { className: 'coywolf-files-picker-name' }, f.fileName ),
						el( 'span', { className: 'coywolf-files-picker-size' }, icons ? icons.formatSize( f.size ) : '' )
					);
				} )
			);
		} else {
			grid = el( 'p', {}, __( 'No files found.', 'coywolf-files' ) );
		}

		return el(
			Modal,
			{
				title: __( 'Select a file', 'coywolf-files' ),
				onRequestClose: props.onClose,
				className: 'coywolf-files-picker'
			},
			el(
				'div',
				{ className: 'coywolf-files-picker-search' },
				el( TextControl, {
					ref: searchRef,
					label: __( 'Search files', 'coywolf-files' ),
					hideLabelFromVision: true,
					value: query,
					placeholder: __( 'Search files…', 'coywolf-files' ),
					onChange: setQuery,
					__nextHasNoMarginBottom: true
				} )
			),
			grid
		);
	}

	/**
	 * Upload modal.
	 */
	function Uploader( props ) {
		var fileRef = useRef( null );
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var progressState = useState( 0 );
		var progress = progressState[ 0 ];
		var setProgress = progressState[ 1 ];
		var statusState = useState( '' );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];
		var aliveRef = useRef( true );

		useEffect( function () {
			aliveRef.current = true;
			return function () {
				aliveRef.current = false;
			};
		}, [] );

		function statusText( state ) {
			switch ( state ) {
				case 'preparing': return __( 'Preparing upload…', 'coywolf-files' );
				case 'uploading': return __( 'Uploading…', 'coywolf-files' );
				case 'finishing': return __( 'Finishing…', 'coywolf-files' );
				default: return '';
			}
		}

		function start() {
			var file = fileRef.current && fileRef.current.files && fileRef.current.files[ 0 ];
			if ( ! file ) {
				setStatus( __( 'Choose a file first.', 'coywolf-files' ) );
				return;
			}
			if ( ! window.coywolfFilesUpload ) {
				setStatus( __( 'The uploader failed to load.', 'coywolf-files' ) );
				return;
			}
			setBusy( true );
			setProgress( 0 );
			window.coywolfFilesUpload.upload( file, {
				onStatus: function ( s ) {
					if ( aliveRef.current ) {
						setStatus( statusText( s ) );
					}
				},
				onProgress: function ( fraction ) {
					if ( aliveRef.current ) {
						setProgress( Math.round( fraction * 100 ) );
					}
				},
				onDone: function ( record ) {
					if ( aliveRef.current ) {
						props.onUploaded( record );
					}
				},
				onError: function ( message ) {
					if ( aliveRef.current ) {
						setBusy( false );
						setStatus( ( message || __( 'Upload failed.', 'coywolf-files' ) ) );
					}
				}
			} );
		}

		return el(
			Modal,
			{
				title: __( 'Upload file', 'coywolf-files' ),
				onRequestClose: props.onClose,
				className: 'coywolf-files-uploader-modal'
			},
			el(
				'div',
				{ className: 'coywolf-files-upload-field' },
				el( 'label', { htmlFor: 'coywolf-files-up-file' }, __( 'File', 'coywolf-files' ) ),
				el( 'input', { id: 'coywolf-files-up-file', type: 'file', ref: fileRef, disabled: busy } ),
				el( 'p', { className: 'components-base-control__help' }, __( 'Any file, any size — sent directly to your storage bucket in resumable chunks.', 'coywolf-files' ) )
			),
			el(
				'div',
				{ className: 'coywolf-files-upload-actions' },
				el( Button, { variant: 'primary', onClick: start, disabled: busy }, __( 'Upload', 'coywolf-files' ) )
			),
			busy ? el(
				'div',
				{
					className: 'coywolf-files-upload-progress',
					role: 'progressbar',
					'aria-valuemin': 0,
					'aria-valuemax': 100,
					'aria-valuenow': progress,
					'aria-label': __( 'Upload progress', 'coywolf-files' )
				},
				el( 'div', { className: 'coywolf-files-upload-bar', style: { width: progress + '%' } } )
			) : null,
			// Always render the live region so status changes are announced (a
			// region inserted together with its first message is not).
			el( 'div', { className: 'coywolf-files-upload-status', role: 'status', 'aria-live': 'polite' }, status || '' )
		);
	}

	/**
	 * Block edit.
	 */
	function Edit( props ) {
		var a = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps();
		var pickerState = useState( false );
		var pickerOpen = pickerState[ 0 ];
		var setPickerOpen = pickerState[ 1 ];
		var uploaderState = useState( false );
		var uploaderOpen = uploaderState[ 0 ];
		var setUploaderOpen = uploaderState[ 1 ];

		function resolvedBool( attr ) {
			var v = a[ attr ];
			return ( undefined === v || null === v ) ? defaultBool( attr ) : !! v;
		}

		function inheritToggle( attr, label ) {
			var isInheriting = ( undefined === a[ attr ] || null === a[ attr ] );
			var value = isInheriting ? defaultBool( attr ) : a[ attr ];
			return el( ToggleControl, {
				label: label,
				checked: !! value,
				help: isInheriting ? __( 'Inheriting the site default.', 'coywolf-files' ) : __( 'Overriding the site default.', 'coywolf-files' ),
				__nextHasNoMarginBottom: true,
				onChange: function ( v ) {
					var u = {};
					u[ attr ] = v;
					setAttributes( u );
				}
			} );
		}

		function applyFile( f ) {
			setAttributes( {
				fileId: f.fileId,
				fileName: f.fileName || '',
				title: a.title || f.fileName || '',
				ext: f.ext || '',
				mime: f.mime || '',
				size: f.size || 0,
				uploaded: f.uploaded || ''
			} );
		}

		var inspector = el(
			InspectorControls,
			{},
			el(
				PanelBody,
				{ title: __( 'File', 'coywolf-files' ), initialOpen: true },
				el( 'p', {}, a.fileName ? a.fileName : __( 'No file selected.', 'coywolf-files' ) ),
				el(
					Button,
					{
						variant: 'secondary',
						onClick: function () {
							setPickerOpen( true );
						}
					},
					a.fileId ? __( 'Replace file', 'coywolf-files' ) : __( 'Select file', 'coywolf-files' )
				)
			),
			el(
				PanelBody,
				{ title: __( 'Details', 'coywolf-files' ), initialOpen: true },
				el( TextControl, {
					label: __( 'Name', 'coywolf-files' ),
					value: a.title || '',
					placeholder: a.fileName || '',
					help: __( 'Shown on the card. Defaults to the file name.', 'coywolf-files' ),
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { title: v } );
					}
				} ),
				el( TextareaControl, {
					label: __( 'Description', 'coywolf-files' ),
					value: a.description || '',
					rows: 3,
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { description: v } );
					}
				} )
			),
			el(
				PanelBody,
				{ title: __( 'Display', 'coywolf-files' ), initialOpen: false },
				inheritToggle( 'showIcon', __( 'Show file-type icon', 'coywolf-files' ) ),
				inheritToggle( 'showDescription', __( 'Show description', 'coywolf-files' ) ),
				inheritToggle( 'showMeta', __( 'Show meta line (type · size · date)', 'coywolf-files' ) ),
				inheritToggle( 'showDownload', __( 'Show download button', 'coywolf-files' ) ),
				inheritToggle( 'showCopyLink', __( 'Show copy-link button', 'coywolf-files' ) )
			)
		);

		var body;
		if ( ! a.fileId ) {
			var actions = [];
			if ( cfg.configured ) {
				actions.push(
					el( Button, {
						key: 'select',
						variant: 'primary',
						onClick: function () {
							setPickerOpen( true );
						}
					}, __( 'Select file', 'coywolf-files' ) ),
					el( Button, {
						key: 'upload',
						variant: 'secondary',
						onClick: function () {
							setUploaderOpen( true );
						}
					}, __( 'Upload file', 'coywolf-files' ) )
				);
			} else {
				actions.push(
					el( 'p', { key: 'notice' }, __( 'Connect a storage bucket in Files → Settings to upload or add files.', 'coywolf-files' ) ),
					el( Button, {
						key: 'settings',
						variant: 'secondary',
						href: cfg.settingsUrl
					}, __( 'Open settings', 'coywolf-files' ) )
				);
			}
			body = el(
				Placeholder,
				{
					icon: 'media-default',
					label: __( 'Coywolf File', 'coywolf-files' ),
					instructions: __( 'Upload a file to your storage bucket, or pick one you’ve already uploaded.', 'coywolf-files' )
				},
				el( 'div', { className: 'coywolf-files-placeholder-actions' }, actions )
			);
		} else {
			body = el( ServerSideRender, {
				block: 'coywolf/file',
				attributes: a
			} );
		}

		var picker = pickerOpen ? el( Picker, {
			onClose: function () {
				setPickerOpen( false );
			},
			onSelect: function ( f ) {
				applyFile( f );
				setPickerOpen( false );
			}
		} ) : null;

		var uploader = uploaderOpen ? el( Uploader, {
			onClose: function () {
				setUploaderOpen( false );
			},
			onUploaded: function ( f ) {
				applyFile( f );
				setUploaderOpen( false );
			}
		} ) : null;

		return el( Fragment, {}, inspector, el( 'div', blockProps, body ), picker, uploader );
	}

	/**
	 * Static fallback saved into post content (shown only if the plugin is gone).
	 */
	function save( props ) {
		var a = props.attributes;
		var blockProps = useBlockProps.save();
		if ( ! a.fileId ) {
			return null;
		}
		return el(
			'p',
			blockProps,
			a.title || a.fileName || a.fileId
		);
	}

	wp.blocks.registerBlockType( 'coywolf/file', {
		edit: Edit,
		save: save
	} );
} )( window.wp );
