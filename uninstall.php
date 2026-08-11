<?php
/**
 * Uninstall cleanup for Coywolf Files.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes the options,
 * custom tables, transients, capability, scheduled events, and postmeta the
 * plugin creates. Files themselves live in your storage bucket and are never
 * touched — removing remote media on uninstall is left to the operator.
 *
 * @package CoywolfFiles
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
$coywolf_files_options = array(
	'coywolf_files_settings',
	'coywolf_files_connection',
	'coywolf_files_access_key',
	'coywolf_files_secret_key',
	'coywolf_files_version',
);
foreach ( $coywolf_files_options as $coywolf_files_option ) {
	delete_option( $coywolf_files_option );
}

// Custom tables.
$coywolf_files_tables = array(
	$wpdb->prefix . 'coywolf_files',
	$wpdb->prefix . 'coywolf_files_usage',
);
foreach ( $coywolf_files_tables as $coywolf_files_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $coywolf_files_table ) );
}

// Self-updater caches.
delete_site_transient( 'coywolf_files_gh_release' );
delete_site_transient( 'coywolf_files_gh_release_neg' );
delete_site_transient( 'coywolf_files_gh_release_err' );

// Plugin transients (connection status, pending uploads).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_coywolf_files_' ) . '%', $wpdb->esc_like( '_transient_timeout_coywolf_files_' ) . '%' ) );

// Strip the access capability from every role.
$coywolf_files_roles = wp_roles();
if ( $coywolf_files_roles instanceof WP_Roles ) {
	foreach ( array_keys( $coywolf_files_roles->roles ) as $coywolf_files_role_slug ) {
		$coywolf_files_role = get_role( $coywolf_files_role_slug );
		if ( $coywolf_files_role && $coywolf_files_role->has_cap( 'coywolf_files_manage' ) ) {
			$coywolf_files_role->remove_cap( 'coywolf_files_manage' );
		}
	}
}

// Scheduled events.
foreach ( array( 'coywolf_files_reconcile', 'coywolf_files_refresh_release' ) as $coywolf_files_hook ) {
	$coywolf_files_ts = wp_next_scheduled( $coywolf_files_hook );
	if ( $coywolf_files_ts ) {
		wp_unschedule_event( $coywolf_files_ts, $coywolf_files_hook );
	}
	wp_unschedule_hook( $coywolf_files_hook );
}

// Per-post usage index.
delete_post_meta_by_key( 'coywolf_files_ids' );
