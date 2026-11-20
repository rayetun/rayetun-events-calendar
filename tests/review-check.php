<?php
/**
 * Checks for the points raised in the plugin review, against a real
 * WordPress database: who sees which events in the dashboard, who may
 * import, what an import may change, and the batched daily date job.
 *
 * Usage: php tests/review-check.php <path-to-wordpress> [plugins-folder]
 *
 * The optional plugins folder is used in place of wp-content/plugins, so the
 * checks can run against another copy of the plugin than the site's own.
 *
 * Makes its own users and events, and deletes them all at the end,
 * including anything an interrupted run left behind.
 *
 * @package RayEtun\EventsCalendar
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line test script.

$_SERVER['HTTP_HOST']   = 'pinspot-dev.local';
$_SERVER['REQUEST_URI'] = '/';
if ( ! empty( $argv[2] ) ) {
	define( 'WP_PLUGIN_DIR', $argv[2] );
}
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use RayEtun\EventsCalendar\Event;
use RayEtun\EventsCalendar\Import;
use RayEtun\EventsCalendar\Occurrences;
use RayEtun\EventsCalendar\Post_Types;

const PREFIX = 'Review check:';
const LOGIN  = 'rayec_review_check_';

/**
 * Remove everything this script makes.
 *
 * @return void
 */
function rayec_review_check_tidy(): void {
	wp_set_current_user( 0 );
	foreach ( get_posts(
		array(
			'post_type'      => Post_Types::EVENT,
			'post_status'    => 'any',
			's'              => PREFIX,
			'fields'         => 'ids',
			'posts_per_page' => 100,
		)
	) as $stale ) {
		if ( 0 === strpos( (string) get_post_field( 'post_title', $stale ), PREFIX ) ) {
			wp_delete_post( $stale, true );
		}
	}
	foreach ( array( 'admin', 'editor', 'author', 'other', 'contributor' ) as $role ) {
		$user = get_user_by( 'login', LOGIN . $role );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
	}
	foreach ( array( 'new category', 'another category' ) as $name ) {
		$term = get_term_by( 'name', PREFIX . ' ' . $name, Post_Types::CATEGORY );
		if ( $term ) {
			wp_delete_term( $term->term_id, Post_Types::CATEGORY );
		}
	}
	wp_clear_scheduled_hook( Occurrences::BATCH_HOOK );
}
rayec_review_check_tidy();

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

$check( 'the copy of the plugin under test is loaded', class_exists( Occurrences::class ) && defined( Occurrences::class . '::BATCH_HOOK' ), RAYEC_FILE );
echo '        from ' . dirname( RAYEC_FILE ) . "\n";

/*
 * Users, one per role, and events owned by them.
 */
$users = array();
foreach ( array(
	'admin'       => 'administrator',
	'editor'      => 'editor',
	'author'      => 'author',
	'other'       => 'author',
	'contributor' => 'contributor',
) as $key => $role ) {
	$users[ $key ] = (int) wp_insert_user(
		array(
			'user_login' => LOGIN . $key,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => LOGIN . $key . '@example.invalid',
			'role'       => $role,
		)
	);
}
$check( 'test users made', 5 === count( array_filter( $users ) ) );

$start = static fn( string $when ) => ( new DateTimeImmutable( $when, wp_timezone() ) )->format( 'Y-m-d\T18:00' );
$make  = static function ( string $owner, string $title, string $status, array $more = array() ) use ( $users, $start ) {
	wp_set_current_user( $users[ $owner ] );
	$id = Event::save(
		array_merge(
			array(
				'title'       => PREFIX . ' ' . $title,
				'start'       => $start( '+2 days' ),
				'end'         => substr( $start( '+2 days' ), 0, 11 ) . '20:00',
				'post_status' => $status,
			),
			$more
		)
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$other_draft     = $make( 'other', 'Other draft', 'draft' );
$other_pending   = $make( 'other', 'Other pending', 'pending' );
$other_published = $make( 'other', 'Other published', 'publish' );
$own_draft       = $make( 'author', 'Own draft', 'draft' );
$admin_private   = $make( 'admin', 'Admin private', 'private' );
$check( 'events made', $other_draft && $other_pending && $other_published && $own_draft && $admin_private );

/*
 * Who sees what.
 */
echo "\nThe dashboard shows unpublished events only to those who may edit them\n";
$titles = static function ( array $items ): array {
	$found = array();
	foreach ( $items as $item ) {
		// Private events come back as "Private: ..." titles.
		$at = strpos( (string) $item['title'], PREFIX );
		if ( false !== $at ) {
			$found[] = substr( (string) $item['title'], $at + strlen( PREFIX ) + 1 );
		}
	}
	sort( $found );
	return $found;
};
$rest   = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/rayec/v1' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	return rest_do_request( $request );
};
$range  = array(
	'start' => wp_date( 'Y-m-d' ),
	'end'   => wp_date( 'Y-m-d', strtotime( '+7 days' ) ),
);

wp_set_current_user( $users['author'] );
$list = $titles( $rest( 'GET', '/events', array( 'per_page' => 100 ) )->get_data()['items'] );
$plan = $titles( $rest( 'GET', '/occurrences', $range )->get_data() );
$home = $rest( 'GET', '/dashboard' )->get_data();
$seen = $titles( array_merge( $home['today'], $home['week'] ) );
$check( 'an author\'s event list: published events and their own draft', array( 'Other published', 'Own draft' ) === $list, implode( ', ', $list ) );
$check( 'an author\'s planner: the same', array( 'Other published', 'Own draft' ) === $plan, implode( ', ', $plan ) );
$check( 'an author\'s Home screen: the same, and only their own drafts counted', array( 'Other published', 'Own draft' ) === $seen && 1 === $home['stats']['drafts'], implode( ', ', $seen ) . '; drafts ' . $home['stats']['drafts'] );
$draft = $rest( 'GET', '/events', array( 'status' => 'draft' ) )->get_data()['items'];
$check( 'asking for drafts by name changes nothing', array( 'Own draft' ) === $titles( $draft ), implode( ', ', $titles( $draft ) ) );

wp_set_current_user( $users['editor'] );
$list = $titles( $rest( 'GET', '/events', array( 'per_page' => 100 ) )->get_data()['items'] );
$check( 'an editor sees every event, as in the posts screen', array( 'Admin private', 'Other draft', 'Other pending', 'Other published', 'Own draft' ) === $list, implode( ', ', $list ) );

/*
 * A clash with an event the user may not see does not give its title away.
 */
wp_set_current_user( $users['author'] );
$venue = (int) wp_insert_post(
	array(
		'post_type'   => Post_Types::VENUE,
		'post_title'  => PREFIX . ' Hall',
		'post_status' => 'publish',
	)
);
wp_set_current_user( $users['other'] );
Event::save( array( 'venue_id' => $venue ), $other_draft );
wp_set_current_user( $users['author'] );
$preview = $rest(
	'POST',
	'/preview',
	array(
		'start'    => $start( '+2 days' ),
		'end'      => substr( $start( '+2 days' ), 0, 11 ) . '20:00',
		'venue_id' => $venue,
	)
)->get_data();
$clash   = wp_list_pluck( (array) ( $preview['conflicts'] ?? array() ), 'title' );
$check( 'a venue clash with someone else\'s draft is named "Another event"', in_array( 'Another event', $clash, true ) && ! in_array( PREFIX . ' Other draft', $clash, true ), implode( ', ', $clash ) );
wp_delete_post( $venue, true );

/*
 * Who may import.
 */
echo "\nImport and calendar feeds are for administrators\n";
foreach ( array(
	array( 'POST', '/import/read' ),
	array( 'POST', '/import/run' ),
	array( 'GET', '/feeds' ),
	array( 'POST', '/feeds' ),
) as $route ) {
	wp_set_current_user( $users['editor'] );
	$as_editor = $rest( $route[0], $route[1] )->get_status();
	wp_set_current_user( $users['admin'] );
	$as_admin = $rest( $route[0], $route[1] )->get_status();
	$check( "{$route[0]} {$route[1]}: refused for an editor, open to an administrator", 403 === $as_editor && 403 !== $as_admin, "editor {$as_editor}, admin {$as_admin}" );
}

/*
 * What an import may change.
 */
echo "\nAn import changes only events its user may edit\n";
update_post_meta( $other_published, Import::UID_META, 'review-check-uid' );
update_post_meta( $other_published, Import::SOURCE_META, 'review-check' );
$item = array(
	'uid'         => 'review-check-uid',
	'title'       => PREFIX . ' Renamed by import',
	'description' => '',
	'start'       => $start( '+3 days' ),
	'end'         => substr( $start( '+3 days' ), 0, 11 ) . '20:00',
	'all_day'     => false,
	'timezone'    => '',
	'rrule'       => '',
	'exdates'     => array(),
	'location'    => '',
	'url'         => '',
	'categories'  => array( PREFIX . ' new category' ),
	'status'      => 'scheduled',
);

wp_set_current_user( $users['author'] );
$result = Import::run(
	array( $item ),
	array(
		'source'         => 'review-check',
		'use_categories' => true,
	)
);
$check( 'someone else\'s event is left as it was, and reported', 0 === $result['updated'] && 1 === count( $result['failed'] ) && PREFIX . ' Other published' === get_the_title( $other_published ), wp_json_encode( $result ) );

wp_set_current_user( $users['admin'] );
$result = Import::run(
	array( $item ),
	array(
		'source'         => 'review-check',
		'use_categories' => true,
	)
);
$check( 'an administrator\'s import updates it', 1 === $result['updated'] && PREFIX . ' Renamed by import' === get_the_title( $other_published ), wp_json_encode( $result ) );

wp_set_current_user( $users['contributor'] );
$fresh  = array_merge(
	$item,
	array(
		'uid'        => 'review-check-new',
		'title'      => PREFIX . ' Sent for review',
		'categories' => array( PREFIX . ' another category' ),
	)
);
$result = Import::run(
	array( $fresh ),
	array(
		'source'         => 'review-check',
		'post_status'    => 'publish',
		'use_categories' => true,
	)
);
$new    = (int) ( $result['ids'][0] ?? 0 );
$check( 'someone who cannot publish adds events for review', $new && 'pending' === get_post_status( $new ), $new ? get_post_status( $new ) : 'nothing added' );
$check( 'and cannot make new categories on the way', false === get_term_by( 'name', PREFIX . ' another category', Post_Types::CATEGORY ) );

/*
 * The daily job.
 */
echo "\nThe daily job extends repeating events a batch at a time\n";
wp_set_current_user( $users['admin'] );
$weekly   = $make( 'admin', 'Weekly for ever', 'publish', array( 'rrule' => 'FREQ=WEEKLY' ) );
$counted  = $make( 'admin', 'Five times', 'publish', array( 'rrule' => 'FREQ=WEEKLY;COUNT=5' ) );
$soon_end = $make( 'admin', 'Ends in three months', 'publish', array( 'rrule' => 'FREQ=WEEKLY;UNTIL=' . gmdate( 'Ymd', strtotime( '+3 months' ) ) . 'T235959Z' ) );
$far_end  = $make( 'admin', 'Ends in five years', 'publish', array( 'rrule' => 'FREQ=MONTHLY;UNTIL=' . gmdate( 'Ymd', strtotime( '+5 years' ) ) . 'T235959Z' ) );
$reach    = static fn( int $id ) => (string) get_post_meta( $id, Occurrences::REACH_META, true );
$horizon  = gmdate( 'Y-m-d', strtotime( '+' . Occurrences::WINDOW_MONTHS . ' months' ) );

$check( 'a series with no end notes how far its dates reach', abs( strtotime( $reach( $weekly ) ) - strtotime( $horizon ) ) <= 2 * DAY_IN_SECONDS, $reach( $weekly ) );
$check( 'a series with a count, all inside the window, is complete', 'complete' === $reach( $counted ), $reach( $counted ) );
$check( 'a series ending inside the window is complete', 'complete' === $reach( $soon_end ), $reach( $soon_end ) );
$check( 'a series ending beyond the window is not', 'complete' !== $reach( $far_end ) && '' !== $reach( $far_end ), $reach( $far_end ) );
$check( 'a one-off event is not tracked', '' === $reach( $own_draft ) );

$due = Occurrences::series_to_extend( 5000 );
$check( 'freshly built series are not due', ! array_intersect( array( $weekly, $counted, $soon_end, $far_end ), $due ) );

update_post_meta( $weekly, Occurrences::REACH_META, gmdate( 'Y-m-d', strtotime( '+20 months' ) ) );
delete_post_meta( $far_end, Occurrences::REACH_META );
$due = Occurrences::series_to_extend( 5000 );
$check( 'a series built over a month ago, or never noted, is due', in_array( $weekly, $due, true ) && in_array( $far_end, $due, true ) && ! in_array( $counted, $due, true ), implode( ', ', array_intersect( $due, array( $weekly, $counted, $soon_end, $far_end ) ) ) );

// One series per batch: the job rebuilds one and books the next batch.
$one = static fn() => 1;
add_filter( 'rayec_extend_batch_size', $one );
wp_clear_scheduled_hook( Occurrences::BATCH_HOOK );
$before = count( Occurrences::series_to_extend( 5000 ) );
Occurrences::extend_all();
$after = count( Occurrences::series_to_extend( 5000 ) );
$check( 'one batch rebuilds one series and books the next batch', $after === $before - 1 && false !== wp_next_scheduled( Occurrences::BATCH_HOOK ), "{$before} due, then {$after}" );
remove_filter( 'rayec_extend_batch_size', $one );

// Work through what is left, as the booked batches would.
$runs = 0;
while ( Occurrences::series_to_extend( 1 ) && $runs < 200 ) {
	wp_clear_scheduled_hook( Occurrences::BATCH_HOOK );
	Occurrences::extend_all();
	++$runs;
}
$check( 'batches end once nothing is due, with no batch left booked', ! Occurrences::series_to_extend( 1 ) && false === wp_next_scheduled( Occurrences::BATCH_HOOK ) && $reach( $weekly ) >= gmdate( 'Y-m-d', strtotime( '+23 months' ) ), "{$runs} runs" );

/*
 * Tidy up.
 */
rayec_review_check_tidy();
$check( 'everything made is gone', ! get_post( $weekly ) && ! get_user_by( 'login', LOGIN . 'admin' ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
