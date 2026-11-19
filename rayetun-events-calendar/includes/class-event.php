<?php
/**
 * Event model.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes events.
 *
 * Start and end are stored as local wall-clock time ("Y-m-d H:i:s") together
 * with a named time zone; UTC lives in the occurrence index.
 */
class Event {

	const STATUSES = array( 'scheduled', 'cancelled', 'postponed', 'moved_online' );
	const FORMATS  = array( 'in_person', 'online', 'hybrid' );

	/**
	 * Statuses read just before they are overwritten, by event ID.
	 *
	 * @var array<int, string>
	 */
	private static array $status_before = array();

	/**
	 * Hooks.
	 *
	 * A status can be changed from the drawer, from a bulk action on the
	 * Events table, or from the block editor through WordPress's own REST
	 * meta. Watching the meta itself catches all three, and anything an
	 * add-on or an import does as well.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'update_post_meta', array( __CLASS__, 'note_status' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'status_written' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'status_written' ), 10, 4 );
	}

	/**
	 * Remember a status before it is overwritten.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public static function note_status( $meta_id, $object_id, $meta_key ): void {
		if ( '_rayec_status' === $meta_key ) {
			self::$status_before[ (int) $object_id ] = (string) get_post_meta( (int) $object_id, '_rayec_status', true );
		}
	}

	/**
	 * Announce a status that has changed.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public static function status_written( $meta_id, $object_id, $meta_key, $value ): void {
		if ( '_rayec_status' !== $meta_key ) {
			return;
		}
		$id  = (int) $object_id;
		$old = self::$status_before[ $id ] ?? '';
		unset( self::$status_before[ $id ] );
		$old = in_array( $old, self::STATUSES, true ) ? $old : 'scheduled';
		$new = in_array( (string) $value, self::STATUSES, true ) ? (string) $value : 'scheduled';
		if ( $old === $new ) {
			return;
		}
		self::forget( $id );

		/**
		 * Fires when an event's status changes, however it was changed.
		 *
		 * @param int    $id  Event ID.
		 * @param string $new "scheduled", "cancelled", "postponed" or "moved_online".
		 * @param string $old The status before.
		 */
		do_action( 'rayec_event_status_changed', $id, $new, $old );
	}

	/**
	 * Access the organiser provides at the event itself.
	 *
	 * A venue's features describe the building and stay the same from one
	 * event to the next; these describe what is laid on for this event, and
	 * they mean as much online as in a hall.
	 *
	 * @return array<string, string> key => label.
	 */
	public static function access_features(): array {
		return array(
			'captions'          => __( 'Live captions', 'rayetun-events-calendar' ),
			'sign_language'     => __( 'Sign language interpretation', 'rayetun-events-calendar' ),
			'audio_description' => __( 'Audio description', 'rayetun-events-calendar' ),
			'relaxed'           => __( 'Relaxed session', 'rayetun-events-calendar' ),
			'easy_read'         => __( 'Easy-read materials', 'rayetun-events-calendar' ),
			'companion_free'    => __( 'Free place for a companion', 'rayetun-events-calendar' ),
		);
	}

	/**
	 * Who an event is for, by age. The key is stored; the label is shown.
	 *
	 * @return array<string, string>
	 */
	public static function ages(): array {
		return array(
			'all'    => __( 'All ages', 'rayetun-events-calendar' ),
			'family' => __( 'Family friendly', 'rayetun-events-calendar' ),
			'12'     => __( 'Ages 12 and over', 'rayetun-events-calendar' ),
			'16'     => __( 'Ages 16 and over', 'rayetun-events-calendar' ),
			'18'     => __( 'Ages 18 and over', 'rayetun-events-calendar' ),
			'21'     => __( 'Ages 21 and over', 'rayetun-events-calendar' ),
		);
	}

	/**
	 * How long before the start the doors open, in minutes.
	 *
	 * @return int[]
	 */
	public static function door_minutes(): array {
		return array( 15, 30, 45, 60, 90 );
	}

	/**
	 * A stored age key, or '' when it is not one we know.
	 *
	 * @param mixed $value Submitted or stored value.
	 * @return string
	 */
	public static function clean_age( $value ): string {
		$value = (string) $value;
		return isset( self::ages()[ $value ] ) ? $value : '';
	}

	/**
	 * Minutes before the start that doors open, or 0 for not stated.
	 *
	 * @param mixed $value Submitted or stored value.
	 * @return int
	 */
	public static function clean_doors( $value ): int {
		$value = (int) $value;
		return in_array( $value, self::door_minutes(), true ) ? $value : 0;
	}

	/**
	 * Keep only known access features, in the order they are listed.
	 *
	 * @param mixed $raw Submitted or stored list.
	 * @return string[]
	 */
	public static function clean_access( $raw ): array {
		$raw = is_array( $raw ) ? array_map( 'strval', $raw ) : array();
		return array_values( array_intersect( array_keys( self::access_features() ), $raw ) );
	}

	/**
	 * A valid time zone object for a stored value.
	 *
	 * @param string $name Time zone name.
	 * @return DateTimeZone
	 */
	public static function timezone( string $name = '' ): DateTimeZone {
		if ( '' !== $name ) {
			try {
				return new DateTimeZone( $name );
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}
		return wp_timezone();
	}

	/**
	 * Events already built in this request, keyed by ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $seen = array();

	/**
	 * An event that does not exist yet, with every field in its empty state.
	 *
	 * The Accessibility Guard runs against this while a new event is still
	 * being written, before anything has reached the database.
	 *
	 * @return array<string, mixed>
	 */
	public static function blank(): array {
		return array(
			'program_id'   => 0,
			'program'      => null,
			'speaker_ids'  => array(),
			'speakers'     => array(),
			'extra_times'  => array(),
			'room_id'      => '',
			'room'         => '',
			'organizer_id' => 0,
			'organizer'    => null,
			'skip_closed'  => false,
			'image'        => null,
			'summary'      => '',
			'id'           => 0,
			'title'        => '',
			'description'  => '',
			'post_status'  => 'auto-draft',
			'permalink'    => '',
			'edit_link'    => '',
			'start'        => '',
			'end'          => '',
			'timezone'     => self::timezone()->getName(),
			'all_day'      => false,
			'rrule'        => '',
			'rrule_text'   => '',
			'exdates'      => array(),
			'event_status' => 'scheduled',
			'format'       => 'in_person',
			'cost'         => '',
			'online_url'   => '',
			'featured'     => false,
			'venue_id'     => 0,
			'venue'        => null,
			'categories'   => array(),
			'thumbnail_id' => 0,
			'rsvp'         => Registrations::defaults(),
			'access'       => array(),
			'access_notes' => '',
			'age'          => '',
			'doors'        => 0,
			'policy'       => '',
		);
	}

	/**
	 * Full event data.
	 *
	 * @param int|WP_Post $post Event.
	 * @return array<string, mixed>|null
	 */
	public static function get( $post ): ?array {
		$post = get_post( $post );
		if ( ! $post || Post_Types::EVENT !== $post->post_type ) {
			return null;
		}

		// A month grid asks for the same repeating event once per date it
		// falls on, and every ask is a dozen meta reads. The answer cannot
		// change inside one request, so it is kept.
		if ( isset( self::$seen[ $post->ID ] ) ) {
			return self::$seen[ $post->ID ];
		}

		$id       = $post->ID;
		$tz_name  = (string) get_post_meta( $id, '_rayec_timezone', true );
		$tz       = self::timezone( $tz_name );
		$start    = (string) get_post_meta( $id, '_rayec_start', true );
		$end      = (string) get_post_meta( $id, '_rayec_end', true );
		$rrule    = (string) get_post_meta( $id, '_rayec_rrule', true );
		$exdates  = get_post_meta( $id, '_rayec_exdates', true );
		$venue_id = (int) get_post_meta( $id, '_rayec_venue_id', true );
		$status   = (string) get_post_meta( $id, '_rayec_status', true );
		$format   = (string) get_post_meta( $id, '_rayec_format', true );

		$start_dt = self::local_datetime( $start, $tz );

		$categories = array();
		$terms      = get_the_terms( $id, Post_Types::CATEGORY );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'id'    => $term->term_id,
					'name'  => $term->name,
					'color' => (string) get_term_meta( $term->term_id, 'rayec_color', true ),
					'icon'  => (string) get_term_meta( $term->term_id, 'rayec_icon', true ),
				);
			}
		}

		$program = null;
		$terms   = get_the_terms( $id, Post_Types::PROGRAM );
		if ( is_array( $terms ) && $terms ) {
			$link    = get_term_link( $terms[0] );
			$program = array(
				'id'   => $terms[0]->term_id,
				'name' => $terms[0]->name,
				'url'  => is_wp_error( $link ) ? '' : $link,
			);
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $id );
		$image        = null;
		if ( $thumbnail_id ) {
			$src = wp_get_attachment_image_src( $thumbnail_id, 'medium_large' );
			if ( $src ) {
				$image = array(
					'id'     => $thumbnail_id,
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
				);
			}
		}
		$summary = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) ), 30 );

		$organizer_id = (int) get_post_meta( $id, '_rayec_organizer_id', true );
		$venue        = $venue_id ? Venue::get( $venue_id ) : null;
		$room_id      = (string) get_post_meta( $id, '_rayec_room', true );
		$room         = Venue::room_name( $venue, $room_id );

		$all_day     = (bool) get_post_meta( $id, '_rayec_all_day', true );
		$speaker_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_rayec_speaker', false ) ) ) ) );
		$speakers    = array_values( array_filter( array_map( array( Speaker::class, 'get' ), $speaker_ids ) ) );

		$data = array(
			'program_id'   => $program ? $program['id'] : 0,
			'program'      => $program,
			'speaker_ids'  => wp_list_pluck( $speakers, 'id' ),
			'speakers'     => $speakers,
			'extra_times'  => $all_day ? array() : self::clean_times( get_post_meta( $id, '_rayec_extra_times', true ), substr( $start, 11, 5 ) ),
			'room_id'      => '' !== $room ? $room_id : '',
			'room'         => $room,
			'organizer_id' => $organizer_id,
			'organizer'    => $organizer_id ? Organizer::get( $organizer_id ) : null,
			'skip_closed'  => (bool) get_post_meta( $id, '_rayec_skip_closed', true ),
			'image'        => $image,
			'summary'      => $summary,
			'id'           => $id,
			'title'        => get_the_title( $post ),
			'description'  => $post->post_content,
			'post_status'  => $post->post_status,
			'permalink'    => get_permalink( $post ),
			'edit_link'    => get_edit_post_link( $id, 'raw' ),
			'start'        => $start ? str_replace( ' ', 'T', substr( $start, 0, 16 ) ) : '',
			'end'          => $end ? str_replace( ' ', 'T', substr( $end, 0, 16 ) ) : '',
			'timezone'     => $tz->getName(),
			'all_day'      => (bool) get_post_meta( $id, '_rayec_all_day', true ),
			'rrule'        => $rrule,
			'rrule_text'   => ( $rrule && $start_dt ) ? Recurrence::describe( $rrule, $start_dt ) : '',
			'exdates'      => is_array( $exdates ) ? array_values( $exdates ) : array(),
			'event_status' => in_array( $status, self::STATUSES, true ) ? $status : 'scheduled',
			'format'       => in_array( $format, self::FORMATS, true ) ? $format : 'in_person',
			'cost'         => (string) get_post_meta( $id, '_rayec_cost', true ),
			'online_url'   => (string) get_post_meta( $id, '_rayec_online_url', true ),
			'featured'     => (bool) get_post_meta( $id, '_rayec_featured', true ),
			'venue_id'     => $venue_id,
			'venue'        => $venue,
			'categories'   => $categories,
			'thumbnail_id' => $thumbnail_id,
			'rsvp'         => Registrations::settings( $id ),
			'access'       => self::clean_access( get_post_meta( $id, '_rayec_event_access', true ) ),
			'access_notes' => (string) get_post_meta( $id, '_rayec_event_access_notes', true ),
			'age'          => self::clean_age( get_post_meta( $id, '_rayec_age', true ) ),
			'doors'        => self::clean_doors( get_post_meta( $id, '_rayec_doors', true ) ),
			'policy'       => (string) get_post_meta( $id, '_rayec_policy', true ),
		);

		/**
		 * Filters the data describing an event.
		 *
		 * @param array<string, mixed> $data Event data.
		 * @param WP_Post              $post Event post.
		 */
		$data = apply_filters( 'rayec_event_data', $data, $post );

		self::$seen[ $id ] = $data;
		return $data;
	}

	/**
	 * Forget the events read so far, so a change is seen straight away.
	 *
	 * Saving an event, and the tests, call this. Nothing else needs to:
	 * within one request an event does not change under you.
	 *
	 * @param int $id One event, or 0 for all of them.
	 * @return void
	 */
	public static function forget( int $id = 0 ): void {
		if ( $id ) {
			unset( self::$seen[ $id ] );
			return;
		}
		self::$seen = array();
	}

	/**
	 * Load several events at once, with one trip for the posts, one for all
	 * their meta and one for all their terms.
	 *
	 * Without this a month of fifty events is a hundred and fifty queries;
	 * with it, three.
	 *
	 * @param array<int, int> $ids Event IDs.
	 * @return void
	 */
	public static function prime( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$ids = array_values( array_diff( $ids, array_keys( self::$seen ) ) );
		if ( ! $ids ) {
			return;
		}

		_prime_post_caches( $ids, true, true );

		// The venues and organisers they point at, in one trip as well.
		$related = array();
		foreach ( $ids as $id ) {
			foreach ( array( '_rayec_venue_id', '_rayec_organizer_id' ) as $key ) {
				$related[] = (int) get_post_meta( $id, $key, true );
			}
			foreach ( (array) get_post_meta( $id, '_rayec_speaker', false ) as $speaker ) {
				$related[] = (int) $speaker;
			}
		}
		$related = array_values( array_unique( array_filter( $related ) ) );
		if ( $related ) {
			_prime_post_caches( $related, false, true );
		}
	}

	/**
	 * Extra start times on the same day, as sorted "HH:MM" strings.
	 *
	 * @param mixed  $raw        Stored or submitted times.
	 * @param string $start_time The event's own start time, which is not repeated.
	 * @return string[]
	 */
	public static function clean_times( $raw, string $start_time ): array {
		$times = array();
		foreach ( (array) $raw as $time ) {
			if ( is_string( $time ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) && $time !== $start_time ) {
				$times[ $time ] = true;
			}
		}
		ksort( $times );
		return array_slice( array_keys( $times ), 0, 8 );
	}

	/**
	 * Recalculate derived data after the block editor saves an event's fields.
	 *
	 * @param int $id Event ID.
	 * @return void
	 */
	public static function sync( int $id ): void {
		if ( Post_Types::EVENT !== get_post_type( $id ) ) {
			return;
		}
		self::forget( $id );
		$tz    = self::timezone( (string) get_post_meta( $id, '_rayec_timezone', true ) );
		$start = self::local_datetime( (string) get_post_meta( $id, '_rayec_start', true ), $tz );
		$end   = self::local_datetime( (string) get_post_meta( $id, '_rayec_end', true ), $tz );

		if ( ! $start ) {
			$start = ( new DateTimeImmutable( 'tomorrow', $tz ) )->setTime( 10, 0 );
		}
		if ( get_post_meta( $id, '_rayec_all_day', true ) ) {
			$start = $start->setTime( 0, 0 );
			$end   = ( $end && $end >= $start ? $end : $start )->setTime( 23, 59 );
		} elseif ( ! $end || $end <= $start ) {
			$end = $start->modify( '+1 hour' );
		}

		update_post_meta( $id, '_rayec_start', $start->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_rayec_end', $end->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_rayec_timezone', $tz->getName() );
		update_post_meta( $id, '_rayec_rrule', Recurrence::normalize( (string) get_post_meta( $id, '_rayec_rrule', true ) ) );

		self::forget( $id );
		Occurrences::regenerate( $id );

		/** This action is documented in includes/class-event.php */
		do_action( 'rayec_event_saved', $id );
	}

	/**
	 * Create or update an event.
	 *
	 * @param array<string, mixed> $data Event fields.
	 * @param int                  $id   Existing event ID, or 0 to create.
	 * @return int|WP_Error Event ID.
	 */
	public static function save( array $data, int $id = 0 ) {
		$existing = $id ? self::get( $id ) : null;
		if ( $id && ! $existing ) {
			return new WP_Error( 'rayec_not_found', __( 'That event no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : ( $existing['title'] ?? '' );
		if ( '' === $title ) {
			return new WP_Error( 'rayec_no_title', __( 'Give the event a name.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$tz_name = isset( $data['timezone'] ) ? (string) $data['timezone'] : ( $existing['timezone'] ?? '' );
		$tz      = self::timezone( $tz_name );
		$start   = self::local_datetime( (string) ( $data['start'] ?? ( $existing['start'] ?? '' ) ), $tz );
		$end     = self::local_datetime( (string) ( $data['end'] ?? ( $existing['end'] ?? '' ) ), $tz );
		$all_day = (bool) ( $data['all_day'] ?? ( $existing['all_day'] ?? false ) );

		if ( ! $start ) {
			return new WP_Error( 'rayec_no_start', __( 'Choose when the event starts.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( $all_day ) {
			$start = $start->setTime( 0, 0 );
			$end   = ( $end && $end >= $start ? $end : $start )->setTime( 23, 59 );
		} elseif ( ! $end || $end <= $start ) {
			$end = $start->modify( '+1 hour' );
		}

		$post_status = isset( $data['post_status'] ) ? (string) $data['post_status'] : ( $existing['post_status'] ?? 'publish' );
		if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$post_status = 'draft';
		}
		if ( 'publish' === $post_status && ! current_user_can( 'publish_posts' ) && ( ! $existing || 'publish' !== $existing['post_status'] ) ) {
			$post_status = 'pending';
		}

		$post_args = array(
			'post_type'   => Post_Types::EVENT,
			'post_title'  => $title,
			'post_status' => $post_status,
		);
		if ( isset( $data['description'] ) ) {
			$post_args['post_content'] = wp_kses_post( (string) $data['description'] );
		}

		if ( $id ) {
			$post_args['ID'] = $id;
			$result          = wp_update_post( wp_slash( $post_args ), true );
		} else {
			$result = wp_insert_post( wp_slash( $post_args ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result;

		update_post_meta( $id, '_rayec_start', $start->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_rayec_end', $end->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_rayec_timezone', $tz->getName() );
		update_post_meta( $id, '_rayec_all_day', $all_day ? 1 : 0 );

		if ( array_key_exists( 'rrule', $data ) ) {
			update_post_meta( $id, '_rayec_rrule', Recurrence::normalize( (string) $data['rrule'] ) );
		}
		if ( isset( $data['exdates'] ) && is_array( $data['exdates'] ) ) {
			$exdates = array();
			foreach ( $data['exdates'] as $date ) {
				if ( is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$exdates[] = $date;
				}
			}
			update_post_meta( $id, '_rayec_exdates', array_values( array_unique( $exdates ) ) );
		}
		if ( isset( $data['event_status'] ) && in_array( $data['event_status'], self::STATUSES, true ) ) {
			update_post_meta( $id, '_rayec_status', $data['event_status'] );
		}
		if ( isset( $data['format'] ) && in_array( $data['format'], self::FORMATS, true ) ) {
			update_post_meta( $id, '_rayec_format', $data['format'] );
		}
		if ( isset( $data['cost'] ) ) {
			update_post_meta( $id, '_rayec_cost', sanitize_text_field( (string) $data['cost'] ) );
		}
		if ( isset( $data['online_url'] ) ) {
			update_post_meta( $id, '_rayec_online_url', esc_url_raw( (string) $data['online_url'] ) );
		}
		if ( array_key_exists( 'extra_times', $data ) ) {
			update_post_meta( $id, '_rayec_extra_times', $all_day ? array() : self::clean_times( $data['extra_times'], $start->format( 'H:i' ) ) );
		}
		if ( array_key_exists( 'speaker_ids', $data ) ) {
			// One row per speaker, so clashes can be found with a plain join.
			delete_post_meta( $id, '_rayec_speaker' );
			foreach ( array_slice( array_unique( array_map( 'intval', (array) $data['speaker_ids'] ) ), 0, 30 ) as $speaker_id ) {
				if ( Post_Types::SPEAKER === get_post_type( $speaker_id ) ) {
					add_post_meta( $id, '_rayec_speaker', $speaker_id );
				}
			}
		}
		if ( isset( $data['organizer_id'] ) ) {
			$organizer_id = (int) $data['organizer_id'];
			update_post_meta( $id, '_rayec_organizer_id', ( $organizer_id && Post_Types::ORGANIZER === get_post_type( $organizer_id ) ) ? $organizer_id : 0 );
		}
		if ( array_key_exists( 'thumbnail_id', $data ) ) {
			$thumbnail_id = (int) $data['thumbnail_id'];
			if ( $thumbnail_id && wp_attachment_is_image( $thumbnail_id ) ) {
				set_post_thumbnail( $id, $thumbnail_id );
			} elseif ( ! $thumbnail_id ) {
				delete_post_thumbnail( $id );
			}
		}
		if ( isset( $data['skip_closed'] ) ) {
			update_post_meta( $id, '_rayec_skip_closed', $data['skip_closed'] ? 1 : 0 );
		}
		if ( isset( $data['featured'] ) ) {
			update_post_meta( $id, '_rayec_featured', $data['featured'] ? 1 : 0 );
		}
		if ( isset( $data['venue_id'] ) ) {
			$venue_id = (int) $data['venue_id'];
			update_post_meta( $id, '_rayec_venue_id', ( $venue_id && Post_Types::VENUE === get_post_type( $venue_id ) ) ? $venue_id : 0 );
		}
		if ( array_key_exists( 'room_id', $data ) || isset( $data['venue_id'] ) ) {
			// A room only makes sense inside the venue it belongs to.
			$room_id = array_key_exists( 'room_id', $data ) ? (string) $data['room_id'] : (string) get_post_meta( $id, '_rayec_room', true );
			$venue   = Venue::get( (int) get_post_meta( $id, '_rayec_venue_id', true ) );
			$known   = Venue::room_name( $venue, $room_id );
			update_post_meta( $id, '_rayec_room', '' === $known ? '' : $room_id );
		}
		if ( isset( $data['program_id'] ) ) {
			$program_id = (int) $data['program_id'];
			wp_set_object_terms( $id, $program_id && term_exists( $program_id, Post_Types::PROGRAM ) ? array( $program_id ) : array(), Post_Types::PROGRAM );
		}
		if ( isset( $data['category_ids'] ) && is_array( $data['category_ids'] ) ) {
			wp_set_object_terms( $id, array_map( 'intval', $data['category_ids'] ), Post_Types::CATEGORY );
		}

		if ( isset( $data['access'] ) && is_array( $data['access'] ) ) {
			update_post_meta( $id, '_rayec_event_access', self::clean_access( $data['access'] ) );
		}
		if ( isset( $data['access_notes'] ) ) {
			update_post_meta( $id, '_rayec_event_access_notes', sanitize_textarea_field( (string) $data['access_notes'] ) );
		}
		if ( isset( $data['age'] ) ) {
			update_post_meta( $id, '_rayec_age', self::clean_age( $data['age'] ) );
		}
		if ( isset( $data['doors'] ) ) {
			update_post_meta( $id, '_rayec_doors', self::clean_doors( $data['doors'] ) );
		}
		if ( isset( $data['policy'] ) ) {
			update_post_meta( $id, '_rayec_policy', sanitize_textarea_field( (string) $data['policy'] ) );
		}
		if ( isset( $data['rsvp'] ) && is_array( $data['rsvp'] ) ) {
			Registrations::save_settings( $id, $data['rsvp'] );
		}

		if ( $existing ) {
			// Bookings are named by their date's start, so they move when it does.
			$repeats = array_key_exists( 'rrule', $data ) ? '' !== Recurrence::normalize( (string) $data['rrule'] ) : '' !== $existing['rrule'];
			Registrations::follow_move( $id, (string) $existing['start'], $start->format( 'Y-m-d H:i' ), '' !== $existing['rrule'], $repeats );
		}

		self::forget( $id );
		Occurrences::regenerate( $id );

		/**
		 * Fires after an event has been saved and its occurrences rebuilt.
		 *
		 * @param int $id Event ID.
		 */
		do_action( 'rayec_event_saved', $id );

		return $id;
	}

	/**
	 * Copy an event as a draft.
	 *
	 * @param int $id Event ID.
	 * @return int|WP_Error New event ID.
	 */
	public static function duplicate( int $id ) {
		$event = self::get( $id );
		if ( ! $event ) {
			return new WP_Error( 'rayec_not_found', __( 'That event no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}

		/* translators: %s: event title. */
		$event['title']        = sprintf( __( '%s (copy)', 'rayetun-events-calendar' ), $event['title'] );
		$event['post_status']  = 'draft';
		$event['category_ids'] = wp_list_pluck( $event['categories'], 'id' );

		return self::save( $event );
	}

	/**
	 * Parse a stored or submitted local date-time.
	 *
	 * @param string       $value "Y-m-d H:i[:s]" or "Y-m-d\TH:i".
	 * @param DateTimeZone $tz    Event time zone.
	 * @return DateTimeImmutable|null
	 */
	public static function local_datetime( string $value, DateTimeZone $tz ): ?DateTimeImmutable {
		$value = str_replace( 'T', ' ', trim( $value ) );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::\d{2})?)?$/', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$date = new DateTimeImmutable( 'now', $tz );
		return $date->setDate( (int) $m[1], (int) $m[2], (int) $m[3] )->setTime( (int) ( $m[4] ?? 0 ), (int) ( $m[5] ?? 0 ), 0 );
	}
}
