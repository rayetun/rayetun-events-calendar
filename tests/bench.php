<?php
/**
 * Performance bench for the calendar.
 *
 * Seeds a site with as many events as you like, times the queries and the
 * rendering, and takes it all away again. Every post it makes carries
 * `_rayec_bench`, and `--remove` deletes exactly those and nothing else.
 *
 *   php tests/bench.php /path/to/wordpress seed 10000
 *   php tests/bench.php /path/to/wordpress run
 *   php tests/bench.php /path/to/wordpress check
 *   php tests/bench.php /path/to/wordpress remove
 *
 * "check" is the one that matters after touching the range query: it makes a
 * ten-day event and proves the floor under that query has not hidden it.
 *
 * Of the events it seeds, four in five are one-off and one in five repeats
 * weekly, which is roughly what a busy venue looks like and which is what
 * fills the occurrence table up.
 *
 * @package RayEtun\EventsCalendar
 */

$_SERVER['HTTP_HOST']                                    = 'pinspot-dev.local';
$_SERVER['REQUEST_URI']                                  = '/';
$GLOBALS['wp_filter']['pre_option_active_plugins'][10][] = array(
	'function'      => static fn() => array( 'rayetun-events-calendar/rayetun-events-calendar.php' ),
	'accepted_args' => 1,
);
require $argv[1] . '/wp-load.php';

use RayEtun\EventsCalendar\Event;
use RayEtun\EventsCalendar\Occurrences;
use RayEtun\EventsCalendar\Organizer;
use RayEtun\EventsCalendar\Post_Types;
use RayEtun\EventsCalendar\Speaker;
use RayEtun\EventsCalendar\Venue;

global $wpdb;

$command = $argv[2] ?? 'run';
$howmany = (int) ( $argv[3] ?? 10000 );
$table   = $wpdb->prefix . Occurrences::TABLE;

/**
 * Time one thing, several times, and report the middle and the worst.
 *
 * @param string   $label What it is.
 * @param callable $work  The thing.
 * @param int      $runs  How many times.
 * @return float The median, in milliseconds.
 */
function bench_time( string $label, callable $work, int $runs = 7 ): float {
	$times = array();
	$note  = '';
	for ( $i = 0; $i < $runs; $i++ ) {
		bench_cold();
		$before  = microtime( true );
		$result  = $work();
		$times[] = ( microtime( true ) - $before ) * 1000;
		if ( 0 === $i && is_scalar( $result ) ) {
			$note = ' (' . $result . ')';
		}
	}
	sort( $times );
	$median = $times[ (int) floor( count( $times ) / 2 ) ];
	printf(
		"%-46s %8.1f ms   worst %7.1f ms%s\n",
		$label,
		$median,
		end( $times ),
		$note
	);
	return $median;
}

/**
 * Put the process back to how a fresh request would find it.
 *
 * Without this every run after the first reads from caches that a real page
 * load would not have, and the numbers flatter the plugin.
 *
 * @return void
 */
function bench_cold(): void {
	Event::forget();
	Venue::forget();
	Organizer::forget();
	Speaker::forget();
	wp_cache_flush();
}

/** Rows in the index, and events, right now. */
function bench_counts(): array {
	global $wpdb;
	$table = $wpdb->prefix . Occurrences::TABLE;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$occurrences = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$events      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Post_Types::EVENT ) );
	return array( $events, $occurrences );
}

// ---------------------------------------------------------------------------
// Seed
// ---------------------------------------------------------------------------

if ( 'seed' === $command ) {
	$now      = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$from     = $now->modify( '-6 months' );
	$venues   = get_posts(
		array(
			'post_type'      => Post_Types::VENUE,
			'posts_per_page' => 10,
			'fields'         => 'ids',
		)
	);
	$venues   = $venues ? $venues : array( 0 );
	$author   = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
	$names    = array( 'Yoga', 'Choir practice', 'Winter market', 'Reading group', 'Coffee morning', 'Repair cafe', 'Film night', 'Toddler group', 'Chess club', 'Life drawing' );
	$made     = 0;
	$rows     = 0;
	$start_at = microtime( true );

	for ( $batch = 0; $batch < ceil( $howmany / 200 ); $batch++ ) {
		$posts = array();
		$ids   = array();

		for ( $i = 0; $i < 200 && $made < $howmany; $i++, $made++ ) {
			$day     = $from->modify( '+' . ( $made % 540 ) . ' days' )->setTime( 9 + ( $made % 10 ), 0 );
			$title   = $names[ $made % 10 ] . ' ' . ( $made + 1 );
			$slug    = 'bench-' . ( $made + 1 );
			$content = '<p>A seeded event for timing the calendar, with enough words in it that the page has something real to lay out and the description is not empty.</p>';

			$posts[] = $wpdb->prepare(
				'(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
				$author,
				$day->format( 'Y-m-d H:i:s' ),
				$day->format( 'Y-m-d H:i:s' ),
				$content,
				$title,
				'',
				'publish',
				$slug,
				Post_Types::EVENT,
				'',
				''
			);
			$ids[]   = array( $day, $made );
		}

		if ( ! $posts ) {
			break;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( "INSERT INTO {$wpdb->posts} (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,post_name,post_type,post_mime_type,to_ping) VALUES " . implode( ',', $posts ) );
		$first = (int) $wpdb->insert_id;

		$meta = array();
		$occ  = array();
		foreach ( $ids as $n => $info ) {
			list( $day, $index ) = $info;
			$post_id             = $first + $n;
			$repeats             = 0 === $index % 5;
			$end                 = $day->modify( '+90 minutes' );

			foreach (
				array(
					'_rayec_start'    => $day->format( 'Y-m-d H:i:s' ),
					'_rayec_end'      => $end->format( 'Y-m-d H:i:s' ),
					'_rayec_timezone' => 'Europe/London',
					'_rayec_all_day'  => '0',
					'_rayec_format'   => 'in_person',
					'_rayec_venue_id' => (string) $venues[ $index % count( $venues ) ],
					'_rayec_rrule'    => $repeats ? 'FREQ=WEEKLY' : '',
					'_rayec_bench'    => '1',
				) as $key => $value
			) {
				$meta[] = $wpdb->prepare( '(%d,%s,%s)', $post_id, $key, $value );
			}

			$dates = array( $day );
			if ( $repeats ) {
				// Two years of a weekly series is what fills the index up.
				for ( $w = 1; $w < 104; $w++ ) {
					$dates[] = $day->modify( '+' . ( $w * 7 ) . ' days' );
				}
			}
			foreach ( $dates as $date ) {
				$finish = $date->modify( '+90 minutes' );
				$occ[]  = $wpdb->prepare(
					'(%d,%s,%s,%s,%s,%d)',
					$post_id,
					$date->format( 'Y-m-d H:i:s' ),
					$finish->format( 'Y-m-d H:i:s' ),
					$date->format( 'Y-m-d H:i:s' ),
					$finish->format( 'Y-m-d H:i:s' ),
					0
				);
				++$rows;
			}
		}

		foreach ( array_chunk( $meta, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES " . implode( ',', $chunk ) );
		}
		foreach ( array_chunk( $occ, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( "INSERT INTO {$table} (event_id,start_utc,end_utc,start_local,end_local,all_day) VALUES " . implode( ',', $chunk ) );
		}

		printf( "  seeded %d events, %d occurrences\r", $made, $rows );
	}

	list( $events, $occurrences ) = bench_counts();
	printf(
		"\nSeeded %d events and %d occurrences in %.1fs. The site now holds %d published events and %d occurrence rows.\n",
		$made,
		$rows,
		microtime( true ) - $start_at,
		$events,
		$occurrences
	);
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Check: the floor under the range query must never hide a long event
// ---------------------------------------------------------------------------

if ( 'check' === $command ) {
	$admin = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) ( $admin[0] ?? 1 ) );

	$failed = 0;

	/**
	 * Report one check.
	 *
	 * @param string $label What is being checked.
	 * @param bool   $got   What happened.
	 * @param bool   $want  What should have happened.
	 * @return void
	 */
	$check = static function ( string $label, bool $got, bool $want ) use ( &$failed ): void {
		$ok = $got === $want;
		if ( ! $ok ) {
			++$failed;
		}
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . '
';
	};

	/**
	 * Whether a one-day query finds an event.
	 *
	 * @param DateTimeImmutable $day      The day to look at.
	 * @param int               $event_id The event to look for.
	 * @return bool
	 */
	$on = static function ( DateTimeImmutable $day, int $event_id ): bool {
		$rows = Occurrences::query(
			array(
				'start' => $day->setTime( 0, 0 )->format( 'Y-m-d H:i:s' ),
				'end'   => $day->setTime( 0, 0 )->modify( '+1 day' )->format( 'Y-m-d H:i:s' ),
				'limit' => 500,
			)
		);
		return in_array( $event_id, array_map( static fn( $r ) => (int) $r->event_id, $rows ), true );
	};

	$start = new DateTimeImmutable( 'first day of next month 09:00', new DateTimeZone( 'UTC' ) );
	$end   = $start->modify( '+10 days' );
	$id    = Event::save(
		array(
			'title'       => 'A ten-day festival',
			'start'       => $start->format( 'Y-m-d\TH:i' ),
			'end'         => $end->format( 'Y-m-d\TH:i' ),
			'timezone'    => 'UTC',
			'post_status' => 'publish',
		)
	);
	if ( is_wp_error( $id ) ) {
		echo 'Could not make the test event: ' . $id->get_error_message() . '
';
		exit( 1 );
	}

	printf(
		'A ten-day event, %s to %s. The longest event on the site now reads %d days.

',
		$start->format( 'j M' ),
		$end->format( 'j M' ),
		Occurrences::longest_days()
	);

	$check( 'found on the day it starts', $on( $start, $id ), true );
	$check( 'found on its sixth day', $on( $start->modify( '+5 days' ), $id ), true );
	$check( 'found on its last day', $on( $end, $id ), true );
	$check( 'not found the day after it finishes', $on( $end->modify( '+2 days' ), $id ), false );
	$check( 'not found the week before it starts', $on( $start->modify( '-7 days' ), $id ), false );

	wp_delete_post( $id, true );

	// The longest-event figure only ever grows, so a ten-day event leaves the
	// site scanning ten days further back than it needs to. Clearing it makes
	// the next read work it out again from what is actually there.
	delete_option( 'rayec_longest_days' );
	printf(
		'
The longest event now reads %d days again.
',
		Occurrences::longest_days()
	);

	echo $failed ? "
{$failed} FAILED
" : 'ALL PASSED
';
	exit( $failed ? 1 : 0 );
}

// ---------------------------------------------------------------------------
// Remove
// ---------------------------------------------------------------------------

if ( 'remove' === $command ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_rayec_bench'" );
	$ids = array_map( 'intval', (array) $ids );
	if ( ! $ids ) {
		echo "Nothing seeded.\n";
		exit( 0 );
	}
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		$in = implode( ',', $chunk );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table} WHERE event_id IN ({$in})" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
	}
	list( $events, $occurrences ) = bench_counts();
	printf( "Removed %d seeded events. %d published events and %d occurrence rows left.\n", count( $ids ), $events, $occurrences );
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

list( $events, $occurrences ) = bench_counts();
printf(
	"%d published events, %d occurrence rows, WordPress %s, PHP %s\n\n",
	$events,
	$occurrences,
	get_bloginfo( 'version' ),
	PHP_VERSION
);

$month_start = ( new DateTimeImmutable( 'first day of this month 00:00', new DateTimeZone( 'UTC' ) ) );
$month_end   = $month_start->modify( '+1 month' );
$week_start  = ( new DateTimeImmutable( 'monday this week 00:00', new DateTimeZone( 'UTC' ) ) );

echo "Queries\n";
bench_time(
	'month range',
	static function () use ( $month_start, $month_end ) {
		return count(
			Occurrences::query(
				array(
					'start' => $month_start->format( 'Y-m-d H:i:s' ),
					'end'   => $month_end->format( 'Y-m-d H:i:s' ),
					'limit' => 2000,
				)
			)
		) . ' rows';
	}
);
bench_time(
	'month range, one category',
	static function () use ( $month_start, $month_end ) {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::CATEGORY,
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		return count(
			Occurrences::query(
				array(
					'start'    => $month_start->format( 'Y-m-d H:i:s' ),
					'end'      => $month_end->format( 'Y-m-d H:i:s' ),
					'category' => ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->term_id : 0,
					'limit'    => 2000,
				)
			)
		) . ' rows';
	}
);
bench_time(
	'week range',
	static function () use ( $week_start ) {
		return count(
			Occurrences::query(
				array(
					'start' => $week_start->format( 'Y-m-d H:i:s' ),
					'end'   => $week_start->modify( '+7 days' )->format( 'Y-m-d H:i:s' ),
					'limit' => 2000,
				)
			)
		) . ' rows';
	}
);
bench_time(
	'the next twenty, from now',
	static function () {
		return count(
			Occurrences::query(
				array(
					'start' => gmdate( 'Y-m-d H:i:s' ),
					'limit' => 20,
				)
			)
		) . ' rows';
	}
);

echo "\nRendering\n";
foreach ( array( 'month', 'week', 'day', 'list' ) as $view ) {
	bench_time(
		'[rayec_calendar view="' . $view . '"]',
		static function () use ( $view ) {
			$html = do_shortcode( '[rayec_calendar view="' . $view . '"]' );
			return strlen( $html ) . ' bytes';
		},
		5
	);
}

echo "\nQuery plan for the month range\n";
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$floor = $month_start->modify( '-' . ( Occurrences::longest_days() + 1 ) . ' days' );
$plan  = $wpdb->get_results(
	$wpdb->prepare(
		"EXPLAIN SELECT o.* FROM {$table} o INNER JOIN {$wpdb->posts} p ON p.ID = o.event_id WHERE p.post_type = %s AND p.post_status IN ('publish') AND o.end_utc >= %s AND o.start_utc >= %s AND o.start_utc < %s ORDER BY o.start_utc ASC, o.id ASC LIMIT 2000",
		Post_Types::EVENT,
		$month_start->format( 'Y-m-d H:i:s' ),
		$floor->format( 'Y-m-d H:i:s' ),
		$month_end->format( 'Y-m-d H:i:s' )
	)
);
foreach ( (array) $plan as $step ) {
	printf(
		"  %-8s key=%-18s rows=%-10s extra=%s\n",
		$step->table ?? '?',
		$step->key ?? '(none)',
		$step->rows ?? '?',
		$step->Extra ?? ''
	);
}
