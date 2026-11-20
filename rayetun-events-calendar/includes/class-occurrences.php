<?php
/**
 * Occurrence index.
 *
 * One row per concrete date an event happens on, generated from the event's
 * rule on a rolling window so date-range queries never have to expand rules.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Occurrence storage and queries.
 */
class Occurrences {

	/**
	 * The table's name, without the site's prefix.
	 *
	 * @var string
	 */
	const TABLE = 'rayec_occurrences';

	const WINDOW_MONTHS = 24;
	const MAX_PER_EVENT = 1000;

	/**
	 * Repeating events rebuilt by one background job.
	 */
	const BATCH_SIZE = 25;

	/**
	 * The one-off job that carries on when a batch leaves series waiting.
	 */
	const BATCH_HOOK = 'rayec_extend_occurrences_batch';

	/**
	 * Post meta: how far a series' stored dates reach (UTC Y-m-d), or
	 * "complete" when the series has no dates beyond them.
	 */
	const REACH_META = '_rayec_rows_reach';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( Installer::CRON_HOOK, array( __CLASS__, 'extend_all' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'extend_all' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_event' ) );

		// Anything read earlier in this request may now be wrong. Saving goes
		// through the models, which clear up after themselves; trashing,
		// restoring and deleting do not, and can come from anywhere.
		foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $moment ) {
			add_action( $moment, array( __CLASS__, 'forget_everything' ) );
		}
	}

	/**
	 * Drop every per-request cache the models hold.
	 *
	 * @return void
	 */
	public static function forget_everything(): void {
		Event::forget();
		Venue::forget();
		Organizer::forget();
		Speaker::forget();
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Rebuild the rows for one event.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of occurrences stored.
	 */
	public static function regenerate( int $event_id ): int {
		global $wpdb;

		self::delete_for_event( $event_id );

		$event = Event::get( $event_id );
		if ( ! $event || '' === $event['start'] ) {
			if ( $event ) {
				// Nothing to extend until the event has a start again.
				update_post_meta( $event_id, self::REACH_META, 'complete' );
			}
			return 0;
		}

		$tz    = Event::timezone( $event['timezone'] );
		$start = Event::local_datetime( $event['start'], $tz );
		$end   = Event::local_datetime( $event['end'], $tz );
		if ( ! $start ) {
			update_post_meta( $event_id, self::REACH_META, 'complete' );
			return 0;
		}
		$duration = $end ? max( 0, $end->getTimestamp() - $start->getTimestamp() ) : HOUR_IN_SECONDS;
		self::note_length( (int) ceil( $duration / DAY_IN_SECONDS ) );

		$window_end = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+' . self::WINDOW_MONTHS . ' months' );
		$starts     = $event['rrule']
			? Recurrence::expand( $start, $event['rrule'], $window_end, self::MAX_PER_EVENT )
			: array( $start );
		self::note_reach( $event_id, (string) $event['rrule'], $starts, $window_end );

		$excluded = array_flip( $event['exdates'] );
		if ( $event['skip_closed'] && $event['rrule'] ) {
			$excluded += Plugin::closed_dates();
		}
		$utc  = new DateTimeZone( 'UTC' );
		$rows = array();

		foreach ( $starts as $first ) {
			if ( isset( $excluded[ $first->format( 'Y-m-d' ) ] ) ) {
				continue;
			}
			foreach ( self::sessions( $first, $event['extra_times'] ) as $occurrence ) {
				$occurrence_end = $occurrence->setTimestamp( $occurrence->getTimestamp() + $duration );
				$rows[]         = array(
					$event_id,
					$occurrence->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
					$occurrence_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
					$occurrence->format( 'Y-m-d H:i:s' ),
					$occurrence_end->format( 'Y-m-d H:i:s' ),
					$event['all_day'] ? 1 : 0,
				);
			}
		}

		$table = $wpdb->prefix . self::TABLE;
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '(%d,%s,%s,%s,%s,%d)' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table; placeholders are built above, one group per row.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (event_id,start_utc,end_utc,start_local,end_local,all_day) VALUES {$placeholders}", array_merge( ...$chunk ) ) );
		}

		return count( $rows );
	}

	/**
	 * Every start on one day: the event's own time plus any extra times.
	 *
	 * @param DateTimeImmutable $first The day's first start.
	 * @param string[]          $times Extra "HH:MM" start times.
	 * @return DateTimeImmutable[]
	 */
	public static function sessions( DateTimeImmutable $first, array $times ): array {
		$sessions = array( $first );
		foreach ( $times as $time ) {
			$sessions[] = $first->setTime( (int) substr( $time, 0, 2 ), (int) substr( $time, 3, 2 ) );
		}
		return $sessions;
	}

	/**
	 * Other events that one of these speakers is booked for during a time range.
	 *
	 * @param int[]  $speaker_ids Speaker IDs.
	 * @param string $start       Range start, UTC.
	 * @param string $end         Range end, UTC.
	 * @param int    $exclude     Event ID to ignore (the one being edited).
	 * @return array<int, object> Rows with event_id and speaker_id.
	 */
	public static function speaker_conflicts( array $speaker_ids, string $start, string $end, int $exclude = 0 ): array {
		global $wpdb;
		$speaker_ids = array_values( array_filter( array_map( 'absint', $speaker_ids ) ) );
		if ( ! $speaker_ids ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE;
		$in    = implode( ',', array_fill( 0, count( $speaker_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- custom table; the IN list is built from placeholders above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.event_id, m.meta_value AS speaker_id FROM {$table} o INNER JOIN {$wpdb->posts} p ON p.ID = o.event_id INNER JOIN {$wpdb->postmeta} m ON m.post_id = o.event_id AND m.meta_key = '_rayec_speaker' AND m.meta_value IN ({$in}) WHERE p.post_status IN ('publish','draft','pending','private') AND o.event_id != %d AND o.start_utc < %s AND o.end_utc > %s ORDER BY o.start_utc ASC LIMIT 10", array_merge( array_map( 'strval', $speaker_ids ), array( $exclude, $end, $start ) ) ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove an event's rows.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public static function delete_for_event( $event_id ): void {
		global $wpdb;
		if ( Post_Types::EVENT !== get_post_type( $event_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'event_id' => (int) $event_id ), array( '%d' ) );
	}

	/**
	 * Push the rolling window forward for repeating events, a few at a time.
	 *
	 * Runs once a day. Each run rebuilds at most BATCH_SIZE series, and only
	 * those that need it: series whose stored dates reach less than a month
	 * short of the full window. A series that has no more dates to add (its
	 * end date or count is already inside the window) is marked complete and
	 * left alone. When more series are waiting, the next batch is scheduled
	 * a few minutes later, so a site with many series is brought up to date
	 * over several short jobs, not one long one.
	 *
	 * @return void
	 */
	public static function extend_all(): void {
		/**
		 * Filters how many repeating events one background job rebuilds.
		 *
		 * @param int $size Series per batch.
		 */
		$size = max( 1, min( 200, (int) apply_filters( 'rayec_extend_batch_size', self::BATCH_SIZE ) ) );

		$ids = self::series_to_extend( $size + 1 );
		foreach ( array_slice( $ids, 0, $size ) as $id ) {
			self::regenerate( (int) $id );
		}

		if ( count( $ids ) > $size && ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::BATCH_HOOK );
		}
	}

	/**
	 * Repeating events whose stored dates are due to be extended.
	 *
	 * @param int $limit How many to return.
	 * @return int[]
	 */
	public static function series_to_extend( int $limit ): array {
		// A series rebuilt within the last month still has 23 of its 24 months.
		$due = wp_date( 'Y-m-d', strtotime( '+' . ( self::WINDOW_MONTHS - 1 ) . ' months' ), new DateTimeZone( 'UTC' ) );

		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::EVENT,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- background job, a small batch at a time.
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'     => '_rayec_rrule',
							'value'   => '',
							'compare' => '!=',
						),
						array(
							'relation' => 'OR',
							array(
								'key'     => self::REACH_META,
								'compare' => 'NOT EXISTS',
							),
							// 'complete' sorts after every date, so a finished series never matches.
							array(
								'key'     => self::REACH_META,
								'value'   => $due,
								'compare' => '<',
								'type'    => 'CHAR',
							),
						),
					),
				)
			)
		);
	}

	/**
	 * Note how far a series' stored dates reach, for extend_all().
	 *
	 * @param int                 $event_id   Event ID.
	 * @param string              $rrule      The event's rule.
	 * @param DateTimeImmutable[] $starts     The starts that were generated.
	 * @param DateTimeImmutable   $window_end Where the window stopped.
	 * @return void
	 */
	private static function note_reach( int $event_id, string $rrule, array $starts, DateTimeImmutable $window_end ): void {
		if ( '' === $rrule ) {
			delete_post_meta( $event_id, self::REACH_META );
			return;
		}
		$rule = Recurrence::parse( $rrule );
		// UNTIL starts with its date as Ymd, which compares as a string.
		$until    = substr( (string) $rule['UNTIL'], 0, 8 );
		$complete = count( $starts ) >= self::MAX_PER_EVENT
			|| ( $rule['COUNT'] > 0 && count( $starts ) >= $rule['COUNT'] )
			|| ( 8 === strlen( $until ) && $until <= $window_end->format( 'Ymd' ) );
		update_post_meta( $event_id, self::REACH_META, $complete ? 'complete' : $window_end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d' ) );
	}

	/**
	 * How many days the longest event on this site runs for.
	 *
	 * Kept as an option because every range query needs it and a
	 * `MAX(...)` over the index would cost more than the query it is meant to
	 * speed up. It only ever grows, which is the safe direction: too large
	 * costs a little speed, too small would hide a long event.
	 *
	 * @return int
	 */
	public static function longest_days(): int {
		global $wpdb;

		$known = get_option( 'rayec_longest_days', null );
		if ( null !== $known ) {
			return max( 1, (int) $known );
		}

		// Not worked out yet: once, on the whole table, then remembered.
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; the name is internal.
		$days = (int) $wpdb->get_var( "SELECT MAX(TIMESTAMPDIFF(DAY, start_utc, end_utc)) FROM {$table}" );
		$days = max( 1, $days );
		update_option( 'rayec_longest_days', $days, true );
		return $days;
	}

	/**
	 * Note how long an event runs, so range queries stay bounded.
	 *
	 * @param int $days Length in days.
	 * @return void
	 */
	private static function note_length( int $days ): void {
		$days = max( 1, $days );
		if ( $days > self::longest_days() ) {
			update_option( 'rayec_longest_days', $days, true );
		}
	}

	/**
	 * Occurrences overlapping a range.
	 *
	 * @param array<string, mixed> $args What to look for.
	 *     @type string   $start    Range start, UTC "Y-m-d H:i:s".
	 *     @type string   $end      Range end, UTC "Y-m-d H:i:s".
	 *     @type string[] $statuses Post statuses to include.
	 *     @type int      $category  Category term ID.
	 *     @type int      $venue     Venue ID.
	 *     @type int      $organizer Organizer ID.
	 *     @type string   $format    in_person, online or hybrid.
	 *     @type string   $access    Venue access feature key.
	 *     @type int      $limit    Row limit.
	 *     @type int      $offset   Row offset.
	 *     @type string   $order    ASC or DESC.
	 * @return array<int, object>
	 */
	public static function query( array $args ): array {
		global $wpdb;

		$statuses = ! empty( $args['statuses'] ) ? array_map( 'sanitize_key', (array) $args['statuses'] ) : array( 'publish' );
		$limit    = max( 1, min( 2000, (int) ( $args['limit'] ?? 500 ) ) );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$order    = 'DESC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';
		$table    = $wpdb->prefix . self::TABLE;

		$where  = array( 'p.post_type = %s', 'p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')' );
		$values = array_merge( array( Post_Types::EVENT ), $statuses );

		// Someone who may not see other people's unpublished events gets
		// published ones and their own. See Rest::visible_to().
		if ( ! empty( $args['visible_to'] ) ) {
			$where[]  = "( p.post_status = 'publish' OR p.post_author = %d )";
			$values[] = (int) $args['visible_to'];
		}

		if ( ! empty( $args['start'] ) ) {
			$where[]  = 'o.end_utc >= %s';
			$values[] = (string) $args['start'];

			// "Still running on the day" means end_utc is at or after the
			// range start, which on its own matches every row ever written
			// and leaves the database scanning the whole history. Nothing can
			// still be running unless it began within the longest event on
			// the site, so the scan is bounded there and the index earns its
			// keep. The longest event is tracked as rows are written.
			$where[]  = 'o.start_utc >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $args['start'] . ' UTC' ) - ( self::longest_days() + 1 ) * DAY_IN_SECONDS );
		}
		if ( ! empty( $args['end'] ) ) {
			$where[]  = 'o.start_utc < %s';
			$values[] = (string) $args['end'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( p.post_title LIKE %s OR p.post_content LIKE %s )';
			$values[] = $like;
			$values[] = $like;
		}
		if ( ! empty( $args['category'] ) ) {
			$where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = o.event_id AND tt.taxonomy = %s AND tt.term_id = %d )";
			$values[] = Post_Types::CATEGORY;
			$values[] = (int) $args['category'];
		}

		if ( ! empty( $args['venue'] ) ) {
			$where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} vm WHERE vm.post_id = o.event_id AND vm.meta_key = '_rayec_venue_id' AND vm.meta_value = %s )";
			$values[] = (string) (int) $args['venue'];
		}
		if ( ! empty( $args['organizer'] ) ) {
			$where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} om WHERE om.post_id = o.event_id AND om.meta_key = '_rayec_organizer_id' AND om.meta_value = %s )";
			$values[] = (string) (int) $args['organizer'];
		}
		if ( ! empty( $args['format'] ) ) {
			if ( 'in_person' === $args['format'] ) {
				// In person is the default, so events saved before the field existed have no row.
				$where[]  = "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} fm WHERE fm.post_id = o.event_id AND fm.meta_key = '_rayec_format' AND fm.meta_value != %s )";
				$values[] = 'in_person';
			} else {
				$where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} fm WHERE fm.post_id = o.event_id AND fm.meta_key = '_rayec_format' AND fm.meta_value = %s )";
				$values[] = (string) $args['format'];
			}
		}
		if ( ! empty( $args['access'] ) && isset( Event::access_features()[ (string) $args['access'] ] ) ) {
			// Access laid on at the event: the event's own serialized list.
			$where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} em WHERE em.post_id = o.event_id AND em.meta_key = '_rayec_event_access' AND em.meta_value LIKE %s )";
			$values[] = '%' . $wpdb->esc_like( '"' . (string) $args['access'] . '"' ) . '%';
		} elseif ( ! empty( $args['access'] ) ) {
			// The venue's features are a serialized list, so the key is matched inside it.
			$where[]  = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} vm INNER JOIN {$wpdb->postmeta} am ON am.post_id = vm.meta_value AND am.meta_key = '_rayec_access' WHERE vm.post_id = o.event_id AND vm.meta_key = '_rayec_venue_id' AND am.meta_value LIKE %s )";
			$values[] = '%' . $wpdb->esc_like( '"' . (string) $args['access'] . '"' ) . '%';
		}

		$values[] = $limit;
		$values[] = $offset;

		/*
		 * Every element of $where above is a string literal written in this
		 * function. Not one of them interpolates a variable: each value a
		 * caller supplied is a %s or %d placeholder, and the value itself
		 * goes into $values, which $wpdb->prepare() escapes. So $where_sql
		 * carries no caller input at all, only the placeholders. $order is
		 * one of two literals, and $table is the table prefix and a class
		 * constant.
		 *
		 * Static analysis cannot follow a clause built with implode(), which
		 * is why the sniffs below are silenced here rather than anywhere else
		 * in this file.
		 */
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see the note above: $where_sql holds placeholders only, and prepare() escapes every value.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.* FROM {$table} o INNER JOIN {$wpdb->posts} p ON p.ID = o.event_id WHERE {$where_sql} ORDER BY o.start_utc {$order}, o.id ASC LIMIT %d OFFSET %d", $values ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Other events using a venue during a time range.
	 *
	 * @param int    $venue_id Venue ID.
	 * @param string $start    Range start, UTC.
	 * @param string $end      Range end, UTC.
	 * @param int    $exclude  Event ID to ignore (the one being edited).
	 * @return array<int, object> Rows with event_id, start_utc, end_utc, all_day.
	 */
	public static function venue_conflicts( int $venue_id, string $start, string $end, int $exclude = 0 ): array {
		global $wpdb;
		if ( ! $venue_id ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.* FROM {$table} o INNER JOIN {$wpdb->posts} p ON p.ID = o.event_id INNER JOIN {$wpdb->postmeta} m ON m.post_id = o.event_id AND m.meta_key = '_rayec_venue_id' AND m.meta_value = %s WHERE p.post_status IN ('publish','draft','pending','private') AND o.event_id != %d AND o.start_utc < %s AND o.end_utc > %s ORDER BY o.start_utc ASC LIMIT 20", (string) $venue_id, $exclude, $end, $start ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The next (or, failing that, most recent) occurrence for each event.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @return array<int, object> Keyed by event ID.
	 */
	public static function next_for_events( array $event_ids ): array {
		global $wpdb;

		$event_ids = array_values( array_filter( array_map( 'intval', $event_ids ) ) );
		if ( ! $event_ids ) {
			return array();
		}

		$table = $wpdb->prefix . self::TABLE;
		$in    = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$out   = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table; IN list is built from %d placeholders.
		$upcoming = $wpdb->get_results( $wpdb->prepare( "SELECT o.* FROM {$table} o INNER JOIN ( SELECT event_id, MIN(start_utc) AS s FROM {$table} WHERE end_utc >= %s AND event_id IN ({$in}) GROUP BY event_id ) n ON n.event_id = o.event_id AND n.s = o.start_utc", array_merge( array( $now ), $event_ids ) ) );
		foreach ( (array) $upcoming as $row ) {
			$out[ (int) $row->event_id ] = $row;
		}

		$missing = array_diff( $event_ids, array_keys( $out ) );
		if ( $missing ) {
			$in = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table; IN list is built from %d placeholders.
			$past = $wpdb->get_results( $wpdb->prepare( "SELECT o.* FROM {$table} o INNER JOIN ( SELECT event_id, MAX(start_utc) AS s FROM {$table} WHERE event_id IN ({$in}) GROUP BY event_id ) n ON n.event_id = o.event_id AND n.s = o.start_utc", array_values( $missing ) ) );
			foreach ( (array) $past as $row ) {
				$out[ (int) $row->event_id ] = $row;
			}
		}

		return $out;
	}

	/**
	 * Start and end of a row as objects in the display time zone.
	 *
	 * All-day events keep their own calendar date wherever they are viewed.
	 *
	 * @param object            $row Occurrence row.
	 * @param DateTimeZone|null $tz  Display time zone; the site's by default.
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	public static function times( object $row, ?DateTimeZone $tz = null ): array {
		$tz = $tz ? $tz : wp_timezone();
		if ( (int) $row->all_day ) {
			return array(
				new DateTimeImmutable( $row->start_local, $tz ),
				new DateTimeImmutable( $row->end_local, $tz ),
			);
		}
		$utc = new DateTimeZone( 'UTC' );
		return array(
			( new DateTimeImmutable( $row->start_utc, $utc ) )->setTimezone( $tz ),
			( new DateTimeImmutable( $row->end_utc, $utc ) )->setTimezone( $tz ),
		);
	}

	/**
	 * Human-readable "when" line.
	 *
	 * @param DateTimeImmutable $start   Start.
	 * @param DateTimeImmutable $end     End.
	 * @param bool              $all_day All-day flag.
	 * @return string
	 */
	public static function format_range( DateTimeImmutable $start, DateTimeImmutable $end, bool $all_day ): string {
		$date_format = (string) get_option( 'date_format', 'F j, Y' );
		$time_format = Plugin::time_format();
		$tz          = $start->getTimezone();
		$same_day    = $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' );
		$day         = wp_date( 'l, ' . $date_format, $start->getTimestamp(), $tz );

		if ( $all_day ) {
			if ( $same_day ) {
				/* translators: %s: date. */
				return sprintf( __( '%s, all day', 'rayetun-events-calendar' ), $day );
			}
			/* translators: 1: start date, 2: end date. */
			return sprintf( __( '%1$s to %2$s', 'rayetun-events-calendar' ), $day, wp_date( 'l, ' . $date_format, $end->getTimestamp(), $tz ) );
		}

		$from = wp_date( $time_format, $start->getTimestamp(), $tz );
		if ( $same_day ) {
			/* translators: 1: date, 2: start time, 3: end time. */
			return sprintf( __( '%1$s, %2$s to %3$s', 'rayetun-events-calendar' ), $day, $from, wp_date( $time_format, $end->getTimestamp(), $tz ) );
		}
		/* translators: 1: start date, 2: start time, 3: end date and time. */
		return sprintf( __( '%1$s, %2$s to %3$s', 'rayetun-events-calendar' ), $day, $from, wp_date( 'l, ' . $date_format . ' ' . $time_format, $end->getTimestamp(), $tz ) );
	}

	/**
	 * The same range in two parts, the day and the time, for a layout that
	 * gives each its own line instead of wrapping one long one.
	 *
	 * @param DateTimeImmutable $start   Start.
	 * @param DateTimeImmutable $end     End.
	 * @param bool              $all_day All-day event.
	 * @return array{date: string, time: string, day: string, weekday: string} The date with its weekday, the time, and the date and weekday apart for a narrow box.
	 */
	public static function split_range( DateTimeImmutable $start, DateTimeImmutable $end, bool $all_day ): array {
		$date_format = (string) get_option( 'date_format', 'F j, Y' );
		$time_format = Plugin::time_format();
		$tz          = $start->getTimezone();
		$same_day    = $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' );
		$day         = wp_date( 'l, ' . $date_format, $start->getTimestamp(), $tz );
		$apart       = array(
			'day'     => wp_date( $date_format, $start->getTimestamp(), $tz ),
			'weekday' => wp_date( 'l', $start->getTimestamp(), $tz ),
		);

		if ( $all_day ) {
			return $apart + array(
				/* translators: 1: start date, 2: end date. */
				'date' => $same_day ? $day : sprintf( __( '%1$s to %2$s', 'rayetun-events-calendar' ), $day, wp_date( 'l, ' . $date_format, $end->getTimestamp(), $tz ) ),
				'time' => __( 'All day', 'rayetun-events-calendar' ),
			);
		}

		$from  = wp_date( $time_format, $start->getTimestamp(), $tz );
		$until = $same_day
			? wp_date( $time_format, $end->getTimestamp(), $tz )
			: wp_date( 'l, ' . $date_format . ' ' . $time_format, $end->getTimestamp(), $tz );
		return $apart + array(
			'date' => $day,
			/* translators: 1: start time, 2: end time, or end date and time. */
			'time' => sprintf( __( '%1$s to %2$s', 'rayetun-events-calendar' ), $from, $until ),
		);
	}
}
