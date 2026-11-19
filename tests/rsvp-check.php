<?php
/**
 * Registration checks against a real WordPress database.
 *
 * Usage: php tests/rsvp-check.php <path-to-wordpress>
 *
 * Makes its own events, registers people against them, checks the places on
 * each date, and deletes everything it made. No email leaves the machine:
 * every message is caught before sending and inspected instead.
 *
 * @package RayEtun\EventsCalendar
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line test script.

$_SERVER['HTTP_HOST']   = 'pinspot-dev.local';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';

use RayEtun\EventsCalendar\Event;
use RayEtun\EventsCalendar\Ics;
use RayEtun\EventsCalendar\Occurrences;
use RayEtun\EventsCalendar\Registrations;

global $wpdb;

// Publishing needs an editor; without one, Event::save() files events as pending.
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

// Anything an interrupted earlier run left behind.
foreach ( get_posts(
	array(
		'post_type'      => 'rayec_event',
		'post_status'    => 'any',
		's'              => 'RSVP check:',
		'fields'         => 'ids',
		'posts_per_page' => 20,
	)
) as $stale ) {
	if ( 0 === strpos( get_the_title( $stale ), 'RSVP check:' ) ) {
		wp_delete_post( $stale, true );
	}
}

$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);

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

$tz       = wp_timezone();
$tomorrow = ( new DateTimeImmutable( 'tomorrow 18:00', $tz ) )->format( 'Y-m-d\TH:i' );
$made     = array();

/*
 * One-off event: 3 places, up to 2 each.
 */
echo "One-off event, 3 places, up to 2 per booking\n";
$id     = Event::save(
	array(
		'title'       => 'RSVP check: one-off',
		'start'       => $tomorrow,
		'timezone'    => $tz->getName(),
		'post_status' => 'publish',
		'rsvp'        => array(
			'enabled'  => true,
			'capacity' => 3,
			'max'      => 2,
			'access'   => true,
		),
	)
);
$made[] = $id;
Event::forget();
$event = Event::get( $id );
$dates = Registrations::upcoming_dates( $event );
$start = $dates[0]['start'];
$check( 'settings saved with the event', 3 === $event['rsvp']['capacity'] && 2 === $event['rsvp']['max'] && $event['rsvp']['enabled'] );
$check( 'one date offered, 3 places left', 1 === count( $dates ) && 3 === $dates[0]['left'] );

$mails = array();
$a     = Registrations::register(
	$event,
	array(
		'start'  => $start,
		'name'   => 'Ada Example',
		'email'  => 'ada@example.com',
		'places' => 2,
		'access' => 'Step-free entrance, please.',
	)
);
$check( 'Ada books 2 places', is_array( $a ) && 'confirmed' === $a['status'] && ! $a['existing'] );
if ( ! is_array( $a ) ) {
	echo '  stopped: ' . $a->get_error_message() . '
';
	exit( 1 );
}
$check( 'Ada is emailed, and so is the organiser', 2 === count( $mails ) && 'ada@example.com' === $mails[0]['to'] );
$check( 'the email carries a working cancel link', false !== strpos( (string) $mails[0]['message'], 'rayec_key=' . $a['token'] ) );
$check( 'the email repeats the access request', false !== strpos( (string) $mails[0]['message'], 'Step-free entrance' ) );

$b = Registrations::register(
	$event,
	array(
		'start'  => $start,
		'name'   => 'Ben Example',
		'email'  => 'ben@example.com',
		'places' => 2,
	)
);
$check( 'Ben cannot have 2 when 1 is left', is_wp_error( $b ) && 'rayec_rsvp_places' === $b->get_error_code() );

$b = Registrations::register(
	$event,
	array(
		'start'  => $start,
		'name'   => 'Ben Example',
		'email'  => 'ben@example.com',
		'places' => 1,
	)
);
$check( 'Ben books the last place', is_array( $b ) && 'confirmed' === $b['status'] );
$taken = Registrations::taken( $id, array( $start ) );
$check( 'the date now holds 3', 3 === $taken[ $start ] );

$c = Registrations::register(
	$event,
	array(
		'start' => $start,
		'name'  => 'Cy Example',
		'email' => 'cy@example.com',
	)
);
$check( 'Cy is told the date is full', is_wp_error( $c ) && 'rayec_rsvp_full' === $c->get_error_code() );

$mails = array();
$again = Registrations::register(
	$event,
	array(
		'start' => $start,
		'name'  => 'Ada Again',
		'email' => 'ada@example.com',
	)
);
$check( 'Ada registering again makes no second booking', is_array( $again ) && $again['existing'] && $again['id'] === $a['id'] );
$check( 'and her details are re-sent to her own address only', 1 === count( $mails ) && 'ada@example.com' === $mails[0]['to'] );
$mails = array();
Registrations::register(
	$event,
	array(
		'start' => $start,
		'name'  => 'Ada Again',
		'email' => 'ada@example.com',
	)
);
$check( 'a third try within minutes sends nothing', 0 === count( $mails ) );
$check( 'the old cancel link stopped working when the details were re-sent', is_wp_error( Registrations::cancel_with_token( $a['id'], $a['token'] ) ) );

$check( 'a wrong key cannot cancel Ben', is_wp_error( Registrations::cancel_with_token( $b['id'], 'not-the-key' ) ) );
$mails     = array();
$cancelled = Registrations::cancel_with_token( $b['id'], $b['token'] );
$check( 'Ben cancels with his own link', is_object( $cancelled ) && 'cancelled' === $cancelled->status );
$taken = Registrations::taken( $id, array( $start ) );
$check( 'his place is free again', 2 === $taken[ $start ] );
$check( 'Ben and the organiser are both told', 2 === count( $mails ) );
$check( 'the same link cannot cancel twice', is_wp_error( Registrations::cancel_with_token( $b['id'], $b['token'] ) ) );

$ics = Ics::single( $event, ...Occurrences::times( Registrations::upcoming_date( $id, $start ) ) );
$check( 'the calendar file is one date, with no repeat rule', false !== strpos( $ics, 'BEGIN:VEVENT' ) && false === strpos( $ics, 'RRULE' ) && 1 === substr_count( $ics, 'DTSTART' ) );

/*
 * The one-off event changes.
 */
echo "\nWhen the one-off event changes\n";
$job = static function ( int $event_id, string $kind, $check ) {
	$args = array( $event_id, $kind, $check );
	$at   = wp_next_scheduled( Registrations::NOTICE_HOOK, $args );
	if ( $at ) {
		wp_unschedule_event( $at, Registrations::NOTICE_HOOK, $args );
	}
	return (bool) $at;
};

$later = ( new DateTimeImmutable( 'tomorrow 19:30', $tz ) )->format( 'Y-m-d\TH:i' );
Event::save( array( 'start' => $later ), $id );
Event::forget();
$event = Event::get( $id );
$ada   = Registrations::get( $a['id'] );
$check( 'moving the event to 7.30pm takes the booking with it', '19:30:00' === substr( $ada->occurrence_start, 11 ) );
$taken = Registrations::taken( $id, array( $ada->occurrence_start ) );
$check( 'and the places are counted at the new time', 2 === $taken[ $ada->occurrence_start ] );
$check( 'a "new time" email is booked, a minute later', $job( $id, 'moved', str_replace( 'T', ' ', $later ) ) );
$mails = array();
$check( 'it goes to Ada only (Ben had cancelled)', 1 === Registrations::send_notices( $id, 'moved', str_replace( 'T', ' ', $later ) ) && 'ada@example.com' === $mails[0]['to'] && 0 === strpos( $mails[0]['subject'], 'New time' ) );
$check( 'and carries a fresh cancel link and the new time', false !== strpos( $mails[0]['message'], 'rayec_key=' ) && false !== strpos( $mails[0]['message'], '7:30' ) );

Event::save( array( 'event_status' => 'cancelled' ), $id );
$check( 'cancelling the event books a "cancelled" email', $job( $id, 'status', 'cancelled' ) );
$mails = array();
$check( 'which tells Ada', 1 === Registrations::send_notices( $id, 'status', 'cancelled' ) && 0 === strpos( $mails[0]['subject'], 'Cancelled' ) );

update_post_meta( $id, '_rayec_status', 'postponed' );
$check( 'a status written straight to the meta (bulk actions, block editor) is noticed too', $job( $id, 'status', 'postponed' ) );
update_post_meta( $id, '_rayec_status', 'scheduled' );
$job( $id, 'status', 'scheduled' );
Event::forget();
$mails = array();
$check( 'a status changed back within the minute sends nothing for the slip', 0 === Registrations::send_notices( $id, 'status', 'postponed' ) && 0 === count( $mails ) );
$check( 'but saying it is going ahead does go out', 1 === Registrations::send_notices( $id, 'status', 'scheduled' ) && 0 === strpos( $mails[0]['subject'], 'Going ahead' ) );

update_post_meta( $id, '_rayec_status', 'postponed' );
update_post_meta( $id, '_rayec_status', 'scheduled' );
$job( $id, 'status', 'postponed' );
$job( $id, 'status', 'scheduled' );
Event::forget();
$mails = array();
$check( 'postponed and put straight back: nobody hears anything at all', 0 === Registrations::send_notices( $id, 'status', 'postponed' ) && 0 === Registrations::send_notices( $id, 'status', 'scheduled' ) && 0 === count( $mails ) );

$there = ( new DateTimeImmutable( 'tomorrow 20:00', $tz ) )->format( 'Y-m-d\TH:i' );
Event::save( array( 'start' => $there ), $id );
Event::save( array( 'start' => $later ), $id );
$job( $id, 'moved', str_replace( 'T', ' ', $there ) );
$job( $id, 'moved', str_replace( 'T', ' ', $later ) );
Event::forget();
$mails = array();
$check( 'moved and moved straight back: nobody hears anything either', 0 === Registrations::send_notices( $id, 'moved', str_replace( 'T', ' ', $there ) ) && 0 === Registrations::send_notices( $id, 'moved', str_replace( 'T', ' ', $later ) ) && 0 === count( $mails ) && '19:30:00' === substr( Registrations::get( $a['id'] )->occurrence_start, 11 ) );

/*
 * Weekly series: 1 place per date.
 */
echo "\nWeekly series, 1 place on each date\n";
$sid    = Event::save(
	array(
		'title'       => 'RSVP check: weekly',
		'start'       => $tomorrow,
		'timezone'    => $tz->getName(),
		'rrule'       => 'FREQ=WEEKLY;COUNT=4',
		'post_status' => 'publish',
		'rsvp'        => array(
			'enabled'  => true,
			'capacity' => 1,
		),
	)
);
$made[] = $sid;
Event::forget();
$series = Event::get( $sid );
$dates  = Registrations::upcoming_dates( $series );
$check( 'four dates offered', 4 === count( $dates ) );

$x = Registrations::register(
	$series,
	array(
		'start' => $dates[0]['start'],
		'name'  => 'Xan',
		'email' => 'xan@example.com',
	)
);
$y = Registrations::register(
	$series,
	array(
		'start' => $dates[0]['start'],
		'name'  => 'Yas',
		'email' => 'yas@example.com',
	)
);
$z = Registrations::register(
	$series,
	array(
		'start' => $dates[1]['start'],
		'name'  => 'Yas',
		'email' => 'yas@example.com',
	)
);
$check( 'the first date takes Xan', is_array( $x ) );
$check( 'the first date is then full for Yas', is_wp_error( $y ) && 'rayec_rsvp_full' === $y->get_error_code() );
$check( 'but the second date still has its own place', is_array( $z ) );

// Saving rebuilds every occurrence row; the bookings must survive it.
Event::save( array( 'title' => 'RSVP check: weekly (renamed)' ), $sid );
Event::forget();
$series = Event::get( $sid );
$after  = Registrations::upcoming_dates( $series );
$check( 'after the series is saved again, the first date is still full', 0 === $after[0]['left'] && 0 === $after[1]['left'] && 1 === $after[2]['left'] );

$check(
	'a date that is not in the series is refused',
	is_wp_error(
		Registrations::register(
			$series,
			array(
				'start' => '2001-01-01 18:00:00',
				'name'  => 'Old',
				'email' => 'old@example.com',
			)
		)
	)
);


// The series moves to 7pm: each booking keeps its date.
$yas_before = Registrations::get( $z['id'] )->occurrence_start;
Event::save( array( 'start' => ( new DateTimeImmutable( 'tomorrow 19:00', $tz ) )->format( 'Y-m-d\TH:i' ) ), $sid );
Event::forget();
$series = Event::get( $sid );
$yas    = Registrations::get( $z['id'] );
$check( 'moving the series to 7pm keeps Yas on her date, at 7pm', substr( $yas->occurrence_start, 0, 10 ) === substr( $yas_before, 0, 10 ) && '19:00:00' === substr( $yas->occurrence_start, 11 ) );
$job( $sid, 'moved', substr( str_replace( 'T', ' ', $series['start'] ), 0, 16 ) );
$check( 'the 7pm date still counts her place', 0 === Registrations::upcoming_dates( $series )[1]['left'] );

$rest = static function ( string $route, array $params ) use ( $sid ) {
	$request = new WP_REST_Request( 'POST', '/rayec/v1/events/' . $sid . '/' . $route );
	$request->set_url_params( array( 'id' => $sid ) );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return 'occurrence' === $route ? \RayEtun\EventsCalendar\Rest::change_occurrence( $request ) : \RayEtun\EventsCalendar\Rest::restore_series( $request );
};

// One date taken out of the series and moved a day later.
$her_day = substr( $yas->occurrence_start, 0, 10 );
$moved   = ( new DateTimeImmutable( $her_day, $tz ) )->modify( '+1 day' )->format( 'Y-m-d' );
$result  = $rest(
	'occurrence',
	array(
		'date'     => $her_day,
		'action'   => 'detach',
		'new_date' => $moved,
	)
);
$yas     = Registrations::get( $z['id'] );
$check( 'moving her date on its own takes her booking to the new event', is_array( $result ) && (int) $yas->event_id === (int) $result['created'] && $moved . ' 19:00:00' === $yas->occurrence_start );
$check( 'and books her a "new time" email', $job( (int) $result['created'], 'moved_ids', array( (int) $z['id'] ) ) );
$rest( 'series-restore', $result['undo'] );
$yas = Registrations::get( $z['id'] );
$check( 'undo puts her back on the series, before the moved date is deleted', $yas && (int) $yas->event_id === $sid && $her_day . ' 19:00:00' === $yas->occurrence_start && null === get_post( $result['created'] ) );

// Her date skipped.
$result = $rest(
	'occurrence',
	array(
		'date'   => $her_day,
		'action' => 'skip',
	)
);
$check( 'skipping her date cancels her booking', 'cancelled' === Registrations::get( $z['id'] )->status );
$check( 'and books a "date cancelled" email', $job( $sid, 'dropped', array( (int) $z['id'] ) ) );
$rest( 'series-restore', $result['undo'] );
$mails = array();
$check( 'undo gives her place back, and the email then has nothing to say', 'confirmed' === Registrations::get( $z['id'] )->status && 0 === Registrations::send_notices( $sid, 'dropped', array( (int) $z['id'] ) ) );

$result = $rest(
	'occurrence',
	array(
		'date'   => $her_day,
		'action' => 'skip',
	)
);
$job( $sid, 'dropped', array( (int) $z['id'] ) );
$mails = array();
$check( 'skipped for real, she is told', 1 === Registrations::send_notices( $sid, 'dropped', array( (int) $z['id'] ) ) && 0 === strpos( $mails[0]['subject'], 'Date cancelled' ) );
$rest( 'series-restore', $result['undo'] );
Event::forget();
$series = Event::get( $sid );
$after  = Registrations::upcoming_dates( $series );

Event::save( array( 'event_status' => 'postponed' ), $sid );
Event::forget();
$series = Event::get( $sid );
$p      = Registrations::register(
	$series,
	array(
		'start' => $after[3]['start'],
		'name'  => 'Pat',
		'email' => 'pat@example.com',
	)
);
$check( 'a postponed event takes no registrations', is_wp_error( $p ) && 'rayec_rsvp_closed' === $p->get_error_code() );

/*
 * Places on the calendar.
 */
echo "\nPlaces on the calendar\n";
$twelve = array( 'rsvp' => array( 'capacity' => 12 ) );
$check( 'with 12 places, 3 left is worth saying and 4 is not', Registrations::few_left( $twelve, 3 ) && ! Registrations::few_left( $twelve, 4 ) );
$check( 'a full date is not "few left": it says Full instead', ! Registrations::few_left( $twelve, 0 ) );
$check( 'with 100 places, a fifth is the line', Registrations::few_left( array( 'rsvp' => array( 'capacity' => 100 ) ), 20 ) && ! Registrations::few_left( array( 'rsvp' => array( 'capacity' => 100 ) ), 21 ) );
$check( 'with no limit, nothing is ever "few left"', ! Registrations::few_left( array( 'rsvp' => array( 'capacity' => 0 ) ), 1 ) );
$check( 'a postponed event has no booking box beside its page', '' === Registrations::aside( $series, array( new DateTimeImmutable( '+2 days' ), new DateTimeImmutable( '+2 days 1 hour' ) ) ) );

/*
 * The organiser writes to the people booked.
 */
echo "\nEmail the people booked\n";
$note_id    = Event::save(
	array(
		'title'       => 'RSVP check: message',
		'start'       => $tomorrow,
		'timezone'    => $tz->getName(),
		'post_status' => 'publish',
		'rsvp'        => array(
			'enabled'  => true,
			'capacity' => 40,
			'max'      => 1,
		),
	)
);
$made[]     = $note_id;
$note_event = Event::get( $note_id );
$note_start = Registrations::upcoming_dates( $note_event )[0]['start'];
$booked     = array();
for ( $i = 1; $i <= 23; $i++ ) {
	$booked[] = Registrations::register(
		$note_event,
		array(
			'start'  => $note_start,
			'name'   => "Guest {$i}",
			'email'  => "guest{$i}@example.com",
			'places' => 1,
		)
	);
}
Registrations::set_status( $booked[22]['id'], 'cancelled', 'organiser' );
$mails = array();
$sent  = Registrations::send_message( $note_event, '', 'Room change', "We have moved to Room 4.\nSee you there." );
$check( 'it reaches the 22 still booked, 20 at once and 2 later', is_array( $sent ) && 22 === $sent['people'] && 20 === $sent['sent'] && 2 === $sent['queued'], wp_json_encode( $sent ) );
$check( 'one email each, with the subject as written', 20 === count( $mails ) && 'Room change' === $mails[0]['subject'] && 'guest1@example.com' === $mails[0]['to'] );
$check( 'each starts with the name and ends with what they booked', 0 === strpos( $mails[0]['message'], 'Hello Guest 1,' ) && false !== strpos( $mails[0]['message'], 'Room 4' ) && false !== strpos( $mails[0]['message'], 'RSVP check: message' ) && false !== strpos( $mails[0]['message'], 'confirmation email cancels' ) );
$queue = (array) get_option( Registrations::MESSAGE_QUEUE, array() );
$key   = (string) array_key_first( $queue );
$check( 'the rest wait in the queue, with a job booked', 1 === count( $queue ) && 2 === count( $queue[ $key ]['groups'] ) && false !== wp_next_scheduled( Registrations::MESSAGE_HOOK, array( $key ) ) );
// Someone in the second batch cancels before it goes.
Registrations::set_status( $booked[21]['id'], 'cancelled', 'organiser' );
$mails = array();
wp_unschedule_event( (int) wp_next_scheduled( Registrations::MESSAGE_HOOK, array( $key ) ), Registrations::MESSAGE_HOOK, array( $key ) );
$check( 'the next batch skips anyone who cancelled since', 1 === Registrations::send_message_batch( $key ) && 1 === count( $mails ) && 'guest21@example.com' === $mails[0]['to'] );
$check( 'and the queue is empty after it', false === get_option( Registrations::MESSAGE_QUEUE ) && false === wp_next_scheduled( Registrations::MESSAGE_HOOK, array( $key ) ) );
$log = Registrations::messages_sent( $note_event );
$check( 'the message is recorded for the panel', 1 === count( $log ) && 'Room change' === $log[0]['subject'] && 22 === $log[0]['people'] );
$past = Registrations::send_message( $note_event, '2000-01-01 10:00:00', 'Late', 'Too late.' );
$check( 'a date that has passed is refused', is_wp_error( $past ) && 'rayec_past' === $past->get_error_code() );
$none = Registrations::send_message( $note_event, '2099-01-01 10:00:00', 'Nobody', 'Nobody here.' );
$check( 'a date nobody booked is refused', is_wp_error( $none ) && 'rayec_nobody' === $none->get_error_code() );
$rest = new WP_REST_Request( 'POST', '/rayec/v1/events/' . $note_id . '/message' );
$rest->set_body_params(
	array(
		'subject' => '',
		'message' => 'No subject.',
	)
);
$check( 'over REST, a message with no subject is refused', 400 === rest_do_request( $rest )->get_status() );
wp_set_current_user( 0 );
$rest->set_body_params(
	array(
		'subject' => 'Hello',
		'message' => 'From nobody.',
	)
);
$check( 'and someone not signed in cannot send at all', in_array( rest_do_request( $rest )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

/*
 * Personal data.
 */
echo "\nPersonal data\n";
$export = Registrations::export( 'yas@example.com' );
$check( 'export finds every registration for an address', 1 === count( $export['data'] ) && $export['done'] );
$erase = Registrations::erase( 'xan@example.com' );
$row   = Registrations::get( $x['id'] );
$check( 'erase removes the name and address but keeps the place counted', 1 === $erase['items_removed'] && 'xan@example.com' !== $row->email && 'Xan' !== $row->name && 'confirmed' === $row->status );

/*
 * Tidy up.
 */
foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
	foreach ( (array) ( $hooks[ Registrations::NOTICE_HOOK ] ?? array() ) as $scheduled ) {
		if ( in_array( (int) $scheduled['args'][0], $made, true ) ) {
			wp_unschedule_event( $timestamp, Registrations::NOTICE_HOOK, $scheduled['args'] );
		}
	}
}
foreach ( $made as $event_id ) {
	wp_delete_post( $event_id, true );
}
$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Registrations::table() . ' WHERE event_id IN (%d, %d)', $made[0], $made[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
echo "\nTidy up\n";
$check( 'deleting the events deletes their registrations', 0 === $left );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
