<?php
/**
 * Registration: free RSVP, with a place limit on each date.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registrations for event dates.
 *
 * A registration belongs to one date of one event. A date is named by the
 * event's own wall-clock start ("2026-11-18 18:00:00"), not by a row in the
 * occurrence table: those rows are rebuilt every time an event is saved and
 * their IDs change. The local start is what a series keeps across daylight
 * saving, so it is also what identifies a date in it.
 *
 * The place limit applies to each date. A weekly class with 20 places has
 * 20 places every week.
 */
class Registrations {

	const TABLE = 'rayec_registrations';

	/**
	 * Every status a registration can have. "pending" and "waitlisted" are
	 * not set by this plugin; they are here for add-ons that take payment
	 * first or keep a waiting list.
	 */
	const STATUSES = array( 'confirmed', 'pending', 'waitlisted', 'cancelled', 'attended' );

	/**
	 * Statuses that hold a place. A pending registration holds one while the
	 * person pays, so the last place cannot be sold twice.
	 */
	const HOLDING = array( 'confirmed', 'pending', 'attended' );

	/**
	 * Most places one person can book at once.
	 */
	const MAX_PLACES = 10;

	/**
	 * How long a repeat confirmation waits before it can be sent again.
	 */
	const RESEND_AFTER = 10 * MINUTE_IN_SECONDS;

	/**
	 * The scheduled job that tells registered people about a change.
	 */
	const NOTICE_HOOK = 'rayec_tell_registrants';

	/**
	 * How long a change waits before anyone is told about it. Long enough to
	 * undo a slip of the mouse, after which nobody hears about the slip; the
	 * job checks the change still stands before it sends anything.
	 */
	const GRACE = MINUTE_IN_SECONDS;

	/**
	 * Statuses whose holders are expecting to come.
	 */
	const EXPECTING = array( 'confirmed', 'pending', 'waitlisted' );

	/**
	 * What the form needs to draw itself again after a failed submission.
	 *
	 * @var array<string, mixed>
	 */
	private static array $form = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rayec_event_status_changed', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( self::NOTICE_HOOK, array( __CLASS__, 'send_notices' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_event' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
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

	/*
	 * ---------------------------------------------------------------------
	 * Settings on the event
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Registration settings for a new event.
	 *
	 * @return array{enabled: bool, capacity: int, max: int, access: bool}
	 */
	public static function defaults(): array {
		return array(
			'enabled'  => false,
			'capacity' => 0,
			'max'      => 1,
			'access'   => true,
		);
	}

	/**
	 * Registration settings for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array{enabled: bool, capacity: int, max: int, access: bool}
	 */
	public static function settings( int $event_id ): array {
		$max    = (int) get_post_meta( $event_id, '_rayec_rsvp_max', true );
		$access = get_post_meta( $event_id, '_rayec_rsvp_access', true );

		return array(
			'enabled'  => (bool) get_post_meta( $event_id, '_rayec_rsvp', true ),
			'capacity' => max( 0, (int) get_post_meta( $event_id, '_rayec_rsvp_capacity', true ) ),
			'max'      => $max ? max( 1, min( self::MAX_PLACES, $max ) ) : 1,
			// Asking about access needs is on unless someone switched it off.
			'access'   => '' === $access ? true : (bool) $access,
		);
	}

	/**
	 * Save registration settings sent with an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $raw      Submitted settings.
	 * @return void
	 */
	public static function save_settings( int $event_id, array $raw ): void {
		if ( array_key_exists( 'enabled', $raw ) ) {
			update_post_meta( $event_id, '_rayec_rsvp', $raw['enabled'] ? 1 : 0 );
		}
		if ( array_key_exists( 'capacity', $raw ) ) {
			update_post_meta( $event_id, '_rayec_rsvp_capacity', max( 0, min( 100000, (int) $raw['capacity'] ) ) );
		}
		if ( array_key_exists( 'max', $raw ) ) {
			update_post_meta( $event_id, '_rayec_rsvp_max', max( 1, min( self::MAX_PLACES, (int) $raw['max'] ) ) );
		}
		if ( array_key_exists( 'access', $raw ) ) {
			update_post_meta( $event_id, '_rayec_rsvp_access', $raw['access'] ? 1 : 0 );
		}
	}

	/**
	 * Whether an event is taking registrations at all.
	 *
	 * Postponed and cancelled events are not: there is no date to come to.
	 * An event that moved online still is.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return bool
	 */
	public static function is_open( array $event ): bool {
		return ! empty( $event['rsvp']['enabled'] )
			&& 'publish' === $event['post_status']
			&& in_array( $event['event_status'], array( 'scheduled', 'moved_online' ), true );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Dates and places
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The event's own wall-clock start for a moment, which is how a date is named.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param DateTimeImmutable    $start A start, in any time zone.
	 * @return string "Y-m-d H:i:s".
	 */
	public static function local_start( array $event, DateTimeImmutable $start ): string {
		if ( ! empty( $event['all_day'] ) ) {
			// All-day dates are stored as they are written, whatever the zone.
			return $start->format( 'Y-m-d' ) . ' 00:00:00';
		}
		return $start->setTimezone( Event::timezone( (string) $event['timezone'] ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * One date of an event that is still to come.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $start    Local start, "Y-m-d H:i:s".
	 * @return object|null Occurrence row.
	 */
	public static function upcoming_date( int $event_id, string $start ): ?object {
		global $wpdb;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start ) ) {
			return null;
		}
		$table = $wpdb->prefix . Occurrences::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND start_local = %s AND start_utc > %s LIMIT 1", $event_id, $start, gmdate( 'Y-m-d H:i:s' ) ) );
		return $row ? $row : null;
	}

	/**
	 * Places held on each date of an event.
	 *
	 * @param int      $event_id Event ID.
	 * @param string[] $starts   Dates to report even when nobody has registered.
	 * @return array<string, int> Local start => places held.
	 */
	public static function taken( int $event_id, array $starts = array() ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT occurrence_start, SUM(places) AS taken FROM {$table} WHERE event_id = %d AND status IN (%s, %s, %s) GROUP BY occurrence_start", $event_id, self::HOLDING[0], self::HOLDING[1], self::HOLDING[2] ) );

		$out = array_fill_keys( $starts, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->occurrence_start ] = (int) $row->taken;
		}
		return $out;
	}

	/**
	 * The next dates someone could register for, with the places left on each.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param int                  $limit How many dates.
	 * @return array<int, array{start: string, label: string, short: string, taken: int, left: int|null}>
	 */
	public static function upcoming_dates( array $event, int $limit = 12 ): array {
		global $wpdb;

		$table = $wpdb->prefix . Occurrences::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND start_utc > %s ORDER BY start_utc ASC LIMIT %d", (int) $event['id'], gmdate( 'Y-m-d H:i:s' ), $limit ) );
		if ( ! $rows ) {
			return array();
		}

		$taken    = self::taken( (int) $event['id'], wp_list_pluck( $rows, 'start_local' ) );
		$capacity = (int) ( $event['rsvp']['capacity'] ?? 0 );
		$out      = array();
		$format   = (string) get_option( 'date_format', 'F j, Y' ) . ( $event['all_day'] ? '' : ', ' . Plugin::time_format() );
		foreach ( $rows as $row ) {
			$times = Occurrences::times( $row );
			$held  = $taken[ $row->start_local ] ?? 0;
			$out[] = array(
				'start' => (string) $row->start_local,
				'label' => Occurrences::format_range( $times[0], $times[1], (bool) $event['all_day'] ),
				// The start alone, for the date picker, where the whole range does not fit.
				'short' => wp_date( 'l, ' . $format, $times[0]->getTimestamp(), $times[0]->getTimezone() ),
				'taken' => $held,
				'left'  => $capacity ? max( 0, $capacity - $held ) : null,
			);
		}
		return $out;
	}

	/**
	 * A readable "when" for a date, past or to come.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param string               $start Local start.
	 * @return string
	 */
	public static function when( array $event, string $start ): string {
		global $wpdb;

		$table = $wpdb->prefix . Occurrences::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND start_local = %s LIMIT 1", (int) $event['id'], $start ) );
		if ( $row ) {
			$times = Occurrences::times( $row );
			return Occurrences::format_range( $times[0], $times[1], (bool) $event['all_day'] );
		}

		// The date has since been moved or removed from the series.
		$tz    = Event::timezone( (string) $event['timezone'] );
		$local = Event::local_datetime( $start, $tz );
		if ( ! $local ) {
			return $start;
		}
		$format = (string) get_option( 'date_format', 'F j, Y' ) . ( $event['all_day'] ? '' : ' ' . Plugin::time_format() );
		return wp_date( 'l, ' . $format, $local->getTimestamp(), $tz );
	}

	/**
	 * The event page for one date.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param string               $start Local start.
	 * @return string
	 */
	public static function date_url( array $event, string $start ): string {
		$args = array();
		if ( $event['rrule'] || $event['extra_times'] ) {
			$args['rayec_date'] = substr( $start, 0, 10 );
		}
		if ( $event['extra_times'] ) {
			$args['rayec_time'] = substr( $start, 11, 5 );
		}
		return $args ? add_query_arg( $args, (string) $event['permalink'] ) : (string) $event['permalink'];
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reading and writing registrations
	 * ---------------------------------------------------------------------
	 */

	/**
	 * One registration.
	 *
	 * @param int $id Registration ID.
	 * @return object|null
	 */
	public static function get( int $id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/**
	 * Every registration for an event, oldest date first.
	 *
	 * @param int $event_id Event ID.
	 * @return object[]
	 */
	public static function for_event( int $event_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY occurrence_start ASC, id ASC LIMIT 5000", $event_id ) );
	}

	/**
	 * Register someone for one date.
	 *
	 * Counting the places and writing the row happen under one named lock
	 * per date, so two people pressing the button for the last place at the
	 * same moment cannot both get it.
	 *
	 * Registering again with an address that already has a place does not
	 * make a second registration, and does not say so either: the reply
	 * looks exactly like a new registration, and the email goes to the
	 * address's owner. Otherwise anyone could type an address in to find out
	 * whether that person is going to a support group.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param array<string, mixed> $input start, name, email, places, access, data.
	 * @return array{id: int, status: string, existing: bool, token: string}|WP_Error
	 */
	public static function register( array $event, array $input ) {
		global $wpdb;

		if ( ! self::is_open( $event ) ) {
			return new WP_Error( 'rayec_rsvp_closed', __( 'This event is not taking registrations.', 'rayetun-events-calendar' ) );
		}
		$event_id = (int) $event['id'];
		$start    = (string) ( $input['start'] ?? '' );
		if ( ! self::upcoming_date( $event_id, $start ) ) {
			return new WP_Error( 'rayec_rsvp_date', __( 'That date is no longer open for registration. Choose another.', 'rayetun-events-calendar' ) );
		}

		$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$places   = max( 1, min( (int) $event['rsvp']['max'], (int) ( $input['places'] ?? 1 ) ) );
		$lock     = 'rayec_rsvp_' . md5( $event_id . '|' . $start );
		$existing = null;
		$id       = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a MySQL named lock, held only while the places are counted and the row written.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return new WP_Error( 'rayec_rsvp_busy', __( 'A lot of people are registering at once. Please press Register again.', 'rayetun-events-calendar' ) );
		}

		try {
			$table = $wpdb->prefix . self::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND occurrence_start = %s AND email = %s AND status IN (%s, %s, %s, %s) LIMIT 1", $event_id, $start, $email, 'confirmed', 'pending', 'attended', 'waitlisted' ) );
		} finally {
			if ( $existing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releasing the named lock taken above.
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}

		if ( $existing ) {
			// Already booked: send the details again to the address's owner,
			// at most once every few minutes so the form cannot be used to
			// fill someone's inbox.
			$sent_key = 'rayec_rsvp_sent_' . (int) $existing->id;
			if ( 'confirmed' === $existing->status && ! get_transient( $sent_key ) ) {
				set_transient( $sent_key, 1, self::RESEND_AFTER );
				self::send_confirmation( (int) $existing->id, self::new_token( (int) $existing->id ) );
			}
			return array(
				'id'       => (int) $existing->id,
				'status'   => (string) $existing->status,
				'existing' => true,
				'token'    => '',
			);
		}

		try {
			$status   = 'confirmed';
			$capacity = (int) $event['rsvp']['capacity'];
			if ( $capacity ) {
				$taken = self::taken( $event_id, array( $start ) );
				$left  = $capacity - $taken[ $start ];
				if ( $places > $left ) {
					/**
					 * Filters what happens when a date has no room for a registration.
					 *
					 * Return "waitlisted" to accept the registration onto a waiting
					 * list instead of turning it away. Anything else turns it away.
					 *
					 * @param string               $status Empty by default.
					 * @param array<string, mixed> $event  Event.
					 * @param array<string, mixed> $input  What was submitted.
					 * @param int                  $left   Places left on the date.
					 */
					$status = (string) apply_filters( 'rayec_registration_when_full', '', $event, $input, max( 0, $left ) );
					if ( 'waitlisted' !== $status ) {
						return $left > 0
							? new WP_Error(
								'rayec_rsvp_places',
								/* translators: %d: number of places left. */
								sprintf( _n( 'Only %d place is left on this date.', 'Only %d places are left on this date.', $left, 'rayetun-events-calendar' ), $left )
							)
							: new WP_Error( 'rayec_rsvp_full', __( 'This date is full. Choose another date if there is one.', 'rayetun-events-calendar' ) );
					}
				}
			}

			if ( 'confirmed' === $status ) {
				/**
				 * Filters the status a new registration starts with.
				 *
				 * An add-on that takes payment returns "pending", which holds the
				 * place while the person pays, and confirms it afterwards.
				 *
				 * @param string               $status "confirmed".
				 * @param array<string, mixed> $event  Event.
				 * @param array<string, mixed> $input  What was submitted.
				 */
				$status = (string) apply_filters( 'rayec_registration_status', 'confirmed', $event, $input );
				if ( ! in_array( $status, array( 'confirmed', 'pending' ), true ) ) {
					$status = 'confirmed';
				}
			}

			$token = wp_generate_password( 32, false );
			$now   = gmdate( 'Y-m-d H:i:s' );
			$data  = isset( $input['data'] ) && is_array( $input['data'] ) && $input['data'] ? wp_json_encode( $input['data'] ) : '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			$wpdb->insert(
				$table,
				array(
					'event_id'         => $event_id,
					'occurrence_start' => $start,
					'name'             => mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 190 ),
					'email'            => $email,
					'places'           => $places,
					'status'           => $status,
					'access_request'   => $event['rsvp']['access'] ? mb_substr( sanitize_textarea_field( (string) ( $input['access'] ?? '' ) ), 0, 2000 ) : '',
					'data'             => (string) $data,
					'token_hash'       => hash( 'sha256', $token ),
					'user_id'          => get_current_user_id(),
					'created_utc'      => $now,
					'updated_utc'      => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			$id = (int) $wpdb->insert_id;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releasing the named lock taken above.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		if ( ! $id ) {
			return new WP_Error( 'rayec_rsvp_failed', __( 'Your registration could not be saved. Please try again.', 'rayetun-events-calendar' ) );
		}

		/**
		 * Fires after a registration is written.
		 *
		 * @param int                  $id    Registration ID.
		 * @param array<string, mixed> $event Event.
		 * @param string               $token The secret in this person's cancel link.
		 */
		do_action( 'rayec_registration_created', $id, $event, $token );

		if ( 'confirmed' === $status ) {
			self::send_confirmation( $id, $token );
			self::tell_organiser( $id, 'new' );
		}

		return array(
			'id'       => $id,
			'status'   => $status,
			'existing' => false,
			'token'    => $token,
		);
	}

	/**
	 * Change a registration's status.
	 *
	 * @param int    $id     Registration ID.
	 * @param string $status New status.
	 * @param string $by     Who changed it: "attendee", "organiser" or "system".
	 * @return object|WP_Error The registration as it is now.
	 */
	public static function set_status( int $id, string $status, string $by = 'organiser' ) {
		global $wpdb;

		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'rayec_not_found', __( 'That registration no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'rayec_bad_status', __( 'That is not a registration status.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		$old = (string) $row->status;
		if ( $old === $status ) {
			return $row;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update(
			self::table(),
			array(
				'status'      => $status,
				'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$row = self::get( $id );

		/**
		 * Fires after a registration's status changes.
		 *
		 * @param int    $id     Registration ID.
		 * @param string $status New status.
		 * @param string $old    Old status.
		 * @param string $by     "attendee", "organiser" or "system".
		 */
		do_action( 'rayec_registration_status_changed', $id, $status, $old, $by );

		if ( 'cancelled' === $status ) {
			self::send_cancelled( $id, 'organiser' === $by );
			if ( 'attendee' === $by ) {
				self::tell_organiser( $id, 'cancelled' );
			}
		} elseif ( 'confirmed' === $status && 'cancelled' === $old && 'organiser' === $by ) {
			// A place given back: the person needs a working cancel link again.
			self::send_confirmation( $id, self::new_token( $id ) );
		}

		return $row;
	}

	/**
	 * Cancel a place with the secret from its cancel link.
	 *
	 * @param int    $id    Registration ID.
	 * @param string $token Secret from the link.
	 * @return object|WP_Error
	 */
	public static function cancel_with_token( int $id, string $token ) {
		$row = self::get( $id );
		if ( ! $row || ! self::token_matches( $row, $token ) || ! in_array( $row->status, array( 'confirmed', 'pending', 'waitlisted' ), true ) ) {
			return new WP_Error( 'rayec_rsvp_link', __( 'This cancellation link does not work any more. The place may already have been cancelled.', 'rayetun-events-calendar' ) );
		}
		return self::set_status( $id, 'cancelled', 'attendee' );
	}

	/**
	 * Whether a secret belongs to a registration. Only its hash is stored.
	 *
	 * @param object $row   Registration.
	 * @param string $token Secret.
	 * @return bool
	 */
	public static function token_matches( object $row, string $token ): bool {
		return '' !== $token && '' !== (string) $row->token_hash && hash_equals( (string) $row->token_hash, hash( 'sha256', $token ) );
	}

	/**
	 * Give a registration a new secret, which retires the old cancel link.
	 *
	 * @param int $id Registration ID.
	 * @return string The new secret.
	 */
	public static function new_token( int $id ): string {
		global $wpdb;

		$token = wp_generate_password( 32, false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update( self::table(), array( 'token_hash' => hash( 'sha256', $token ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return $token;
	}

	/**
	 * The link that lets someone cancel their own place.
	 *
	 * @param object               $row   Registration.
	 * @param array<string, mixed> $event Event.
	 * @param string               $token Secret.
	 * @return string
	 */
	public static function cancel_url( object $row, array $event, string $token ): string {
		return add_query_arg(
			array(
				'rayec_cancel' => (int) $row->id,
				'rayec_key'    => $token,
			),
			self::date_url( $event, (string) $row->occurrence_start )
		) . '#rayec-register-status';
	}

	/**
	 * Remove the registrations of an event that is being deleted for good.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_for_event( $post_id ): void {
		global $wpdb;

		if ( Post_Types::EVENT !== get_post_type( (int) $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( self::table(), array( 'event_id' => (int) $post_id ), array( '%d' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Email
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Send someone the details of their place, with the date as a calendar file.
	 *
	 * @param int    $id    Registration ID.
	 * @param string $token Secret for the cancel link.
	 * @return bool Whether the mail was handed over for sending.
	 */
	public static function send_confirmation( int $id, string $token ): bool {
		$row   = self::get( $id );
		$event = $row ? Event::get( (int) $row->event_id ) : null;
		if ( ! $row || ! $event || ! is_email( $row->email ) ) {
			return false;
		}

		$when  = self::when( $event, (string) $row->occurrence_start );
		$lines = array(
			/* translators: %s: the person's name. */
			sprintf( __( 'Hello %s,', 'rayetun-events-calendar' ), $row->name ),
			'',
			/* translators: %s: event title. */
			sprintf( _n( 'You have a place at %s.', 'You have places at %s.', (int) $row->places, 'rayetun-events-calendar' ), $event['title'] ),
			'',
			/* translators: %s: date and time. */
			sprintf( __( 'When: %s', 'rayetun-events-calendar' ), $when ),
		);
		$where = self::where_text( $event );
		if ( '' !== $where ) {
			/* translators: %s: venue or "Online". */
			$lines[] = sprintf( __( 'Where: %s', 'rayetun-events-calendar' ), $where );
		}
		if ( (int) $row->places > 1 ) {
			/* translators: %d: number of places. */
			$lines[] = sprintf( __( 'Places: %d', 'rayetun-events-calendar' ), (int) $row->places );
		}
		if ( '' !== trim( (string) $row->access_request ) ) {
			$lines[] = '';
			$lines[] = __( 'You told us about your access requirements:', 'rayetun-events-calendar' );
			$lines[] = trim( (string) $row->access_request );
			$lines[] = __( 'The organiser has this, and will get in touch if they need to know more.', 'rayetun-events-calendar' );
		}
		$lines[] = '';
		/* translators: %s: web address. */
		$lines[] = sprintf( __( 'Event page: %s', 'rayetun-events-calendar' ), self::date_url( $event, (string) $row->occurrence_start ) );
		/* translators: %s: web address. */
		$lines[] = sprintf( __( 'If your plans change, you can cancel here: %s', 'rayetun-events-calendar' ), self::cancel_url( $row, $event, $token ) );
		$lines[] = '';
		$lines[] = __( 'A calendar file for this date is attached.', 'rayetun-events-calendar' );
		$lines[] = '';
		$lines[] = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$headers   = array();
		$organiser = $event['organizer'] ?? null;
		if ( $organiser && is_email( (string) $organiser['email'] ) ) {
			$headers[] = 'Reply-To: ' . $organiser['email'];
		}

		/* translators: 1: event title, 2: date and time. */
		$subject = sprintf( __( 'You are registered: %1$s, %2$s', 'rayetun-events-calendar' ), $event['title'], $when );

		$ics  = '';
		$date = self::upcoming_date( (int) $event['id'], (string) $row->occurrence_start );
		if ( $date ) {
			$times = Occurrences::times( $date );
			$ics   = Ics::single( $event, $times[0], $times[1] );
		}

		return self::mail( (string) $row->email, $subject, $lines, $headers, $ics, sanitize_title( $event['title'] ) . '.ics' );
	}

	/**
	 * Tell someone their place has been cancelled.
	 *
	 * @param int  $id           Registration ID.
	 * @param bool $by_organiser Whether the organiser cancelled it.
	 * @return bool
	 */
	public static function send_cancelled( int $id, bool $by_organiser ): bool {
		$row   = self::get( $id );
		$event = $row ? Event::get( (int) $row->event_id ) : null;
		if ( ! $row || ! $event || ! is_email( $row->email ) ) {
			return false;
		}

		$when  = self::when( $event, (string) $row->occurrence_start );
		$lines = array(
			/* translators: %s: the person's name. */
			sprintf( __( 'Hello %s,', 'rayetun-events-calendar' ), $row->name ),
			'',
			$by_organiser
				/* translators: 1: event title, 2: date and time. */
				? sprintf( __( 'The organiser has cancelled your place at %1$s on %2$s.', 'rayetun-events-calendar' ), $event['title'], $when )
				/* translators: 1: event title, 2: date and time. */
				: sprintf( __( 'Your place at %1$s on %2$s has been cancelled, as you asked.', 'rayetun-events-calendar' ), $event['title'], $when ),
			'',
			/* translators: %s: web address. */
			sprintf( __( 'Event page: %s', 'rayetun-events-calendar' ), $event['permalink'] ),
			'',
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		/* translators: %s: event title. */
		$subject = sprintf( __( 'Place cancelled: %s', 'rayetun-events-calendar' ), $event['title'] );
		return self::mail( (string) $row->email, $subject, $lines );
	}

	/**
	 * Tell the organiser about a new registration or a cancellation.
	 *
	 * @param int    $id   Registration ID.
	 * @param string $what "new" or "cancelled".
	 * @return bool
	 */
	public static function tell_organiser( int $id, string $what ): bool {
		if ( ! Plugin::setting( 'rsvp_notify' ) ) {
			return false;
		}
		$to    = (string) Plugin::setting( 'rsvp_notify_email' );
		$to    = is_email( $to ) ? $to : (string) get_option( 'admin_email' );
		$row   = self::get( $id );
		$event = $row ? Event::get( (int) $row->event_id ) : null;
		if ( ! $row || ! $event || ! is_email( $to ) ) {
			return false;
		}

		$when     = self::when( $event, (string) $row->occurrence_start );
		$taken    = self::taken( (int) $event['id'], array( (string) $row->occurrence_start ) );
		$held     = $taken[ (string) $row->occurrence_start ];
		$capacity = (int) $event['rsvp']['capacity'];

		$lines = array(
			'new' === $what
				/* translators: 1: person's name, 2: event title, 3: date and time. */
				? sprintf( __( '%1$s has registered for %2$s on %3$s.', 'rayetun-events-calendar' ), $row->name, $event['title'], $when )
				/* translators: 1: person's name, 2: event title, 3: date and time. */
				: sprintf( __( '%1$s has cancelled their place at %2$s on %3$s.', 'rayetun-events-calendar' ), $row->name, $event['title'], $when ),
			'',
			/* translators: %s: email address. */
			sprintf( __( 'Email: %s', 'rayetun-events-calendar' ), $row->email ),
			/* translators: %d: number of places. */
			sprintf( __( 'Places: %d', 'rayetun-events-calendar' ), (int) $row->places ),
		);
		if ( 'new' === $what && '' !== trim( (string) $row->access_request ) ) {
			$lines[] = '';
			$lines[] = __( 'Access requirements:', 'rayetun-events-calendar' );
			$lines[] = trim( (string) $row->access_request );
		}
		$lines[] = '';
		$lines[] = $capacity
			/* translators: 1: places taken, 2: places in total. */
			? sprintf( __( '%1$d of %2$d places are now taken on this date.', 'rayetun-events-calendar' ), $held, $capacity )
			/* translators: %d: places taken. */
			: sprintf( _n( '%d place is now taken on this date.', '%d places are now taken on this date.', $held, 'rayetun-events-calendar' ), $held );
		$lines[] = '';
		/* translators: %s: web address. */
		$lines[] = sprintf( __( 'Everyone registered: %s', 'rayetun-events-calendar' ), admin_url( 'admin.php?page=' . Admin::PAGE_SLUG . '#/events/' . (int) $event['id'] ) );

		$subject = 'new' === $what
			/* translators: 1: event title, 2: date and time. */
			? sprintf( __( 'New registration: %1$s, %2$s', 'rayetun-events-calendar' ), $event['title'], $when )
			/* translators: 1: event title, 2: date and time. */
			: sprintf( __( 'Cancelled: %1$s, %2$s', 'rayetun-events-calendar' ), $event['title'], $when );

		return self::mail( $to, $subject, $lines, array( 'Reply-To: ' . $row->email ) );
	}

	/**
	 * Send a plain-text email, with an optional calendar file.
	 *
	 * Plain text, because it reads the same in every mail program and every
	 * screen reader, and nothing in it depends on images loading.
	 *
	 * @param string   $to       Recipient.
	 * @param string   $subject  Subject.
	 * @param string[] $lines    Body, one line each.
	 * @param string[] $headers  Extra headers.
	 * @param string   $ics      Calendar file contents, or empty.
	 * @param string   $filename Calendar file name.
	 * @return bool
	 */
	private static function mail( string $to, string $subject, array $lines, array $headers = array(), string $ics = '', string $filename = 'event.ics' ): bool {
		$subject = wp_specialchars_decode( $subject, ENT_QUOTES );
		$body    = wp_specialchars_decode( implode( "\n", $lines ), ENT_QUOTES );

		/**
		 * Filters a registration email before it is sent.
		 *
		 * @param array{to: string, subject: string, body: string, headers: string[]} $mail Email.
		 */
		$mail = (array) apply_filters(
			'rayec_registration_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			)
		);

		$attach = null;
		if ( '' !== $ics ) {
			// Attached from memory, so no temporary file is written to disk.
			$attach = static function ( $phpmailer ) use ( $ics, $filename ) {
				$phpmailer->addStringAttachment( $ics, $filename, 'base64', 'text/calendar' );
			};
			add_action( 'phpmailer_init', $attach );
		}

		$sent = wp_mail( (string) $mail['to'], (string) $mail['subject'], (string) $mail['body'], (array) $mail['headers'] );

		if ( $attach ) {
			remove_action( 'phpmailer_init', $attach );
		}
		return (bool) $sent;
	}

	/**
	 * Where the event is, as one line of text.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function where_text( array $event ): string {
		$online = in_array( $event['format'], array( 'online', 'hybrid' ), true ) || 'moved_online' === $event['event_status'];
		$parts  = array();
		if ( 'online' !== $event['format'] && 'moved_online' !== $event['event_status'] && ! empty( $event['venue'] ) ) {
			$parts[] = trim( implode( ', ', array_filter( array( (string) $event['venue']['name'], (string) ( $event['venue']['address'] ?? '' ), (string) ( $event['venue']['city'] ?? '' ) ) ) ) );
		}
		if ( $online ) {
			$parts[] = $event['online_url']
				/* translators: %s: web address to join. */
				? sprintf( __( 'Online, at %s', 'rayetun-events-calendar' ), $event['online_url'] )
				: __( 'Online. Joining details will be shared before the event.', 'rayetun-events-calendar' );
		}
		return implode( ' / ', array_filter( $parts ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The form on the event page
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Handle a registration or a cancellation posted to an event page.
	 *
	 * @return void
	 */
	public static function handle_post(): void {
		if ( ! is_singular( Post_Types::EVENT ) ) {
			return;
		}
		$event = Event::get( get_queried_object_id() );
		if ( ! $event ) {
			return;
		}

		if ( isset( $_POST['rayec_rsvp_nonce'] ) ) {
			$fresh = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rayec_rsvp_nonce'] ) ), 'rayec_rsvp_' . $event['id'] );
			self::handle_register( $event, (bool) $fresh );
		} elseif ( isset( $_POST['rayec_cancel_nonce'], $_POST['rayec_cancel_place'] ) ) {
			$id = absint( wp_unslash( $_POST['rayec_cancel_place'] ) );
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rayec_cancel_nonce'] ) ), 'rayec_cancel_' . $id ) ) {
				self::$form['cancel_error'] = __( 'That took too long. Please press the button again.', 'rayetun-events-calendar' );
				return;
			}
			$key    = isset( $_POST['rayec_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rayec_key'] ) ) : '';
			$result = self::cancel_with_token( $id, $key );
			if ( is_wp_error( $result ) ) {
				self::$form['cancel_error'] = $result->get_error_message();
				return;
			}
			wp_safe_redirect( add_query_arg( 'rayec_cancelled', '1', self::date_url( $event, (string) $result->occurrence_start ) ) . '#rayec-register-status' );
			exit;
		}
	}

	/**
	 * Check and save a registration from the form.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param bool                 $fresh Whether the form's nonce checked out.
	 * @return void
	 */
	private static function handle_register( array $event, bool $fresh ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post(); a stale form is answered with a fresh one below rather than thrown away.
		$values = array(
			'start'  => isset( $_POST['rayec_rsvp_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rayec_rsvp_date'] ) ) : '',
			'name'   => isset( $_POST['rayec_rsvp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rayec_rsvp_name'] ) ) : '',
			'email'  => isset( $_POST['rayec_rsvp_email'] ) ? sanitize_email( wp_unslash( $_POST['rayec_rsvp_email'] ) ) : '',
			'places' => isset( $_POST['rayec_rsvp_places'] ) ? absint( wp_unslash( $_POST['rayec_rsvp_places'] ) ) : 1,
			'access' => isset( $_POST['rayec_rsvp_access'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rayec_rsvp_access'] ) ) : '',
		);
		$trap   = isset( $_POST['rayec_rsvp_website'] ) ? sanitize_text_field( wp_unslash( $_POST['rayec_rsvp_website'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$done = add_query_arg( 'rayec_registered', '1', self::date_url( $event, $values['start'] ) ) . '#rayec-register-status';

		// A field nobody can see was filled in: a robot. Thank it and save nothing.
		if ( '' !== $trap ) {
			wp_safe_redirect( $done );
			exit;
		}

		$errors = array();
		if ( ! $fresh ) {
			$errors['form'] = __( 'This page had been open for a while, so the form has been refreshed. Check your details and press Register again.', 'rayetun-events-calendar' );
		}
		if ( '' === $values['start'] ) {
			$errors['date'] = __( 'Choose a date.', 'rayetun-events-calendar' );
		}
		if ( '' === trim( $values['name'] ) ) {
			$errors['name'] = __( 'Enter your name.', 'rayetun-events-calendar' );
		}
		if ( ! is_email( $values['email'] ) ) {
			$errors['email'] = __( 'Enter your email address, like name@example.com.', 'rayetun-events-calendar' );
		}

		/**
		 * Filters the errors found in a registration before it is saved.
		 *
		 * Add-ons that add fields check them here. Keys are field names; a key
		 * of "form" is shown at the top of the form only.
		 *
		 * @param array<string, string> $errors Errors so far.
		 * @param array<string, mixed>  $event  Event.
		 * @param array<string, mixed>  $values What was submitted.
		 */
		$errors = (array) apply_filters( 'rayec_registration_validate', $errors, $event, $values );

		if ( ! $errors ) {
			/**
			 * Filters extra data stored with a registration, such as the answers
			 * to an add-on's own questions.
			 *
			 * @param array<string, mixed> $data   Extra data.
			 * @param array<string, mixed> $event  Event.
			 * @param array<string, mixed> $values What was submitted.
			 */
			$values['data'] = (array) apply_filters( 'rayec_registration_data', array(), $event, $values );

			$result = self::register( $event, $values );
			if ( ! is_wp_error( $result ) ) {
				/**
				 * Filters where someone goes after registering.
				 *
				 * An add-on that takes payment sends a pending registration to
				 * its checkout here.
				 *
				 * @param string                                                           $url    The event page, saying the place is booked.
				 * @param array{id: int, status: string, existing: bool, token: string} $result The registration.
				 * @param array<string, mixed>                                             $event  Event.
				 */
				$url = (string) apply_filters( 'rayec_registration_redirect', $done, $result, $event );
				wp_safe_redirect( $url );
				exit;
			}

			$fields = array(
				'rayec_rsvp_date'   => 'date',
				'rayec_rsvp_full'   => 'date',
				'rayec_rsvp_places' => 'places',
			);
			$errors[ $fields[ $result->get_error_code() ] ?? 'form' ] = $result->get_error_message();
		}

		self::$form = array(
			'errors' => $errors,
			'values' => $values,
		);
	}

	/**
	 * The "Places" row for the facts panel at the top of the event page.
	 *
	 * @param array<string, mixed>                                   $event Event.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $times The date being shown.
	 * @return array{0: string, 1: string}|null Term and value HTML.
	 */
	public static function fact( array $event, ?array $times ): ?array {
		if ( empty( $event['rsvp']['enabled'] ) || ! $times ) {
			return null;
		}

		$link = '<p class="rayec-fact__note"><a href="#rayec-register">' . esc_html__( 'Register', 'rayetun-events-calendar' ) . '</a></p>';
		if ( ! self::is_open( $event ) ) {
			$main = __( 'Registration closed', 'rayetun-events-calendar' );
			$link = '';
		} elseif ( $times[0]->getTimestamp() <= time() ) {
			$main = __( 'Registration closed for this date', 'rayetun-events-calendar' );
			$link = '';
		} elseif ( $event['rsvp']['capacity'] ) {
			$start = self::local_start( $event, $times[0] );
			$taken = self::taken( (int) $event['id'], array( $start ) );
			$left  = max( 0, (int) $event['rsvp']['capacity'] - $taken[ $start ] );
			$main  = $left
				/* translators: 1: places left, 2: places in total. */
				? sprintf( _n( '%1$d of %2$d place left', '%1$d of %2$d places left', $left, 'rayetun-events-calendar' ), $left, (int) $event['rsvp']['capacity'] )
				: __( 'This date is full', 'rayetun-events-calendar' );
		} else {
			$main = __( 'Registration open', 'rayetun-events-calendar' );
		}

		return array( __( 'Places', 'rayetun-events-calendar' ), '<p class="rayec-fact__main">' . esc_html( $main ) . '</p>' . $link );
	}

	/**
	 * The Register section, after the description.
	 *
	 * @param array<string, mixed>                                   $event Event.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $times The date being shown.
	 * @return string
	 */
	public static function render( array $event, ?array $times ): string {
		$status = self::status_box( $event );
		// Someone who came to cancel sees only that, not the form to book again.
		if ( '' !== Frontend::param( 'rayec_cancel' ) && '' !== $status ) {
			return '<section class="rayec-section rayec-rsvp" id="rayec-register">' . $status . '</section>';
		}
		if ( empty( $event['rsvp']['enabled'] ) ) {
			return '' !== $status ? '<section class="rayec-section rayec-rsvp" id="rayec-register">' . $status . '</section>' : '';
		}

		$html  = '<section class="rayec-section rayec-rsvp" id="rayec-register" aria-labelledby="rayec-register-title">';
		$html .= '<h2 class="rayec-section__title" id="rayec-register-title">' . esc_html__( 'Register', 'rayetun-events-calendar' ) . '</h2>';
		$html .= $status;

		if ( ! self::is_open( $event ) ) {
			$why = array(
				'cancelled' => __( 'Registration is closed because this event has been cancelled.', 'rayetun-events-calendar' ),
				'postponed' => __( 'Registration is closed until a new date is announced.', 'rayetun-events-calendar' ),
			);
			return $html . '<p>' . esc_html( $why[ $event['event_status'] ] ?? __( 'Registration is closed.', 'rayetun-events-calendar' ) ) . '</p></section>';
		}

		$dates = self::upcoming_dates( $event );
		if ( ! $dates ) {
			return $html . '<p>' . esc_html__( 'There are no dates to register for at the moment.', 'rayetun-events-calendar' ) . '</p></section>';
		}
		$open = array_filter( $dates, static fn( $date ) => null === $date['left'] || $date['left'] > 0 );
		if ( ! $open ) {
			$message = count( $dates ) > 1
				? __( 'Every date coming up is full.', 'rayetun-events-calendar' )
				: __( 'This event is full.', 'rayetun-events-calendar' );
			return $html . '<p>' . esc_html( $message ) . '</p></section>';
		}

		$form = self::form( $event, $dates, $times );
		if ( '1' === Frontend::param( 'rayec_registered' ) ) {
			// Just booked: the form folds away, so nobody books twice by accident.
			$form = '<details class="rayec-rsvp__again"><summary>' . esc_html__( 'Register someone else, or for another date', 'rayetun-events-calendar' ) . '</summary>' . $form . '</details>';
		}
		return $html . $form . '</section>';
	}

	/**
	 * The registration form.
	 *
	 * Built to the pattern that works with or without a screen reader: every
	 * field has a visible label, the required ones say so in words, and when
	 * something is wrong a summary at the top links to each field, which
	 * carries its own message too.
	 *
	 * @param array<string, mixed>                                   $event Event.
	 * @param array<int, array<string, mixed>>                       $dates Dates that can be chosen.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $times The date being shown.
	 * @return string
	 */
	private static function form( array $event, array $dates, ?array $times ): string {
		$errors = (array) ( self::$form['errors'] ?? array() );
		$values = (array) ( self::$form['values'] ?? array() );
		$user   = wp_get_current_user();

		$chosen = (string) ( $values['start'] ?? '' );
		if ( '' === $chosen && $times ) {
			$chosen = self::local_start( $event, $times[0] );
		}
		$name  = (string) ( $values['name'] ?? ( $user->exists() ? $user->display_name : '' ) );
		$email = (string) ( $values['email'] ?? ( $user->exists() ? $user->user_email : '' ) );

		$labels = array(
			'date'   => 'rayec-rsvp-date',
			'name'   => 'rayec-rsvp-name',
			'email'  => 'rayec-rsvp-email',
			'places' => 'rayec-rsvp-places',
			'access' => 'rayec-rsvp-access',
		);

		$html = '';
		if ( $errors ) {
			$html .= '<div class="rayec-notice rayec-notice--error" id="rayec-register-errors" tabindex="-1" aria-labelledby="rayec-register-errors-title">';
			$html .= '<h3 class="rayec-notice__title" id="rayec-register-errors-title">' . esc_html__( 'Please check the form', 'rayetun-events-calendar' ) . '</h3><ul>';
			foreach ( $errors as $field => $message ) {
				$html .= isset( $labels[ $field ] )
					? '<li><a href="#' . esc_attr( $labels[ $field ] ) . '">' . esc_html( $message ) . '</a></li>'
					: '<li>' . esc_html( $message ) . '</li>';
			}
			$html .= '</ul></div>';
		}

		$action = self::date_url( $event, '' !== $chosen ? $chosen : $dates[0]['start'] ) . '#rayec-register-errors';
		$html  .= '<form class="rayec-form" method="post" action="' . esc_url( $action ) . '" novalidate>';
		$html  .= wp_nonce_field( 'rayec_rsvp_' . $event['id'], 'rayec_rsvp_nonce', false, false );

		// The date.
		if ( count( $dates ) > 1 ) {
			$html .= '<div class="rayec-field">' . self::label( 'date', __( 'Date', 'rayetun-events-calendar' ), true );
			$html .= self::error( 'date', $errors );
			$html .= '<select id="rayec-rsvp-date" name="rayec_rsvp_date" required' . self::described( 'date', $errors ) . '>';
			foreach ( $dates as $date ) {
				$full   = null !== $date['left'] && $date['left'] < 1;
				$suffix = '';
				if ( $full ) {
					$suffix = ' (' . __( 'full', 'rayetun-events-calendar' ) . ')';
				} elseif ( null !== $date['left'] ) {
					/* translators: %d: places left. */
					$suffix = ' (' . sprintf( _n( '%d place left', '%d places left', $date['left'], 'rayetun-events-calendar' ), $date['left'] ) . ')';
				}
				$html .= '<option value="' . esc_attr( $date['start'] ) . '"' . selected( $chosen, $date['start'], false ) . disabled( $full, true, false ) . '>' . esc_html( $date['short'] . $suffix ) . '</option>';
			}
			$html .= '</select></div>';
		} else {
			$only  = $dates[0];
			$html .= '<input type="hidden" name="rayec_rsvp_date" value="' . esc_attr( $only['start'] ) . '">';
			$html .= self::error( 'date', $errors );
			if ( null !== $only['left'] ) {
				/* translators: %d: places left. */
				$html .= '<p class="rayec-rsvp__left">' . esc_html( sprintf( _n( '%d place left.', '%d places left.', $only['left'], 'rayetun-events-calendar' ), $only['left'] ) ) . '</p>';
			}
		}

		// Name and email.
		$html .= '<div class="rayec-field">' . self::label( 'name', __( 'Your name', 'rayetun-events-calendar' ), true ) . self::error( 'name', $errors );
		$html .= '<input type="text" id="rayec-rsvp-name" name="rayec_rsvp_name" autocomplete="name" required maxlength="190" value="' . esc_attr( $name ) . '"' . self::described( 'name', $errors ) . '></div>';

		$html .= '<div class="rayec-field">' . self::label( 'email', __( 'Email address', 'rayetun-events-calendar' ), true );
		$html .= '<p class="rayec-field__hint" id="rayec-rsvp-email-hint">' . esc_html__( 'Your confirmation and calendar file are sent here.', 'rayetun-events-calendar' ) . '</p>';
		$html .= self::error( 'email', $errors );
		$html .= '<input type="email" id="rayec-rsvp-email" name="rayec_rsvp_email" autocomplete="email" required maxlength="190" spellcheck="false" value="' . esc_attr( $email ) . '"' . self::described( 'email', $errors, 'rayec-rsvp-email-hint' ) . '></div>';

		// Places, when one person may book more than one.
		$max = (int) $event['rsvp']['max'];
		if ( $max > 1 ) {
			$picked = max( 1, min( $max, (int) ( $values['places'] ?? 1 ) ) );
			$html  .= '<div class="rayec-field">' . self::label( 'places', __( 'Places', 'rayetun-events-calendar' ), false );
			$html  .= '<p class="rayec-field__hint" id="rayec-rsvp-places-hint">' . esc_html__( 'Including you.', 'rayetun-events-calendar' ) . '</p>';
			$html  .= self::error( 'places', $errors );
			$html  .= '<select id="rayec-rsvp-places" name="rayec_rsvp_places"' . self::described( 'places', $errors, 'rayec-rsvp-places-hint' ) . '>';
			for ( $i = 1; $i <= $max; $i++ ) {
				$html .= '<option value="' . $i . '"' . selected( $picked, $i, false ) . '>' . $i . '</option>';
			}
			$html .= '</select></div>';
		}

		// Access requirements.
		if ( $event['rsvp']['access'] ) {
			$html .= '<div class="rayec-field">' . self::label( 'access', __( 'Access requirements', 'rayetun-events-calendar' ), false );
			$html .= '<p class="rayec-field__hint" id="rayec-rsvp-access-hint">' . esc_html__( 'Anything that would help you take part, such as step-free access, a sign language interpreter, a quiet space or a seat near the front. Only the organiser sees this.', 'rayetun-events-calendar' ) . '</p>';
			$html .= '<textarea id="rayec-rsvp-access" name="rayec_rsvp_access" rows="3" maxlength="2000" aria-describedby="rayec-rsvp-access-hint">' . esc_textarea( (string) ( $values['access'] ?? '' ) ) . '</textarea></div>';
		}

		/**
		 * Filters extra HTML inside the registration form, before the button.
		 *
		 * @param string               $html   Extra fields. Empty by default.
		 * @param array<string, mixed> $event  Event.
		 * @param array<string, mixed> $values What was submitted, when the form is drawn again.
		 * @param array<string, string> $errors Errors by field.
		 */
		$html .= (string) apply_filters( 'rayec_registration_fields', '', $event, $values, $errors );

		// A field only a robot fills in. Hidden from sight and from screen readers.
		$html .= '<div class="rayec-hp" aria-hidden="true"><label for="rayec-rsvp-website">' . esc_html__( 'Leave this empty', 'rayetun-events-calendar' ) . '</label><input type="text" id="rayec-rsvp-website" name="rayec_rsvp_website" tabindex="-1" autocomplete="off"></div>';

		$privacy = __( 'Your name and email address are used only to manage your place.', 'rayetun-events-calendar' );
		$policy  = get_privacy_policy_url();
		$html   .= '<p class="rayec-field__hint">' . esc_html( $privacy ) . ( $policy ? ' <a href="' . esc_url( $policy ) . '">' . esc_html__( 'Privacy policy', 'rayetun-events-calendar' ) . '</a>' : '' ) . '</p>';

		$html .= '<p class="rayec-actions"><button type="submit" class="rayec-btn rayec-btn--primary">' . esc_html__( 'Register', 'rayetun-events-calendar' ) . '</button></p>';
		return $html . '</form>';
	}

	/**
	 * A field label, saying in words whether it is required.
	 *
	 * @param string $field    Field key.
	 * @param string $text     Label.
	 * @param bool   $required Required.
	 * @return string
	 */
	private static function label( string $field, string $text, bool $required ): string {
		$note = $required ? __( '(required)', 'rayetun-events-calendar' ) : __( '(optional)', 'rayetun-events-calendar' );
		return '<label class="rayec-field__label" for="rayec-rsvp-' . esc_attr( $field ) . '">' . esc_html( $text ) . ' <span class="rayec-field__req">' . esc_html( $note ) . '</span></label>';
	}

	/**
	 * A field's own error message.
	 *
	 * @param string                $field  Field key.
	 * @param array<string, string> $errors Errors.
	 * @return string
	 */
	private static function error( string $field, array $errors ): string {
		if ( empty( $errors[ $field ] ) ) {
			return '';
		}
		return '<p class="rayec-field__error" id="rayec-rsvp-' . esc_attr( $field ) . '-error"><span class="rayec-sr">' . esc_html__( 'Error:', 'rayetun-events-calendar' ) . ' </span>' . esc_html( $errors[ $field ] ) . '</p>';
	}

	/**
	 * The aria attributes that tie a field to its hint and its error.
	 *
	 * @param string                $field  Field key.
	 * @param array<string, string> $errors Errors.
	 * @param string                $hint   Hint element ID.
	 * @return string
	 */
	private static function described( string $field, array $errors, string $hint = '' ): string {
		$ids = array_filter( array( $hint, empty( $errors[ $field ] ) ? '' : 'rayec-rsvp-' . $field . '-error' ) );
		$out = $ids ? ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"' : '';
		return $out . ( empty( $errors[ $field ] ) ? '' : ' aria-invalid="true"' );
	}

	/**
	 * What happened, when someone arrives back from the form or their email.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function status_box( array $event ): string {
		if ( '1' === Frontend::param( 'rayec_registered' ) ) {
			return '<div class="rayec-notice rayec-notice--ok" id="rayec-register-status" tabindex="-1"><p><strong>' . esc_html__( 'You are registered.', 'rayetun-events-calendar' ) . '</strong> ' . esc_html__( 'We have emailed you the details, with a calendar file and a link to cancel if your plans change.', 'rayetun-events-calendar' ) . '</p></div>';
		}
		if ( '1' === Frontend::param( 'rayec_cancelled' ) ) {
			return '<div class="rayec-notice rayec-notice--ok" id="rayec-register-status" tabindex="-1"><p><strong>' . esc_html__( 'Your place has been cancelled.', 'rayetun-events-calendar' ) . '</strong> ' . esc_html__( 'Thank you for letting us know.', 'rayetun-events-calendar' ) . '</p></div>';
		}

		$id  = absint( Frontend::param( 'rayec_cancel' ) );
		$key = Frontend::param( 'rayec_key' );
		if ( ! $id || '' === $key ) {
			return '';
		}

		$error = (string) ( self::$form['cancel_error'] ?? '' );
		$row   = self::get( $id );
		$valid = $row && (int) $row->event_id === (int) $event['id'] && self::token_matches( $row, $key ) && in_array( $row->status, array( 'confirmed', 'pending', 'waitlisted' ), true );
		if ( ! $valid ) {
			$message = '' !== $error ? $error : __( 'This cancellation link does not work any more. The place may already have been cancelled.', 'rayetun-events-calendar' );
			return '<div class="rayec-notice" id="rayec-register-status" tabindex="-1"><p>' . esc_html( $message ) . '</p></div>';
		}

		// Cancelling takes a button press, never just opening the link: mail
		// programs open links to check them, and that must not cancel anything.
		$html  = '<div class="rayec-notice" id="rayec-register-status" tabindex="-1">';
		$html .= '<h3 class="rayec-notice__title">' . esc_html__( 'Cancel your place?', 'rayetun-events-calendar' ) . '</h3>';
		if ( '' !== $error ) {
			$html .= '<p class="rayec-field__error">' . esc_html( $error ) . '</p>';
		}
		/* translators: 1: person's name, 2: event title, 3: date and time. */
		$html .= '<p>' . esc_html( sprintf( _n( '%1$s, this gives up your place at %2$s on %3$s.', '%1$s, this gives up your places at %2$s on %3$s.', (int) $row->places, 'rayetun-events-calendar' ), $row->name, $event['title'], self::when( $event, (string) $row->occurrence_start ) ) ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( self::date_url( $event, (string) $row->occurrence_start ) . '#rayec-register-status' ) . '">';
		$html .= wp_nonce_field( 'rayec_cancel_' . $id, 'rayec_cancel_nonce', false, false );
		$html .= '<input type="hidden" name="rayec_cancel_place" value="' . esc_attr( (string) $id ) . '">';
		$html .= '<input type="hidden" name="rayec_key" value="' . esc_attr( $key ) . '">';
		$html .= '<p class="rayec-actions"><button type="submit" class="rayec-btn rayec-btn--primary">' . esc_html( _n( 'Cancel my place', 'Cancel my places', (int) $row->places, 'rayetun-events-calendar' ) ) . '</button> ';
		$html .= '<a class="rayec-btn" href="' . esc_url( self::date_url( $event, (string) $row->occurrence_start ) ) . '">' . esc_html__( 'Keep my place', 'rayetun-events-calendar' ) . '</a></p>';
		return $html . '</form></div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * When the event changes: keep bookings on their dates, tell people
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Bookings for an event, by ID, between two local starts.
	 *
	 * @param int      $event_id Event ID.
	 * @param string   $from     First local start included, or empty.
	 * @param string   $to       Last local start included, or empty.
	 * @param string[] $statuses Statuses to include; empty for every status.
	 * @return int[]
	 */
	private static function ids_between( int $event_id, string $from = '', string $to = '', array $statuses = array() ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$from  = '' !== $from ? $from : '1000-01-01 00:00:00';
		$to    = '' !== $to ? $to : '9999-12-31 23:59:59';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE event_id = %d AND occurrence_start BETWEEN %s AND %s", $event_id, $from, $to ) );

		$ids = array();
		foreach ( $rows as $row ) {
			if ( ! $statuses || in_array( $row->status, $statuses, true ) ) {
				$ids[] = (int) $row->id;
			}
		}
		return $ids;
	}

	/**
	 * Now, on the event's own clock, as a local start can be compared with it.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function local_now( array $event ): string {
		return ( new DateTimeImmutable( 'now', Event::timezone( (string) $event['timezone'] ) ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Where some bookings are now, so a change can be undone exactly.
	 *
	 * @param int[] $ids Registration IDs.
	 * @return array<int, array{id: int, event_id: int, start: string, status: string}>
	 */
	public static function snapshot( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$row = self::get( (int) $id );
			if ( $row ) {
				$out[] = array(
					'id'       => (int) $row->id,
					'event_id' => (int) $row->event_id,
					'start'    => (string) $row->occurrence_start,
					'status'   => (string) $row->status,
				);
			}
		}
		return $out;
	}

	/**
	 * Put bookings back where a snapshot says they were.
	 *
	 * Only bookings that are, and were, in one of the given events are
	 * touched, so an undo request cannot reach anybody else's.
	 *
	 * @param mixed $snapshot From snapshot(), as sent back by the dashboard.
	 * @param int[] $events   Events the caller may change.
	 * @return int How many were put back.
	 */
	public static function restore( $snapshot, array $events ): int {
		global $wpdb;

		$count = 0;
		foreach ( is_array( $snapshot ) ? $snapshot : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row    = self::get( (int) ( $item['id'] ?? 0 ) );
			$event  = (int) ( $item['event_id'] ?? 0 );
			$start  = (string) ( $item['start'] ?? '' );
			$status = (string) ( $item['status'] ?? '' );
			if ( ! $row || ! in_array( (int) $row->event_id, $events, true ) || ! in_array( $event, $events, true )
				|| ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start ) || ! in_array( $status, self::STATUSES, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$count += (int) $wpdb->update(
				self::table(),
				array(
					'event_id'         => $event,
					'occurrence_start' => $start,
					'status'           => $status,
					'updated_utc'      => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}
		return $count;
	}

	/**
	 * Keep bookings on their dates when an event is moved.
	 *
	 * A one-off event moved to another day or time takes its bookings with
	 * it. A series whose time changes keeps each booking on its own date, at
	 * the new time. When an event changes between one-off and repeating,
	 * there is no telling which new date an old booking belongs to, so they
	 * are left where they are and show under their old dates.
	 *
	 * @param int    $event_id       Event ID.
	 * @param string $old            Old local start, "Y-m-d H:i" or with a "T".
	 * @param string $now            New local start.
	 * @param bool   $repeats_before Whether the event repeated before.
	 * @param bool   $repeats_after  Whether it repeats now.
	 * @return int How many bookings moved.
	 */
	public static function follow_move( int $event_id, string $old, string $now, bool $repeats_before, bool $repeats_after ): int {
		global $wpdb;

		$old = substr( str_replace( 'T', ' ', $old ), 0, 16 );
		$now = substr( str_replace( 'T', ' ', $now ), 0, 16 );
		if ( $old === $now || '' === $old || $repeats_before !== $repeats_after ) {
			return 0;
		}

		if ( $repeats_after ) {
			// The dates come from the rule; only the clock moves.
			$clock = static fn( string $at ) => (int) substr( $at, 11, 2 ) * HOUR_IN_SECONDS + (int) substr( $at, 14, 2 ) * MINUTE_IN_SECONDS;
			$delta = $clock( $now ) - $clock( $old );
		} else {
			// Wall-clock difference: both are read as UTC so no zone gets a say.
			$utc   = new DateTimeZone( 'UTC' );
			$delta = ( new DateTimeImmutable( $now, $utc ) )->getTimestamp() - ( new DateTimeImmutable( $old, $utc ) )->getTimestamp();
		}
		if ( 0 === $delta ) {
			return 0;
		}

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$moved = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET occurrence_start = DATE_ADD(occurrence_start, INTERVAL %d SECOND), updated_utc = %s WHERE event_id = %d", $delta, gmdate( 'Y-m-d H:i:s' ), $event_id ) );

		if ( $moved ) {
			// What people were last told, so moving there and back says nothing.
			if ( '' === (string) get_post_meta( $event_id, '_rayec_start_told', true ) ) {
				update_post_meta( $event_id, '_rayec_start_told', $old );
			}
			self::schedule( $event_id, 'moved', $now );
		}
		return $moved;
	}

	/**
	 * Move the bookings of one date to another event and start, as when one
	 * date of a series is taken out and moved on its own.
	 *
	 * @param int    $from_event Series.
	 * @param string $from_start Local start of the date taken out.
	 * @param int    $to_event   The event made from it.
	 * @param string $to_start   Its local start.
	 * @return array<int, array<string, mixed>> Snapshot, for undoing.
	 */
	public static function move_date( int $from_event, string $from_start, int $to_event, string $to_start ): array {
		global $wpdb;

		$ids = self::ids_between( $from_event, $from_start, $from_start );
		if ( ! $ids ) {
			return array();
		}
		$before = self::snapshot( $ids );
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->update(
				self::table(),
				array(
					'event_id'         => $to_event,
					'occurrence_start' => $to_start,
					'updated_utc'      => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		}

		$expecting = array_values( array_intersect( $ids, self::ids_between( $to_event, $to_start, $to_start, self::EXPECTING ) ) );
		if ( $expecting && $from_start !== $to_start ) {
			self::schedule( $to_event, 'moved_ids', $expecting );
		}
		return $before;
	}

	/**
	 * Move the bookings from a date onwards to another event, as when a
	 * series is split into two. The dates and times stay as they were, so
	 * nobody needs telling.
	 *
	 * @param int    $from_event Series being ended.
	 * @param string $from_date  First date that moves, "Y-m-d".
	 * @param int    $to_event   The series made from the rest.
	 * @return array<int, array<string, mixed>> Snapshot, for undoing.
	 */
	public static function move_from( int $from_event, string $from_date, int $to_event ): array {
		global $wpdb;

		$ids = self::ids_between( $from_event, $from_date . ' 00:00:00' );
		if ( ! $ids ) {
			return array();
		}
		$before = self::snapshot( $ids );
		$table  = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET event_id = %d, updated_utc = %s WHERE event_id = %d AND occurrence_start >= %s", $to_event, gmdate( 'Y-m-d H:i:s' ), $from_event, $from_date . ' 00:00:00' ) );
		return $before;
	}

	/**
	 * Cancel the bookings on dates that are no longer happening, as when a
	 * date is skipped or a series is ended early, and tell the people.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $from     First local start affected.
	 * @param string $to       Last local start affected, or empty for all after.
	 * @return array<int, array<string, mixed>> Snapshot, for undoing.
	 */
	public static function drop_dates( int $event_id, string $from, string $to = '' ): array {
		global $wpdb;

		$ids = self::ids_between( $event_id, $from, $to, self::EXPECTING );
		if ( ! $ids ) {
			return array();
		}
		$before = self::snapshot( $ids );
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->update(
				self::table(),
				array(
					'status'      => 'cancelled',
					'updated_utc' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			/** This action is documented in set_status(). */
			do_action( 'rayec_registration_status_changed', $id, 'cancelled', 'confirmed', 'system' );
		}
		self::schedule( $event_id, 'dropped', $ids );
		return $before;
	}

	/**
	 * An event's status changed: tell everyone booked on a date to come.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   New status.
	 * @param string $old      Old status.
	 * @return void
	 */
	public static function status_changed( $event_id, $status, $old ): void {
		unset( $old );
		$event = Event::get( (int) $event_id );
		if ( ! $event || 'publish' !== $event['post_status'] ) {
			return;
		}
		if ( self::ids_between( (int) $event_id, self::local_now( $event ), '', self::EXPECTING ) ) {
			self::schedule( (int) $event_id, 'status', (string) $status );
		}
	}

	/**
	 * Book the job that tells people, after the grace period.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $kind     "status", "moved", "moved_ids" or "dropped".
	 * @param mixed  $check    What must still be true when the job runs.
	 * @return void
	 */
	private static function schedule( int $event_id, string $kind, $check ): void {
		wp_schedule_single_event( time() + self::GRACE, self::NOTICE_HOOK, array( $event_id, $kind, $check ) );
	}

	/**
	 * Tell people about a change, if it still stands.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $kind     What changed.
	 * @param mixed  $check    The status, start or booking IDs it was about.
	 * @return int How many emails were sent.
	 */
	public static function send_notices( $event_id, $kind, $check ): int {
		$event = Event::get( (int) $event_id );
		if ( ! $event ) {
			return 0;
		}
		$sent = 0;

		if ( 'status' === $kind ) {
			// Changed again since (the later change sends its own), or back to what
			// people were last told, as when a slip is put right within the minute.
			$told = (string) get_post_meta( (int) $event_id, '_rayec_status_told', true );
			$told = '' !== $told ? $told : 'scheduled';
			if ( $event['event_status'] !== $check || $told === $check ) {
				return 0;
			}
			update_post_meta( (int) $event_id, '_rayec_status_told', $check );
			// One email per person, naming every date they had booked.
			$people = array();
			foreach ( self::ids_between( (int) $event_id, self::local_now( $event ), '', self::EXPECTING ) as $id ) {
				$row = self::get( $id );
				if ( $row && is_email( $row->email ) ) {
					$people[ strtolower( $row->email ) ][] = $row;
				}
			}
			foreach ( $people as $rows ) {
				$sent += (int) self::send_status( $event, $rows );
			}
			return $sent;
		}

		if ( 'moved' === $kind ) {
			if ( substr( str_replace( 'T', ' ', (string) $event['start'] ), 0, 16 ) !== $check || (string) get_post_meta( (int) $event_id, '_rayec_start_told', true ) === $check ) {
				return 0;
			}
			update_post_meta( (int) $event_id, '_rayec_start_told', $check );
			$ids = self::ids_between( (int) $event_id, self::local_now( $event ), '', self::EXPECTING );
		} else {
			$ids = array_map( 'intval', (array) $check );
		}

		foreach ( $ids as $id ) {
			$row = self::get( $id );
			if ( ! $row || (int) $row->event_id !== (int) $event_id ) {
				continue;
			}
			if ( 'dropped' === $kind ) {
				// Put back by an undo since: nothing to say.
				$sent += 'cancelled' === $row->status ? (int) self::send_dropped( $row, $event ) : 0;
			} elseif ( in_array( $row->status, self::EXPECTING, true ) ) {
				$sent += (int) self::send_moved( $row, $event );
			}
		}
		return $sent;
	}

	/**
	 * Tell one person about the event's new status.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param object[]             $rows  That person's bookings.
	 * @return bool
	 */
	private static function send_status( array $event, array $rows ): bool {
		$first  = $rows[0];
		$status = (string) $event['event_status'];
		$dates  = array();
		foreach ( $rows as $row ) {
			$dates[] = '- ' . self::when( $event, (string) $row->occurrence_start );
		}

		$news = array(
			/* translators: %s: event title. */
			'cancelled'    => array( __( 'Cancelled: %s', 'rayetun-events-calendar' ), __( 'We are sorry to tell you that %s has been cancelled.', 'rayetun-events-calendar' ), __( 'You do not need to do anything.', 'rayetun-events-calendar' ) ),
			/* translators: %s: event title. */
			'postponed'    => array( __( 'Postponed: %s', 'rayetun-events-calendar' ), __( '%s has been postponed.', 'rayetun-events-calendar' ), __( 'A new date will be announced on the event page, and your booking is kept until then.', 'rayetun-events-calendar' ) ),
			/* translators: %s: event title. */
			'moved_online' => array( __( 'Now online: %s', 'rayetun-events-calendar' ), __( '%s has moved online.', 'rayetun-events-calendar' ), '' !== (string) $event['online_url'] ? sprintf( /* translators: %s: web address. */ __( 'Join here: %s', 'rayetun-events-calendar' ), $event['online_url'] ) : __( 'Joining details will be shared before the event.', 'rayetun-events-calendar' ) ),
			/* translators: %s: event title. */
			'scheduled'    => array( __( 'Going ahead: %s', 'rayetun-events-calendar' ), __( 'Good news: %s is going ahead as planned.', 'rayetun-events-calendar' ), __( 'Your booking stands. We look forward to seeing you.', 'rayetun-events-calendar' ) ),
		);
		if ( ! isset( $news[ $status ] ) ) {
			return false;
		}

		$lines = array_merge(
			array(
				/* translators: %s: the person's name. */
				sprintf( __( 'Hello %s,', 'rayetun-events-calendar' ), $first->name ),
				'',
				sprintf( $news[ $status ][1], $event['title'] ),
				'',
				_n( 'Your booking:', 'Your bookings:', count( $dates ), 'rayetun-events-calendar' ),
			),
			$dates,
			array(
				'',
				$news[ $status ][2],
				'',
				/* translators: %s: web address. */
				sprintf( __( 'Event page: %s', 'rayetun-events-calendar' ), $event['permalink'] ),
				'',
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);

		return self::mail( (string) $first->email, sprintf( $news[ $status ][0], $event['title'] ), $lines, self::reply_to( $event ) );
	}

	/**
	 * Tell one person their date or time has changed, with a fresh calendar
	 * file and cancel link.
	 *
	 * @param object               $row   Registration.
	 * @param array<string, mixed> $event Event.
	 * @return bool
	 */
	private static function send_moved( object $row, array $event ): bool {
		$token = self::new_token( (int) $row->id );
		$row   = self::get( (int) $row->id );
		$when  = self::when( $event, (string) $row->occurrence_start );
		$lines = array(
			/* translators: %s: the person's name. */
			sprintf( __( 'Hello %s,', 'rayetun-events-calendar' ), $row->name ),
			'',
			/* translators: %s: event title. */
			sprintf( __( 'The date or time of %s has changed, and your booking has moved with it.', 'rayetun-events-calendar' ), $event['title'] ),
			'',
			/* translators: %s: date and time. */
			sprintf( __( 'Your booking is now for: %s', 'rayetun-events-calendar' ), $when ),
			'',
			/* translators: %s: web address. */
			sprintf( __( 'If the new time does not suit you, you can cancel here: %s', 'rayetun-events-calendar' ), self::cancel_url( $row, $event, $token ) ),
			'',
			__( 'An updated calendar file is attached.', 'rayetun-events-calendar' ),
			'',
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		$ics  = '';
		$date = self::upcoming_date( (int) $event['id'], (string) $row->occurrence_start );
		if ( $date ) {
			$times = Occurrences::times( $date );
			$ics   = Ics::single( $event, $times[0], $times[1] );
		}

		/* translators: %s: event title. */
		$subject = sprintf( __( 'New time: %s', 'rayetun-events-calendar' ), $event['title'] );
		return self::mail( (string) $row->email, $subject, $lines, self::reply_to( $event ), $ics, sanitize_title( $event['title'] ) . '.ics' );
	}

	/**
	 * Tell one person their date is no longer happening.
	 *
	 * @param object               $row   Registration.
	 * @param array<string, mixed> $event Event.
	 * @return bool
	 */
	private static function send_dropped( object $row, array $event ): bool {
		$when  = self::when( $event, (string) $row->occurrence_start );
		$lines = array(
			/* translators: %s: the person's name. */
			sprintf( __( 'Hello %s,', 'rayetun-events-calendar' ), $row->name ),
			'',
			/* translators: 1: event title, 2: date and time. */
			sprintf( __( 'We are sorry to tell you that %1$s will not take place on %2$s.', 'rayetun-events-calendar' ), $event['title'], $when ),
			__( 'Your booking for that date has been cancelled. You do not need to do anything.', 'rayetun-events-calendar' ),
			'',
			/* translators: %s: web address. */
			sprintf( __( 'Other dates: %s', 'rayetun-events-calendar' ), $event['permalink'] ),
			'',
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		/* translators: %s: event title. */
		$subject = sprintf( __( 'Date cancelled: %s', 'rayetun-events-calendar' ), $event['title'] );
		return self::mail( (string) $row->email, $subject, $lines, self::reply_to( $event ) );
	}

	/**
	 * Reply-To the organiser, when the event has one with an address.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string[]
	 */
	private static function reply_to( array $event ): array {
		$organiser = $event['organizer'] ?? null;
		return ( $organiser && is_email( (string) $organiser['email'] ) ) ? array( 'Reply-To: ' . $organiser['email'] ) : array();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Personal data
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the personal data exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( $exporters ) {
		$exporters['rayetun-events-calendar'] = array(
			'exporter_friendly_name' => __( 'Event registrations', 'rayetun-events-calendar' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( $erasers ) {
		$erasers['rayetun-events-calendar'] = array(
			'eraser_friendly_name' => __( 'Event registrations', 'rayetun-events-calendar' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export one person's registrations.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		global $wpdb;

		$per   = 100;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", (string) $email, $per, ( max( 1, (int) $page ) - 1 ) * $per ) );

		$items = array();
		foreach ( $rows as $row ) {
			$event   = Event::get( (int) $row->event_id );
			$title   = $event ? $event['title'] : __( '(deleted event)', 'rayetun-events-calendar' );
			$items[] = array(
				'group_id'    => 'rayec-registrations',
				'group_label' => __( 'Event registrations', 'rayetun-events-calendar' ),
				'item_id'     => 'rayec-registration-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Event', 'rayetun-events-calendar' ),
						'value' => $title,
					),
					array(
						'name'  => __( 'Date', 'rayetun-events-calendar' ),
						'value' => $event ? self::when( $event, (string) $row->occurrence_start ) : (string) $row->occurrence_start,
					),
					array(
						'name'  => __( 'Name', 'rayetun-events-calendar' ),
						'value' => (string) $row->name,
					),
					array(
						'name'  => __( 'Email', 'rayetun-events-calendar' ),
						'value' => (string) $row->email,
					),
					array(
						'name'  => __( 'Places', 'rayetun-events-calendar' ),
						'value' => (string) $row->places,
					),
					array(
						'name'  => __( 'Access requirements', 'rayetun-events-calendar' ),
						'value' => (string) $row->access_request,
					),
					array(
						'name'  => __( 'Status', 'rayetun-events-calendar' ),
						'value' => (string) $row->status,
					),
					array(
						'name'  => __( 'Registered', 'rayetun-events-calendar' ),
						'value' => (string) $row->created_utc . ' UTC',
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < $per,
		);
	}

	/**
	 * Erase one person's registrations.
	 *
	 * The rows stay, so an organiser's numbers still add up, but nothing in
	 * them can say who the person was.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, unused: everything is done in one pass.
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		global $wpdb;

		unset( $page );
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$count = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET name = %s, email = %s, access_request = '', data = '', token_hash = '', user_id = 0 WHERE email = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
				wp_privacy_anonymize_data( 'text' ),
				wp_privacy_anonymize_data( 'email' ),
				(string) $email
			)
		);

		return array(
			'items_removed'  => $count,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
