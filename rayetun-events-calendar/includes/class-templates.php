<?php
/**
 * Templates for the events address.
 *
 * Out of the box a theme shows the event archive the way it shows any list of
 * posts: a heading reading "Archives: Events" and a stack of titles. That is
 * not what anyone following a date link expects, and the mini calendar and the
 * upcoming list both fall back to this address when no page holds the calendar
 * block. So the plugin supplies its own archive: a block template a block theme
 * can take over in the Site Editor, and a plain template for classic themes.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The event archive template.
 */
class Templates {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// After the post type exists, and after Blocks::register so the calendar block is known.
		add_action( 'init', array( __CLASS__, 'register' ), 12 );
		add_filter( 'archive_template', array( __CLASS__, 'classic_template' ) );
		add_filter( 'get_the_archive_title_prefix', array( __CLASS__, 'archive_title_prefix' ) );
	}

	/**
	 * Register the block template, for block themes.
	 *
	 * A template the owner edits in the Site Editor wins over this one, so
	 * anyone who wants the plain list of titles back can have it.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}

		register_block_template(
			'rayetun-events-calendar//archive-' . Post_Types::EVENT,
			array(
				'title'       => __( 'Event archive: calendar', 'rayetun-events-calendar' ),
				'description' => __( 'The calendar visitors see at the events address. Edit it here to change the heading, the width or what sits around the calendar.', 'rayetun-events-calendar' ),
				'content'     => self::content(),
			)
		);
	}

	/**
	 * The block markup for the archive.
	 *
	 * @return string
	 */
	private static function content(): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
			. '<!-- wp:query-title {"type":"archive","level":1} /-->'
			. '<!-- wp:rayec/calendar {"align":"wide"} /-->'
			. '</main><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}

	/**
	 * Hand classic themes a template of our own for the event archive.
	 *
	 * @param string $template Template path the theme offered.
	 * @return string
	 */
	public static function classic_template( $template ) {
		if ( ! is_post_type_archive( Post_Types::EVENT ) || wp_is_block_theme() ) {
			return $template;
		}
		// A theme that has gone to the trouble of its own event archive keeps it.
		if ( $template && basename( (string) $template ) !== 'archive.php' && basename( (string) $template ) !== 'index.php' ) {
			return $template;
		}
		return RAYEC_DIR . 'templates/archive-event.php';
	}

	/**
	 * Drop the "Archives:" prefix on our own archives.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public static function archive_title_prefix( $prefix ) {
		if ( is_post_type_archive( Post_Types::EVENT ) ) {
			return '';
		}
		return $prefix;
	}
}
