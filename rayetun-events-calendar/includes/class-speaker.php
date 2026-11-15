<?php
/**
 * Speaker model.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes speakers: the people who present, teach or perform at events.
 */
class Speaker {

	/**
	 * Speakers already read in this request, keyed by ID. Null means "looked
	 * for and not there", which is worth remembering too.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static $seen = array();

	/**
	 * Speaker data.
	 *
	 * @param int $id Speaker ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		if ( array_key_exists( $id, self::$seen ) ) {
			return self::$seen[ $id ];
		}

		$post = get_post( $id );
		if ( ! $post || Post_Types::SPEAKER !== $post->post_type || 'trash' === $post->post_status ) {
			self::$seen[ $id ] = null;
			return null;
		}
		$photo_id = (int) get_post_thumbnail_id( $id );
		$src      = $photo_id ? wp_get_attachment_image_src( $photo_id, 'thumbnail' ) : false;

		self::$seen[ $id ] = array(
			'id'       => $id,
			'name'     => get_the_title( $post ),
			'role'     => (string) get_post_meta( $id, '_rayec_role', true ),
			'bio'      => (string) get_post_meta( $id, '_rayec_bio', true ),
			'website'  => (string) get_post_meta( $id, '_rayec_website', true ),
			'photo_id' => $src ? $photo_id : 0,
			'photo'    => $src ? $src[0] : '',
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
	 * All speakers, alphabetical.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::SPEAKER,
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
	 * Create or update a speaker.
	 *
	 * @param array<string, mixed> $data Speaker fields.
	 * @param int                  $id   Existing ID or 0.
	 * @return int|WP_Error
	 */
	public static function save( array $data, int $id = 0 ) {
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'rayec_no_name', __( 'Give the speaker a name.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( $id && ! self::get( $id ) ) {
			return new WP_Error( 'rayec_not_found', __( 'That speaker no longer exists.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}

		$args = array(
			'post_type'   => Post_Types::SPEAKER,
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

		update_post_meta( $id, '_rayec_role', sanitize_text_field( (string) ( $data['role'] ?? '' ) ) );
		update_post_meta( $id, '_rayec_bio', sanitize_textarea_field( (string) ( $data['bio'] ?? '' ) ) );
		update_post_meta( $id, '_rayec_website', esc_url_raw( (string) ( $data['website'] ?? '' ) ) );

		if ( array_key_exists( 'photo_id', $data ) ) {
			$photo_id = (int) $data['photo_id'];
			if ( $photo_id && wp_attachment_is_image( $photo_id ) ) {
				set_post_thumbnail( $id, $photo_id );
			} elseif ( ! $photo_id ) {
				delete_post_thumbnail( $id );
			}
		}

		// What was read earlier in this request is now out of date, and an
		// event's cached shape carries these details inside it.
		self::forget();
		Event::forget();

		return $id;
	}
}
