<?php
/**
 * Organizer model.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes organizers: the people or groups who run events.
 */
class Organizer {

	/**
	 * Records already read in this request, keyed by ID. Null means "looked
	 * for and not there", which is worth remembering too.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static $seen = array();

	/**
	 * Organizer data.
	 *
	 * @param int $id Organizer ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		if ( array_key_exists( $id, self::$seen ) ) {
			return self::$seen[ $id ];
		}

		$post = get_post( $id );
		if ( ! $post || Post_Types::ORGANIZER !== $post->post_type || 'trash' === $post->post_status ) {
			self::$seen[ $id ] = null;
			return null;
		}
		self::$seen[ $id ] = array(
			'id'      => $id,
			'name'    => get_the_title( $post ),
			'email'   => (string) get_post_meta( $id, '_rayec_email', true ),
			'phone'   => (string) get_post_meta( $id, '_rayec_phone', true ),
			'website' => (string) get_post_meta( $id, '_rayec_website', true ),
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
	 * All organizers, alphabetical.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ORGANIZER,
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
	 * Create or update an organizer.
	 *
	 * @param array<string, mixed> $data Organizer fields.
	 * @param int                  $id   Existing ID or 0.
	 * @return int|WP_Error
	 */
	public static function save( array $data, int $id = 0 ) {
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'rayec_no_name', __( 'Give the organizer a name.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( $id && ! self::get( $id ) ) {
			return new WP_Error( 'rayec_not_found', __( 'That organizer no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}
		$email = (string) ( $data['email'] ?? '' );
		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'rayec_bad_email', __( 'That email address does not look right.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$args = array(
			'post_type'   => Post_Types::ORGANIZER,
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

		update_post_meta( $id, '_rayec_email', sanitize_email( $email ) );
		update_post_meta( $id, '_rayec_phone', sanitize_text_field( (string) ( $data['phone'] ?? '' ) ) );
		update_post_meta( $id, '_rayec_website', esc_url_raw( (string) ( $data['website'] ?? '' ) ) );

		// What was read earlier in this request is now out of date, and an
		// event's cached shape carries these details inside it.
		self::forget();
		Event::forget();

		return $id;
	}
}
