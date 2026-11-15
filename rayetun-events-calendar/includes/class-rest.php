<?php
/**
 * REST API (rayec/v1) behind the dashboard app.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route registration and handlers.
 */
class Rest {

	const NS = 'rayec/v1';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Permission: may manage events.
	 *
	 * @return bool
	 */
	public static function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission: may manage settings.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$edit   = array( __CLASS__, 'can_edit' );
		$manage = array( __CLASS__, 'can_manage' );
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);

		$routes = array(
			array( '/dashboard', WP_REST_Server::READABLE, 'dashboard', $edit ),
			array( '/events', WP_REST_Server::READABLE, 'list_events', $edit ),
			array( '/events', WP_REST_Server::CREATABLE, 'save_event', $edit ),
			array( '/events/(?P<id>\d+)', WP_REST_Server::READABLE, 'get_event', $edit, $id_arg ),
			array( '/events/(?P<id>\d+)', WP_REST_Server::EDITABLE, 'save_event', $edit, $id_arg ),
			array( '/events/(?P<id>\d+)', WP_REST_Server::DELETABLE, 'trash_event', $edit, $id_arg ),
			array( '/events/(?P<id>\d+)/restore', WP_REST_Server::CREATABLE, 'restore_event', $edit, $id_arg ),
			array( '/events/(?P<id>\d+)/duplicate', WP_REST_Server::CREATABLE, 'duplicate_event', $edit, $id_arg ),
			array( '/events/(?P<id>\d+)/move', WP_REST_Server::CREATABLE, 'move_event', $edit, $id_arg ),
			array( '/events/(?P<id>\d+)/shift', WP_REST_Server::CREATABLE, 'shift_event', $edit, $id_arg ),
			array( '/events/bulk', WP_REST_Server::CREATABLE, 'bulk_events', $edit ),
			array( '/events/(?P<id>\d+)/occurrence', WP_REST_Server::CREATABLE, 'change_occurrence', $edit, $id_arg ),
			array( '/events/(?P<id>\d+)/series-restore', WP_REST_Server::CREATABLE, 'restore_series', $edit, $id_arg ),
			array( '/guard', WP_REST_Server::CREATABLE, 'guard_check', $edit ),
			array( '/categories/(?P<id>\d+)', WP_REST_Server::EDITABLE, 'save_category', $manage, $id_arg ),
			array( '/categories/(?P<id>\d+)', WP_REST_Server::DELETABLE, 'delete_category', $manage, $id_arg ),
			array( '/occurrences', WP_REST_Server::READABLE, 'list_occurrences', $edit ),
			array( '/parse', WP_REST_Server::CREATABLE, 'parse', $edit ),
			array( '/preview', WP_REST_Server::CREATABLE, 'preview', $edit ),
			array( '/venues', WP_REST_Server::READABLE, 'list_venues', $edit ),
			array( '/venues', WP_REST_Server::CREATABLE, 'save_venue', $edit ),
			array( '/venues/(?P<id>\d+)', WP_REST_Server::EDITABLE, 'save_venue', $edit, $id_arg ),
			array( '/venues/(?P<id>\d+)', WP_REST_Server::DELETABLE, 'trash_venue', $edit, $id_arg ),
			array( '/organizers', WP_REST_Server::READABLE, 'list_organizers', $edit ),
			array( '/organizers', WP_REST_Server::CREATABLE, 'save_organizer', $edit ),
			array( '/organizers/(?P<id>\d+)', WP_REST_Server::EDITABLE, 'save_organizer', $edit, $id_arg ),
			array( '/organizers/(?P<id>\d+)', WP_REST_Server::DELETABLE, 'trash_organizer', $edit, $id_arg ),
			array( '/speakers', WP_REST_Server::READABLE, 'list_speakers', $edit ),
			array( '/speakers', WP_REST_Server::CREATABLE, 'save_speaker', $edit ),
			array( '/speakers/(?P<id>\d+)', WP_REST_Server::EDITABLE, 'save_speaker', $edit, $id_arg ),
			array( '/speakers/(?P<id>\d+)', WP_REST_Server::DELETABLE, 'trash_speaker', $edit, $id_arg ),
			array( '/programs', WP_REST_Server::READABLE, 'list_programs', $edit ),
			array( '/programs', WP_REST_Server::CREATABLE, 'save_program', $manage ),
			array( '/programs/(?P<id>\d+)', WP_REST_Server::EDITABLE, 'save_program', $manage, $id_arg ),
			array( '/programs/(?P<id>\d+)', WP_REST_Server::DELETABLE, 'delete_program', $manage, $id_arg ),
			array( '/holidays', WP_REST_Server::READABLE, 'holidays', $manage ),
			array( '/import/read', WP_REST_Server::CREATABLE, 'import_read', $edit ),
			array( '/import/run', WP_REST_Server::CREATABLE, 'import_run', $edit ),
			array( '/migrate', WP_REST_Server::READABLE, 'migrate_scan', $manage ),
			array( '/migrate/plan', WP_REST_Server::CREATABLE, 'migrate_plan', $manage ),
			array( '/migrate/run', WP_REST_Server::CREATABLE, 'migrate_run', $manage ),
			array( '/migrate/undo', WP_REST_Server::CREATABLE, 'migrate_undo', $manage ),
			array( '/onboarding', WP_REST_Server::READABLE, 'onboarding', $manage ),
			array( '/onboarding', WP_REST_Server::CREATABLE, 'onboarding_save', $manage ),
			array( '/feeds', WP_REST_Server::READABLE, 'list_feeds', $edit ),
			array( '/feeds', WP_REST_Server::CREATABLE, 'save_feed', $edit ),
			array( '/feeds/(?P<id>[a-z0-9]+)/sync', WP_REST_Server::CREATABLE, 'sync_feed', $edit ),
			array( '/feeds/(?P<id>[a-z0-9]+)', WP_REST_Server::DELETABLE, 'delete_feed', $edit ),
			array( '/views', WP_REST_Server::READABLE, 'get_views', $edit ),
			array( '/views', WP_REST_Server::CREATABLE, 'save_views', $edit ),
			array( '/categories', WP_REST_Server::READABLE, 'list_categories', $edit ),
			array( '/categories', WP_REST_Server::CREATABLE, 'save_category', $manage ),
			array( '/settings', WP_REST_Server::READABLE, 'get_settings', $manage ),
			array( '/settings', WP_REST_Server::CREATABLE, 'save_settings', $manage ),
			array( '/sample-data', WP_REST_Server::CREATABLE, 'add_sample_data', $manage ),
			array( '/sample-data', WP_REST_Server::DELETABLE, 'remove_sample_data', $manage ),
			array( '/calendar-page', WP_REST_Server::CREATABLE, 'create_calendar_page', $manage ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( __CLASS__, $route[2] ),
					'permission_callback' => $route[3],
					'args'                => $route[4] ?? array(),
				)
			);
		}

		/**
		 * Fires after the plugin's REST routes are registered.
		 *
		 * @param string $namespace REST namespace to register extra routes in.
		 */
		do_action( 'rayec_register_rest', self::NS );
	}

	/**
	 * Move a one-off event by a number of minutes and, optionally, change its length.
	 *
	 * Working in minutes (rather than absolute times) keeps this correct when the
	 * event's time zone differs from the one the planner is displayed in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function shift_event( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$event = Event::get( $id );
		if ( ! $event || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot move this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		if ( $event['rrule'] ) {
			return new WP_Error( 'rayec_recurring', __( 'This event repeats. Open it to change the series.', 'rayetun-events-calendar' ), array( 'status' => 409 ) );
		}

		if ( $event['extra_times'] ) {
			return new WP_Error( 'rayec_sessions', __( 'This event has more than one start time a day. Open it to change its times.', 'rayetun-events-calendar' ), array( 'status' => 409 ) );
		}

		// Wall-clock arithmetic: the strings are read as if they were UTC so that
		// "one day later" keeps the same time of day across a daylight-saving change.
		$start_ts = strtotime( str_replace( 'T', ' ', $event['start'] ) . ' UTC' );
		$end_ts   = strtotime( str_replace( 'T', ' ', $event['end'] ) . ' UTC' );
		$minutes  = (int) $request->get_param( 'minutes' );
		$length   = $request->get_param( 'duration' );
		if ( ! $start_ts || ! $end_ts ) {
			return new WP_Error( 'rayec_bad_date', __( 'This event has no valid date.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( $event['all_day'] ) {
			// All-day events move by whole days and keep their length.
			$minutes = (int) round( $minutes / 1440 ) * 1440;
			$length  = null;
		}

		$old_length = (int) round( ( $end_ts - $start_ts ) / 60 );
		$new_start  = $start_ts + $minutes * MINUTE_IN_SECONDS;
		$new_end    = null !== $length && '' !== $length
			? $new_start + max( 15, min( 1440, (int) $length ) ) * MINUTE_IN_SECONDS
			: $end_ts + $minutes * MINUTE_IN_SECONDS;

		$result = Event::save(
			array(
				'start' => gmdate( 'Y-m-d H:i', $new_start ),
				'end'   => gmdate( 'Y-m-d H:i', $new_end ),
			),
			$id
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'    => $id,
			'start' => gmdate( 'Y-m-d\TH:i', $new_start ),
			'end'   => gmdate( 'Y-m-d\TH:i', $new_end ),
			'undo'  => array(
				'minutes'  => -$minutes,
				'duration' => $event['all_day'] ? null : $old_length,
			),
		);
	}

	/**
	 * Apply one action to several events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function bulk_events( WP_REST_Request $request ) {
		$ids    = array_slice( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, 100 );
		$action = (string) $request->get_param( 'action' );
		if ( ! in_array( $action, array( 'publish', 'draft', 'cancelled', 'scheduled', 'trash', 'restore' ), true ) ) {
			return new WP_Error( 'rayec_bad_request', __( 'Unknown action.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$done    = array();
		$skipped = 0;
		foreach ( $ids as $id ) {
			$needs = in_array( $action, array( 'trash', 'restore' ), true ) ? 'delete_post' : 'edit_post';
			if ( Post_Types::EVENT !== get_post_type( $id ) || ! current_user_can( $needs, $id ) || ( 'publish' === $action && ! current_user_can( 'publish_posts' ) ) ) {
				++$skipped;
				continue;
			}
			switch ( $action ) {
				case 'publish':
				case 'draft':
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => $action,
						)
					);
					break;
				case 'cancelled':
				case 'scheduled':
					update_post_meta( $id, '_rayec_status', $action );
					break;
				case 'trash':
					wp_trash_post( $id );
					break;
				case 'restore':
					$status = (string) get_post_meta( $id, '_wp_trash_meta_status', true );
					wp_untrash_post( $id );
					if ( $status && 'draft' !== $status ) {
						wp_update_post(
							array(
								'ID'          => $id,
								'post_status' => $status,
							)
						);
					}
					break;
			}
			$done[] = $id;
		}

		return array(
			'done'    => $done,
			'skipped' => $skipped,
		);
	}

	/**
	 * Change one date of a repeating event, or end the series.
	 *
	 * Actions: "skip" removes one date; "end" stops the series before a date;
	 * "detach" turns one date into its own event, optionally on a new date.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function change_occurrence( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$event  = Event::get( $id );
		$date   = (string) $request->get_param( 'date' );
		$action = (string) $request->get_param( 'action' );

		if ( ! $event || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot change this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		if ( ! $event['rrule'] || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'rayec_bad_request', __( 'That date is not part of a repeating event.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		// Everything needed to put the series back exactly as it was.
		$undo = array(
			'rrule'     => $event['rrule'],
			'exdates'   => $event['exdates'],
			'remove_id' => 0,
		);

		if ( 'end' === $action || 'split' === $action ) {
			$tz     = Event::timezone( $event['timezone'] );
			$before = Event::local_datetime( $date, $tz )->modify( '-1 day' );
			if ( $before->format( 'Y-m-d' ) < substr( $event['start'], 0, 10 ) ) {
				return new WP_Error(
					'rayec_bad_request',
					'split' === $action
						? __( 'That is the first date, so there is nothing before it to keep. Edit the event itself instead.', 'rayetun-events-calendar' )
						: __( 'That would remove every date. Move the event to the bin instead.', 'rayetun-events-calendar' ),
					array( 'status' => 400 )
				);
			}

			if ( 'split' === $action ) {
				// "This and following": the dates from here on become their own series.
				$length               = strtotime( $event['end'] ) - strtotime( $event['start'] );
				$start                = $date . ' ' . substr( $event['start'], 11, 5 );
				$copy                 = $event;
				$copy['start']        = $start;
				$copy['end']          = gmdate( 'Y-m-d H:i', strtotime( $start . ' UTC' ) + max( 0, $length ) );
				$copy['exdates']      = array_values( array_filter( $event['exdates'], static fn( $d ) => $d >= $date ) );
				$copy['category_ids'] = wp_list_pluck( $event['categories'], 'id' );
				// A COUNT would restart from the split, so the new series runs open-ended instead.
				$copy['rrule'] = (string) preg_replace( '/;?COUNT=\d+/', '', $event['rrule'] );
				$created       = Event::save( $copy );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$undo['remove_id'] = $created;
			}
			$result = Event::save( array( 'rrule' => Recurrence::with_until( $event['rrule'], $before->format( 'Ymd' ) ) ), $id );
		} elseif ( 'skip' === $action || 'detach' === $action ) {
			if ( 'detach' === $action ) {
				$new_date = (string) $request->get_param( 'new_date' );
				$new_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ? $new_date : $date;
				$length   = strtotime( $event['end'] ) - strtotime( $event['start'] );
				$start    = $new_date . ' ' . substr( $event['start'], 11, 5 );

				$copy            = $event;
				$copy['rrule']   = '';
				$copy['exdates'] = array();
				// The week planner can also move the time and change the length.
				$shift    = (int) $request->get_param( 'shift_minutes' );
				$duration = $request->get_param( 'duration_minutes' );
				if ( $event['extra_times'] && ( $shift || ( null !== $duration && '' !== $duration ) ) ) {
					return new WP_Error( 'rayec_sessions', __( 'This event has more than one start time a day. Open it to change its times.', 'rayetun-events-calendar' ), array( 'status' => 409 ) );
				}
				$start_ts = strtotime( $start . ' UTC' ) + ( $event['all_day'] ? 0 : $shift * MINUTE_IN_SECONDS );
				if ( null !== $duration && '' !== $duration && ! $event['all_day'] ) {
					$length = max( 15, min( 1440, (int) $duration ) ) * MINUTE_IN_SECONDS;
				}
				$copy['start']        = gmdate( 'Y-m-d H:i', $start_ts );
				$copy['end']          = gmdate( 'Y-m-d H:i', $start_ts + max( 0, $length ) );
				$copy['category_ids'] = wp_list_pluck( $event['categories'], 'id' );
				$created              = Event::save( $copy );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$undo['remove_id'] = $created;
			}
			$result = Event::save( array( 'exdates' => array_merge( $event['exdates'], array( $date ) ) ), $id );
		} else {
			return new WP_Error( 'rayec_bad_request', __( 'Unknown action.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'      => $id,
			'created' => $undo['remove_id'],
			'undo'    => $undo,
		);
	}

	/**
	 * Undo a change made by change_occurrence().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_series( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Event::get( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot change this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		$remove = (int) $request->get_param( 'remove_id' );
		if ( $remove && Post_Types::EVENT === get_post_type( $remove ) && current_user_can( 'delete_post', $remove ) ) {
			wp_delete_post( $remove, true );
		}
		$result = Event::save(
			array(
				'rrule'   => (string) $request->get_param( 'rrule' ),
				'exdates' => (array) $request->get_param( 'exdates' ),
			),
			$id
		);
		return is_wp_error( $result ) ? $result : array( 'restored' => true );
	}

	/**
	 * List organizers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_organizers(): array {
		return Organizer::all();
	}

	/**
	 * Create or update an organizer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_organizer( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot edit this organizer.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		$params = $request->get_json_params();
		$result = Organizer::save( is_array( $params ) ? $params : array(), $id );
		return is_wp_error( $result ) ? $result : Organizer::get( $result );
	}

	/**
	 * Bin an organizer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash_organizer( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Post_Types::ORGANIZER !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot delete this organizer.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		wp_trash_post( $id );
		return array( 'trashed' => true );
	}

	/**
	 * List speakers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_speakers(): array {
		return Speaker::all();
	}

	/**
	 * Create or update a speaker.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_speaker( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot edit this speaker.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		$params = $request->get_json_params();
		$result = Speaker::save( is_array( $params ) ? $params : array(), $id );
		return is_wp_error( $result ) ? $result : Speaker::get( $result );
	}

	/**
	 * Bin a speaker.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash_speaker( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Post_Types::SPEAKER !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot delete this speaker.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		wp_trash_post( $id );
		return array( 'trashed' => true );
	}

	/**
	 * Delete a category.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function delete_category( WP_REST_Request $request ) {
		$result = wp_delete_term( (int) $request['id'], Post_Types::CATEGORY );
		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error( 'rayec_not_found', __( 'That category no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}
		return self::list_categories();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shaping helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Occurrence row as the app expects it.
	 *
	 * @param object               $row   Occurrence row.
	 * @param array<string, mixed> $event Event data.
	 * @return array<string, mixed>
	 */
	public static function occurrence_view( object $row, array $event ): array {
		list( $start, $end ) = Occurrences::times( $row );

		return array(
			'id'           => (int) $row->id,
			'event_id'     => (int) $row->event_id,
			'title'        => $event['title'],
			'start'        => $start->format( 'Y-m-d\TH:i' ),
			'end'          => $end->format( 'Y-m-d\TH:i' ),
			'date'         => $start->format( 'Y-m-d' ),
			'all_day'      => (bool) $row->all_day,
			'time_label'   => (int) $row->all_day ? __( 'All day', 'rayetun-events-calendar' ) : wp_date( Plugin::time_format(), $start->getTimestamp(), $start->getTimezone() ),
			'when_label'   => Occurrences::format_range( $start, $end, (bool) $row->all_day ),
			'recurring'    => '' !== $event['rrule'],
			'event_status' => $event['event_status'],
			'post_status'  => $event['post_status'],
			'color'        => $event['categories'][0]['color'] ?? '',
			'venue_name'   => $event['venue']['name'] ?? '',
			'room'         => $event['room'],
			'venue_id'     => (int) $event['venue_id'],
			'room_id'      => $event['room_id'],
			'multi'        => ! empty( $event['extra_times'] ),
			'image'        => $event['image']['url'] ?? '',
			'summary'      => $event['summary'],
			'rrule_text'   => $event['rrule_text'],
			'format'       => $event['format'],
			'permalink'    => $event['permalink'],
		);
	}

	/**
	 * Occurrence rows to views, loading each event once.
	 *
	 * @param array<int, object> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function occurrence_views( array $rows ): array {
		Event::prime( wp_list_pluck( $rows, 'event_id' ) );

		$events = array();
		$out    = array();
		foreach ( $rows as $row ) {
			$event_id = (int) $row->event_id;
			if ( ! array_key_exists( $event_id, $events ) ) {
				$events[ $event_id ] = Event::get( $event_id );
			}
			if ( $events[ $event_id ] ) {
				$out[] = self::occurrence_view( $row, $events[ $event_id ] );
			}
		}
		return $out;
	}

	/**
	 * UTC string for a site-local date boundary.
	 *
	 * @param string $date   "Y-m-d".
	 * @param string $modify Optional relative modifier.
	 * @return string
	 */
	private static function utc( string $date, string $modify = '' ): string {
		$local = Event::local_datetime( $date, wp_timezone() );
		$local = $local ? $local : new DateTimeImmutable( 'today', wp_timezone() );
		if ( '' !== $modify ) {
			$local = $local->modify( $modify );
		}
		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Event with its Guard result and next occurrence.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param object|null          $next  Next occurrence row.
	 * @return array<string, mixed>
	 */
	private static function event_view( array $event, ?object $next = null ): array {
		$event['guard'] = Accessibility_Guard::check( $event );
		$event['next']  = $next ? self::occurrence_view( $next, $event ) : null;
		return $event;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Dashboard
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Home screen data.
	 *
	 * @return array<string, mixed>
	 */
	public static function dashboard(): array {
		global $wpdb;

		$statuses = array( 'publish', 'draft', 'pending', 'private' );
		$today    = wp_date( 'Y-m-d' );
		$now_utc  = gmdate( 'Y-m-d H:i:s' );

		$today_rows = Occurrences::query(
			array(
				'start'    => self::utc( $today ),
				'end'      => self::utc( $today, '+1 day' ),
				'statuses' => $statuses,
				'limit'    => 50,
			)
		);
		$week_rows  = Occurrences::query(
			array(
				'start'    => self::utc( $today, '+1 day' ),
				'end'      => self::utc( $today, '+8 days' ),
				'statuses' => $statuses,
				'limit'    => 50,
			)
		);

		// Accessibility score across upcoming events.
		$table = $wpdb->prefix . Occurrences::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$upcoming_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$table} o INNER JOIN {$wpdb->posts} p ON p.ID = o.event_id WHERE o.end_utc >= %s AND p.post_status IN ('publish','draft','pending','private') LIMIT 300", $now_utc ) ) );

		Event::prime( $upcoming_ids );

		$total     = 0;
		$checked   = 0;
		$flagged   = array();
		$recurring = 0;
		foreach ( $upcoming_ids as $event_id ) {
			$event = Event::get( $event_id );
			if ( ! $event ) {
				continue;
			}
			$guard  = Accessibility_Guard::check( $event );
			$total += $guard['score'];
			++$checked;
			if ( $event['rrule'] ) {
				++$recurring;
			}
			if ( $guard['issues'] ) {
				$flagged[] = array(
					'id'     => $event_id,
					'title'  => $event['title'],
					'score'  => $guard['score'],
					'issues' => $guard['issues'],
				);
			}
		}
		usort( $flagged, static fn( $a, $b ) => $a['score'] <=> $b['score'] );

		$counts = wp_count_posts( Post_Types::EVENT );
		$venues = wp_count_posts( Post_Types::VENUE );
		$all    = (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 ) + (int) ( $counts->pending ?? 0 ) + (int) ( $counts->private ?? 0 );

		$has_access = false;
		foreach ( Venue::all() as $venue ) {
			if ( $venue['access'] || '' !== $venue['access_notes'] ) {
				$has_access = true;
				break;
			}
		}

		return array(
			'stats'       => array(
				'upcoming'  => count( $upcoming_ids ),
				'this_week' => count( $today_rows ) + count( $week_rows ),
				'recurring' => $recurring,
				'venues'    => (int) ( $venues->publish ?? 0 ),
				'drafts'    => (int) ( $counts->draft ?? 0 ) + (int) ( $counts->pending ?? 0 ),
			),
			'today'       => self::occurrence_views( $today_rows ),
			'week'        => self::occurrence_views( $week_rows ),
			'a11y'        => array(
				'score'   => $checked ? (int) round( $total / $checked ) : null,
				'checked' => $checked,
				'flagged' => array_slice( $flagged, 0, 5 ),
				'clean'   => $checked - count( $flagged ),
			),
			'checklist'   => array(
				'event'  => $all > 0,
				'venue'  => (int) ( $venues->publish ?? 0 ) > 0,
				'access' => $has_access,
				'page'   => (bool) self::calendar_page_id(),
			),
			'calendar'    => self::calendar_page_id() ? get_permalink( self::calendar_page_id() ) : get_post_type_archive_link( Post_Types::EVENT ),
			'has_samples' => (bool) self::sample_ids(),
		);
	}

	/**
	 * ID of a published page that shows the calendar.
	 *
	 * @return int
	 */
	public static function calendar_page_id(): int {
		$cached = get_transient( 'rayec_calendar_page' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup, cached in a transient below.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT 1",
				'%' . $wpdb->esc_like( '<!-- wp:rayec/calendar' ) . '%',
				'%' . $wpdb->esc_like( '[rayec_calendar' ) . '%'
			)
		);
		set_transient( 'rayec_calendar_page', $id, 5 * MINUTE_IN_SECONDS );
		return $id;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Events
	 * ---------------------------------------------------------------------
	 */

	/**
	 * List events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function list_events( WP_REST_Request $request ): array {
		global $wpdb;

		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$when     = sanitize_key( (string) $request->get_param( 'when' ) );
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 20 ) ) );

		$args = array(
			'post_type'      => Post_Types::EVENT,
			'post_status'    => in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'trash' ), true ) ? $status : array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $per_page,
			'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
			's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin list ordered by start date.
			'meta_key'       => '_rayec_start',
			'orderby'        => 'meta_value',
			'order'          => 'past' === $when ? 'DESC' : 'ASC',
		);

		if ( in_array( $when, array( 'upcoming', 'past' ), true ) ) {
			$table = $wpdb->prefix . Occurrences::TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
			$upcoming = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$table} WHERE end_utc >= %s LIMIT 5000", gmdate( 'Y-m-d H:i:s' ) ) ) );
			if ( 'upcoming' === $when ) {
				$args['post__in'] = $upcoming ? $upcoming : array( 0 );
			} elseif ( $upcoming ) {
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- bounded admin query.
				$args['post__not_in'] = $upcoming;
			}
		}

		$query = new WP_Query( $args );
		$next  = Occurrences::next_for_events( wp_list_pluck( $query->posts, 'ID' ) );
		Event::prime( wp_list_pluck( $query->posts, 'ID' ) );
		$items = array();
		foreach ( $query->posts as $post ) {
			$event = Event::get( $post );
			if ( $event ) {
				$items[] = self::event_view( $event, $next[ $post->ID ] ?? null );
			}
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * One event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_event( WP_REST_Request $request ) {
		$event = Event::get( (int) $request['id'] );
		if ( ! $event || ! current_user_can( 'edit_post', $event['id'] ) ) {
			return new WP_Error( 'rayec_not_found', __( 'That event no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}
		$next = Occurrences::next_for_events( array( $event['id'] ) );
		return self::event_view( $event, $next[ $event['id'] ] ?? null );
	}

	/**
	 * Run the Accessibility Guard over what is on screen, saved or not.
	 *
	 * The editor sends the title, the content and the featured image it is
	 * holding; everything else comes from the saved event. That way the
	 * pre-publish check is about what is being published, not about the last
	 * version that reached the database.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function guard_check( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;

		if ( $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'rayec_forbidden', __( 'You cannot edit this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
			}
			$event = Event::get( $id );
			if ( ! $event ) {
				return new WP_Error( 'rayec_not_found', __( 'That event no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
			}
		} else {
			$event = Event::blank();
		}

		if ( array_key_exists( 'title', $params ) ) {
			$event['title'] = wp_strip_all_tags( (string) $params['title'] );
		}
		if ( array_key_exists( 'content', $params ) ) {
			$event['description'] = wp_kses_post( (string) $params['content'] );
		}
		if ( array_key_exists( 'thumbnail_id', $params ) ) {
			$event['thumbnail_id'] = (int) $params['thumbnail_id'];
		}

		return Accessibility_Guard::check( $event );
	}

	/**
	 * Create or update an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_event( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot edit this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		$result = Event::save( is_array( $params ) ? $params : array(), $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$next = Occurrences::next_for_events( array( $result ) );
		return self::event_view( Event::get( $result ), $next[ $result ] ?? null );
	}

	/**
	 * Move an event to the bin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash_event( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Post_Types::EVENT !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot delete this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		wp_trash_post( $id );
		return array(
			'trashed' => true,
			'id'      => $id,
		);
	}

	/**
	 * Restore an event from the bin (Undo).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_event( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Post_Types::EVENT !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot restore this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		$status = (string) get_post_meta( $id, '_wp_trash_meta_status', true );
		wp_untrash_post( $id );
		if ( $status && 'draft' !== $status ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $status,
				)
			);
		}
		return array(
			'restored' => true,
			'id'       => $id,
		);
	}

	/**
	 * Duplicate an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate_event( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot copy this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		$result = Event::duplicate( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::event_view( Event::get( $result ) );
	}

	/**
	 * Move a one-off event to another date, keeping its times.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function move_event( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$event = Event::get( $id );
		if ( ! $event || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot move this event.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		if ( $event['rrule'] ) {
			return new WP_Error( 'rayec_recurring', __( 'This event repeats. Open it to change the series.', 'rayetun-events-calendar' ), array( 'status' => 409 ) );
		}

		$tz     = Event::timezone( $event['timezone'] );
		$target = Event::local_datetime( (string) $request->get_param( 'date' ), $tz );
		$start  = Event::local_datetime( $event['start'], $tz );
		$end    = Event::local_datetime( $event['end'], $tz );
		if ( ! $target || ! $start || ! $end ) {
			return new WP_Error( 'rayec_bad_date', __( 'That is not a valid date.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$days      = (int) $start->setTime( 0, 0 )->diff( $target->setTime( 0, 0 ) )->format( '%r%a' );
		$new_start = $start->modify( sprintf( '%+d days', $days ) );
		$new_end   = $end->modify( sprintf( '%+d days', $days ) );

		$result = Event::save(
			array(
				'start' => $new_start->format( 'Y-m-d H:i' ),
				'end'   => $new_end->format( 'Y-m-d H:i' ),
			),
			$id
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'       => $id,
			'previous' => substr( $event['start'], 0, 10 ),
			'date'     => $new_start->format( 'Y-m-d' ),
		);
	}

	/**
	 * Occurrences in a date range (site time zone).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_occurrences( WP_REST_Request $request ): array {
		$rows = Occurrences::query(
			array(
				'start'    => self::utc( (string) $request->get_param( 'start' ) ),
				'end'      => self::utc( (string) $request->get_param( 'end' ), '+1 day' ),
				'statuses' => array( 'publish', 'draft', 'pending', 'private' ),
				'limit'    => 2000,
			)
		);
		return self::occurrence_views( $rows );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scheduling helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Next dates for a start and rule.
	 *
	 * @param string $start   Local start.
	 * @param string $end     Local end.
	 * @param string $tz_name Time zone.
	 * @param string $rrule   Rule.
	 * @param bool   $all_day All-day flag.
	 * @param array  $extra   Venue, room, speakers and the other things a preview shows.
	 * @return array<string, mixed>
	 */
	private static function preview_data( string $start, string $end, string $tz_name, string $rrule, bool $all_day, array $extra = array() ): array {
		$tz       = Event::timezone( $tz_name );
		$start_dt = Event::local_datetime( $start, $tz );
		if ( ! $start_dt ) {
			return array(
				'dates' => array(),
				'text'  => '',
				'more'  => false,
			);
		}
		$end_dt   = Event::local_datetime( $end, $tz );
		$duration = $end_dt ? max( 0, $end_dt->getTimestamp() - $start_dt->getTimestamp() ) : HOUR_IN_SECONDS;
		$closed   = ! empty( $extra['skip_closed'] ) && $rrule ? Plugin::closed_dates() : array();
		$starts   = Recurrence::expand( $start_dt, $rrule, $start_dt->modify( '+10 years' ), 11 + count( $closed ) );
		$skipped  = array();
		$starts   = array_values(
			array_filter(
				$starts,
				static function ( $occurrence ) use ( $closed, &$skipped ) {
					$key = $occurrence->format( 'Y-m-d' );
					if ( isset( $closed[ $key ] ) ) {
						$skipped[] = '' !== $closed[ $key ] ? $closed[ $key ] : $key;
						return false;
					}
					return true;
				}
			)
		);

		$utc       = new DateTimeZone( 'UTC' );
		$dates     = array();
		$conflicts = array();
		$venue     = ! empty( $extra['venue_id'] ) ? Venue::get( (int) $extra['venue_id'] ) : null;
		$room      = Venue::room_name( $venue, (string) ( $extra['room_id'] ?? '' ) );
		$times     = $all_day ? array() : Event::clean_times( $extra['extra_times'] ?? array(), $start_dt->format( 'H:i' ) );
		$speakers  = array_map( 'absint', (array) ( $extra['speaker_ids'] ?? array() ) );
		$sessions  = array();
		foreach ( array_slice( $starts, 0, 10 ) as $first ) {
			$sessions = array_merge( $sessions, Occurrences::sessions( $first, $times ) );
		}
		foreach ( array_slice( $sessions, 0, 10 ) as $occurrence ) {
			$occurrence_end = $occurrence->setTimestamp( $occurrence->getTimestamp() + $duration );
			$label          = Occurrences::format_range( $occurrence, $occurrence_end, $all_day );
			$dates[]        = $label;

			$clashes = count( $conflicts ) < 3 ? Occurrences::venue_conflicts(
				(int) ( $extra['venue_id'] ?? 0 ),
				$occurrence->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				$occurrence_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				(int) ( $extra['event_id'] ?? 0 )
			) : array();
			foreach ( $clashes as $clash ) {
				// Two events in different rooms of one venue do not clash. An event
				// with no room is taken to use the whole venue.
				$other = (string) get_post_meta( (int) $clash->event_id, '_rayec_room', true );
				$other = Venue::room_name( $venue, $other );
				if ( '' !== $room && '' !== $other && $room !== $other ) {
					continue;
				}
				$conflicts[] = array(
					'when'  => $label,
					'title' => get_the_title( (int) $clash->event_id ),
					'room'  => $other,
				);
			}

			$busy = count( $conflicts ) < 3 ? Occurrences::speaker_conflicts(
				$speakers,
				$occurrence->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				$occurrence_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				(int) ( $extra['event_id'] ?? 0 )
			) : array();
			foreach ( $busy as $clash ) {
				$conflicts[] = array(
					'when'    => $label,
					'title'   => get_the_title( (int) $clash->event_id ),
					'room'    => '',
					'speaker' => get_the_title( (int) $clash->speaker_id ),
				);
			}
		}

		return array(
			'dates'     => $dates,
			'text'      => $rrule ? Recurrence::describe( $rrule, $start_dt ) : '',
			'more'      => count( $starts ) > 10 || count( $sessions ) > 10,
			'skipped'   => array_slice( array_unique( $skipped ), 0, 5 ),
			'conflicts' => array_slice( $conflicts, 0, 3 ),
		);
	}

	/**
	 * Parse a plain-language sentence.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function parse( WP_REST_Request $request ): array {
		$tz_name = sanitize_text_field( (string) $request->get_param( 'timezone' ) );
		$parsed  = Natural_Language::parse( sanitize_text_field( (string) $request->get_param( 'text' ) ), Event::timezone( $tz_name ) );

		$parsed['preview'] = self::preview_data( $parsed['start'], $parsed['end'], $tz_name, $parsed['rrule'], $parsed['all_day'], array( 'skip_closed' => $parsed['skip_closed'] ) );
		return $parsed;
	}

	/**
	 * Preview a rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function preview( WP_REST_Request $request ): array {
		return self::preview_data(
			sanitize_text_field( (string) $request->get_param( 'start' ) ),
			sanitize_text_field( (string) $request->get_param( 'end' ) ),
			sanitize_text_field( (string) $request->get_param( 'timezone' ) ),
			Recurrence::normalize( sanitize_text_field( (string) $request->get_param( 'rrule' ) ) ),
			(bool) $request->get_param( 'all_day' ),
			array(
				'skip_closed' => (bool) $request->get_param( 'skip_closed' ),
				'venue_id'    => absint( $request->get_param( 'venue_id' ) ),
				'room_id'     => sanitize_key( (string) $request->get_param( 'room_id' ) ),
				'speaker_ids' => array_map( 'absint', (array) $request->get_param( 'speaker_ids' ) ),
				'extra_times' => array_map( 'sanitize_text_field', (array) $request->get_param( 'extra_times' ) ),
				'event_id'    => absint( $request->get_param( 'event_id' ) ),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Venues and categories
	 * ---------------------------------------------------------------------
	 */

	/**
	 * List venues.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_venues(): array {
		return Venue::all();
	}

	/**
	 * Create or update a venue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_venue( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot edit this venue.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		$params = $request->get_json_params();
		$result = Venue::save( is_array( $params ) ? $params : array(), $id );
		return is_wp_error( $result ) ? $result : Venue::get( $result );
	}

	/**
	 * Bin a venue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash_venue( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Post_Types::VENUE !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot delete this venue.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		wp_trash_post( $id );
		return array( 'trashed' => true );
	}

	/**
	 * List categories.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::CATEGORY,
				'hide_empty' => false,
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$color = (string) get_term_meta( $term->term_id, 'rayec_color', true );
			$out[] = array(
				'id'       => $term->term_id,
				'name'     => $term->name,
				'color'    => $color,
				'icon'     => (string) get_term_meta( $term->term_id, 'rayec_icon', true ),
				'contrast' => $color ? round( Accessibility_Guard::contrast( $color, '#ffffff' ), 2 ) : null,
				'count'    => (int) $term->count,
			);
		}
		return $out;
	}

	/**
	 * Create a category.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function save_category( WP_REST_Request $request ) {
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$color = sanitize_hex_color( (string) $request->get_param( 'color' ) );
		if ( '' === $name ) {
			return new WP_Error( 'rayec_no_name', __( 'Give the category a name.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		$id   = (int) $request['id'];
		$term = $id ? wp_update_term( $id, Post_Types::CATEGORY, array( 'name' => $name ) ) : wp_insert_term( $name, Post_Types::CATEGORY );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		update_term_meta( $term['term_id'], 'rayec_color', $color ? $color : '' );
		$icon = sanitize_key( (string) $request->get_param( 'icon' ) );
		update_term_meta( $term['term_id'], 'rayec_icon', isset( Frontend::category_icons()[ $icon ] ) ? $icon : '' );
		return self::list_categories();
	}

	/**
	 * List programs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_programs(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::PROGRAM,
				'hide_empty' => false,
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link  = get_term_link( $term );
			$out[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'description' => $term->description,
				'count'       => (int) $term->count,
				'url'         => is_wp_error( $link ) ? '' : $link,
			);
		}
		return $out;
	}

	/**
	 * Create or update a program.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function save_program( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'rayec_no_name', __( 'Give the program a name.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		$args = array(
			'name'        => $name,
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
		);
		$id   = (int) $request['id'];
		$term = $id ? wp_update_term( $id, Post_Types::PROGRAM, $args ) : wp_insert_term( $name, Post_Types::PROGRAM, $args );
		return is_wp_error( $term ) ? $term : self::list_programs();
	}

	/**
	 * Delete a program. Its events are kept.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function delete_program( WP_REST_Request $request ) {
		$result = wp_delete_term( (int) $request['id'], Post_Types::PROGRAM );
		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error( 'rayec_not_found', __( 'That program no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}
		return self::list_programs();
	}

	/**
	 * Bundled public holidays for a country and year.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function holidays( WP_REST_Request $request ): array {
		return array(
			'countries' => Holidays::countries(),
			'items'     => Holidays::for_year( strtoupper( sanitize_key( (string) $request->get_param( 'country' ) ) ), (int) $request->get_param( 'year' ) ),
		);
	}

	/**
	 * The current user's saved views of the Events table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_views(): array {
		$views = get_user_meta( get_current_user_id(), 'rayec_event_views', true );
		return is_array( $views ) ? array_values( $views ) : array();
	}

	/**
	 * Replace the current user's saved views.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	public static function save_views( WP_REST_Request $request ): array {
		$views = array();
		foreach ( array_slice( (array) $request->get_param( 'views' ), 0, 20 ) as $view ) {
			$name = is_array( $view ) ? sanitize_text_field( (string) ( $view['name'] ?? '' ) ) : '';
			if ( '' === $name ) {
				continue;
			}
			$tab     = (string) ( $view['tab'] ?? '' );
			$views[] = array(
				'name'    => $name,
				'tab'     => in_array( $tab, array( 'upcoming', 'past', 'draft', 'all' ), true ) ? $tab : 'upcoming',
				'search'  => sanitize_text_field( (string) ( $view['search'] ?? '' ) ),
				'columns' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $view['columns'] ?? array() ) ) ) ),
			);
		}
		update_user_meta( get_current_user_id(), 'rayec_event_views', $views );
		return $views;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings and setup
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		return Plugin::settings();
	}

	/**
	 * Save settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function save_settings( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return Plugin::save_settings( is_array( $params ) ? $params : array() );
	}

	/**
	 * IDs of sample posts.
	 *
	 * @return int[]
	 */
	private static function sample_ids(): array {
		return get_posts(
			array(
				'post_type'      => array( Post_Types::EVENT, Post_Types::VENUE ),
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-only lookup.
				'meta_key'       => '_rayec_sample',
			)
		);
	}

	/**
	 * Add sample venues, categories and events.
	 *
	 * @return array<string, mixed>
	 */
	public static function add_sample_data(): array {
		if ( self::sample_ids() ) {
			return array( 'created' => 0 );
		}

		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );

		$hall   = Venue::save(
			array(
				'name'         => __( 'Community Hall', 'rayetun-events-calendar' ),
				'address'      => __( '12 Market Street', 'rayetun-events-calendar' ),
				'city'         => __( 'Riverside', 'rayetun-events-calendar' ),
				'access'       => array( 'step_free', 'accessible_toilet', 'hearing_loop', 'seating' ),
				'access_notes' => __( 'Level entrance from the car park side. Two blue-badge bays by the door.', 'rayetun-events-calendar' ),
			)
		);
		$studio = Venue::save(
			array(
				'name'    => __( 'Riverside Studio', 'rayetun-events-calendar' ),
				'address' => __( '4 Mill Lane', 'rayetun-events-calendar' ),
				'city'    => __( 'Riverside', 'rayetun-events-calendar' ),
			)
		);

		$categories = array();
		foreach (
			array(
				'wellbeing' => array( __( 'Wellbeing', 'rayetun-events-calendar' ), '#0F766E' ),
				'community' => array( __( 'Community', 'rayetun-events-calendar' ), '#B45309' ),
				'learning'  => array( __( 'Learning', 'rayetun-events-calendar' ), '#4338CA' ),
			) as $key => $category
		) {
			$term = term_exists( $category[0], Post_Types::CATEGORY );
			$term = $term ? $term : wp_insert_term( $category[0], Post_Types::CATEGORY );
			if ( ! is_wp_error( $term ) ) {
				$categories[ $key ] = (int) $term['term_id'];
				update_term_meta( $categories[ $key ], 'rayec_color', $category[1] );
			}
		}

		$saturday = $today->modify( 'next saturday' );
		$samples  = array(
			array(
				'title'        => __( 'Gentle Yoga', 'rayetun-events-calendar' ),
				'description'  => '<p>' . __( 'A slow, friendly class for all bodies. Mats and chairs are provided, and every pose has a seated option.', 'rayetun-events-calendar' ) . '</p>',
				'start'        => $today->setTime( 18, 0 )->format( 'Y-m-d H:i' ),
				'end'          => $today->setTime( 19, 0 )->format( 'Y-m-d H:i' ),
				'rrule'        => 'FREQ=WEEKLY;BYDAY=TU,TH',
				'venue_id'     => is_wp_error( $studio ) ? 0 : $studio,
				'category_ids' => array( $categories['wellbeing'] ?? 0 ),
				'cost'         => __( 'Free', 'rayetun-events-calendar' ),
			),
			array(
				'title'        => __( 'Neighbourhood Forum', 'rayetun-events-calendar' ),
				'description'  => '<p>' . __( 'Monthly open meeting. Agenda items can be sent in advance, and a hearing loop is switched on for every session.', 'rayetun-events-calendar' ) . '</p>',
				'start'        => $today->setTime( 19, 30 )->format( 'Y-m-d H:i' ),
				'end'          => $today->setTime( 21, 0 )->format( 'Y-m-d H:i' ),
				'rrule'        => 'FREQ=MONTHLY;BYDAY=2TU',
				'venue_id'     => is_wp_error( $hall ) ? 0 : $hall,
				'category_ids' => array( $categories['community'] ?? 0 ),
			),
			array(
				'title'        => __( 'Spring Makers Market', 'rayetun-events-calendar' ),
				'description'  => '<p>' . __( 'Thirty local makers, live music and a quiet hour from 10 to 11 with the music off.', 'rayetun-events-calendar' ) . '</p>',
				'start'        => $saturday->format( 'Y-m-d' ),
				'end'          => $saturday->format( 'Y-m-d' ),
				'all_day'      => true,
				'venue_id'     => is_wp_error( $hall ) ? 0 : $hall,
				'category_ids' => array( $categories['community'] ?? 0 ),
				'featured'     => true,
			),
			array(
				'title'        => __( 'Intro to Screen Readers', 'rayetun-events-calendar' ),
				'description'  => '<p>' . __( 'A one-hour online walkthrough with live captions. The recording is shared with everyone who joins.', 'rayetun-events-calendar' ) . '</p>',
				'start'        => $today->modify( '+3 days' )->setTime( 12, 0 )->format( 'Y-m-d H:i' ),
				'end'          => $today->modify( '+3 days' )->setTime( 13, 0 )->format( 'Y-m-d H:i' ),
				'format'       => 'online',
				'online_url'   => 'https://example.com/join',
				'category_ids' => array( $categories['learning'] ?? 0 ),
			),
			array(
				'title'       => __( 'Volunteer Welcome Evening', 'rayetun-events-calendar' ),
				'description' => '',
				'start'       => $today->modify( '+5 days' )->setTime( 18, 30 )->format( 'Y-m-d H:i' ),
				'end'         => $today->modify( '+5 days' )->setTime( 20, 0 )->format( 'Y-m-d H:i' ),
				'post_status' => 'draft',
			),
		);

		// Start repeating samples on a day their rule lands on.
		$samples[0]['start'] = $today->modify( 'tuesday this week' ) >= $today ? $today->modify( 'tuesday this week' )->setTime( 18, 0 )->format( 'Y-m-d H:i' ) : $today->modify( 'next tuesday' )->setTime( 18, 0 )->format( 'Y-m-d H:i' );
		$samples[0]['end']   = substr( $samples[0]['start'], 0, 10 ) . ' 19:00';
		$second_tuesday      = $today->modify( 'second tuesday of this month' );
		if ( $second_tuesday < $today ) {
			$second_tuesday = $today->modify( 'second tuesday of next month' );
		}
		$samples[1]['start'] = $second_tuesday->setTime( 19, 30 )->format( 'Y-m-d H:i' );
		$samples[1]['end']   = $second_tuesday->setTime( 21, 0 )->format( 'Y-m-d H:i' );

		$created = 0;
		foreach ( array( $hall, $studio ) as $venue_id ) {
			if ( ! is_wp_error( $venue_id ) ) {
				update_post_meta( $venue_id, '_rayec_sample', 1 );
			}
		}
		foreach ( $samples as $sample ) {
			$id = Event::save( $sample );
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_rayec_sample', 1 );
				++$created;
			}
		}

		return array( 'created' => $created );
	}

	/**
	 * Delete sample content.
	 *
	 * @return array<string, mixed>
	 */
	public static function remove_sample_data(): array {
		$ids = self::sample_ids();
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return array( 'removed' => count( $ids ) );
	}

	/**
	 * Read a calendar, from an address or from pasted text, and say what
	 * importing it would do. Nothing is written.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_read( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$url    = trim( (string) ( $params['url'] ?? '' ) );
		$text   = (string) ( $params['text'] ?? '' );

		if ( '' !== $url ) {
			$text = Import::fetch( $url );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
		}
		if ( '' === trim( (string) $text ) ) {
			return new WP_Error( 'rayec_nothing', __( 'Paste a calendar, choose a file, or give an address.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$text      = (string) $text;
		$day_first = ! empty( $params['day_first'] );

		if ( self::looks_like_csv( $text ) ) {
			$csv = Import::read_csv( $text );
			if ( is_wp_error( $csv ) ) {
				return $csv;
			}

			// The owner's choice of columns wins; otherwise the headers are read.
			$mapping = array();
			if ( isset( $params['mapping'] ) && is_array( $params['mapping'] ) ) {
				$fields = Import::csv_fields();
				foreach ( $params['mapping'] as $index => $field ) {
					$field                   = (string) $field;
					$mapping[ (int) $index ] = isset( $fields[ $field ] ) ? $field : '';
				}
			}
			if ( ! $mapping ) {
				$mapping = Import::guess_mapping( $csv['headers'] );
			}

			$items = Import::from_csv( $csv['rows'], $mapping, $day_first );
			$plan  = Import::plan( $items, array( 'source' => $url ) );

			return array(
				'format'    => 'csv',
				'source'    => $url,
				'columns'   => $csv['headers'],
				'mapping'   => $mapping,
				'fields'    => Import::csv_fields(),
				'day_first' => $day_first,
				'sample'    => array_slice( $csv['rows'], 0, 3 ),
				'items'     => $items,
				'counts'    => $plan['counts'],
				'rows'      => $plan['rows'],
			);
		}

		$items = Import::parse( $text );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$plan = Import::plan( $items, array( 'source' => $url ) );
		return array(
			'format' => 'ics',
			'source' => $url,
			'items'  => $items,
			'counts' => $plan['counts'],
			'rows'   => $plan['rows'],
		);
	}

	/**
	 * Whether a file is a spreadsheet rather than a calendar.
	 *
	 * @param string $text File contents.
	 * @return bool
	 */
	private static function looks_like_csv( string $text ): bool {
		if ( false !== strpos( $text, 'BEGIN:VCALENDAR' ) || false !== strpos( $text, 'BEGIN:VEVENT' ) ) {
			return false;
		}
		$first = (string) strtok( ltrim( $text ), "\n" );
		return substr_count( $first, ',' ) >= 1 || substr_count( $first, ';' ) >= 1 || substr_count( $first, "\t" ) >= 1;
	}

	/**
	 * Import the events the owner has just seen in the plan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_run( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$items  = $params['items'] ?? array();
		if ( ! is_array( $items ) || ! $items ) {
			return new WP_Error( 'rayec_nothing', __( 'There is nothing to import.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( count( $items ) > Import::MAX_EVENTS ) {
			$items = array_slice( $items, 0, Import::MAX_EVENTS );
		}

		return Import::run(
			array_map( array( __CLASS__, 'import_item' ), $items ),
			array(
				'source'         => trim( (string) ( $params['source'] ?? '' ) ),
				'post_status'    => (string) ( $params['post_status'] ?? 'draft' ),
				'category'       => (int) ( $params['category'] ?? 0 ),
				'create_venues'  => ! empty( $params['create_venues'] ),
				'use_categories' => ! empty( $params['use_categories'] ),
			)
		);
	}

	/**
	 * Put one event from the request back into the shape the importer expects.
	 *
	 * The plan the owner approved came from this site, but it travelled through
	 * the browser, so every field is read again rather than trusted.
	 *
	 * @param mixed $item One item.
	 * @return array<string, mixed>
	 */
	private static function import_item( $item ): array {
		$item = is_array( $item ) ? $item : array();
		return array(
			'uid'         => sanitize_text_field( (string) ( $item['uid'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
			'start'       => sanitize_text_field( (string) ( $item['start'] ?? '' ) ),
			'end'         => sanitize_text_field( (string) ( $item['end'] ?? '' ) ),
			'all_day'     => ! empty( $item['all_day'] ),
			'timezone'    => sanitize_text_field( (string) ( $item['timezone'] ?? '' ) ),
			'rrule'       => sanitize_text_field( (string) ( $item['rrule'] ?? '' ) ),
			'dropped'     => '',
			'exdates'     => array_map( 'sanitize_text_field', (array) ( $item['exdates'] ?? array() ) ),
			'location'    => sanitize_text_field( (string) ( $item['location'] ?? '' ) ),
			'url'         => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
			'categories'  => array_map( 'sanitize_text_field', (array) ( $item['categories'] ?? array() ) ),
			'status'      => in_array( $item['status'] ?? '', Event::STATUSES, true ) ? (string) $item['status'] : 'scheduled',
		);
	}

	/**
	 * What the welcome needs to know.
	 *
	 * @return array<string, mixed>
	 */
	public static function onboarding(): array {
		return Onboarding::state();
	}

	/**
	 * Save the welcome's answers, or put it away unanswered.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function onboarding_save( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		if ( ! empty( $params['reset'] ) ) {
			return Onboarding::reset();
		}
		if ( ! empty( $params['skip'] ) ) {
			return Onboarding::skip();
		}
		return Onboarding::finish( $params );
	}

	/**
	 * The calendars this site follows.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_feeds(): array {
		return array(
			'feeds'     => Subscriptions::all(),
			'schedules' => Subscriptions::schedules(),
		);
	}

	/**
	 * Follow a calendar, or change how often it is read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_feed( WP_REST_Request $request ) {
		$feed = Subscriptions::save( (array) $request->get_json_params() );
		return is_wp_error( $feed ) ? $feed : array( 'feed' => $feed );
	}

	/**
	 * Read one feed now rather than waiting for its turn.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function sync_feed( WP_REST_Request $request ) {
		return Subscriptions::sync( (string) $request['id'] );
	}

	/**
	 * Stop following a calendar.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function delete_feed( WP_REST_Request $request ): array {
		return array( 'removed' => Subscriptions::remove( (string) $request['id'] ) );
	}

	/**
	 * What there is to migrate from The Events Calendar.
	 *
	 * @return array<string, mixed>
	 */
	public static function migrate_scan(): array {
		return Migrate::scan();
	}

	/**
	 * What the migration would do. Nothing is written.
	 *
	 * @return array<string, mixed>
	 */
	public static function migrate_plan(): array {
		return Migrate::plan();
	}

	/**
	 * Carry one batch across.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function migrate_run( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return Migrate::run(
			array(
				'offset'      => (int) ( $params['offset'] ?? 0 ),
				'post_status' => (string) ( $params['post_status'] ?? 'draft' ),
				'keep_status' => ! empty( $params['keep_status'] ),
			)
		);
	}

	/**
	 * Undo the migration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function migrate_undo( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return Migrate::rollback( (string) ( $params['confirm'] ?? '' ) );
	}

	/**
	 * Create a page that shows the calendar.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_calendar_page() {
		$existing = self::calendar_page_id();
		if ( ! $existing ) {
			$existing = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Calendar', 'rayetun-events-calendar' ),
					'post_content' => '<!-- wp:rayec/calendar {"align":"wide"} /-->',
				),
				true
			);
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			delete_transient( 'rayec_calendar_page' );
		}
		return array(
			'id'  => (int) $existing,
			'url' => get_permalink( (int) $existing ),
		);
	}
}
