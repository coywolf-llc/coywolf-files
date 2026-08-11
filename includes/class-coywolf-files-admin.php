<?php
/**
 * Admin screens for Coywolf Files.
 *
 * Registers the Files menu and its subpages (All Files, Upload File,
 * Documentation, Settings), enqueues admin assets on the plugin's own screens,
 * and handles single + bulk deletes from the All Files table (which strip the
 * block from any post/page, delete the object from storage, and remove the
 * registry record).
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI controller.
 */
class Coywolf_Files_Admin {

	/**
	 * Top-level menu slug (also the All Files screen).
	 */
	const PAGE = 'coywolf-files';

	/**
	 * Storage.
	 *
	 * @var Coywolf_Files_Storage
	 */
	private $storage;

	/**
	 * Registry.
	 *
	 * @var Coywolf_Files_Registry
	 */
	private $registry;

	/**
	 * Usage index.
	 *
	 * @var Coywolf_Files_Index
	 */
	private $index;

	/**
	 * Settings.
	 *
	 * @var Coywolf_Files_Settings
	 */
	private $settings;

	/**
	 * Registered submenu hook suffixes.
	 *
	 * @var array
	 */
	private $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Coywolf_Files_Storage  $storage  Storage.
	 * @param Coywolf_Files_Registry $registry Registry.
	 * @param Coywolf_Files_Index    $index    Usage index.
	 * @param Coywolf_Files_Settings $settings Settings.
	 */
	public function __construct( Coywolf_Files_Storage $storage, Coywolf_Files_Registry $registry, Coywolf_Files_Index $index, Coywolf_Files_Settings $settings ) {
		$this->storage  = $storage;
		$this->registry = $registry;
		$this->index    = $index;
		$this->settings = $settings;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Files menu and its subpages.
	 */
	public function register_menu() {
		$cap = Coywolf_Files::CAPABILITY;

		$this->hooks['list'] = add_menu_page(
			__( 'Coywolf Files', 'coywolf-files' ),
			__( 'Files', 'coywolf-files' ),
			$cap,
			self::PAGE,
			array( $this, 'render_files_page' ),
			'dashicons-media-default',
			26
		);

		$this->hooks['list_sub'] = add_submenu_page(
			self::PAGE,
			__( 'All Files', 'coywolf-files' ),
			__( 'All Files', 'coywolf-files' ),
			$cap,
			self::PAGE,
			array( $this, 'render_files_page' )
		);

		$this->hooks['upload'] = add_submenu_page(
			self::PAGE,
			__( 'Upload File', 'coywolf-files' ),
			__( 'Upload File', 'coywolf-files' ),
			$cap,
			'coywolf-files-upload',
			array( $this, 'render_upload_page' )
		);

		$this->hooks['docs'] = add_submenu_page(
			self::PAGE,
			__( 'Documentation', 'coywolf-files' ),
			__( 'Documentation', 'coywolf-files' ),
			$cap,
			'coywolf-files-docs',
			array( 'Coywolf_Files_Docs', 'render_page' )
		);

		$this->hooks['settings'] = add_submenu_page(
			self::PAGE,
			__( 'Settings', 'coywolf-files' ),
			__( 'Settings', 'coywolf-files' ),
			$cap,
			Coywolf_Files_Settings::PAGE,
			array( $this->settings, 'render_page' )
		);

		add_action( 'load-' . $this->hooks['list'], array( $this, 'handle_list_actions' ) );
	}

	/**
	 * Enqueue admin assets on the plugin's own screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'coywolf-files-admin', COYWOLF_FILES_URL . 'css/admin.css', array(), Coywolf_Files::VERSION );

		// Settings screen: the Check CORS button + the live appearance preview.
		if ( isset( $this->hooks['settings'] ) && $hook === $this->hooks['settings'] ) {
			// The preview card reuses the front-end card styles.
			wp_enqueue_style( 'coywolf-files-view' );
			wp_enqueue_script( 'coywolf-files-settings', COYWOLF_FILES_URL . 'js/settings.js', array( 'wp-api-fetch', 'wp-i18n' ), Coywolf_Files::VERSION, true );
			wp_set_script_translations( 'coywolf-files-settings', 'coywolf-files' );
			wp_add_inline_script(
				'coywolf-files-settings',
				'window.coywolfFilesSettings = ' . wp_json_encode(
					array(
						'corsJson' => Coywolf_Files_Docs::cors_json_s3(),
						'i18n'     => array(
							'checking'  => __( 'Checking…', 'coywolf-files' ),
							'ok'        => __( '✓ CORS is configured correctly — uploads will work.', 'coywolf-files' ),
							'noEtag'    => __( 'Uploads work, but the ETag header is not exposed, so large files will fail. Add "ETag" to the CORS ExposeHeaders and check again.', 'coywolf-files' ),
							'rejected'  => __( 'CORS is allowing this site, but the test upload was rejected — check your keys and bucket permissions.', 'coywolf-files' ),
							'fail'      => __( 'CORS is not configured for this site. Add this rule to your bucket, then check again:', 'coywolf-files' ),
							'serverErr' => __( 'Could not start the check. Make sure a bucket is connected above.', 'coywolf-files' ),
						),
					)
				) . ';',
				'before'
			);
		}

		$on_list   = isset( $this->hooks['list'] ) && $hook === $this->hooks['list'];
		$on_upload = isset( $this->hooks['upload'] ) && $hook === $this->hooks['upload'];
		if ( ! $on_list && ! $on_upload ) {
			return;
		}

		Coywolf_Files_Block::register_upload_script();
		wp_enqueue_script(
			'coywolf-files-admin',
			COYWOLF_FILES_URL . 'js/admin.js',
			array( 'wp-api-fetch', 'wp-i18n', 'coywolf-files-upload' ),
			Coywolf_Files::VERSION,
			true
		);
		wp_set_script_translations( 'coywolf-files-admin', 'coywolf-files' );
		wp_add_inline_script(
			'coywolf-files-admin',
			'window.coywolfFilesAdmin = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'coywolf-files/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'listUrl' => admin_url( 'admin.php?page=' . self::PAGE ),
					'i18n'    => array(
						'copied'        => __( 'Copied!', 'coywolf-files' ),
						'copyFailed'    => __( 'Copy failed', 'coywolf-files' ),
						'copyLink'      => __( 'Copy link', 'coywolf-files' ),
						'confirmDelete' => __( 'Delete this file? It will be removed from storage and from any post or page that uses it. This cannot be undone.', 'coywolf-files' ),
						'pickFile'      => __( 'Choose a file first.', 'coywolf-files' ),
						'preparing'     => __( 'Preparing upload…', 'coywolf-files' ),
						'uploading'     => __( 'Uploading…', 'coywolf-files' ),
						'finishing'     => __( 'Finishing…', 'coywolf-files' ),
						'uploaded'      => __( 'Uploaded! It’s now in your library — add it to a post or page with the Coywolf File block.', 'coywolf-files' ),
						'uploadFailed'  => __( 'Upload failed.', 'coywolf-files' ),
						'retrying'      => __( 'Connection hiccup — retrying…', 'coywolf-files' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/*
	 * Delete actions.
	 */

	/**
	 * Handle single + bulk delete from the list table.
	 */
	public function handle_list_actions() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			return;
		}

		// Bulk delete.
		if ( isset( $_REQUEST['file_ids'] ) && is_array( $_REQUEST['file_ids'] ) ) {
			check_admin_referer( 'bulk-files' );
			if ( 'delete' !== $this->current_bulk_action() ) {
				return;
			}
			$ids     = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['file_ids'] ) );
			$deleted = 0;
			foreach ( $ids as $file_id ) {
				if ( '' === $file_id ) {
					continue;
				}
				Coywolf_Files::instance()->purge_file( $file_id );
				++$deleted;
			}
			$this->redirect_list( array( 'coywolf_files_deleted' => $deleted ) );
		}

		// Single delete.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'delete' === $action && ! empty( $_GET['file_id'] ) ) {
			$file_id = sanitize_text_field( wp_unslash( $_GET['file_id'] ) );
			check_admin_referer( 'coywolf_files_delete_' . $file_id );
			Coywolf_Files::instance()->purge_file( $file_id );
			$this->redirect_list( array( 'coywolf_files_deleted' => 1 ) );
		}
	}

	/**
	 * Resolve the chosen bulk action from either select.
	 *
	 * @return string
	 */
	private function current_bulk_action() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '-1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '-1' === $action && isset( $_REQUEST['action2'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $action;
	}

	/**
	 * Redirect back to the list with query args, then exit.
	 *
	 * @param array $args Query args.
	 */
	private function redirect_list( $args ) {
		$url = add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/*
	 * Screens.
	 */

	/**
	 * All Files screen.
	 */
	public function render_files_page() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'All Files', 'coywolf-files' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=coywolf-files-upload' ) ) . '" class="page-title-action">' . esc_html__( 'Upload File', 'coywolf-files' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		if ( ! $this->storage->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL. */
					__( 'Connect a storage bucket in <a href="%s">Settings</a> to upload and serve files.', 'coywolf-files' ),
					esc_url( $this->settings->page_url() )
				)
			) . '</p></div>';
		}

		$this->render_deleted_notice();

		require_once COYWOLF_FILES_PATH . 'includes/class-coywolf-files-list-table.php';
		$table = new Coywolf_Files_List_Table( $this->registry, $this->index, Coywolf_Files::instance()->download() );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		$table->search_box( __( 'Search files', 'coywolf-files' ), 'coywolf-files' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Notice after a delete.
	 */
	private function render_deleted_notice() {
		if ( ! isset( $_GET['coywolf_files_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = (int) $_GET['coywolf_files_deleted']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: %d: number of files deleted. */
				_n( '%d file deleted.', '%d files deleted.', $count, 'coywolf-files' ),
				$count
			)
		) . '</p></div>';
	}

	/**
	 * Upload File screen.
	 */
	public function render_upload_page() {
		if ( ! current_user_can( Coywolf_Files::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Upload File', 'coywolf-files' ) . '</h1>';

		if ( ! $this->storage->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL. */
					__( 'Connect a storage bucket in <a href="%s">Settings</a> first.', 'coywolf-files' ),
					esc_url( $this->settings->page_url() )
				)
			) . '</p></div></div>';
			return;
		}
		?>
		<div class="coywolf-files-uploader" data-list-url="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
			<p class="description"><?php esc_html_e( 'Upload any-sized file straight to your storage bucket. Large files are sent in resumable chunks directly from your browser. Uploaded files appear in your library and can be added to a post or page with the Coywolf File block.', 'coywolf-files' ); ?></p>
			<div class="coywolf-files-drop" id="coywolf-files-drop">
				<label for="coywolf-files-file"><?php esc_html_e( 'Choose a file or drag it here.', 'coywolf-files' ); ?></label>
				<input type="file" id="coywolf-files-file" />
			</div>
			<p>
				<button type="button" class="button button-primary" id="coywolf-files-upload-start"><?php esc_html_e( 'Upload', 'coywolf-files' ); ?></button>
			</p>
			<div class="coywolf-files-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Upload progress', 'coywolf-files' ); ?>" hidden><div class="coywolf-files-progress-bar"></div></div>
			<div class="coywolf-files-upload-status" role="status" aria-live="polite"></div>
		</div>
		<?php
		echo '</div>';
	}
}
