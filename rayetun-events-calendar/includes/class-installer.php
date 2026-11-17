<?php
/**
 * Activation, upgrade and deactivation.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the occurrence table and schedules the rolling-window top-up.
 */
class Installer {

	const CRON_HOOK = 'rayec_extend_occurrences';

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( 'rayec_db_version', RAYEC_DB_VERSION, false );
		update_option( 'rayec_flush_rewrite', 1, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Run table upgrades when the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'rayec_db_version' ) !== RAYEC_DB_VERSION ) {
			self::create_tables();
			update_option( 'rayec_db_version', RAYEC_DB_VERSION, false );
		}
		if ( get_option( 'rayec_version' ) !== RAYEC_VERSION ) {
			// A new version may add public URLs (such as programs), so refresh the rules once.
			update_option( 'rayec_version', RAYEC_VERSION, false );
			update_option( 'rayec_flush_rewrite', 1, false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Create or update custom tables.
	 *
	 * A composite index on (start_utc, end_utc) was tried here and dropped
	 * again, because it measured slower. InnoDB stores a secondary index in
	 * key-then-primary-key order, so start_utc on its own already hands the
	 * rows back in the order the range queries sort by, while the composite
	 * made the server sort them. The end_utc test is pushed down into the
	 * index scan either way. What made those queries fast was bounding
	 * start_utc from below in Occurrences::query(), not another index.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'rayec_occurrences';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				start_utc datetime NOT NULL,
				end_utc datetime NOT NULL,
				start_local datetime NOT NULL,
				end_local datetime NOT NULL,
				all_day tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY event_id (event_id),
				KEY start_utc (start_utc),
				KEY end_utc (end_utc),
				KEY start_local (start_local)
			) {$charset};"
		);

		// One row per registration. A date is named by the event's own
		// wall-clock start, because occurrence rows are rebuilt on every save.
		$registrations = $wpdb->prefix . Registrations::TABLE;
		dbDelta(
			"CREATE TABLE {$registrations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				occurrence_start datetime NOT NULL,
				name varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				places smallint(5) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'confirmed',
				access_request text NULL,
				data longtext NULL,
				token_hash char(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_utc datetime NOT NULL,
				updated_utc datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_date (event_id,occurrence_start),
				KEY email (email)
			) {$charset};"
		);
	}
}
