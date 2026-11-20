<?php
/**
 * Moving a site over from The Events Calendar.
 *
 * Three promises hold this together.
 *
 * Nothing of theirs is touched. Their events, venues and organizers are read
 * and left exactly where they are, so the old plugin keeps working and the
 * owner can take as long as they like to decide.
 *
 * Nothing happens unseen. The owner reads a plan first, and afterwards gets a
 * summary of what was made and what could not be carried across.
 *
 * Everything can be undone. Every post this migration creates is marked as its
 * own, so undoing it removes exactly those and nothing else.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Events Calendar migration.
 */
class Migrate {

	/**
	 * What `_rayec_source` says on everything this migration makes.
	 *
	 * @var string
	 */
	const SOURCE = 'the-events-calendar';

	/**
	 * Their post types.
	 *
	 * @var string
	 */
	const EVENT     = 'tribe_events';
	const VENUE     = 'tribe_venue';
	const ORGANIZER = 'tribe_organizer';

	/**
	 * Where the old address is kept, so it can be redirected.
	 *
	 * @var string
	 */
	const PATH_META = '_rayec_source_path';

	/**
	 * How many events one pass carries over.
	 *
	 * @var int
	 */
	const BATCH = 20;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 0 );
	}

	/**
	 * What is there to migrate.
	 *
	 * Reads the database rather than asking the plugin, so this still works
	 * when The Events Calendar has been deactivated but its content is there.
	 *
	 * @return array<string, mixed>
	 */
	public static function scan(): array {
		global $wpdb;

		$counts = array();
		foreach ( array(
			'events'     => self::EVENT,
			'venues'     => self::VENUE,
			'organizers' => self::ORGANIZER,
		) as $key => $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counting another plugin's posts, which have no cache of ours.
			$counts[ $key ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','trash','inherit')",
					$type
				)
			);
		}

		$done = self::migrated_ids();

		return array(
			'found'      => $counts['events'] > 0 || $counts['venues'] > 0,
			'active'     => class_exists( 'Tribe__Events__Main' ),
			'events'     => $counts['events'],
			'venues'     => $counts['venues'],
			'organizers' => $counts['organizers'],
			'migrated'   => count( $done ),
			'remaining'  => max( 0, $counts['events'] - count( $done ) ),
			'last_run'   => get_option( 'rayec_migration', null ),
		);
	}

	/**
	 * Their event IDs that have already been brought across.
	 *
	 * @return array<int, int> Their ID => our ID.
	 */
	private static function migrated_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup over our own meta.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uid.post_id AS ours, uid.meta_value AS theirs
				 FROM {$wpdb->postmeta} uid
				 INNER JOIN {$wpdb->postmeta} src ON src.post_id = uid.post_id AND src.meta_key = %s AND src.meta_value = %s
				 INNER JOIN {$wpdb->posts} p ON p.ID = uid.post_id AND p.post_type = %s AND p.post_status <> 'trash'
				 WHERE uid.meta_key = %s",
				Import::SOURCE_META,
				self::SOURCE,
				Post_Types::EVENT,
				Import::UID_META
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->theirs ] = (int) $row->ours;
		}
		return $map;
	}

	/**
	 * Their events, oldest first, in the order they will be carried over.
	 *
	 * @param int $offset Where to start.
	 * @param int $limit  How many.
	 * @return array<int, \WP_Post>
	 */
	private static function their_events( int $offset, int $limit ): array {
		return get_posts(
			array(
				'post_type'      => self::EVENT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * What the migration would do, event by event.
	 *
	 * @param int $limit How many rows to describe.
	 * @return array<string, mixed>
	 */
	public static function plan( int $limit = 50 ): array {
		$done   = self::migrated_ids();
		$rows   = array();
		$counts = array(
			'new'     => 0,
			'update'  => 0,
			'skipped' => 0,
		);

		$offset = 0;
		$shown  = 0;
		while ( $shown < $limit ) {
			$batch = self::their_events( $offset, 50 );
			if ( ! $batch ) {
				break;
			}
			$offset += count( $batch );

			foreach ( $batch as $post ) {
				$data   = self::read_event( $post );
				$action = isset( $done[ $post->ID ] ) ? 'update' : 'new';
				$note   = '';

				if ( '' === $data['start'] ) {
					$action = 'skipped';
					$note   = __( 'It has no start date of theirs to read.', 'rayetun-events-calendar' );
				} elseif ( '' !== $data['recurrence_note'] ) {
					$note = $data['recurrence_note'];
				}

				++$counts[ $action ];
				if ( $shown < $limit ) {
					++$shown;
					$rows[] = array(
						'uid'      => (string) $post->ID,
						'title'    => $data['title'],
						'start'    => $data['start'],
						'all_day'  => $data['all_day'],
						'repeats'  => false,
						'location' => $data['venue_name'],
						'action'   => $action,
						'note'     => $note,
					);
				}
			}
		}

		// Count whatever is past the rows being shown.
		while ( true ) {
			$batch = self::their_events( $offset, 100 );
			if ( ! $batch ) {
				break;
			}
			$offset += count( $batch );
			foreach ( $batch as $post ) {
				$action = isset( $done[ $post->ID ] ) ? 'update' : 'new';
				if ( '' === (string) get_post_meta( $post->ID, '_EventStartDate', true ) ) {
					$action = 'skipped';
				}
				++$counts[ $action ];
			}
		}

		return array(
			'counts' => $counts,
			'rows'   => $rows,
		);
	}

	/**
	 * Carry one batch of events across.
	 *
	 * @param array<string, mixed> $options Migration options.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( array $options ) {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot migrate events.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}

		$offset = max( 0, (int) ( $options['offset'] ?? 0 ) );
		$keep   = ! empty( $options['keep_status'] );
		$status = in_array( $options['post_status'] ?? '', array( 'publish', 'draft', 'pending' ), true ) ? (string) $options['post_status'] : 'draft';

		$done    = self::migrated_ids();
		$batch   = self::their_events( $offset, self::BATCH );
		$added   = 0;
		$updated = 0;
		$skipped = 0;
		$notes   = array();

		foreach ( $batch as $post ) {
			$data = self::read_event( $post );
			if ( '' === $data['start'] ) {
				++$skipped;
				continue;
			}

			$ours = $done[ $post->ID ] ?? 0;
			$save = array(
				'title'        => $data['title'],
				'description'  => $data['description'],
				'start'        => $data['start'],
				'end'          => $data['end'],
				'all_day'      => $data['all_day'],
				'timezone'     => $data['timezone'],
				'event_status' => $data['status'],
				'cost'         => $data['cost'],
				'post_status'  => $keep ? self::our_status( $post->post_status ) : $status,
				'category_ids' => self::terms( $post->ID ),
				'venue_id'     => $data['venue_id'] ? self::venue( $data['venue_id'] ) : 0,
				'organizer_id' => $data['organizer_id'] ? self::organizer( $data['organizer_id'] ) : 0,
			);
			if ( $data['thumbnail_id'] ) {
				$save['thumbnail_id'] = $data['thumbnail_id'];
			}

			$result = Event::save( $save, $ours );
			if ( is_wp_error( $result ) ) {
				++$skipped;
				$notes[] = array(
					'title'  => $data['title'],
					'reason' => $result->get_error_message(),
				);
				continue;
			}

			$id = (int) $result;
			if ( $ours ) {
				++$updated;
			} else {
				++$added;
				update_post_meta( $id, Import::SOURCE_META, self::SOURCE );
				update_post_meta( $id, Import::UID_META, (string) $post->ID );
			}

			// Their addresses, so an old link still lands on the right event.
			delete_post_meta( $id, self::PATH_META );
			foreach ( $data['paths'] as $path ) {
				add_post_meta( $id, self::PATH_META, $path );
			}
			if ( '' !== $data['excerpt'] ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_excerpt' => $data['excerpt'],
					)
				);
			}
		}

		$offset += count( $batch );
		$left    = count( self::their_events( $offset, 1 ) ) > 0;

		$record = get_option( 'rayec_migration', array() );
		$record = is_array( $record ) ? $record : array();
		$record = array(
			'started'  => $record['started'] ?? current_time( 'mysql' ),
			'finished' => $left ? '' : current_time( 'mysql' ),
			'added'    => (int) ( $record['added'] ?? 0 ) + $added,
			'updated'  => (int) ( $record['updated'] ?? 0 ) + $updated,
			'skipped'  => (int) ( $record['skipped'] ?? 0 ) + $skipped,
		);
		update_option( 'rayec_migration', $record, false );

		return array(
			'added'   => $added,
			'updated' => $updated,
			'skipped' => $skipped,
			'failed'  => $notes,
			'offset'  => $offset,
			'more'    => $left,
			'total'   => $record,
		);
	}

	/**
	 * Undo a migration: remove what it made, and nothing else.
	 *
	 * Their events, venues and organizers were never touched, so this puts the
	 * site back where it was.
	 *
	 * @param string $confirm The word "undo", said in as many words.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rollback( string $confirm = '' ) {
		if ( ! current_user_can( 'delete_posts' ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot remove these events.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}
		// Deleting is the one thing here that cannot be undone, so the word has
		// to be said out loud: a stray or repeated request cannot do it.
		if ( 'undo' !== $confirm ) {
			return new WP_Error( 'rayec_not_confirmed', __( 'Say so plainly to undo a migration.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$removed = 0;
		foreach ( array( Post_Types::EVENT, Post_Types::VENUE, Post_Types::ORGANIZER ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'any',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- a deliberate ceiling.
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off, on the owner's own command.
					'meta_query'     => array(
						array(
							'key'   => Import::SOURCE_META,
							'value' => self::SOURCE,
						),
					),
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
				++$removed;
			}
		}

		delete_option( 'rayec_migration' );

		return array(
			'removed' => $removed,
			'more'    => $removed >= 1500,
		);
	}

	/**
	 * Read one of their events into plain data.
	 *
	 * @param \WP_Post $post Their event.
	 * @return array<string, mixed>
	 */
	private static function read_event( $post ): array {
		$start = (string) get_post_meta( $post->ID, '_EventStartDate', true );
		$end   = (string) get_post_meta( $post->ID, '_EventEndDate', true );
		$zone  = (string) get_post_meta( $post->ID, '_EventTimezone', true );
		$venue = (int) get_post_meta( $post->ID, '_EventVenueID', true );

		$organizers = array_map( 'intval', (array) get_post_meta( $post->ID, '_EventOrganizerID', false ) );
		$organizers = array_values( array_filter( $organizers ) );

		$status = strtolower( (string) get_post_meta( $post->ID, '_tribe_events_status', true ) );
		$status = in_array( $status, array( 'canceled', 'cancelled' ), true ) ? 'cancelled' : ( 'postponed' === $status ? 'postponed' : 'scheduled' );

		$description = (string) $post->post_content;
		$website     = (string) get_post_meta( $post->ID, '_EventURL', true );
		if ( '' !== $website ) {
			// We have no field for an event's own website, so it is kept in the text.
			$description .= "\n\n" . '<p><a href="' . esc_url( $website ) . '" rel="noopener">' . esc_html__( 'Event website', 'rayetun-events-calendar' ) . '</a></p>';
		}

		$recurrence = get_post_meta( $post->ID, '_EventRecurrence', true );
		$note       = ( $recurrence && ( ! is_array( $recurrence ) || ! empty( $recurrence ) ) )
			? __( 'It repeats in The Events Calendar Pro, which keeps its rules in its own format. It comes across as a single event; set the repeat here.', 'rayetun-events-calendar' )
			: '';

		return array(
			'title'           => (string) $post->post_title,
			'description'     => $description,
			'excerpt'         => (string) $post->post_excerpt,
			'start'           => '' !== $start ? str_replace( ' ', 'T', substr( $start, 0, 16 ) ) : '',
			'end'             => '' !== $end ? str_replace( ' ', 'T', substr( $end, 0, 16 ) ) : '',
			'all_day'         => 'yes' === strtolower( (string) get_post_meta( $post->ID, '_EventAllDay', true ) ),
			'timezone'        => in_array( $zone, timezone_identifiers_list(), true ) ? $zone : wp_timezone()->getName(),
			'cost'            => (string) get_post_meta( $post->ID, '_EventCost', true ),
			'status'          => $status,
			'venue_id'        => $venue,
			'venue_name'      => $venue ? (string) get_the_title( $venue ) : '',
			'organizer_id'    => $organizers[0] ?? 0,
			'thumbnail_id'    => (int) get_post_thumbnail_id( $post->ID ),
			'paths'           => self::their_paths( $post ),
			'recurrence_note' => $note,
		);
	}

	/**
	 * The addresses one of their events answered on.
	 *
	 * While their plugin is active WordPress can say exactly; once it is gone
	 * the post type is not registered any more, so the address it used by
	 * default — /event/the-slug/ — is kept as well.
	 *
	 * @param \WP_Post $post Their event.
	 * @return array<int, string>
	 */
	private static function their_paths( $post ): array {
		$paths = array();

		if ( post_type_exists( self::EVENT ) ) {
			$link = get_permalink( $post );
			$path = $link ? (string) wp_parse_url( $link, PHP_URL_PATH ) : '';
			if ( '' !== $path && false === strpos( $path, '?' ) ) {
				$paths[] = '/' . trim( $path, '/' ) . '/';
			}
		}
		if ( '' !== (string) $post->post_name ) {
			$paths[] = '/event/' . $post->post_name . '/';
		}

		return array_values( array_unique( array_filter( $paths, static fn( $path ) => '//' !== $path ) ) );
	}

	/**
	 * Their post status in our terms.
	 *
	 * @param string $status Their status.
	 * @return string
	 */
	private static function our_status( string $status ): string {
		return in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'draft';
	}

	/**
	 * Our category IDs for one of their events.
	 *
	 * @param int $their_id Their event ID.
	 * @return array<int, int>
	 */
	private static function terms( int $their_id ): array {
		$names = array();

		if ( taxonomy_exists( 'tribe_events_cat' ) ) {
			$terms = wp_get_object_terms( $their_id, 'tribe_events_cat' );
			$names = is_wp_error( $terms ) ? array() : wp_list_pluck( $terms, 'name' );
		} else {
			// Their plugin is gone, so the taxonomy is not registered any more.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading another plugin's terms, which have no cache of ours.
			$names = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT t.name FROM {$wpdb->terms} t
					 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'tribe_events_cat'
					 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
					 WHERE tr.object_id = %d",
					$their_id
				)
			);
		}

		$ids = array();
		foreach ( $names as $name ) {
			$name     = (string) $name;
			$existing = get_term_by( 'name', $name, Post_Types::CATEGORY );
			if ( $existing && ! is_wp_error( $existing ) ) {
				$ids[] = (int) $existing->term_id;
				continue;
			}
			$made = wp_insert_term( $name, Post_Types::CATEGORY );
			if ( ! is_wp_error( $made ) ) {
				$ids[] = (int) $made['term_id'];
			}
		}
		return $ids;
	}

	/**
	 * Our venue for one of theirs, making it the first time it is needed.
	 *
	 * @param int $their_id Their venue ID.
	 * @return int
	 */
	private static function venue( int $their_id ): int {
		$ours = self::ours( $their_id, Post_Types::VENUE );
		if ( $ours ) {
			return $ours;
		}

		$address = array_filter(
			array(
				(string) get_post_meta( $their_id, '_VenueAddress', true ),
			)
		);
		$city    = array_filter(
			array(
				(string) get_post_meta( $their_id, '_VenueCity', true ),
				(string) get_post_meta( $their_id, '_VenueStateProvince', true ),
				(string) get_post_meta( $their_id, '_VenueZip', true ),
			)
		);

		$made = Venue::save(
			array(
				'name'    => (string) get_the_title( $their_id ),
				'address' => implode( ', ', $address ),
				'city'    => implode( ', ', $city ),
				'lat'     => (string) get_post_meta( $their_id, '_VenueLatitude', true ),
				'lng'     => (string) get_post_meta( $their_id, '_VenueLongitude', true ),
			)
		);
		if ( is_wp_error( $made ) ) {
			return 0;
		}

		self::mark( (int) $made, $their_id );
		return (int) $made;
	}

	/**
	 * Our organizer for one of theirs, making it the first time it is needed.
	 *
	 * @param int $their_id Their organizer ID.
	 * @return int
	 */
	private static function organizer( int $their_id ): int {
		$ours = self::ours( $their_id, Post_Types::ORGANIZER );
		if ( $ours ) {
			return $ours;
		}

		$made = Organizer::save(
			array(
				'name'    => (string) get_the_title( $their_id ),
				'email'   => (string) get_post_meta( $their_id, '_OrganizerEmail', true ),
				'phone'   => (string) get_post_meta( $their_id, '_OrganizerPhone', true ),
				'website' => (string) get_post_meta( $their_id, '_OrganizerWebsite', true ),
			)
		);
		if ( is_wp_error( $made ) ) {
			return 0;
		}

		self::mark( (int) $made, $their_id );
		return (int) $made;
	}

	/**
	 * The post we already made for one of theirs.
	 *
	 * @param int    $their_id Their post ID.
	 * @param string $type     Our post type.
	 * @return int
	 */
	private static function ours( int $their_id, string $type ): int {
		$found = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- matching one migrated post.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => Import::SOURCE_META,
						'value' => self::SOURCE,
					),
					array(
						'key'   => Import::UID_META,
						'value' => (string) $their_id,
					),
				),
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Mark one of our posts as made by this migration.
	 *
	 * @param int $ours   Our post ID.
	 * @param int $theirs Their post ID.
	 * @return void
	 */
	private static function mark( int $ours, int $theirs ): void {
		update_post_meta( $ours, Import::SOURCE_META, self::SOURCE );
		update_post_meta( $ours, Import::UID_META, (string) $theirs );
	}

	/**
	 * Send an old address to the event that took its place.
	 *
	 * Only ever runs on a page that would otherwise be a 404, so it cannot get
	 * in the way of The Events Calendar while both are installed.
	 *
	 * @return void
	 */
	public static function redirect(): void {
		if ( ! is_404() ) {
			return;
		}

		$asked = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path  = (string) wp_parse_url( $asked, PHP_URL_PATH );
		$path  = '/' . trim( rawurldecode( $path ), '/' ) . '/';
		if ( '//' === $path ) {
			return;
		}

		$found = get_posts(
			array(
				'post_type'      => Post_Types::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only on a page that is already a 404.
				'meta_query'     => array(
					array(
						'key'   => self::PATH_META,
						'value' => $path,
					),
				),
			)
		);
		if ( ! $found ) {
			return;
		}

		$target = get_permalink( (int) $found[0] );
		if ( ! $target ) {
			return;
		}
		wp_safe_redirect( $target, 301, 'RayEtun Accessible Events Calendar' );
		exit;
	}
}
