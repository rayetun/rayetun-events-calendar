<?php
/**
 * The first five minutes.
 *
 * Three questions, each of which changes something real, and then a way in:
 * a calendar page, a first event, or the events you already have somewhere
 * else. Nothing is asked twice, nothing is compulsory, and the answers are
 * all in Settings afterwards.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-run setup.
 */
class Onboarding {

	/**
	 * Where "they have been through it" is kept.
	 *
	 * @var string
	 */
	const OPTION = 'rayec_welcomed';

	/**
	 * Other event plugins worth noticing, and what to look for.
	 *
	 * Each is checked by its own post type, which is there whether or not the
	 * plugin is switched on at this moment.
	 *
	 * @return array<string, array{name: string, post_type: string, migrate: bool}>
	 */
	private static function others(): array {
		return array(
			'the-events-calendar' => array(
				'name'      => 'The Events Calendar',
				'post_type' => 'tribe_events',
				'migrate'   => true,
			),
			'events-manager'      => array(
				'name'      => 'Events Manager',
				'post_type' => 'event',
				'migrate'   => false,
			),
			'modern-events'       => array(
				'name'      => 'Modern Events Calendar',
				'post_type' => 'mec-events',
				'migrate'   => false,
			),
			'eventprime'          => array(
				'name'      => 'EventPrime',
				'post_type' => 'em_event',
				'migrate'   => false,
			),
			'event-organiser'     => array(
				'name'      => 'Event Organiser',
				'post_type' => 'event',
				'migrate'   => false,
			),
			'all-in-one'          => array(
				'name'      => 'All-in-One Event Calendar',
				'post_type' => 'ai1ec_event',
				'migrate'   => false,
			),
		);
	}

	/**
	 * What the welcome needs to know.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		global $wpdb;

		$found = array();
		$seen  = array();
		foreach ( self::others() as $key => $plugin ) {
			// Two plugins use the post type "event"; count it once.
			if ( isset( $seen[ $plugin['post_type'] ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counting another plugin's posts, which have no cache of ours.
			$count                        = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','trash','inherit')",
					$plugin['post_type']
				)
			);
			$seen[ $plugin['post_type'] ] = true;
			if ( $count > 0 ) {
				$found[] = array(
					'key'     => $key,
					'name'    => $plugin['name'],
					'events'  => $count,
					'migrate' => $plugin['migrate'],
				);
			}
		}

		$events = wp_count_posts( Post_Types::EVENT );

		return array(
			'done'     => (bool) get_option( self::OPTION, false ),
			'events'   => (int) ( $events->publish ?? 0 ) + (int) ( $events->draft ?? 0 ) + (int) ( $events->pending ?? 0 ),
			'page'     => (bool) Rest::calendar_page_id(),
			'found'    => $found,
			'settings' => array(
				'default_view'   => (string) Plugin::setting( 'default_view' ),
				'week_starts_on' => (int) Plugin::setting( 'week_starts_on' ),
				'theme'          => (string) Plugin::setting( 'theme' ),
			),
		);
	}

	/**
	 * Save the answers.
	 *
	 * @param array<string, mixed> $answers What they chose.
	 * @return array<string, mixed>
	 */
	public static function finish( array $answers ): array {
		$saved = Plugin::save_settings(
			array(
				'default_view'   => (string) ( $answers['default_view'] ?? '' ),
				'week_starts_on' => (int) ( $answers['week_starts_on'] ?? 1 ),
			)
		);
		update_option( Plugin::SETTINGS_OPTION, $saved, false );

		$page = array();
		if ( ! empty( $answers['make_page'] ) ) {
			$made = Rest::create_calendar_page();
			$page = is_wp_error( $made ) ? array() : $made;
		}

		update_option( self::OPTION, true, false );

		return array(
			'done' => true,
			'page' => $page,
		);
	}

	/**
	 * Show the welcome again, for someone who wants another look.
	 *
	 * @return array<string, mixed>
	 */
	public static function reset(): array {
		delete_option( self::OPTION );
		return array( 'done' => false );
	}

	/**
	 * Put the welcome away without answering it.
	 *
	 * @return array<string, mixed>
	 */
	public static function skip(): array {
		update_option( self::OPTION, true, false );
		return array( 'done' => true );
	}
}
