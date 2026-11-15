<?php
// Dev-only smoke test: activate the plugin on the Local site and exercise the engines.
$_SERVER['HTTP_HOST']   = 'pinspot-dev.local';
$_SERVER['REQUEST_URI'] = '/';
// Usage: php tests/smoke.php /path/to/wordpress
// Read-only: for this process only, load WordPress with just this plugin active.
// Nothing is written to the site.
$GLOBALS['wp_filter']['pre_option_active_plugins'][10][] = array(
	'function'      => static fn() => array( 'rayetun-events-calendar/rayetun-events-calendar.php' ),
	'accepted_args' => 1,
);
require $argv[1] . '/wp-load.php';

use RayEtun\EventsCalendar\Recurrence;
use RayEtun\EventsCalendar\Natural_Language;

global $wpdb;

echo 'wp ' . get_bloginfo( 'version' ) . ', tz ' . wp_timezone_string() . "\n";
echo 'table: ' . $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rayec_occurrences'" ) . "\n";

$fail = 0;

/**
 * Report one check and count the failures.
 *
 * @param string $label What is being checked.
 * @param mixed  $got   What the code returned.
 * @param mixed  $want  What it should have returned.
 * @return void
 */
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$fail;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok ? '' : "\n   got:  " . wp_json_encode( $got ) . "\n   want: " . wp_json_encode( $want ) ) . "\n";
}
$fmt = static fn( $dates, $f = 'Y-m-d H:i' ) => array_map( static fn( $d ) => $d->format( $f ), $dates );

$ny = new DateTimeZone( 'America/New_York' );

// Weekly Tue/Thu.
$r = Recurrence::expand( new DateTimeImmutable( '2026-11-17 18:00', $ny ), 'FREQ=WEEKLY;BYDAY=TU,TH;COUNT=5' );
check( 'weekly TU,TH count 5', $fmt( $r, 'Y-m-d' ), array( '2026-11-17', '2026-11-19', '2026-11-24', '2026-11-26', '2026-12-01' ) );

// DST: wall clock stays 18:00 across the March change; UTC shifts.
$r = Recurrence::expand( new DateTimeImmutable( '2027-03-09 18:00', $ny ), 'FREQ=WEEKLY;COUNT=3' );
check( 'DST wall clock', $fmt( $r, 'Y-m-d H:i T' ), array( '2027-03-09 18:00 EST', '2027-03-16 18:00 EDT', '2027-03-23 18:00 EDT' ) );

// Monthly on the 31st skips short months.
$r = Recurrence::expand( new DateTimeImmutable( '2027-01-31 09:00', $ny ), 'FREQ=MONTHLY;COUNT=4' );
check( 'monthly 31st', $fmt( $r, 'Y-m-d' ), array( '2027-01-31', '2027-03-31', '2027-05-31', '2027-07-31' ) );

// Second Tuesday.
$r = Recurrence::expand( new DateTimeImmutable( '2026-12-08 19:30', $ny ), 'FREQ=MONTHLY;BYDAY=2TU;COUNT=3' );
check( 'second Tuesday', $fmt( $r, 'Y-m-d' ), array( '2026-12-08', '2027-01-12', '2027-02-09' ) );

// Last Friday.
$r = Recurrence::expand( new DateTimeImmutable( '2026-11-27 12:00', $ny ), 'FREQ=MONTHLY;BYDAY=-1FR;COUNT=3' );
check( 'last Friday', $fmt( $r, 'Y-m-d' ), array( '2026-11-27', '2026-12-25', '2027-01-29' ) );

// Leap day yearly.
$r = Recurrence::expand( new DateTimeImmutable( '2028-02-29 10:00', $ny ), 'FREQ=YEARLY;COUNT=2' );
check( 'leap day yearly', $fmt( $r, 'Y-m-d' ), array( '2028-02-29', '2032-02-29' ) );

// Every other week + until.
$r = Recurrence::expand( new DateTimeImmutable( '2026-11-16 08:00', $ny ), 'FREQ=WEEKLY;INTERVAL=2;UNTIL=20261231' );
check( 'biweekly until', $fmt( $r, 'Y-m-d' ), array( '2026-11-16', '2026-11-30', '2026-12-14', '2026-12-28' ) );

// Daily interval 3.
$r = Recurrence::expand( new DateTimeImmutable( '2026-11-16 08:00', $ny ), 'FREQ=DAILY;INTERVAL=3;COUNT=3' );
check( 'every 3 days', $fmt( $r, 'Y-m-d' ), array( '2026-11-16', '2026-11-19', '2026-11-22' ) );

// Plain language. "Now" is Friday 13 November 2026, 09:00.
$now = new DateTimeImmutable( '2026-11-13 09:00', $ny );
$nl  = static function ( $text ) use ( $ny, $now ) {
	$p = Natural_Language::parse( $text, $ny, $now );
	return array( $p['title'], $p['start'], $p['end'], $p['all_day'], $p['rrule'] );
};
check( 'NL spec sentence', $nl( 'Yoga every Tue and Thu at 6pm until June, skip public holidays' ), array( 'Yoga', '2026-11-17T18:00', '2026-11-17T19:00', false, 'FREQ=WEEKLY;BYDAY=TU,TH;UNTIL=20270630' ) );
check( 'NL one-off', $nl( 'Board meeting on March 3 at 2:30pm' ), array( 'Board meeting', '2027-03-03T14:30', '2027-03-03T15:30', false, '' ) );
check( 'NL range', $nl( 'Choir practice every Monday 7-9pm' ), array( 'Choir practice', '2026-11-16T19:00', '2026-11-16T21:00', false, 'FREQ=WEEKLY;BYDAY=MO' ) );
check( 'NL ordinal', $nl( 'Neighbourhood forum on the second Tuesday of every month at 19:30' ), array( 'Neighbourhood forum', '2026-12-08T19:30', '2026-12-08T20:30', false, 'FREQ=MONTHLY;BYDAY=2TU' ) );
check( 'NL month day', $nl( 'Rent due on the 1st of every month' ), array( 'Rent due', '2026-12-01T00:00', '2026-12-01T23:59', true, 'FREQ=MONTHLY;BYMONTHDAY=1' ) );
check( 'NL all day', $nl( 'Summer fair 14 June 2027' ), array( 'Summer fair', '2027-06-14T00:00', '2027-06-14T23:59', true, '' ) );
check( 'NL tomorrow', $nl( 'Coffee morning tomorrow at 10am' ), array( 'Coffee morning', '2026-11-14T10:00', '2026-11-14T11:00', false, '' ) );
check( 'NL count', $nl( 'Pottery class every Wednesday at 6:30pm for 8 weeks' ), array( 'Pottery class', '2026-11-18T18:30', '2026-11-18T19:30', false, 'FREQ=WEEKLY;BYDAY=WE;COUNT=8' ) );
check( 'NL weekdays', $nl( 'Breakfast club weekdays 8am to 9am' ), array( 'Breakfast club', '2026-11-13T08:00', '2026-11-13T09:00', false, 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR' ) );
check( 'NL other week', $nl( 'Book group every other week on Thursdays at 7pm' ), array( 'Book group', '2026-11-19T19:00', '2026-11-19T20:00', false, 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TH' ) );
check( 'NL friday', $nl( 'Quiz night Friday 8pm' ), array( 'Quiz night', '2026-11-13T20:00', '2026-11-13T21:00', false, '' ) );

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASSED\n";
