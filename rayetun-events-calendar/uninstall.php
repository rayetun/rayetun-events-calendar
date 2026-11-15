<?php
/**
 * Uninstall: removes plugin data only when the owner has opted in.
 *
 * @package RayEtun\EventsCalendar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rayec_settings = get_option( 'rayec_settings', array() );

wp_clear_scheduled_hook( 'rayec_extend_occurrences' );
wp_clear_scheduled_hook( 'rayec_sync_feeds' );
delete_transient( 'rayec_calendar_page' );

if ( empty( $rayec_settings['delete_data'] ) ) {
	return;
}

global $wpdb;

$rayec_posts = get_posts(
	array(
		'post_type'      => array( 'rayec_event', 'rayec_venue', 'rayec_organizer', 'rayec_speaker' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
foreach ( $rayec_posts as $rayec_post_id ) {
	wp_delete_post( (int) $rayec_post_id, true );
}

foreach ( array( 'rayec_category', 'rayec_tag', 'rayec_program' ) as $rayec_taxonomy ) {
	// Taxonomies are not registered during uninstall, so read term IDs directly.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
	$rayec_terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $rayec_taxonomy ) );
	foreach ( $rayec_terms as $rayec_term_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
		$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $rayec_term_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
		$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $rayec_term_id ), array( '%d' ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
	$wpdb->delete( $wpdb->term_taxonomy, array( 'taxonomy' => $rayec_taxonomy ), array( '%s' ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dropping the plugin's own table on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rayec_occurrences" );

// The still map pictures the plugin built for itself.
$rayec_uploads = wp_upload_dir();
if ( empty( $rayec_uploads['error'] ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	foreach ( array( 'rayec-maps', 'rayec-share' ) as $rayec_folder ) {
		$rayec_path = trailingslashit( $rayec_uploads['basedir'] ) . $rayec_folder;
		if ( $wp_filesystem && $wp_filesystem->is_dir( $rayec_path ) ) {
			$wp_filesystem->delete( $rayec_path, true );
		}
	}
}

delete_option( 'rayec_settings' );
delete_option( 'rayec_db_version' );
delete_option( 'rayec_flush_rewrite' );
delete_option( 'rayec_feeds' );
delete_option( 'rayec_migration' );
