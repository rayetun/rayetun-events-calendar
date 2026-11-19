<?php
/**
 * Checks for the event page's second half against a real WordPress database:
 * Good to know, the route buttons, and "More events like this".
 *
 * Usage: php tests/event-page-check.php <path-to-wordpress>
 *
 * Makes its own category, program, venue and events, and deletes them all
 * at the end, including anything an interrupted run left behind.
 *
 * @package RayEtun\EventsCalendar
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line test script.

$_SERVER['HTTP_HOST']   = 'pinspot-dev.local';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';

use RayEtun\EventsCalendar\Event;
use RayEtun\EventsCalendar\Event_Page;
use RayEtun\EventsCalendar\Plugin;
use RayEtun\EventsCalendar\Post_Types;
use RayEtun\EventsCalendar\Venue;

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

const PREFIX = 'Page check:';

/**
 * Remove everything this script makes.
 *
 * @return void
 */
function rayec_page_check_tidy(): void {
	foreach ( array( Post_Types::EVENT, Post_Types::VENUE ) as $type ) {
		foreach ( get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'any',
				's'              => PREFIX,
				'fields'         => 'ids',
				'posts_per_page' => 50,
			)
		) as $stale ) {
			if ( 0 === strpos( get_the_title( $stale ), PREFIX ) ) {
				wp_delete_post( $stale, true );
			}
		}
	}
	foreach ( array( Post_Types::CATEGORY, Post_Types::PROGRAM ) as $taxonomy ) {
		$term = get_term_by( 'name', PREFIX . ' term', $taxonomy );
		if ( $term ) {
			wp_delete_term( $term->term_id, $taxonomy );
		}
	}
}
rayec_page_check_tidy();

$passed = 0;
$failed = 0;
$check  = static function ( string $what, bool $ok, string $detail = '' ) use ( &$passed, &$failed ) {
	if ( $ok ) {
		++$passed;
		echo "  ok    {$what}\n";
	} else {
		++$failed;
		echo "  FAIL  {$what}" . ( '' !== $detail ? " ({$detail})" : '' ) . "\n";
	}
};

$tz  = wp_timezone();
$day = static fn( string $when ) => new DateTimeImmutable( $when, $tz );

/*
 * Duration, in words.
 */
echo "\nDuration\n";
$check( 'an hour', '1 hour' === Event_Page::duration( $day( '2030-01-07 18:00' ), $day( '2030-01-07 19:00' ), false ) );
$check( 'an hour and a half', '1 hour 30 minutes' === Event_Page::duration( $day( '2030-01-07 18:00' ), $day( '2030-01-07 19:30' ), false ) );
$check( 'under an hour', '45 minutes' === Event_Page::duration( $day( '2030-01-07 18:00' ), $day( '2030-01-07 18:45' ), false ) );
$check( 'one all-day date', 'All day' === Event_Page::duration( $day( '2030-01-07 00:00' ), $day( '2030-01-08 00:00' ), true ) );
$check( 'three all-day dates', '3 days' === Event_Page::duration( $day( '2030-01-07 00:00' ), $day( '2030-01-10 00:00' ), true ) );
$check( 'a weekend from Friday evening to Sunday afternoon is 3 days', '3 days' === Event_Page::duration( $day( '2030-01-04 18:00' ), $day( '2030-01-06 16:00' ), false ) );
$check( 'an end before the start says nothing', '' === Event_Page::duration( $day( '2030-01-07 18:00' ), $day( '2030-01-07 17:00' ), false ) );

/*
 * Fixtures: a category, a program, a venue, and events around them.
 */
$cat   = wp_insert_term( PREFIX . ' term', Post_Types::CATEGORY );
$prog  = wp_insert_term( PREFIX . ' term', Post_Types::PROGRAM );
$cat   = (int) $cat['term_id'];
$prog  = (int) $prog['term_id'];
$venue = Venue::save(
	array(
		'name'    => PREFIX . ' Hall',
		'address' => '1 High Street',
		'city'    => 'Riverside',
		'lat'     => '51.5',
		'lng'     => '-0.12',
	)
);

$make = static function ( string $title, string $start, array $more = array() ) use ( $cat, $venue ) {
	$id = Event::save(
		array_merge(
			array(
				'title'        => PREFIX . ' ' . $title,
				'start'        => $start,
				'end'          => substr( $start, 0, 11 ) . '23:00',
				'category_ids' => array( $cat ),
				'venue_id'     => $venue,
				'post_status'  => 'publish',
			),
			$more
		)
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
};
$at   = static fn( string $when ) => $day( $when )->format( 'Y-m-d\T19:00' );

$main      = $make(
	'Main',
	$at( '+3 days' ),
	array(
		'age'    => '18',
		'doors'  => 30,
		'policy' => 'Refunds up to 7 days before.',
		'cost'   => '£5',
	)
);
$soon      = $make( 'Soon', $at( '+7 days' ) );
$copy      = $make( 'Soon', $at( '+8 days' ) );
$called    = $make( 'Called off', $at( '+9 days' ), array( 'event_status' => 'cancelled' ) );
$over      = $make( 'Over', $at( '-9 days' ) );
$later     = $make( 'Later', $at( '+14 days' ) );
$sibling   = $make( 'Sibling', $at( '+15 days' ), array( 'program_id' => $prog ) );
$latest    = $make( 'Latest', $at( '+21 days' ) );
$elsewhere = $make( 'Elsewhere', $at( '+5 days' ), array( 'category_ids' => array() ) );
$check( 'fixtures made', $venue > 0 && $main && $soon && $copy && $called && $over && $later && $sibling && $latest && $elsewhere );

/*
 * More events like this.
 */
echo "\nMore events like this\n";
$titles = static fn( array $items ) => array_map( static fn( $item ) => str_replace( PREFIX . ' ', '', $item['event']['title'] ), $items );

list( $kind, $items ) = Event_Page::related( Event::get( $main ) );
$check( 'same category, the next date of each, in date order, three at most', 'category' === $kind && array( 'Soon', 'Later', 'Sibling' ) === $titles( $items ), implode( ', ', $titles( $items ) ) );
$check( 'a copy under the same name, a cancelled event and a past one are left out', ! array_intersect( array( 'Called off', 'Over' ), $titles( $items ) ) && 1 === count( array_keys( $titles( $items ), 'Soon', true ) ) );

Event::save( array( 'program_id' => $prog ), $main );
list( , $items ) = Event_Page::related( Event::get( $main ) );
$check( 'events in the same program are left to "More in" the program', array( 'Soon', 'Later', 'Latest' ) === $titles( $items ), implode( ', ', $titles( $items ) ) );

list( $kind, $items ) = Event_Page::related( Event::get( $elsewhere ) );
$check( 'an event with no category falls back to its venue', 'venue' === $kind && in_array( 'Main', $titles( $items ), true ) && ! in_array( 'Elsewhere', $titles( $items ), true ), $kind . ': ' . implode( ', ', $titles( $items ) ) );

$html = Event_Page::more_like_this( Event::get( $main ) );
$check( 'cards and the links onward', 3 === substr_count( $html, 'class="rayec-card"' ) && false !== strpos( $html, 'rayec_cat=' . $cat ) && false !== strpos( $html, 'rayec_venue=' . $venue ) );

add_filter( 'rayec_related_events_args', '__return_empty_array' );
$html = Event_Page::more_like_this( Event::get( $main ) );
remove_filter( 'rayec_related_events_args', '__return_empty_array' );
$check( 'nothing alike leaves the links alone, under "Keep exploring"', false === strpos( $html, 'rayec-card' ) && false !== strpos( $html, 'Keep exploring' ) );

/*
 * Good to know.
 */
echo "\nGood to know\n";
$event = Event::get( $main );
$html  = Event_Page::good_to_know( $event, array( $day( '+3 days 19:00' ), $day( '+3 days 23:00' ) ) );
$check( 'the stored facts are shown', false !== strpos( $html, 'Ages 18 and over' ) && false !== strpos( $html, '£5' ) && false !== strpos( $html, '4 hours' ) && false !== strpos( $html, 'Refunds up to 7 days before.' ) );
$check( 'doors open 30 minutes before the start', false !== strpos( $html, wp_date( Plugin::time_format(), $day( '+3 days 18:30' )->getTimestamp(), $tz ) ) );
$check( 'stored values are cleaned', '' === Event::clean_age( 'nineteen' ) && 0 === Event::clean_doors( 20 ) && 45 === Event::clean_doors( '45' ) );

$plain = Event::get( $later );
$check( 'an event with nothing more to say gets no section', '' === Event_Page::good_to_know( $plain, array( $day( '+14 days 19:00' ), $day( '+14 days 23:00' ) ) ) );

Event::save(
	array(
		'rsvp' => array(
			'enabled'  => true,
			'capacity' => 10,
			'max'      => 1,
			'access'   => false,
		),
	),
	$later
);
$html = Event_Page::good_to_know( Event::get( $later ), array( $day( '+14 days 19:00' ), $day( '+14 days 23:00' ) ) );
$check( 'taking bookings with no written policy says how to cancel', false !== strpos( $html, 'link in your confirmation email' ) && false !== strpos( $html, 'Book ahead' ) );

/*
 * Getting here.
 */
echo "\nGetting here\n";
$settings = Plugin::SETTINGS_OPTION;
$use      = static function ( string $provider ) use ( $settings ) {
	remove_all_filters( 'option_' . $settings );
	add_filter( 'option_' . $settings, static fn( $value ) => array_merge( is_array( $value ) ? $value : array(), array( 'directions' => $provider ) ) );
};
$place    = Venue::get( $venue );

$use( 'google' );
$html = Event_Page::routes( $place );
$check( 'Google Maps: walk, cycle, public transport and drive, to the pin', 4 === substr_count( $html, 'rayec-route__link' ) && false !== strpos( $html, 'travelmode=transit' ) && false !== strpos( $html, 'destination=51.5%2C-0.12' ) );

$use( 'apple' );
$html = Event_Page::routes( $place );
$check( 'Apple Maps: no cycling button', 3 === substr_count( $html, 'rayec-route__link' ) && false !== strpos( $html, 'dirflg=r' ) && false === strpos( $html, 'Cycle' ) );

$use( 'osm' );
$html = Event_Page::routes( $place );
$check( 'OpenStreetMap: no public transport button', 3 === substr_count( $html, 'rayec-route__link' ) && false !== strpos( $html, 'fossgis_osrm_bike' ) && false === strpos( $html, 'Public transport' ) );
remove_all_filters( 'option_' . $settings );

$check( 'each button names the venue for screen readers', false !== strpos( Event_Page::routes( $place ), 'to ' . PREFIX . ' Hall' ) );

/*
 * Tidy up.
 */
rayec_page_check_tidy();
$check( 'everything made is gone', ! get_post( $main ) && ! get_post( $venue ) && ! get_term( $cat, Post_Types::CATEGORY ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
