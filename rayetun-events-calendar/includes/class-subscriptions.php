<?php
/**
 * Calendars this site keeps up with.
 *
 * An import is a moment; a subscription is a standing arrangement. The feed is
 * read again on a schedule and the events it holds are updated in place, which
 * works because an imported event remembers where it came from.
 *
 * Events that disappear from the feed are left alone rather than deleted: a
 * feed that breaks, or that only publishes the next month, must never be able
 * to empty a calendar.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscribed calendars.
 */
class Subscriptions {

	/**
	 * Where the list is kept.
	 *
	 * @var string
	 */
	const OPTION = 'rayec_feeds';

	/**
	 * The cron hook.
	 *
	 * @var string
	 */
	const HOOK = 'rayec_sync_feeds';

	/**
	 * How often a feed can be checked.
	 *
	 * @return array<string, string> Key => what to call it.
	 */
	public static function schedules(): array {
		return array(
			'hourly'     => __( 'Every hour', 'rayetun-events-calendar' ),
			'twicedaily' => __( 'Twice a day', 'rayetun-events-calendar' ),
			'daily'      => __( 'Once a day', 'rayetun-events-calendar' ),
			'weekly'     => __( 'Once a week', 'rayetun-events-calendar' ),
		);
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_due' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Make sure the hourly check is booked.
	 *
	 * The check itself is hourly; each feed is only read when it is due, so
	 * "once a week" costs one quick look an hour and six days of nothing.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( self::all() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Every subscribed calendar.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? array_values( array_filter( $saved, 'is_array' ) ) : array();
	}

	/**
	 * Add or change a subscription.
	 *
	 * @param array<string, mixed> $feed Feed.
	 * @return array<string, mixed>|WP_Error The saved feed.
	 */
	public static function save( array $feed ) {
		$url = trim( (string) ( $feed['url'] ?? '' ) );
		$url = (string) preg_replace( '#^webcal://#i', 'https://', $url );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'rayec_bad_url', __( 'That does not look like an address this site can reach.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$every = (string) ( $feed['every'] ?? 'daily' );
		$every = isset( self::schedules()[ $every ] ) ? $every : 'daily';

		$all   = self::all();
		$id    = (string) ( $feed['id'] ?? '' );
		$saved = array(
			'id'             => '' !== $id ? sanitize_key( $id ) : substr( md5( $url . wp_rand() ), 0, 12 ),
			'url'            => $url,
			'name'           => sanitize_text_field( (string) ( $feed['name'] ?? '' ) ),
			'every'          => $every,
			'post_status'    => in_array( $feed['post_status'] ?? '', array( 'publish', 'draft', 'pending' ), true ) ? (string) $feed['post_status'] : 'draft',
			'category'       => (int) ( $feed['category'] ?? 0 ),
			'create_venues'  => ! empty( $feed['create_venues'] ),
			'use_categories' => ! empty( $feed['use_categories'] ),
			// Whose subscription this is: the scheduled check runs as them, so
			// it can do no more than they could do by hand.
			'user'           => get_current_user_id(),
			'last_run'       => 0,
			'last_result'    => '',
		);

		$replaced = false;
		foreach ( $all as $index => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $saved['id'] || ( $existing['url'] ?? '' ) === $saved['url'] ) {
				// Keep what the last check found.
				$saved['id']          = (string) ( $existing['id'] ?? $saved['id'] );
				$saved['last_run']    = (int) ( $existing['last_run'] ?? 0 );
				$saved['last_result'] = (string) ( $existing['last_result'] ?? '' );
				$all[ $index ]        = $saved;
				$replaced             = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$all[] = $saved;
		}

		update_option( self::OPTION, $all, false );
		self::schedule();
		return $saved;
	}

	/**
	 * Stop following a calendar. The events it brought in stay.
	 *
	 * @param string $id Feed ID.
	 * @return bool
	 */
	public static function remove( string $id ): bool {
		$all  = self::all();
		$left = array_values( array_filter( $all, static fn( $feed ) => ( $feed['id'] ?? '' ) !== $id ) );
		update_option( self::OPTION, $left, false );

		if ( ! $left ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
		return count( $left ) !== count( $all );
	}

	/**
	 * How long between checks, in seconds.
	 *
	 * @param string $every Schedule key.
	 * @return int
	 */
	private static function gap( string $every ): int {
		switch ( $every ) {
			case 'hourly':
				return HOUR_IN_SECONDS;
			case 'twicedaily':
				return 12 * HOUR_IN_SECONDS;
			case 'weekly':
				return WEEK_IN_SECONDS;
			default:
				return DAY_IN_SECONDS;
		}
	}

	/**
	 * Read every feed that is due. This is what the schedule calls.
	 *
	 * @return array<int, array<string, mixed>> What each one did.
	 */
	public static function run_due(): array {
		$done = array();
		foreach ( self::all() as $feed ) {
			$due = (int) ( $feed['last_run'] ?? 0 ) + self::gap( (string) $feed['every'] );
			if ( time() < $due ) {
				continue;
			}
			$done[] = self::sync( (string) $feed['id'] );
		}
		return $done;
	}

	/**
	 * Read one feed now.
	 *
	 * @param string $id Feed ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function sync( string $id ) {
		$all   = self::all();
		$index = null;
		foreach ( $all as $key => $feed ) {
			if ( ( $feed['id'] ?? '' ) === $id ) {
				$index = $key;
				break;
			}
		}
		if ( null === $index ) {
			return new WP_Error( 'rayec_no_feed', __( 'That calendar is not on the list.', 'rayetun-events-calendar' ), array( 'status' => 404 ) );
		}

		$feed = $all[ $index ];

		// On a schedule there is nobody signed in, so run as whoever asked for this.
		$restore = get_current_user_id();
		if ( ! $restore && (int) ( $feed['user'] ?? 0 ) ) {
			wp_set_current_user( (int) $feed['user'] );
		}

		$result = self::read( $feed );

		if ( ! $restore ) {
			wp_set_current_user( 0 );
		}

		$all[ $index ]['last_run']    = time();
		$all[ $index ]['last_result'] = is_wp_error( $result )
			? $result->get_error_message()
			: sprintf(
				/* translators: 1: number of events added, 2: number updated. */
				__( '%1$d added, %2$d updated.', 'rayetun-events-calendar' ),
				(int) $result['added'],
				(int) $result['updated']
			);
		update_option( self::OPTION, $all, false );

		return is_wp_error( $result ) ? $result : array_merge(
			$result,
			array( 'feed' => $all[ $index ] )
		);
	}

	/**
	 * Fetch a feed and bring its events in.
	 *
	 * @param array<string, mixed> $feed Feed.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function read( array $feed ) {
		$text = Import::fetch( (string) $feed['url'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$items = Import::parse( $text );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		return Import::run(
			$items,
			array(
				'source'         => (string) $feed['url'],
				'post_status'    => (string) $feed['post_status'],
				'category'       => (int) $feed['category'],
				'create_venues'  => ! empty( $feed['create_venues'] ),
				'use_categories' => ! empty( $feed['use_categories'] ),
			)
		);
	}
}
