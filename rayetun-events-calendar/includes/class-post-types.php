<?php
/**
 * Post types, taxonomies and meta.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the content model.
 */
class Post_Types {

	const EVENT     = 'rayec_event';
	const VENUE     = 'rayec_venue';
	const ORGANIZER = 'rayec_organizer';
	const SPEAKER   = 'rayec_speaker';
	const CATEGORY  = 'rayec_category';
	const TAG       = 'rayec_tag';
	const PROGRAM   = 'rayec_program';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		$slug = (string) Plugin::setting( 'events_slug' );

		// Taxonomies first: their URLs sit under the events slug, and rules registered
		// earlier win, so the event rules must not get the chance to claim them.
		register_taxonomy(
			self::CATEGORY,
			self::EVENT,
			array(
				'labels'            => array(
					'name'          => __( 'Event categories', 'rayetun-events-calendar' ),
					'singular_name' => __( 'Event category', 'rayetun-events-calendar' ),
				),
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => $slug . '/category' ),
			)
		);

		register_taxonomy(
			self::TAG,
			self::EVENT,
			array(
				'labels'       => array(
					'name'          => __( 'Event tags', 'rayetun-events-calendar' ),
					'singular_name' => __( 'Event tag', 'rayetun-events-calendar' ),
				),
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => $slug . '/tag' ),
			)
		);

		// A program groups events that belong together: a festival, a course, a season.
		register_taxonomy(
			self::PROGRAM,
			self::EVENT,
			array(
				'labels'       => array(
					'name'          => __( 'Programs', 'rayetun-events-calendar' ),
					'singular_name' => __( 'Program', 'rayetun-events-calendar' ),
				),
				'hierarchical' => false,
				'show_ui'      => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => $slug . '/program' ),
			)
		);

		register_post_type(
			self::EVENT,
			array(
				'labels'       => array(
					'name'          => __( 'Events', 'rayetun-events-calendar' ),
					'singular_name' => __( 'Event', 'rayetun-events-calendar' ),
					'add_new_item'  => __( 'Add event', 'rayetun-events-calendar' ),
					'edit_item'     => __( 'Edit event', 'rayetun-events-calendar' ),
					'view_item'     => __( 'View event', 'rayetun-events-calendar' ),
					'search_items'  => __( 'Search events', 'rayetun-events-calendar' ),
					'not_found'     => __( 'No events found.', 'rayetun-events-calendar' ),
				),
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'has_archive'  => $slug,
				'rewrite'      => array(
					'slug'       => $slug,
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				'menu_icon'    => 'dashicons-calendar-alt',
			)
		);

		foreach (
			array(
				self::VENUE     => array( __( 'Venues', 'rayetun-events-calendar' ), __( 'Venue', 'rayetun-events-calendar' ) ),
				self::ORGANIZER => array( __( 'Organizers', 'rayetun-events-calendar' ), __( 'Organizer', 'rayetun-events-calendar' ) ),
				self::SPEAKER   => array( __( 'Speakers', 'rayetun-events-calendar' ), __( 'Speaker', 'rayetun-events-calendar' ) ),
			) as $type => $labels
		) {
			register_post_type(
				$type,
				array(
					'labels'              => array(
						'name'          => $labels[0],
						'singular_name' => $labels[1],
					),
					'public'              => false,
					'show_ui'             => true,
					'show_in_menu'        => false,
					'show_in_rest'        => true,
					'exclude_from_search' => true,
					'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				)
			);
		}
	}

	/**
	 * Flush rewrite rules once after activation or a slug change.
	 *
	 * @return void
	 */
	public static function maybe_flush(): void {
		if ( get_option( 'rayec_flush_rewrite' ) ) {
			delete_option( 'rayec_flush_rewrite' );
			flush_rewrite_rules();
		}
	}
}
