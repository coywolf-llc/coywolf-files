<?php
/**
 * The coywolf/file editor block.
 *
 * A dynamic block: the editor stores attributes (and a static link fallback in
 * save()), while the front end is server-rendered here — a file "card" styled
 * after Recollect: a parametric file-type badge icon, the file name, a
 * type · size · uploaded-date meta line, an optional description, and Download /
 * Copy-link controls. Light and dark themes are driven by CSS custom properties.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the file block.
 */
class Coywolf_Files_Block {

	/**
	 * Badge color per file-family (matches Recollect's file-icon palette).
	 *
	 * @var array
	 */
	private static $icon_colors = array(
		'pdf'  => '#B42318',
		'doc'  => '#155EEF',
		'docx' => '#155EEF',
		'rtf'  => '#155EEF',
		'txt'  => '#475467',
		'md'   => '#475467',
		'xls'  => '#067647',
		'xlsx' => '#067647',
		'csv'  => '#067647',
		'ppt'  => '#C4320A',
		'pptx' => '#C4320A',
		'key'  => '#C4320A',
		'png'  => '#C11574',
		'jpg'  => '#C11574',
		'jpeg' => '#C11574',
		'gif'  => '#C11574',
		'webp' => '#C11574',
		'svg'  => '#C11574',
		'heic' => '#C11574',
		'bmp'  => '#C11574',
		'mp4'  => '#93264A',
		'mov'  => '#93264A',
		'webm' => '#93264A',
		'mkv'  => '#93264A',
		'avi'  => '#93264A',
		'm4v'  => '#93264A',
		'mp3'  => '#026AA2',
		'wav'  => '#026AA2',
		'm4a'  => '#026AA2',
		'aac'  => '#026AA2',
		'ogg'  => '#026AA2',
		'flac' => '#026AA2',
		'zip'  => '#6941E0',
		'rar'  => '#6941E0',
		'7z'   => '#6941E0',
		'tar'  => '#6941E0',
		'gz'   => '#6941E0',
		'json' => '#B54708',
		'js'   => '#B54708',
		'jsx'  => '#B54708',
		'ts'   => '#B54708',
		'html' => '#B54708',
		'css'  => '#B54708',
		'xml'  => '#B54708',
	);

	/**
	 * Fallback badge color.
	 */
	const DEFAULT_ICON_COLOR = '#667085';

	/**
	 * Settings module.
	 *
	 * @var Coywolf_Files_Settings
	 */
	private $settings;

	/**
	 * File registry.
	 *
	 * @var Coywolf_Files_Registry
	 */
	private $registry;

	/**
	 * Download endpoint.
	 *
	 * @var Coywolf_Files_Download
	 */
	private $download;

	/**
	 * Inherit-able block attribute → appearance setting key.
	 *
	 * @var array
	 */
	private $inherit_map = array(
		'showIcon'        => 'show_icon',
		'showDescription' => 'show_description',
		'showMeta'        => 'show_meta',
		'showDownload'    => 'show_download',
		'showCopyLink'    => 'show_copy_link',
	);

	/**
	 * Constructor.
	 *
	 * @param Coywolf_Files_Settings $settings Settings.
	 * @param Coywolf_Files_Registry $registry Registry.
	 * @param Coywolf_Files_Download $download Download endpoint.
	 */
	public function __construct( Coywolf_Files_Settings $settings, Coywolf_Files_Registry $registry, Coywolf_Files_Download $download ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->download = $download;

		add_action( 'init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front' ) );
	}

	/**
	 * Register the block and its assets.
	 */
	public function register() {
		// Front-end assets (needed wherever the block renders).
		wp_register_script( 'coywolf-files-view', COYWOLF_FILES_URL . 'js/view.js', array(), Coywolf_Files::VERSION, true );
		wp_register_style( 'coywolf-files-view', COYWOLF_FILES_URL . 'css/view.css', array(), Coywolf_Files::VERSION );

		$accent = (string) $this->settings->get( 'accent_color' );
		if ( '' !== $accent ) {
			wp_add_inline_style( 'coywolf-files-view', '.coywolf-files{--cwf-accent:' . $accent . ';}' );
		}

		wp_add_inline_script(
			'coywolf-files-view',
			'window.coywolfFilesView = ' . wp_json_encode(
				array(
					'i18n' => array(
						'copied' => __( 'Link copied', 'coywolf-files' ),
						'copy'   => __( 'Copy link', 'coywolf-files' ),
						'failed' => __( 'Could not copy the link.', 'coywolf-files' ),
					),
				)
			) . ';',
			'before'
		);

		// Editor-only assets. Skip the credential-touching payload on front-end
		// requests, where these scripts are never enqueued anyway.
		if ( is_admin() ) {
			self::register_upload_script();
			wp_register_script(
				'coywolf-files-icon',
				COYWOLF_FILES_URL . 'js/file-icon.js',
				array(),
				Coywolf_Files::VERSION,
				true
			);
			wp_register_script(
				'coywolf-files-block',
				COYWOLF_FILES_URL . 'js/block.js',
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render', 'wp-data', 'coywolf-files-upload', 'coywolf-files-icon' ),
				Coywolf_Files::VERSION,
				true
			);
			wp_set_script_translations( 'coywolf-files-block', 'coywolf-files' );
			wp_register_style( 'coywolf-files-block-editor', COYWOLF_FILES_URL . 'css/block-editor.css', array(), Coywolf_Files::VERSION );

			wp_add_inline_script(
				'coywolf-files-block',
				'window.coywolfFilesBlock = ' . wp_json_encode(
					array(
						'restUrl'     => esc_url_raw( rest_url( 'coywolf-files/v1' ) ),
						'nonce'       => wp_create_nonce( 'wp_rest' ),
						'defaults'    => $this->settings->all(),
						'configured'  => $this->storage_configured(),
						'settingsUrl' => $this->settings->page_url(),
					)
				) . ';',
				'before'
			);
		}

		register_block_type(
			COYWOLF_FILES_PATH,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Whether storage is configured (guarded so a missing bootstrap instance can
	 * never fatal block registration).
	 *
	 * @return bool
	 */
	private function storage_configured() {
		$plugin = Coywolf_Files::instance();
		return $plugin->storage()->is_configured();
	}

	/**
	 * Register the shared presigned-upload client (idempotent).
	 */
	public static function register_upload_script() {
		if ( wp_script_is( 'coywolf-files-upload', 'registered' ) ) {
			return;
		}
		wp_register_script(
			'coywolf-files-upload',
			COYWOLF_FILES_URL . 'js/upload.js',
			array( 'wp-api-fetch' ),
			Coywolf_Files::VERSION,
			true
		);
	}

	/**
	 * Enqueue the front-end assets when a singular post/page contains the block.
	 */
	public function enqueue_front() {
		if ( is_singular() && has_block( 'coywolf/file' ) ) {
			wp_enqueue_style( 'coywolf-files-view' );
			wp_enqueue_script( 'coywolf-files-view' );
		}
	}

	/*
	 * Front-end render.
	 */

	/**
	 * Render the block on the front end.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$file_id = isset( $attributes['fileId'] ) ? (string) $attributes['fileId'] : '';
		if ( '' === $file_id ) {
			return '';
		}

		// Prefer the live registry record (authoritative filename/size/type),
		// falling back to the stored block attributes if the record is gone.
		$record   = $this->registry->get( $file_id );
		$filename = $record ? (string) $record['filename'] : ( isset( $attributes['fileName'] ) ? (string) $attributes['fileName'] : '' );
		$mime     = $record ? (string) $record['mime'] : ( isset( $attributes['mime'] ) ? (string) $attributes['mime'] : '' );
		$size     = $record ? (int) $record['size'] : ( isset( $attributes['size'] ) ? (int) $attributes['size'] : 0 );
		$ext      = $record ? (string) $record['ext'] : ( isset( $attributes['ext'] ) ? (string) $attributes['ext'] : self::extension_of( $filename ) );
		$uploaded = $record ? (string) $record['created'] : ( isset( $attributes['uploaded'] ) ? (string) $attributes['uploaded'] : '' );

		if ( ! $record ) {
			return ''; // File no longer exists; render nothing rather than a dead card.
		}

		$title = isset( $attributes['title'] ) && '' !== trim( (string) $attributes['title'] ) ? (string) $attributes['title'] : $filename;
		$desc  = isset( $attributes['description'] ) ? (string) $attributes['description'] : '';
		$cfg   = $this->resolve( $attributes );

		$download_url = $this->download->url( $file_id );

		wp_enqueue_style( 'coywolf-files-view' );
		wp_enqueue_script( 'coywolf-files-view' );

		$classes = 'coywolf-files';
		$scheme  = (string) $this->settings->get( 'color_scheme' );
		if ( in_array( $scheme, array( 'auto', 'light', 'dark' ), true ) ) {
			$classes .= ' coywolf-files-scheme-' . $scheme;
		}

		$wrapper = get_block_wrapper_attributes( array( 'class' => $classes ) );

		// Meta line: TYPE · SIZE · Uploaded DATE.
		$meta_parts = array();
		if ( '' !== $ext ) {
			$meta_parts[] = strtoupper( $ext );
		}
		if ( $size > 0 ) {
			$meta_parts[] = self::format_size( $size );
		}
		if ( '' !== $uploaded ) {
			$ts = strtotime( $uploaded );
			if ( $ts ) {
				$meta_parts[] = sprintf(
					/* translators: %s: formatted date. */
					__( 'Uploaded %s', 'coywolf-files' ),
					wp_date( 'M j, Y', $ts )
				);
			}
		}

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<div class="coywolf-files-card">
				<?php if ( $cfg['showIcon'] ) : ?>
					<div class="coywolf-files-icon">
						<?php echo self::file_icon_svg( $ext ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG built from an enum color + escaped label. ?>
						<?php if ( '' !== $ext ) : ?>
							<span class="screen-reader-text"><?php echo esc_html( strtoupper( $ext ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="coywolf-files-body">
					<div class="coywolf-files-name"><?php echo esc_html( $title ); ?></div>
					<?php if ( $cfg['showDescription'] && '' !== trim( $desc ) ) : ?>
						<div class="coywolf-files-desc"><?php echo esc_html( $desc ); ?></div>
					<?php endif; ?>
					<?php if ( $cfg['showMeta'] && ! empty( $meta_parts ) ) : ?>
						<div class="coywolf-files-meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></div>
					<?php endif; ?>
				</div>
				<div class="coywolf-files-actions">
					<?php if ( $cfg['showDownload'] ) : ?>
						<a class="coywolf-files-btn coywolf-files-download" href="<?php echo esc_url( $download_url ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file name. */ __( 'Download %s', 'coywolf-files' ), $title ) ); ?>" title="<?php esc_attr_e( 'Download', 'coywolf-files' ); ?>"><?php echo self::download_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
					<?php endif; ?>
					<?php if ( $cfg['showCopyLink'] ) : ?>
						<button type="button" class="coywolf-files-btn coywolf-files-copy" data-url="<?php echo esc_url( $download_url ); ?>" data-label="<?php echo esc_attr( sprintf( /* translators: %s: file name. */ __( 'Copy download link for %s', 'coywolf-files' ), $title ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file name. */ __( 'Copy download link for %s', 'coywolf-files' ), $title ) ); ?>" title="<?php esc_attr_e( 'Copy link', 'coywolf-files' ); ?>"><?php echo self::link_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo self::check_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
					<?php endif; ?>
				</div>
				<span class="coywolf-files-status screen-reader-text" role="status" aria-live="polite"></span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Resolve effective display config: per-block override or settings default.
	 *
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	private function resolve( $attributes ) {
		$cfg = array();
		foreach ( $this->inherit_map as $attr => $key ) {
			if ( array_key_exists( $attr, $attributes ) && null !== $attributes[ $attr ] ) {
				$cfg[ $attr ] = (bool) $attributes[ $attr ];
			} else {
				$cfg[ $attr ] = (bool) $this->settings->get( $key );
			}
		}
		return $cfg;
	}

	/*
	 * Icons + formatting (shared with the editor via file-icon.js).
	 */

	/**
	 * The badge color + label for a file extension.
	 *
	 * @param string $ext Extension (no dot).
	 * @return array { color, label }.
	 */
	public static function file_icon( $ext ) {
		$ext   = strtolower( ltrim( (string) $ext, '.' ) );
		$color = isset( self::$icon_colors[ $ext ] ) ? self::$icon_colors[ $ext ] : self::DEFAULT_ICON_COLOR;
		$label = '' !== $ext ? strtoupper( substr( $ext, 0, 4 ) ) : 'FILE';
		return array(
			'color' => $color,
			'label' => $label,
		);
	}

	/**
	 * The parametric file-type icon SVG. Page/fold/stroke are theme CSS vars;
	 * the badge color comes from the (enum) palette and the label is escaped.
	 *
	 * @param string $ext Extension.
	 * @return string
	 */
	public static function file_icon_svg( $ext ) {
		$icon = self::file_icon( $ext );
		return '<svg class="coywolf-files-icon-svg" width="40" height="48" viewBox="0 0 40 48" fill="none" aria-hidden="true" focusable="false">'
			. '<path d="M10 3 h14 l10 10 v28 a4 4 0 0 1 -4 4 H10 a4 4 0 0 1 -4 -4 V7 a4 4 0 0 1 4 -4 z" fill="var(--cwf-icon-page)" stroke="var(--cwf-icon-stroke)" stroke-width="1.5"/>'
			. '<path d="M24 3 L24 13 L34 13" fill="var(--cwf-icon-fold)" stroke="var(--cwf-icon-stroke)" stroke-width="1.5" stroke-linejoin="round"/>'
			. '<rect x="2" y="26" width="27" height="15" rx="3.5" fill="' . esc_attr( $icon['color'] ) . '"/>'
			. '<text x="15.5" y="36.5" text-anchor="middle" font-size="8.5" font-weight="700" fill="#ffffff" style="letter-spacing:0.02em">' . esc_html( $icon['label'] ) . '</text>'
			. '</svg>';
	}

	/**
	 * Lucide "download" icon.
	 *
	 * @return string
	 */
	public static function download_svg() {
		return '<svg class="coywolf-files-ic" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>';
	}

	/**
	 * Lucide "link" icon (copy link).
	 *
	 * @return string
	 */
	public static function link_svg() {
		return '<svg class="coywolf-files-ic coywolf-files-ic-link" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
	}

	/**
	 * Lucide "check" icon (copied confirmation).
	 *
	 * @return string
	 */
	public static function check_svg() {
		return '<svg class="coywolf-files-ic coywolf-files-ic-check" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>';
	}

	/**
	 * Format a byte count the way Recollect does (1024-based, one decimal).
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_size( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes < 1024 ) {
			return sprintf(
				/* translators: %s: number of bytes. */
				_n( '%s B', '%s B', $bytes, 'coywolf-files' ),
				number_format_i18n( $bytes )
			);
		}
		$units = array( 'KB', 'MB', 'GB', 'TB', 'PB' );
		$last  = count( $units ) - 1;
		$value = $bytes / 1024;
		$i     = 0;
		while ( $value >= 1024 && $i < $last ) {
			$value /= 1024;
			++$i;
		}
		return number_format_i18n( $value, 1 ) . ' ' . $units[ $i ];
	}

	/**
	 * Lowercase extension of a filename (no dot), or ''.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public static function extension_of( $filename ) {
		$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		return preg_replace( '/[^a-z0-9]/', '', $ext );
	}
}
