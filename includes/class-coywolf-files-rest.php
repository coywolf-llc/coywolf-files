<?php
/**
 * REST controller — namespace coywolf-files/v1.
 *
 * All routes are admin-only (capability-gated): they mint presigned upload URLs
 * so the browser uploads directly to object storage without the secret key ever
 * reaching it, complete/abort multipart uploads, and search the file library
 * for the block's "browse existing" picker. There are no public routes — file
 * downloads are served by the front-end rewrite endpoint, not the REST API.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the plugin's REST routes.
 */
class Coywolf_Files_REST {

	/**
	 * REST namespace.
	 */
	const NS = 'coywolf-files/v1';

	/**
	 * Storage module.
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

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 */
	public function register_routes() {
		$admin = array( $this, 'admin_permission' );

		register_rest_route(
			self::NS,
			'/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => $admin,
				'args'                => array( 's' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/upload/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_start' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/upload/sign-part',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_sign_part' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/upload/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_complete' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/upload/abort',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_abort' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Admin permission check.
	 *
	 * @return bool
	 */
	public function admin_permission() {
		return current_user_can( Coywolf_Files::CAPABILITY );
	}

	/*
	 * Library.
	 */

	/**
	 * List/search the file library for the picker.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_files( $request ) {
		$rows = $this->registry->search( (string) $request->get_param( 's' ), 60 );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = $this->normalize( $row );
		}
		return rest_ensure_response( array( 'files' => $out ) );
	}

	/*
	 * Uploads.
	 */

	/**
	 * Begin an upload: create the object key and either a single presigned PUT
	 * or a multipart upload, and stash the pending record server-side.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_start( $request ) {
		if ( ! $this->storage->is_configured() ) {
			return new WP_Error( 'coywolf_files_unconfigured', __( 'Connect a storage bucket in Files → Settings first.', 'coywolf-files' ), array( 'status' => 409 ) );
		}
		$params = $this->params( $request );

		$filename = isset( $params['filename'] ) ? sanitize_file_name( (string) $params['filename'] ) : '';
		$mime     = isset( $params['mime'] ) ? sanitize_mime_type( (string) $params['mime'] ) : '';
		$size     = isset( $params['size'] ) ? (int) $params['size'] : 0;

		if ( '' === $filename ) {
			return new WP_Error( 'coywolf_files_bad_name', __( 'A file name is required.', 'coywolf-files' ), array( 'status' => 400 ) );
		}
		if ( $size < 1 ) {
			return new WP_Error( 'coywolf_files_bad_size', __( 'A valid file size is required.', 'coywolf-files' ), array( 'status' => 400 ) );
		}

		$config   = $this->storage->config();
		$provider = is_wp_error( $config ) ? '' : (string) $config['provider'];
		$bucket   = is_wp_error( $config ) ? '' : (string) $config['bucket'];

		$file_id = $this->registry->generate_id();
		$key     = Coywolf_Files_Storage::build_object_key( $file_id, $filename );
		$ext     = Coywolf_Files_Block::extension_of( $filename );

		$pending = array(
			'key'       => $key,
			'filename'  => $filename,
			'mime'      => $mime,
			'ext'       => $ext,
			'size'      => $size,
			'provider'  => $provider,
			'bucket'    => $bucket,
			'upload_id' => '',
			'mode'      => '',
		);

		if ( $size <= Coywolf_Files_Storage::SINGLE_PUT_MAX ) {
			$url = $this->storage->presign_put( $key );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$pending['mode'] = 'put';
			$this->store_pending( $file_id, $pending );
			return rest_ensure_response(
				array(
					'mode'   => 'put',
					'fileId' => $file_id,
					'url'    => $url,
				)
			);
		}

		$upload_id = $this->storage->create_multipart( $key, $mime );
		if ( is_wp_error( $upload_id ) ) {
			return $upload_id;
		}
		list( $part_size, $part_count ) = Coywolf_Files_Storage::plan_parts( $size );

		$pending['mode']      = 'multipart';
		$pending['upload_id'] = $upload_id;
		$this->store_pending( $file_id, $pending );

		return rest_ensure_response(
			array(
				'mode'      => 'multipart',
				'fileId'    => $file_id,
				'partSize'  => $part_size,
				'partCount' => $part_count,
			)
		);
	}

	/**
	 * Presign a single multipart part URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_sign_part( $request ) {
		$params  = $this->params( $request );
		$file_id = isset( $params['fileId'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $params['fileId'] ) : '';
		$part    = isset( $params['partNumber'] ) ? (int) $params['partNumber'] : 0;

		$pending = $this->get_pending( $file_id );
		if ( ! $pending || 'multipart' !== $pending['mode'] ) {
			return new WP_Error( 'coywolf_files_no_pending', __( 'This upload session has expired. Start the upload again.', 'coywolf-files' ), array( 'status' => 410 ) );
		}
		if ( $part < 1 || $part > 10000 ) {
			return new WP_Error( 'coywolf_files_bad_part', __( 'Invalid part number.', 'coywolf-files' ), array( 'status' => 400 ) );
		}

		$url = $this->storage->presign_part( $pending['key'], $pending['upload_id'], $part );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		return rest_ensure_response( array( 'url' => $url ) );
	}

	/**
	 * Finish an upload: complete the multipart upload (if any), confirm the
	 * object, and record it in the registry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_complete( $request ) {
		$params  = $this->params( $request );
		$file_id = isset( $params['fileId'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $params['fileId'] ) : '';

		$pending = $this->get_pending( $file_id );
		if ( ! $pending ) {
			return new WP_Error( 'coywolf_files_no_pending', __( 'This upload session has expired. Start the upload again.', 'coywolf-files' ), array( 'status' => 410 ) );
		}

		if ( 'multipart' === $pending['mode'] ) {
			$parts = isset( $params['parts'] ) && is_array( $params['parts'] ) ? $params['parts'] : array();
			$clean = array();
			foreach ( $parts as $p ) {
				$clean[] = array(
					'PartNumber' => isset( $p['partNumber'] ) ? (int) $p['partNumber'] : 0,
					'ETag'       => isset( $p['etag'] ) ? (string) $p['etag'] : '',
				);
			}
			usort(
				$clean,
				static function ( $a, $b ) {
					return $a['PartNumber'] <=> $b['PartNumber'];
				}
			);
			$done = $this->storage->complete_multipart( $pending['key'], $pending['upload_id'], $clean );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		// Confirm the object exists and read its authoritative size.
		$head = $this->storage->head_object( $pending['key'] );
		$size = ( ! is_wp_error( $head ) && ! empty( $head['size'] ) ) ? (int) $head['size'] : (int) $pending['size'];
		if ( is_wp_error( $head ) ) {
			return new WP_Error( 'coywolf_files_verify', __( 'The upload could not be verified in storage.', 'coywolf-files' ), array( 'status' => 502 ) );
		}

		$inserted = $this->registry->insert(
			array(
				'file_id'    => $file_id,
				'object_key' => $pending['key'],
				'filename'   => $pending['filename'],
				'mime'       => $pending['mime'],
				'ext'        => $pending['ext'],
				'size'       => $size,
				'provider'   => $pending['provider'],
				'bucket'     => $pending['bucket'],
			)
		);
		$this->clear_pending( $file_id );

		if ( ! $inserted ) {
			return new WP_Error( 'coywolf_files_record', __( 'The file was uploaded but could not be recorded.', 'coywolf-files' ), array( 'status' => 500 ) );
		}

		$record = $this->registry->get( $file_id );
		return rest_ensure_response( $this->normalize( $record ) );
	}

	/**
	 * Abort an in-progress upload (best effort).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function upload_abort( $request ) {
		$params  = $this->params( $request );
		$file_id = isset( $params['fileId'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $params['fileId'] ) : '';

		$pending = $this->get_pending( $file_id );
		if ( $pending && 'multipart' === $pending['mode'] ) {
			$this->storage->abort_multipart( $pending['key'], $pending['upload_id'] );
		}
		$this->clear_pending( $file_id );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/*
	 * Helpers.
	 */

	/**
	 * Read JSON (or form) params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function params( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Store a pending upload (server-side, keyed by the server-minted file id).
	 *
	 * @param string $file_id File id.
	 * @param array  $pending Pending record.
	 */
	private function store_pending( $file_id, $pending ) {
		set_transient( 'coywolf_files_pending_' . $file_id, $pending, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Fetch a pending upload.
	 *
	 * @param string $file_id File id.
	 * @return array|null
	 */
	private function get_pending( $file_id ) {
		if ( '' === (string) $file_id ) {
			return null;
		}
		$pending = get_transient( 'coywolf_files_pending_' . $file_id );
		return is_array( $pending ) ? $pending : null;
	}

	/**
	 * Clear a pending upload.
	 *
	 * @param string $file_id File id.
	 */
	private function clear_pending( $file_id ) {
		delete_transient( 'coywolf_files_pending_' . $file_id );
	}

	/**
	 * Normalize a registry row for the editor.
	 *
	 * @param array $row Registry row.
	 * @return array
	 */
	private function normalize( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		return array(
			'fileId'   => (string) $row['file_id'],
			'fileName' => (string) $row['filename'],
			'ext'      => (string) $row['ext'],
			'mime'     => (string) $row['mime'],
			'size'     => (int) $row['size'],
			'uploaded' => (string) $row['created'],
		);
	}
}
