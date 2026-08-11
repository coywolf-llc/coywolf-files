<?php
/**
 * Post ↔ file usage index.
 *
 * Parses posts/pages on save for coywolf/file blocks and records which files
 * they embed — in postmeta (for quick per-post lookups) and in a usage table
 * (for the All Files counts and the filtered admin views). Deleting a file
 * strips its block from every post/page that embeds it.
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
 * Tracks which posts and pages embed which files.
 */
class Coywolf_Files_Index {

	/**
	 * Post types indexed.
	 *
	 * @var array
	 */
	private $types = array( 'post', 'page' );

	/**
	 * Post IDs already reindexed this request. save_post and
	 * transition_post_status both fire on one save; this stops the double parse
	 * + double delete/insert cycle.
	 *
	 * @var array
	 */
	private $reindexed = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_admin_query' ) );
	}

	/**
	 * Usage table name.
	 *
	 * @return string
	 */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_files_usage';
	}

	/**
	 * Create the usage table on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$usage   = self::usage_table();
		dbDelta(
			"CREATE TABLE {$usage} (
  file_id varchar(32) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  post_type varchar(20) NOT NULL DEFAULT 'post',
  PRIMARY KEY  (file_id,post_id),
  KEY post_id (post_id)
) {$charset};"
		);
	}

	/*
	 * Indexing.
	 */

	/**
	 * Reindex on content save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->reindex( $post );
	}

	/**
	 * Reindex on status change (publish/unpublish/trash).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		unset( $new_status, $old_status );
		if ( $post instanceof WP_Post ) {
			$this->reindex( $post );
		}
	}

	/**
	 * Remove usage rows when a post is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::usage_table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Rebuild the postmeta + usage rows for one post.
	 *
	 * @param WP_Post $post Post.
	 */
	private function reindex( $post ) {
		if ( ! in_array( $post->post_type, $this->types, true ) ) {
			return;
		}

		// save_post and transition_post_status both fire on a single save; only
		// reindex a given post once per request.
		if ( isset( $this->reindexed[ $post->ID ] ) ) {
			return;
		}
		$this->reindexed[ $post->ID ] = true;

		$ids = $this->extract_ids( $post->post_content );

		if ( empty( $ids ) ) {
			delete_post_meta( $post->ID, 'coywolf_files_ids' );
		} else {
			update_post_meta( $post->ID, 'coywolf_files_ids', $ids );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::usage_table(), array( 'post_id' => (int) $post->ID ), array( '%d' ) );

		// Only count publicly visible content as "using" a file.
		if ( 'publish' === $post->post_status ) {
			foreach ( $ids as $file_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					self::usage_table(),
					array(
						'file_id'   => $file_id,
						'post_id'   => (int) $post->ID,
						'post_type' => $post->post_type,
					),
					array( '%s', '%d', '%s' )
				);
			}
		}
	}

	/**
	 * Pull unique file ids out of post content.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	private function extract_ids( $content ) {
		if ( false === strpos( $content, 'coywolf/file' ) ) {
			return array();
		}
		$ids = array();
		$this->walk_blocks( parse_blocks( $content ), $ids );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Recursively collect file ids from parsed blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $ids    Accumulator (by reference).
	 */
	private function walk_blocks( $blocks, &$ids ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'coywolf/file' === $block['blockName'] && ! empty( $block['attrs']['fileId'] ) ) {
				$ids[] = (string) $block['attrs']['fileId'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $ids );
			}
		}
	}

	/*
	 * Reads.
	 */

	/**
	 * Every distinct file id currently referenced by indexed posts/pages.
	 *
	 * @return string[]
	 */
	public function embedded_ids() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT file_id FROM %i', self::usage_table() ) ) );
	}

	/**
	 * Post IDs that use a given file.
	 *
	 * @param string $file_id File id.
	 * @return int[]
	 */
	public function posts_for( $file_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE file_id = %s', self::usage_table(), (string) $file_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Usage counts per file, keyed by file id: { posts, pages }.
	 *
	 * @param array $ids File ids.
	 * @return array
	 */
	public function usage_map( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'strval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
		$args         = array_merge( array( self::usage_table() ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT file_id, post_type, COUNT(*) AS c FROM %i WHERE file_id IN ($placeholders) GROUP BY file_id, post_type", $args ), ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$id = $row['file_id'];
			if ( ! isset( $map[ $id ] ) ) {
				$map[ $id ] = array(
					'posts' => 0,
					'pages' => 0,
				);
			}
			if ( 'page' === $row['post_type'] ) {
				$map[ $id ]['pages'] += (int) $row['c'];
			} else {
				$map[ $id ]['posts'] += (int) $row['c'];
			}
		}
		return $map;
	}

	/*
	 * Admin filtered view.
	 */

	/**
	 * Register the filter query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'coywolf_files_file';
		return $vars;
	}

	/**
	 * On edit.php, when ?coywolf_files_file=<id> is set, restrict the list to
	 * posts that use that file.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_admin_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$file_id = isset( $_GET['coywolf_files_file'] ) ? sanitize_text_field( wp_unslash( $_GET['coywolf_files_file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $file_id ) {
			return;
		}
		$ids = $this->posts_for( $file_id );
		$query->set( 'post__in', ! empty( $ids ) ? $ids : array( 0 ) );
	}

	/*
	 * Removal.
	 */

	/**
	 * Strip the coywolf/file block for a (deleted) file from every post/page
	 * that embeds it. The resulting wp_update_post re-runs the indexer, which
	 * clears the usage rows.
	 *
	 * @param string $file_id File id.
	 */
	public function remove_file( $file_id ) {
		foreach ( $this->posts_for( $file_id ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || false === strpos( $post->post_content, 'coywolf/file' ) ) {
				continue;
			}
			$content = serialize_blocks( $this->strip_blocks( parse_blocks( $post->post_content ), (string) $file_id ) );
			if ( $content !== $post->post_content ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				);
			}
		}
	}

	/**
	 * Whether a parsed block is the file block for a given id.
	 *
	 * @param array  $block   Parsed block.
	 * @param string $file_id File id.
	 * @return bool
	 */
	private function is_file_block( $block, $file_id ) {
		return isset( $block['blockName'], $block['attrs']['fileId'] )
			&& 'coywolf/file' === $block['blockName']
			&& (string) $block['attrs']['fileId'] === $file_id;
	}

	/**
	 * Drop matching file blocks from a top-level block list.
	 *
	 * @param array  $blocks  Parsed blocks.
	 * @param string $file_id File id.
	 * @return array
	 */
	private function strip_blocks( $blocks, $file_id ) {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( $this->is_file_block( $block, $file_id ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block = $this->strip_inner_blocks( $block, $file_id );
			}
			$out[] = $block;
		}
		return $out;
	}

	/**
	 * Drop matching file blocks from a block's innerBlocks, keeping innerContent
	 * (which interleaves markup with null placeholders) consistent.
	 *
	 * @param array  $block   Parsed block with innerBlocks.
	 * @param string $file_id File id.
	 * @return array
	 */
	private function strip_inner_blocks( $block, $file_id ) {
		$new_inner   = array();
		$new_content = array();
		$index       = 0;

		foreach ( $block['innerContent'] as $chunk ) {
			if ( null !== $chunk ) {
				$new_content[] = $chunk;
				continue;
			}
			$child = isset( $block['innerBlocks'][ $index ] ) ? $block['innerBlocks'][ $index ] : null;
			++$index;
			if ( null === $child || $this->is_file_block( $child, $file_id ) ) {
				continue; // drop the block and its placeholder.
			}
			if ( ! empty( $child['innerBlocks'] ) ) {
				$child = $this->strip_inner_blocks( $child, $file_id );
			}
			$new_inner[]   = $child;
			$new_content[] = null;
		}

		$block['innerBlocks']  = $new_inner;
		$block['innerContent'] = $new_content;
		return $block;
	}

	/**
	 * Remove usage rows for posts that no longer exist or are no longer published.
	 */
	public function prune() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_id FROM %i', self::usage_table() ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $wpdb->posts, 'publish' ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$valid   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_status = %s AND ID IN ($placeholders)", $args ) );
		$invalid = array_diff( $ids, array_map( 'intval', (array) $valid ) );
		if ( empty( $invalid ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $invalid ), '%d' ) );
		$args         = array_merge( array( self::usage_table() ), $invalid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN ($placeholders)", $args ) );
	}
}
