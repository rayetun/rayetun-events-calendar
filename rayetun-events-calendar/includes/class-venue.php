<?php
/**
 * Venue model.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes venues, including their access profile.
 */
class Venue {

	/**
	 * Venues already read in this request, keyed by ID. Null means "looked
	 * for and not there", which is worth remembering too.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static $seen = array();

	/**
	 * Access features a venue can declare.
	 *
	 * @return array<string, string> key => label.
	 */
	public static function access_features(): array {
		return array(
			'step_free'          => __( 'Step-free entry', 'rayetun-events-calendar' ),
			'accessible_toilet'  => __( 'Accessible toilet', 'rayetun-events-calendar' ),
			'accessible_parking' => __( 'Accessible parking', 'rayetun-events-calendar' ),
			'hearing_loop'       => __( 'Hearing loop', 'rayetun-events-calendar' ),
			'quiet_space'        => __( 'Quiet space', 'rayetun-events-calendar' ),
			'seating'            => __( 'Seating available', 'rayetun-events-calendar' ),
		);
	}

	/**
	 * Venue data.
	 *
	 * @param int $id Venue ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		// A month of events at one venue asks for it once per event. Holding
		// the answer for the rest of the request turns that into one read.
		if ( array_key_exists( $id, self::$seen ) ) {
			return self::$seen[ $id ];
		}

		$post = get_post( $id );
		if ( ! $post || Post_Types::VENUE !== $post->post_type || 'trash' === $post->post_status ) {
			self::$seen[ $id ] = null;
			return null;
		}
		$access = get_post_meta( $id, '_rayec_access', true );

		self::$seen[ $id ] = array(
			'id'           => $id,
			'name'         => get_the_title( $post ),
			'address'      => (string) get_post_meta( $id, '_rayec_address', true ),
			'city'         => (string) get_post_meta( $id, '_rayec_city', true ),
			'access'       => is_array( $access ) ? array_values( $access ) : array(),
			'access_notes' => (string) get_post_meta( $id, '_rayec_access_notes', true ),
			'directions'   => (string) get_post_meta( $id, '_rayec_directions', true ),
			'lat'          => (string) get_post_meta( $id, '_rayec_lat', true ),
			'lng'          => (string) get_post_meta( $id, '_rayec_lng', true ),
			'rooms'        => self::rooms( get_post_meta( $id, '_rayec_rooms', true ) ),
		);

		return self::$seen[ $id ];
	}

	/**
	 * Forget what has been read, so a change is seen straight away.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$seen = array();
	}

	/**
	 * Clean a list of rooms. Each room keeps a short ID so it can be renamed
	 * without losing the events booked into it.
	 *
	 * @param mixed $raw Rooms as stored or as sent by the form.
	 * @return array<int, array{id: string, name: string}>
	 */
	public static function rooms( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$rooms = array();
		$names = array();
		$next  = 1;
		foreach ( $raw as $room ) {
			if ( is_array( $room ) && preg_match( '/^r(\d+)$/', (string) ( $room['id'] ?? '' ), $match ) ) {
				$next = max( $next, (int) $match[1] + 1 );
			}
		}
		foreach ( array_slice( $raw, 0, 30 ) as $room ) {
			$name = sanitize_text_field( (string) ( is_array( $room ) ? ( $room['name'] ?? '' ) : $room ) );
			$key  = strtolower( $name );
			if ( '' === $name || isset( $names[ $key ] ) ) {
				continue;
			}
			$id = is_array( $room ) ? (string) ( $room['id'] ?? '' ) : '';
			if ( ! preg_match( '/^r\d+$/', $id ) || isset( $rooms[ $id ] ) ) {
				$id = 'r' . $next;
				++$next;
			}
			$names[ $key ] = true;
			$rooms[ $id ]  = array(
				'id'   => $id,
				'name' => $name,
			);
		}
		return array_values( $rooms );
	}

	/**
	 * The name of one room at a venue, or an empty string.
	 *
	 * @param array<string, mixed>|null $venue   Venue data.
	 * @param string                    $room_id Room ID.
	 * @return string
	 */
	public static function room_name( ?array $venue, string $room_id ): string {
		foreach ( $venue['rooms'] ?? array() as $room ) {
			if ( $room['id'] === $room_id ) {
				return $room['name'];
			}
		}
		return '';
	}

	/**
	 * A coordinate as a clean decimal string, or empty when out of range.
	 *
	 * @param mixed $value Raw value.
	 * @param float $limit 90 for latitude, 180 for longitude.
	 * @return string
	 */
	private static function coordinate( $value, float $limit ): string {
		if ( ! is_numeric( $value ) || abs( (float) $value ) > $limit ) {
			return '';
		}
		return (string) round( (float) $value, 6 );
	}

	/**
	 * All venues, alphabetical.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::VENUE,
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- a deliberate ceiling.
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), $ids ) ) );
	}

	/**
	 * Create or update a venue.
	 *
	 * @param array<string, mixed> $data Venue fields.
	 * @param int                  $id   Existing ID or 0.
	 * @return int|WP_Error
	 */
	public static function save( array $data, int $id = 0 ) {
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'rayec_no_name', __( 'Give the venue a name.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( $id && ! self::get( $id ) ) {
			return new WP_Error( 'rayec_not_found', __( 'That venue no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}

		$args = array(
			'post_type'   => Post_Types::VENUE,
			'post_title'  => $name,
			'post_status' => 'publish',
		);
		if ( $id ) {
			$args['ID'] = $id;
		}
		$result = $id ? wp_update_post( wp_slash( $args ), true ) : wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result;

		update_post_meta( $id, '_rayec_address', sanitize_text_field( (string) ( $data['address'] ?? '' ) ) );
		update_post_meta( $id, '_rayec_city', sanitize_text_field( (string) ( $data['city'] ?? '' ) ) );
		update_post_meta( $id, '_rayec_access_notes', sanitize_textarea_field( (string) ( $data['access_notes'] ?? '' ) ) );
		update_post_meta( $id, '_rayec_directions', sanitize_textarea_field( (string) ( $data['directions'] ?? '' ) ) );

		$lat = self::coordinate( $data['lat'] ?? '', 90 );
		$lng = self::coordinate( $data['lng'] ?? '', 180 );
		update_post_meta( $id, '_rayec_lat', '' !== $lng ? $lat : '' );
		update_post_meta( $id, '_rayec_lng', '' !== $lat ? $lng : '' );

		$access = array();
		if ( isset( $data['access'] ) && is_array( $data['access'] ) ) {
			$access = array_values( array_intersect( array_keys( self::access_features() ), array_map( 'strval', $data['access'] ) ) );
		}
		update_post_meta( $id, '_rayec_access', $access );

		if ( array_key_exists( 'rooms', $data ) ) {
			update_post_meta( $id, '_rayec_rooms', self::rooms( $data['rooms'] ) );
		}

		// What was read earlier in this request is now out of date, and an
		// event's cached shape carries these details inside it.
		self::forget();
		Event::forget();

		return $id;
	}
}
