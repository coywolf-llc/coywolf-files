<?php
/**
 * All Files list table.
 *
 * Lists uploaded files joined with post/page usage. By default it shows only
 * files that have been added to a post or page (the "In use" filter); the
 * filter can be switched to show every uploaded file or just the unused ones.
 * Search (filename), sorting, and pagination run in PHP over the registry.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The All Files table.
 */
class Coywolf_Files_List_Table extends WP_List_Table {

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
	 * Download endpoint.
	 *
	 * @var Coywolf_Files_Download
	 */
	private $download;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_Files_Registry $registry Registry.
	 * @param Coywolf_Files_Index    $index    Usage index.
	 * @param Coywolf_Files_Download $download Download endpoint.
	 */
	public function __construct( Coywolf_Files_Registry $registry, Coywolf_Files_Index $index, Coywolf_Files_Download $download ) {
		parent::__construct(
			array(
				'singular' => 'file',
				'plural'   => 'files',
				'ajax'     => false,
			)
		);
		$this->registry = $registry;
		$this->index    = $index;
		$this->download = $download;
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'icon'      => __( 'Type', 'coywolf-files' ),
			'name'      => __( 'Name', 'coywolf-files' ),
			'size'      => __( 'Size', 'coywolf-files' ),
			'uploaded'  => __( 'Uploaded', 'coywolf-files' ),
			'downloads' => __( 'Downloads', 'coywolf-files' ),
			'posts'     => __( 'Posts', 'coywolf-files' ),
			'pages'     => __( 'Pages', 'coywolf-files' ),
		);
	}

	/**
	 * Keep Name the primary column.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'name';
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'      => array( 'name', false ),
			'size'      => array( 'size', false ),
			'uploaded'  => array( 'uploaded', true ),
			'downloads' => array( 'downloads', false ),
			'posts'     => array( 'posts', false ),
			'pages'     => array( 'pages', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'coywolf-files' ) );
	}

	/**
	 * In use / All / Unused filter.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current = $this->current_filter();
		$options = array(
			'in_use' => __( 'In use (on posts or pages)', 'coywolf-files' ),
			'unused' => __( 'Unused', 'coywolf-files' ),
			'all'    => __( 'All uploaded files', 'coywolf-files' ),
		);
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="coywolf-files-filter">' . esc_html__( 'Filter files', 'coywolf-files' ) . '</label>';
		echo '<select name="files_filter" id="coywolf-files-filter">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'coywolf-files' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * The active filter.
	 *
	 * @return string
	 */
	private function current_filter() {
		$filter = isset( $_REQUEST['files_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['files_filter'] ) ) : 'in_use'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $filter, array( 'in_use', 'unused', 'all' ), true ) ? $filter : 'in_use';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="file_ids[]" value="%s" />', esc_attr( $item['file_id'] ) );
	}

	/**
	 * Icon column: the file-type badge.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_icon( $item ) {
		$sr = '' !== $item['ext'] ? '<span class="screen-reader-text">' . esc_html( strtoupper( $item['ext'] ) ) . '</span>' : '';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG + escaped label.
		return '<span class="coywolf-files-list-icon">' . Coywolf_Files_Block::file_icon_svg( $item['ext'] ) . $sr . '</span>';
	}

	/**
	 * Name column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_name( $item ) {
		$url  = $this->download->url( $item['file_id'] );
		$name = '' !== $item['filename'] ? $item['filename'] : __( '(unnamed file)', 'coywolf-files' );

		$actions = array(
			'download' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Download', 'coywolf-files' ) . '</a>',
			'copy'     => '<button type="button" class="button-link coywolf-files-copy" data-url="' . esc_url( $url ) . '">' . esc_html__( 'Copy link', 'coywolf-files' ) . '</button>',
			'trash'    => '<a href="' . esc_url( $this->delete_url( $item['file_id'] ) ) . '" class="coywolf-files-delete submitdelete">' . esc_html__( 'Delete', 'coywolf-files' ) . '</a>',
		);

		return '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Type column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_size( $item ) {
		return esc_html( Coywolf_Files_Block::format_size( (int) $item['size'] ) );
	}

	/**
	 * Uploaded column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_uploaded( $item ) {
		$ts = strtotime( (string) $item['created'] );
		return $ts ? esc_html( wp_date( get_option( 'date_format' ), $ts ) ) : '&#8212;';
	}

	/**
	 * Downloads column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_downloads( $item ) {
		return esc_html( number_format_i18n( (int) $item['downloads'] ) );
	}

	/**
	 * Posts usage column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_posts( $item ) {
		return $this->usage_cell( (int) $item['posts'], 'post', $item['file_id'] );
	}

	/**
	 * Pages usage column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_pages( $item ) {
		return $this->usage_cell( (int) $item['pages'], 'page', $item['file_id'] );
	}

	/**
	 * Render a usage count as a link to the filtered post-type list, or "0".
	 *
	 * @param int    $count     Count.
	 * @param string $post_type Post type.
	 * @param string $file_id   File id.
	 * @return string
	 */
	private function usage_cell( $count, $post_type, $file_id ) {
		if ( $count < 1 ) {
			return '0';
		}
		$url = add_query_arg(
			array(
				'post_type'          => $post_type,
				'coywolf_files_file' => $file_id,
			),
			admin_url( 'edit.php' )
		);
		return '<a href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
	}

	/**
	 * Single-delete URL (nonce-protected).
	 *
	 * @param string $file_id File id.
	 * @return string
	 */
	private function delete_url( $file_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'    => Coywolf_Files_Admin::PAGE,
					'action'  => 'delete',
					'file_id' => $file_id,
				),
				admin_url( 'admin.php' )
			),
			'coywolf_files_delete_' . $file_id
		);
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		if ( 'in_use' === $this->current_filter() ) {
			esc_html_e( 'No files are on any posts or pages yet. Add the Coywolf File block to a post, or switch the filter to “All uploaded files”.', 'coywolf-files' );
			return;
		}
		esc_html_e( 'No files found.', 'coywolf-files' );
	}

	/**
	 * Assemble the rows.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$rows  = $this->registry->all();
		$ids   = wp_list_pluck( $rows, 'file_id' );
		$usage = $this->index->usage_map( $ids );

		$items = array();
		foreach ( $rows as $row ) {
			$fid     = (string) $row['file_id'];
			$posts   = isset( $usage[ $fid ]['posts'] ) ? (int) $usage[ $fid ]['posts'] : 0;
			$pages   = isset( $usage[ $fid ]['pages'] ) ? (int) $usage[ $fid ]['pages'] : 0;
			$items[] = array(
				'file_id'    => $fid,
				'filename'   => (string) $row['filename'],
				'ext'        => (string) $row['ext'],
				'size'       => (int) $row['size'],
				'created'    => (string) $row['created'],
				'created_ts' => (int) strtotime( (string) $row['created'] ),
				'downloads'  => (int) $row['downloads'],
				'posts'      => $posts,
				'pages'      => $pages,
			);
		}

		// Filter: In use / Unused / All.
		$filter = $this->current_filter();
		if ( 'in_use' === $filter ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $r ) {
						return ( $r['posts'] + $r['pages'] ) > 0;
					}
				)
			);
		} elseif ( 'unused' === $filter ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $r ) {
						return ( $r['posts'] + $r['pages'] ) < 1;
					}
				)
			);
		}

		// Search by filename.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$items  = array_values(
				array_filter(
					$items,
					static function ( $r ) use ( $needle ) {
						return false !== strpos( strtolower( $r['filename'] ), $needle );
					}
				)
			);
		}

		// Sort.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $orderby, array( 'name', 'size', 'uploaded', 'downloads', 'posts', 'pages' ), true ) ) {
			$orderby = 'uploaded';
			if ( '' === $order ) {
				$order = 'desc';
			}
		}
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'asc';
		}
		usort(
			$items,
			static function ( $a, $b ) use ( $orderby ) {
				if ( 'name' === $orderby ) {
					return strcasecmp( $a['filename'], $b['filename'] );
				}
				if ( 'uploaded' === $orderby ) {
					return $a['created_ts'] <=> $b['created_ts'];
				}
				return $a[ $orderby ] <=> $b[ $orderby ];
			}
		);
		if ( 'desc' === $order ) {
			$items = array_reverse( $items );
		}

		// Paginate.
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total        = count( $items );
		$this->items  = array_slice( $items, ( $current_page - 1 ) * $per_page, $per_page );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}
}
