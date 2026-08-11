<?php
/**
 * Main plugin loader for Coywolf Files.
 *
 * Composition root: instantiates every module once, wires the shared
 * activation/deactivation lifecycle, and exposes the module singletons so
 * modules (and the REST layer) can reach one another.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap singleton.
 */
final class Coywolf_Files {

	/**
	 * Plugin version. Kept in sync with the main-file "Version:" header by the
	 * release workflow (it bumps both).
	 */
	const VERSION = '1.0.4';

	/**
	 * Capability that gates every admin screen and admin REST route.
	 */
	const CAPABILITY = 'coywolf_files_manage';

	/**
	 * Daily cron hook that prunes stale usage rows.
	 */
	const CRON_RECONCILE = 'coywolf_files_reconcile';

	/**
	 * Singleton instance.
	 *
	 * @var Coywolf_Files|null
	 */
	private static $instance = null;

	/**
	 * Settings module.
	 *
	 * @var Coywolf_Files_Settings
	 */
	private $settings;

	/**
	 * Object-storage module (B2 / R2 / S3 via one S3-compatible client).
	 *
	 * @var Coywolf_Files_Storage
	 */
	private $storage;

	/**
	 * Uploaded-file registry.
	 *
	 * @var Coywolf_Files_Registry
	 */
	private $registry;

	/**
	 * Post ↔ file usage index.
	 *
	 * @var Coywolf_Files_Index
	 */
	private $index;

	/**
	 * REST controller.
	 *
	 * @var Coywolf_Files_REST
	 */
	private $rest;

	/**
	 * Editor block.
	 *
	 * @var Coywolf_Files_Block
	 */
	private $block;

	/**
	 * Front-end download endpoint.
	 *
	 * @var Coywolf_Files_Download
	 */
	private $download;

	/**
	 * Admin screens.
	 *
	 * @var Coywolf_Files_Admin
	 */
	private $admin;

	/**
	 * Get the singleton instance.
	 *
	 * @return Coywolf_Files
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the modules together and register shared hooks.
	 */
	private function __construct() {
		$this->settings = new Coywolf_Files_Settings();
		$this->storage  = new Coywolf_Files_Storage( $this->settings );
		$this->registry = new Coywolf_Files_Registry();
		$this->index    = new Coywolf_Files_Index();
		$this->download = new Coywolf_Files_Download( $this->storage, $this->registry, $this->settings );
		$this->rest     = new Coywolf_Files_REST( $this->storage, $this->registry, $this->index, $this->settings );
		$this->block    = new Coywolf_Files_Block( $this->settings, $this->registry, $this->download );
		$this->admin    = new Coywolf_Files_Admin( $this->storage, $this->registry, $this->index, $this->settings );

		add_action( self::CRON_RECONCILE, array( $this, 'reconcile' ) );
	}

	/**
	 * Front-end download endpoint.
	 *
	 * @return Coywolf_Files_Download
	 */
	public function download() {
		return $this->download;
	}

	/**
	 * Daily cron: prune usage rows for posts that no longer exist or are no
	 * longer published.
	 */
	public function reconcile() {
		$this->index->prune();
	}

	/**
	 * Settings module.
	 *
	 * @return Coywolf_Files_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Storage module.
	 *
	 * @return Coywolf_Files_Storage
	 */
	public function storage() {
		return $this->storage;
	}

	/**
	 * File registry.
	 *
	 * @return Coywolf_Files_Registry
	 */
	public function registry() {
		return $this->registry;
	}

	/**
	 * Post ↔ file usage index.
	 *
	 * @return Coywolf_Files_Index
	 */
	public function index() {
		return $this->index;
	}

	/**
	 * Delete a file everywhere: remove its block from any post/page, delete the
	 * object from storage, and drop its registry + usage rows.
	 *
	 * @param string $file_id File id.
	 * @return true|WP_Error True on success, or the storage error (the local
	 *                       records are still removed so a missing object can't
	 *                       strand the library).
	 */
	public function purge_file( $file_id ) {
		$file_id = (string) $file_id;
		$record  = $this->registry->get( $file_id );

		// Strip the block from any post/page first (re-saving re-runs the indexer,
		// which clears the usage rows).
		$this->index->remove_file( $file_id );

		$error = null;
		if ( $record && '' !== (string) $record['object_key'] ) {
			$result = $this->storage->delete_object( (string) $record['object_key'], (string) $record['provider'], (string) $record['bucket'] );
			if ( is_wp_error( $result ) ) {
				$error = $result;
			}
		}

		$this->registry->delete( $file_id );

		return null === $error ? true : $error;
	}

	/**
	 * Activation: create tables, grant the capability, seed defaults, register
	 * the download rewrite, then flush so the rule takes effect, and schedule
	 * the reconcile cron.
	 */
	public static function on_activate() {
		Coywolf_Files_Registry::create_tables();
		Coywolf_Files_Index::create_tables();
		Coywolf_Files_Settings::seed_defaults();

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}

		Coywolf_Files_Download::register_rewrite();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( self::CRON_RECONCILE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_RECONCILE );
		}
	}

	/**
	 * Deactivation: flush rewrites and unschedule the cron. The capability and
	 * stored data are left in place until uninstall.
	 */
	public static function on_deactivate() {
		flush_rewrite_rules();

		$timestamp = wp_next_scheduled( self::CRON_RECONCILE );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_RECONCILE );
		}
	}
}
