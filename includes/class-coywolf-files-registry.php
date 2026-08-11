<?php
/**
 * Uploaded-file registry for Coywolf Files.
 *
 * One row per file uploaded to object storage: the opaque public file id used
 * in blocks and download URLs, the storage object key, and display metadata
 * (filename, size, MIME, extension, provider, bucket, download count). This is
 * the library the "browse existing" picker searches; the All Files admin table
 * joins it against the usage index to show only files placed in content.
 *
 * Custom-table reads use the %i identifier placeholder (WordPress 6.2+); the
 * DirectDatabaseQuery notices are suppressed because these are plugin-owned
 * tables with no Core API equivalent.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The files registry.
 */
class Coywolf_Files_Registry {

	/**
	 * Registry table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_files';
	}

	/**
	 * Create the registry table on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  file_id varchar(32) NOT NULL,
  object_key varchar(512) NOT NULL,
  filename varchar(255) NOT NULL DEFAULT '',
  mime varchar(150) NOT NULL DEFAULT '',
  ext varchar(20) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(10) NOT NULL DEFAULT '',
  bucket varchar(255) NOT NULL DEFAULT '',
  downloads bigint(20) unsigned NOT NULL DEFAULT 0,
  uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY file_id (file_id),
  KEY created (created)
) {$charset};"
		);
	}

	/**
	 * Generate a fresh, unique public file id.
	 *
	 * @return string
	 */
	public function generate_id() {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$candidate = bin2hex( random_bytes( 10 ) ); // 20 hex chars.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE file_id = %s LIMIT 1', self::table(), $candidate ) );
			if ( ! $exists ) {
				return $candidate;
			}
		}
		return bin2hex( random_bytes( 12 ) );
	}

	/**
	 * Insert a file record.
	 *
	 * @param array $data { file_id, object_key, filename, mime, ext, size, provider, bucket }.
	 * @return string|false File id on success, false on failure.
	 */
	public function insert( $data ) {
		global $wpdb;
		$row = array(
			'file_id'     => (string) $data['file_id'],
			'object_key'  => (string) $data['object_key'],
			'filename'    => (string) $data['filename'],
			'mime'        => isset( $data['mime'] ) ? (string) $data['mime'] : '',
			'ext'         => isset( $data['ext'] ) ? (string) $data['ext'] : '',
			'size'        => isset( $data['size'] ) ? (int) $data['size'] : 0,
			'provider'    => isset( $data['provider'] ) ? (string) $data['provider'] : '',
			'bucket'      => isset( $data['bucket'] ) ? (string) $data['bucket'] : '',
			'uploaded_by' => get_current_user_id(),
			'created'     => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			self::table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);
		if ( $ok ) {
			$this->flush_cache( $row['file_id'] );
			return $row['file_id'];
		}
		return false;
	}

	/**
	 * Fetch one record by file id. Cached in the object cache (and a per-request
	 * static) so multiple file blocks on a page — or the same file rendered
	 * twice — don't each hit the database.
	 *
	 * @param string $file_id File id.
	 * @return array|null
	 */
	public function get( $file_id ) {
		$file_id = (string) $file_id;
		if ( '' === $file_id ) {
			return null;
		}
		$found = null;
		$row   = wp_cache_get( $file_id, 'coywolf_files', false, $found );
		if ( $found ) {
			return is_array( $row ) ? $row : null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE file_id = %s', self::table(), $file_id ), ARRAY_A );
		$row = $row ? $row : null;
		wp_cache_set( $file_id, $row, 'coywolf_files', HOUR_IN_SECONDS );
		return $row;
	}

	/**
	 * Drop a file's cache entry (call after any write that changes the row).
	 *
	 * @param string $file_id File id.
	 */
	private function flush_cache( $file_id ) {
		wp_cache_delete( (string) $file_id, 'coywolf_files' );
	}

	/**
	 * Fetch many records keyed by file id.
	 *
	 * @param string[] $file_ids File ids.
	 * @return array file_id => row.
	 */
	public function get_many( $file_ids ) {
		global $wpdb;
		$file_ids = array_values( array_filter( array_map( 'strval', (array) $file_ids ) ) );
		if ( empty( $file_ids ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $file_ids ), '%s' ) );
		$args         = array_merge( array( self::table() ), $file_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE file_id IN ($placeholders)", $args ), ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['file_id'] ] = $row;
		}
		return $map;
	}

	/**
	 * Search the library by filename (for the block's "browse existing" picker).
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public function search( $term, $limit = 60 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		$term  = trim( (string) $term );
		if ( '' === $term ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created DESC LIMIT %d', self::table(), $limit ), ARRAY_A );
		}
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE filename LIKE %s ORDER BY created DESC LIMIT %d', self::table(), $like, $limit ), ARRAY_A );
	}

	/**
	 * All records (for the admin table; usage is joined by the caller).
	 *
	 * @return array
	 */
	public function all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created DESC', self::table() ), ARRAY_A );
	}

	/**
	 * Delete a record.
	 *
	 * @param string $file_id File id.
	 * @return void
	 */
	public function delete( $file_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'file_id' => (string) $file_id ), array( '%s' ) );
		$this->flush_cache( $file_id );
	}

	/**
	 * Increment a file's download counter.
	 *
	 * @param string $file_id File id.
	 * @return void
	 */
	public function increment_downloads( $file_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET downloads = downloads + 1 WHERE file_id = %s', $table, (string) $file_id ) );
		$this->flush_cache( $file_id );
	}
}
