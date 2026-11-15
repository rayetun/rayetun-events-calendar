<?php
/**
 * How events look to search engines and to anything that unfurls a link.
 *
 * The structured data an event carries lives in Frontend::structured_data().
 * This file deals with the rest: what belongs in the sitemap, which calendar
 * addresses should not be indexed at all, and the picture and wording a link
 * shows when somebody shares it.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap, robots and sharing tags.
 */
class Seo {

	/**
	 * How long an event stays in the sitemap after it has finished.
	 *
	 * @var int
	 */
	const KEEP_DAYS = 365;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_head', array( __CLASS__, 'sharing_tags' ), 5 );
	}

	/**
	 * Keep the sitemap to events that are still worth visiting.
	 *
	 * An events calendar that has been running for ten years would otherwise
	 * fill its sitemap with evenings that finished long ago.
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string               $post_type Post type being listed.
	 * @return array<string, mixed>
	 */
	public static function sitemap_args( $args, $post_type ) {
		if ( Post_Types::EVENT !== $post_type || ! is_array( $args ) ) {
			return $args;
		}

		/**
		 * Filters how long a finished event stays in the sitemap.
		 *
		 * @param int $days Days after the last occurrence ends.
		 */
		$days = (int) apply_filters( 'rayec_sitemap_keep_days', self::KEEP_DAYS );

		global $wpdb;
		$table = $wpdb->prefix . Occurrences::TABLE;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, whose name is built here, not taken from anywhere.
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$table} WHERE end_utc >= %s LIMIT 5000", $since ) );
		$ids  = array_map( 'intval', $rows );

		// An empty list would mean "no filter", so say "nothing" out loud.
		$args['post__in'] = $ids ? $ids : array( 0 );
		return $args;
	}

	/**
	 * Keep crawlers out of the endless addresses a calendar can produce.
	 *
	 * Every month, week, day, page and filter is its own address. They are all
	 * made of the same events, and there is no end to them, so they are left
	 * out of the index while still being followed to the events themselves.
	 *
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) || is_admin() ) {
			return $robots;
		}

		$view_args = array( 'rayec_view', 'rayec_month', 'rayec_from', 'rayec_page', 'rayec_s', 'rayec_cat', 'rayec_venue', 'rayec_org', 'rayec_format', 'rayec_access', 'rayec_mini' );
		foreach ( $view_args as $arg ) {
			if ( '' !== Frontend::param( $arg ) ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
				unset( $robots['nofollow'] );
				return $robots;
			}
		}
		return $robots;
	}

	/**
	 * Open Graph and Twitter tags for a single event.
	 *
	 * Most SEO plugins write these themselves; when one is in charge, this
	 * stays out of its way rather than putting a second set on the page.
	 *
	 * @return void
	 */
	public static function sharing_tags(): void {
		if ( ! is_singular( Post_Types::EVENT ) || ! self::should_write_tags() ) {
			return;
		}
		$event = Event::get( (int) get_queried_object_id() );
		if ( ! $event ) {
			return;
		}

		$times       = Frontend::current_occurrences( $event, 0 );
		$description = '' !== $event['summary'] ? $event['summary'] : '';
		if ( $times ) {
			$when        = Occurrences::format_range( $times[0][0], $times[0][1], $event['all_day'] );
			$description = '' !== $description ? $when . '. ' . $description : $when;
		}

		$tags = array(
			'og:type'        => 'article',
			'og:title'       => $event['title'],
			'og:description' => wp_trim_words( $description, 40 ),
			'og:url'         => $event['permalink'],
			'og:site_name'   => get_bloginfo( 'name' ),
		);

		// Its own picture, or one drawn for it, or the map, or the site icon.
		$picture = Share_Image::for_event( $event, $times[0] ?? null );
		if ( $picture ) {
			$tags['og:image']        = $picture['url'];
			$tags['og:image:alt']    = $picture['alt'];
			$tags['og:image:width']  = (string) $picture['width'];
			$tags['og:image:height'] = (string) $picture['height'];
		}

		foreach ( $tags as $property => $content ) {
			if ( '' === (string) $content ) {
				continue;
			}
			echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( (string) $content ) . '">' . "\n";
		}

		echo '<meta name="twitter:card" content="' . ( isset( $tags['og:image'] ) && $tags['og:image:width'] >= 600 ? 'summary_large_image' : 'summary' ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $event['title'] ) . '">' . "\n";
		if ( '' !== $tags['og:description'] ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $tags['og:description'] ) . '">' . "\n";
		}
	}

	/**
	 * Whether anything else is already writing sharing tags.
	 *
	 * @return bool
	 */
	private static function should_write_tags(): bool {
		$taken = defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| class_exists( 'The_SEO_Framework\\Load' );

		/**
		 * Filters whether the plugin writes its own Open Graph tags.
		 *
		 * @param bool $write True to write them.
		 */
		return (bool) apply_filters( 'rayec_write_sharing_tags', ! $taken );
	}
}
