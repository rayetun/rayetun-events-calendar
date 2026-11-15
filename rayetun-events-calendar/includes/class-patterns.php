<?php
/**
 * Block patterns, and event fields as block bindings.
 *
 * Patterns are ready-made arrangements of the plugin's own blocks, so nobody
 * has to work out that a calendar wants a mini calendar beside it.
 *
 * Bindings let an ordinary paragraph, heading, button or image in a template
 * show a field of the event being viewed — the date, the place, the cost — so
 * a site can lay the event page out its own way without a line of PHP.
 *
 * @package RayEtun\EventsCalendar;
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Patterns and bindings.
 */
class Patterns {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// After the blocks they are made of.
		add_action( 'init', array( __CLASS__, 'register' ), 13 );
	}

	/**
	 * Register the pattern category, the patterns and the binding source.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_block_pattern_category(
			'rayec',
			array( 'label' => __( 'Events', 'rayetun-events-calendar' ) )
		);

		foreach ( self::patterns() as $name => $pattern ) {
			register_block_pattern( 'rayetun-events-calendar/' . $name, $pattern );
		}

		register_block_bindings_source(
			'rayec/event',
			array(
				'label'              => __( 'Event field', 'rayetun-events-calendar' ),
				'get_value_callback' => array( __CLASS__, 'field' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * The patterns, keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function patterns(): array {
		return array(
			'events-page'    => array(
				'title'       => __( 'Events page', 'rayetun-events-calendar' ),
				'description' => __( 'A heading and the full calendar, the width of the page.', 'rayetun-events-calendar' ),
				'categories'  => array( 'rayec' ),
				'content'     => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">'
					. esc_html__( "What's on", 'rayetun-events-calendar' )
					. '</h1><!-- /wp:heading -->'
					. '<!-- wp:rayec/calendar {"align":"wide"} /-->',
			),
			'calendar-aside' => array(
				'title'       => __( 'Calendar with a sidebar', 'rayetun-events-calendar' ),
				'description' => __( 'The calendar beside a mini calendar and the next few events.', 'rayetun-events-calendar' ),
				'categories'  => array( 'rayec' ),
				'content'     => '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
					. '<!-- wp:column {"width":"66.66%"} --><div class="wp-block-column" style="flex-basis:66.66%">'
					. '<!-- wp:rayec/calendar /-->'
					. '</div><!-- /wp:column -->'
					. '<!-- wp:column {"width":"33.33%"} --><div class="wp-block-column" style="flex-basis:33.33%">'
					. '<!-- wp:rayec/mini-calendar /-->'
					. '<!-- wp:heading {"level":2,"fontSize":"medium"} --><h2 class="wp-block-heading has-medium-font-size">'
					. esc_html__( 'Coming up', 'rayetun-events-calendar' )
					. '</h2><!-- /wp:heading -->'
					. '<!-- wp:rayec/upcoming {"count":4} /-->'
					. '</div><!-- /wp:column --></div><!-- /wp:columns -->',
			),
			'next-event'     => array(
				'title'       => __( 'The next event', 'rayetun-events-calendar' ),
				'description' => __( 'A countdown to the next event, with the ones after it listed below.', 'rayetun-events-calendar' ),
				'categories'  => array( 'rayec' ),
				'content'     => '<!-- wp:rayec/countdown /-->'
					. '<!-- wp:rayec/upcoming {"count":3,"images":true} /-->',
			),
			'event-facts'    => array(
				'title'         => __( 'Event facts (for a single event template)', 'rayetun-events-calendar' ),
				'description'   => __( 'When, where and how much, each reading itself from the event being viewed. Drop it into a single event template.', 'rayetun-events-calendar' ),
				'categories'    => array( 'rayec' ),
				'templateTypes' => array( 'single' ),
				'content'       => self::facts_pattern(),
			),
		);
	}

	/**
	 * Three bound paragraphs: when, where and cost.
	 *
	 * @return string
	 */
	private static function facts_pattern(): string {
		$rows = array(
			'when'  => __( 'When', 'rayetun-events-calendar' ),
			'where' => __( 'Where', 'rayetun-events-calendar' ),
			'cost'  => __( 'Cost', 'rayetun-events-calendar' ),
		);

		$html = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} --><div class="wp-block-group">';
		foreach ( $rows as $field => $label ) {
			$binding = wp_json_encode(
				array(
					'metadata' => array(
						'bindings' => array(
							'content' => array(
								'source' => 'rayec/event',
								'args'   => array( 'field' => $field ),
							),
						),
					),
				)
			);
			$html   .= '<!-- wp:group --><div class="wp-block-group">';
			$html   .= '<!-- wp:paragraph {"fontSize":"small","textColor":"contrast-2"} --><p class="has-small-font-size">' . esc_html( $label ) . '</p><!-- /wp:paragraph -->';
			$html   .= '<!-- wp:paragraph ' . $binding . ' --><p>' . esc_html( $label ) . '</p><!-- /wp:paragraph -->';
			$html   .= '</div><!-- /wp:group -->';
		}

		return $html . '</div><!-- /wp:group -->';
	}

	/**
	 * One field of the event being viewed.
	 *
	 * @param array<string, mixed> $args  Binding arguments; "field" names the field.
	 * @param \WP_Block            $block The block being rendered.
	 * @return string|null The value, or null to leave the block's own content alone.
	 */
	public static function field( array $args, $block = null ): ?string {
		$post_id = 0;
		if ( $block && isset( $block->context['postId'] ) ) {
			$post_id = (int) $block->context['postId'];
		}
		$post_id = $post_id ? $post_id : (int) get_the_ID();

		if ( ! $post_id || Post_Types::EVENT !== get_post_type( $post_id ) ) {
			return null;
		}
		$event = Event::get( $post_id );
		if ( ! $event ) {
			return null;
		}

		$times = Frontend::current_occurrences( $event, 0 );
		$value = self::value( (string) ( $args['field'] ?? '' ), $event, $times[0] ?? null );

		return '' === $value ? null : $value;
	}

	/**
	 * The text for one field name.
	 *
	 * @param string                 $field Field name.
	 * @param array<string, mixed>   $event Event.
	 * @param array<int, mixed>|null $times The occurrence being shown, if any.
	 * @return string
	 */
	private static function value( string $field, array $event, ?array $times ): string {
		switch ( $field ) {
			case 'when':
				return $times ? Occurrences::format_range( $times[0], $times[1], $event['all_day'] ) : '';
			case 'date':
				return $times ? wp_date( (string) get_option( 'date_format', 'F j, Y' ), $times[0]->getTimestamp(), $times[0]->getTimezone() ) : '';
			case 'time':
				return $times && ! $event['all_day'] ? wp_date( Plugin::time_format(), $times[0]->getTimestamp(), $times[0]->getTimezone() ) : '';
			case 'repeats':
				return (string) $event['rrule_text'];
			case 'where':
				return Frontend::where_line( $event );
			case 'venue':
				return (string) ( $event['venue']['name'] ?? '' );
			case 'address':
				return $event['venue']
					? implode( ', ', array_filter( array( $event['venue']['address'], $event['venue']['city'] ) ) )
					: '';
			case 'room':
				return (string) ( $event['room'] ?? '' );
			case 'organizer':
				return (string) ( $event['organizer']['name'] ?? '' );
			case 'cost':
				return '' !== $event['cost'] ? $event['cost'] : __( 'Free', 'rayetun-events-calendar' );
			case 'program':
				return (string) ( $event['program']['name'] ?? '' );
			case 'category':
				return (string) ( $event['categories'][0]['name'] ?? '' );
			case 'status':
				return (string) Frontend::status_label( $event['event_status'] );
			case 'online_url':
				return (string) $event['online_url'];
			case 'ics':
				return Ics::feed_url( (int) $event['id'] );
			default:
				return '';
		}
	}
}
