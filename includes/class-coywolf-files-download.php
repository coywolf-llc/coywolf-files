<?php
/**
 * Front-end download endpoint for Coywolf Files.
 *
 * Serves a stable, shareable URL (e.g. /coywolf-file/<id>) that looks the file
 * up and 302-redirects to a short-lived presigned GET URL (private bucket) or a
 * configured public URL — always with a download (attachment) disposition. The
 * stable URL is what the block's Copy-link button copies, so links keep working
 * even though the underlying signed URL rotates.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Download URL builder + request handler.
 */
class Coywolf_Files_Download {

	/**
	 * Query var carrying the file id.
	 */
	const QUERY_VAR = 'coywolf_files_download';

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
	 * @param Coywolf_Files_Settings $settings Settings.
	 */
	public function __construct( Coywolf_Files_Storage $storage, Coywolf_Files_Registry $registry, Coywolf_Files_Settings $settings ) {
		$this->storage  = $storage;
		$this->registry = $registry;
		$this->settings = $settings;

		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	/**
	 * The configured link base slug.
	 *
	 * @return string
	 */
	public static function base_slug() {
		$settings = get_option( 'coywolf_files_settings', array() );
		$base     = is_array( $settings ) && ! empty( $settings['download_base'] ) ? sanitize_title( (string) $settings['download_base'] ) : '';
		return '' !== $base ? $base : 'coywolf-file';
	}

	/**
	 * Register the rewrite rule (also called on activation before the flush).
	 */
	public static function register_rewrite() {
		$base = self::base_slug();
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([A-Za-z0-9]+)' );
		add_rewrite_rule( '^' . $base . '/([A-Za-z0-9]+)(?:/[^/]*)?/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the rewrite on init.
	 */
	public function add_rewrite() {
		self::register_rewrite();
	}

	/**
	 * Whitelist the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Build the stable download URL for a file id.
	 *
	 * @param string $file_id File id.
	 * @return string
	 */
	public function url( $file_id ) {
		$file_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $file_id );
		if ( '' === $file_id ) {
			return '';
		}
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return add_query_arg( self::QUERY_VAR, $file_id, home_url( '/' ) );
		}
		return home_url( '/' . self::base_slug() . '/' . $file_id );
	}

	/**
	 * Handle a download request.
	 */
	public function handle() {
		$file_id = get_query_var( self::QUERY_VAR );
		// Guard against an array (e.g. ?coywolf_files_download[]=x) before casting.
		if ( ! is_string( $file_id ) || '' === $file_id ) {
			return;
		}

		// Validate the shape before any lookup, and never write state for an
		// unknown id (the registry lookup is the "is this a real file?" gate).
		if ( ! preg_match( '/^[A-Za-z0-9]{1,32}$/', $file_id ) ) {
			$this->not_found();
			return;
		}

		$record = $this->registry->get( $file_id );
		if ( ! $record ) {
			$this->not_found();
			return;
		}

		$url = $this->storage->download_url( $record, true );
		if ( is_wp_error( $url ) || '' === (string) $url ) {
			status_header( 502 );
			nocache_headers();
			wp_die(
				esc_html__( 'This file is temporarily unavailable. Please try again later.', 'coywolf-files' ),
				esc_html__( 'File unavailable', 'coywolf-files' ),
				array( 'response' => 502 )
			);
		}

		// Count the download only now that the file is confirmed to exist.
		$this->registry->increment_downloads( $file_id );

		nocache_headers();
		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional off-site redirect to the storage host.
		exit;
	}

	/**
	 * Send a 404 and stop.
	 */
	private function not_found() {
		status_header( 404 );
		nocache_headers();
		wp_die(
			esc_html__( 'The requested file could not be found.', 'coywolf-files' ),
			esc_html__( 'File not found', 'coywolf-files' ),
			array( 'response' => 404 )
		);
	}
}
