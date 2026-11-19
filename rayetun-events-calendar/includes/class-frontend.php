<?php
/**
 * Front-end views: calendar block and shortcode, single event, structured data.
 *
 * Everything is rendered on the server and works with JavaScript off. The
 * script adds arrow-key navigation, hover previews and click-to-load maps.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public rendering.
 */
class Frontend {

	const MONTH_CHIPS = 3;

	/**
	 * The most chips ever written into one day of the month grid, however
	 * many events fall on it. Past this the day links to the day view.
	 *
	 * @var int
	 */
	const CHIP_CEILING = 12;

	/**
	 * Query arguments that carry a filter, and the filter each one sets.
	 */
	const FILTER_ARGS = array(
		'rayec_s'      => 'search',
		'rayec_cat'    => 'category',
		'rayec_venue'  => 'venue',
		'rayec_org'    => 'organizer',
		'rayec_format' => 'format',
		'rayec_access' => 'access',
	);

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_content' ) );
		add_action( 'wp_head', array( __CLASS__, 'structured_data' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'order_archive' ) );
	}

	/**
	 * Register assets, block and shortcode.
	 *
	 * @return void
	 */
	public static function register(): void {
		// While debugging, version assets by file time so edits are never served stale.
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		wp_register_style( 'rayec-frontend', RAYEC_URL . 'assets/css/frontend.css', array(), $debug ? (string) filemtime( RAYEC_DIR . 'assets/css/frontend.css' ) : RAYEC_VERSION );
		wp_register_script(
			'rayec-calendar',
			RAYEC_URL . 'assets/js/calendar.js',
			array(),
			$debug ? (string) filemtime( RAYEC_DIR . 'assets/js/calendar.js' ) : RAYEC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script_module(
			'rayec-view',
			RAYEC_URL . 'assets/js/view.js',
			array(
				'@wordpress/interactivity',
				array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				),
			),
			$debug ? (string) filemtime( RAYEC_DIR . 'assets/js/view.js' ) : RAYEC_VERSION
		);

		add_shortcode( 'rayec_calendar', array( __CLASS__, 'render_calendar' ) );

		$asset_file = RAYEC_DIR . 'build/calendar-block.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = require $asset_file;
			wp_register_script( 'rayec-calendar-block', RAYEC_URL . 'build/calendar-block.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'rayec-calendar-block', 'rayetun-events-calendar' );
		}

		register_block_type(
			'rayec/calendar',
			array(
				'api_version'     => 3,
				'title'           => __( 'Events Calendar', 'rayetun-events-calendar' ),
				'description'     => __( 'A month, week, day or list calendar of your events.', 'rayetun-events-calendar' ),
				'category'        => 'widgets',
				'icon'            => 'calendar-alt',
				'keywords'        => array( 'events', 'calendar', 'schedule' ),
				'attributes'      => array_merge(
					array(
						'view'     => array(
							'type'    => 'string',
							'default' => '',
							'enum'    => array( '', 'month', 'week', 'day', 'list' ),
						),
						'category' => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
					self::look_attributes()
				),
				'supports'        => array_merge(
					self::look_supports(),
					array(
						'align'         => array( 'wide', 'full' ),
						'html'          => false,
						'interactivity' => true,
					)
				),
				'editor_script'   => is_readable( $asset_file ) ? 'rayec-calendar-block' : null,
				'editor_style'    => 'rayec-frontend',
				'render_callback' => array( __CLASS__, 'render_calendar' ),
			)
		);
	}

	/**
	 * The owner's colour choice, as a class.
	 *
	 * "inherit" adds nothing at all: the calendar then takes its colours from
	 * the theme around it, which is what most sites want.
	 *
	 * @return string Class name, with a leading space, or an empty string.
	 */
	public static function theme_class(): string {
		$theme = (string) Plugin::setting( 'theme' );
		return in_array( $theme, array( 'light', 'dark', 'system', 'contrast' ), true ) ? ' rayec-theme--' . $theme : '';
	}

	/**
	 * The attributes every block has for its look.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function look_attributes(): array {
		return array(
			'accent'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'corners' => array(
				'type'    => 'string',
				'default' => '',
				'enum'    => array( '', 'square', 'round' ),
			),
		);
	}

	/**
	 * The editor's own style settings every block offers: text and background
	 * colour, text size, and the space around it. They go on the block's
	 * outer element, and everything inside is sized and coloured from that.
	 *
	 * @return array<string, mixed>
	 */
	public static function look_supports(): array {
		return array(
			'color'      => array(
				'text'       => true,
				'background' => true,
				'link'       => false,
			),
			'typography' => array(
				'fontSize' => true,
			),
			'spacing'    => array(
				'margin'  => true,
				'padding' => true,
			),
		);
	}

	/**
	 * The look a block was given in its settings: an accent colour and the
	 * corners. Shortcodes take the same, as accent="#b4235a" corners="round".
	 *
	 * @param array<string, mixed> $atts Block or shortcode attributes.
	 * @return array{class: string, style: string} Class with a leading space, and inline custom properties.
	 */
	public static function look( array $atts ): array {
		$class   = '';
		$style   = '';
		$corners = sanitize_key( (string) ( $atts['corners'] ?? '' ) );
		if ( in_array( $corners, array( 'square', 'round' ), true ) ) {
			$class .= ' rayec-corners--' . $corners;
		}
		$accent = sanitize_hex_color( (string) ( $atts['accent'] ?? '' ) );
		if ( $accent ) {
			$class .= ' has-rayec-accent';
			// Worked out here, not with color-mix(): WordPress strips functions from a block's inline style.
			$style = '--rayec-accent:' . $accent . ';--rayec-accent-strong:' . self::darker( $accent ) . ';--rayec-on-accent:' . self::on_colour( $accent ) . ';';
		}
		return array(
			'class' => $class,
			'style' => $style,
		);
	}

	/**
	 * A colour as six hex digits, without the hash.
	 *
	 * @param string $hex Colour, as #rgb or #rrggbb.
	 * @return string
	 */
	private static function hex6( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		return 3 === strlen( $hex ) ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex;
	}

	/**
	 * The accent a step darker, for hover.
	 *
	 * @param string $hex Colour.
	 * @return string
	 */
	private static function darker( string $hex ): string {
		$hex = self::hex6( $hex );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$out .= sprintf( '%02x', (int) round( hexdec( substr( $hex, $i * 2, 2 ) ) * 0.78 ) );
		}
		return $out;
	}

	/**
	 * Black or white text on a colour: whichever is easier to read.
	 *
	 * @param string $hex Colour, as #rgb or #rrggbb.
	 * @return string
	 */
	private static function on_colour( string $hex ): string {
		$hex   = self::hex6( $hex );
		$light = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$channel = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$light  += $weight * ( $channel <= 0.04045 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4 );
		}
		// Contrast with white, and with the near-black #111 (light 0.0056).
		return 1.05 / ( $light + 0.05 ) >= ( $light + 0.05 ) / 0.0556 ? '#fff' : '#111';
	}

	/**
	 * A block's wrapper attributes, or plain ones for a shortcode, with the
	 * look from its settings.
	 *
	 * @param string               $classes Classes.
	 * @param array<string, mixed> $atts    Attributes.
	 * @return string
	 */
	public static function wrapper_attributes( string $classes, array $atts ): string {
		$look    = self::look( $atts );
		$classes = $classes . $look['class'];
		if ( \WP_Block_Supports::$block_to_render ) {
			return get_block_wrapper_attributes(
				array_filter(
					array(
						'class' => $classes,
						'style' => $look['style'],
					)
				)
			);
		}
		return 'class="' . esc_attr( $classes ) . '"' . ( '' !== $look['style'] ? ' style="' . esc_attr( $look['style'] ) . '"' : '' );
	}

	/**
	 * A sanitised query-string value.
	 *
	 * @param string $key Parameter name.
	 * @return string
	 */
	public static function param( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state (month, view, search) in a public URL.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	/**
	 * Inline icon. Decorative: always paired with text.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	public static function icon( string $name ): string {
		$paths = array(
			'repeat'   => 'M17 2l4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 0 1-3 3H3',
			'pin'      => 'M12 21s7-6.2 7-11.5a7 7 0 1 0-14 0C5 14.8 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
			'clock'    => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
			'search'   => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
			'screen'   => 'M4 5h16v11H4zM9 20h6M12 16v4',
			'check'    => 'M5 12.5l4.5 4.5L19 7.5',
			'calendar' => 'M8 2.5v3M16 2.5v3M3.5 9.5h17M5.5 4h13a2 2 0 0 1 2 2v13.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
			'users'    => 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM20 20v-1.5a3.5 3.5 0 0 0-2.5-3.35M15.5 4.65a3.5 3.5 0 0 1 0 6.7',
			'ticket'   => 'M3.5 9a2.5 2.5 0 0 0 0 5v3.5a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5V14a2.5 2.5 0 0 1 0-5V5.5A1.5 1.5 0 0 0 19 4H5a1.5 1.5 0 0 0-1.5 1.5zM14 4.5v2M14 10.5v3M14 17.5v1.5',
			'timer'    => 'M10 2.5h4M12 14l3-3M12 21.5a8 8 0 1 0 0-16 8 8 0 0 0 0 16z',
			'door'     => 'M5.5 21V4.5A1.5 1.5 0 0 1 7 3h10a1.5 1.5 0 0 1 1.5 1.5V21M3 21h18M14.5 12v.5',
			'shield'   => 'M12 21.5s7.5-3.5 7.5-9.5V5.5L12 2.5l-7.5 3V12c0 6 7.5 9.5 7.5 9.5zM9 12l2 2 4-4',
			'walk'     => 'M13.5 5.5a1.75 1.75 0 1 0 0-3.5 1.75 1.75 0 0 0 0 3.5zM9.5 21.5l2.5-6.5M12 15l3 2.5v4M7.5 12l2.5-3.5 3.5-.5 2.5 3.5 3 1M12 15l1.5-7',
			'bike'     => 'M5.5 21a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM18.5 21a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM15 6.5a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5zM12 17.5V14l-3-3 4-3 2 3h2',
			'bus'      => 'M5 17V6a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v11zM5 11h14M7.5 17v3M16.5 17v3M8.5 14h.01M15.5 14h.01',
			'car'      => 'M3.5 17v-4.5l2-5.5a2 2 0 0 1 1.9-1.4h9.2a2 2 0 0 1 1.9 1.4l2 5.5V17zM3.5 12.5h17M6.5 17v2.5M17.5 17v2.5M7.5 14.75h.01M16.5 14.75h.01',
			'tag'      => 'M3 12.2V4.5A1.5 1.5 0 0 1 4.5 3h7.7l8.8 8.8-9.2 9.2zM7.5 7.5h.01',
			'arrow'    => 'M5 12h14M13 6l6 6-6 6',
		);
		return '<svg class="rayec-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $paths[ $name ] ?? '' ) . '"/></svg>';
	}

	/**
	 * Render the calendar.
	 *
	 * @param array<string, mixed>|string $atts Block attributes or shortcode attributes.
	 * @return string
	 */
	public static function render_calendar( $atts ): string {
		$atts = shortcode_atts(
			array(
				'view'     => '',
				'category' => 0,
				'accent'   => '',
				'corners'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			'rayec_calendar'
		);

		$views = self::views();
		$view  = self::param( 'rayec_view' );
		if ( ! isset( $views[ $view ] ) ) {
			$view = isset( $views[ $atts['view'] ] ) ? $atts['view'] : (string) Plugin::setting( 'default_view' );
		}
		if ( ! isset( $views[ $view ] ) ) {
			$view = 'month';
		}

		$format = self::param( 'rayec_format' );
		$access = sanitize_key( self::param( 'rayec_access' ) );

		$filters = array(
			'category'  => (int) $atts['category'] ? (int) $atts['category'] : absint( self::param( 'rayec_cat' ) ),
			'search'    => self::param( 'rayec_s' ),
			'venue'     => absint( self::param( 'rayec_venue' ) ),
			'organizer' => absint( self::param( 'rayec_org' ) ),
			'format'    => in_array( $format, Event::FORMATS, true ) ? $format : '',
			'access'    => ( isset( Event::access_features()[ $access ] ) || isset( Venue::access_features()[ $access ] ) ) ? $access : '',
		);

		wp_enqueue_style( 'rayec-frontend' );
		wp_enqueue_script( 'rayec-calendar' );

		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$month = $today->modify( 'first day of this month' );
		if ( preg_match( '/^(\d{4})-(\d{2})$/', self::param( 'rayec_month' ), $m ) && checkdate( (int) $m[2], 1, (int) $m[1] ) ) {
			$month = $month->setDate( (int) $m[1], (int) $m[2], 1 );
		}
		$from = Event::local_datetime( self::param( 'rayec_from' ), $tz );
		$from = $from ? $from->setTime( 0, 0 ) : $today;

		// The week containing the chosen day, starting on the day the owner chose.
		$lead       = ( (int) $from->format( 'w' ) - (int) Plugin::setting( 'week_starts_on' ) + 7 ) % 7;
		$week_start = $from->modify( "-{$lead} days" );
		$week_end   = $week_start->modify( '+6 days' );

		$date_format = (string) get_option( 'date_format', 'F j, Y' );
		$title_id    = wp_unique_id( 'rayec-title-' );

		switch ( $view ) {
			case 'week':
				$title = sprintf(
					/* translators: 1: first day of the week, 2: last day of the week. */
					__( '%1$s to %2$s', 'rayetun-events-calendar' ),
					wp_date( $date_format, $week_start->getTimestamp(), $tz ),
					wp_date( $date_format, $week_end->getTimestamp(), $tz )
				);
				break;
			case 'day':
				$title = wp_date( 'l, ' . $date_format, $from->getTimestamp(), $tz );
				break;
			case 'list':
				$title = $from->format( 'Y-m-d' ) === $today->format( 'Y-m-d' )
					? __( 'Upcoming events', 'rayetun-events-calendar' )
					/* translators: %s: date. */
					: sprintf( __( 'Events from %s', 'rayetun-events-calendar' ), wp_date( $date_format, $from->getTimestamp(), $tz ) );
				break;
			default:
				$title = wp_date( 'F Y', $month->getTimestamp(), $tz );
		}

		// As a block, honour alignment and other block supports chosen in the editor.
		$wrapper = self::wrapper_attributes( 'rayec-cal rayec-cal--' . $view . self::theme_class(), $atts );

		// Switching view, month or filter swaps this region in place; with the
		// module blocked every control is still a plain link or a form submit.
		wp_enqueue_script_module( 'rayec-view' );
		$region = wp_unique_id( 'rayec-region-' );

		$html  = '<div ' . $wrapper . ' data-wp-interactive="rayec" data-wp-router-region="' . esc_attr( $region ) . '" data-wp-on--click="actions.navigate">';
		$html .= self::toolbar( $view, $views, $filters, (int) $atts['category'] > 0 );

		$html .= '<div class="rayec-cal__bar">';
		if ( 'month' === $view ) {
			$html .= '<nav class="rayec-cal__nav" aria-label="' . esc_attr__( 'Change month', 'rayetun-events-calendar' ) . '">';
			$html .= self::nav_link( $month->modify( '-1 month' ), __( 'Previous month', 'rayetun-events-calendar' ), '‹', 'month' );
			$html .= self::nav_link( $month->modify( '+1 month' ), __( 'Next month', 'rayetun-events-calendar' ), '›', 'month' );
			$html .= '<a class="rayec-btn" data-rayec-nav href="' . esc_url( remove_query_arg( 'rayec_month' ) ) . '">' . esc_html__( 'Today', 'rayetun-events-calendar' ) . '</a>';
			$html .= '</nav>';
		} elseif ( 'week' === $view || 'day' === $view ) {
			$step   = 'week' === $view ? '7 days' : '1 day';
			$anchor = 'week' === $view ? $week_start : $from;
			$html  .= '<nav class="rayec-cal__nav" aria-label="' . esc_attr( 'week' === $view ? __( 'Change week', 'rayetun-events-calendar' ) : __( 'Change day', 'rayetun-events-calendar' ) ) . '">';
			$html  .= self::nav_link(
				$anchor->modify( '-' . $step ),
				'week' === $view ? __( 'Previous week', 'rayetun-events-calendar' ) : __( 'Previous day', 'rayetun-events-calendar' ),
				'‹',
				$view
			);
			$html  .= self::nav_link(
				$anchor->modify( '+' . $step ),
				'week' === $view ? __( 'Next week', 'rayetun-events-calendar' ) : __( 'Next day', 'rayetun-events-calendar' ),
				'›',
				$view
			);
			$html  .= '<a class="rayec-btn" data-rayec-nav href="' . esc_url( remove_query_arg( array( 'rayec_from', 'rayec_page' ) ) ) . '">' . esc_html__( 'Today', 'rayetun-events-calendar' ) . '</a>';
			$html  .= '</nav>';
		} elseif ( $from->format( 'Y-m-d' ) !== $today->format( 'Y-m-d' ) ) {
			$html .= '<a class="rayec-btn" data-rayec-nav href="' . esc_url( remove_query_arg( array( 'rayec_from', 'rayec_page' ) ) ) . '">' . esc_html__( 'Today', 'rayetun-events-calendar' ) . '</a>';
		}
		$html .= '<h2 class="rayec-cal__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h2>';
		$html .= '</div>';
		// Read out after an in-place change; silent on first load, when the heading is already there.
		$html .= '<p class="rayec-sr" aria-live="polite">' . esc_html( $title ) . '</p>';

		$html .= self::active_filters( $filters, (int) $atts['category'] > 0 );

		switch ( $view ) {
			case 'week':
				$body = self::week_view( $week_start, $today, $filters );
				break;
			case 'day':
				$body = self::day_view( $from, $filters );
				break;
			case 'list':
				$body = self::list_view( $from, $filters );
				break;
			default:
				$body = self::month_view( $month, $today, $filters );
		}
		$html .= str_replace( '{{title_id}}', esc_attr( $title_id ), $body );
		$html .= self::subscribe( $filters );
		$html .= '</div>';

		return $html;
	}

	/**
	 * How to follow this calendar in whatever program the visitor already uses.
	 *
	 * The feed keeps the category and venue in use, so "the yoga classes at the
	 * Riverside Studio" is something a visitor can subscribe to.
	 *
	 * @param array<string, mixed> $filters Filters in use.
	 * @return string
	 */
	private static function subscribe( array $filters ): string {
		$links  = Ics::subscribe_links( $filters );
		$narrow = ! empty( $filters['category'] ) || ! empty( $filters['venue'] );

		$html  = '<details class="rayec-cal__foot rayec-sub">';
		$html .= '<summary>' . esc_html__( 'Subscribe to this calendar', 'rayetun-events-calendar' ) . '</summary>';
		$html .= '<p class="rayec-sub__note">' . esc_html(
			$narrow
				? __( 'Your calendar will keep up with the events shown here, filters and all, and update itself when they change.', 'rayetun-events-calendar' )
				: __( 'Your own calendar will keep up with these events and update itself when they change.', 'rayetun-events-calendar' )
		) . '</p>';
		$html .= '<ul class="rayec-sub__list">';
		foreach ( $links as $key => $link ) {
			$html .= '<li><a class="rayec-btn" href="' . esc_url( $link['url'], array( 'http', 'https', 'webcal' ) ) . '"' . ( 'file' === $key ? '' : ' rel="noopener"' ) . '>' . esc_html( $link['label'] ) . '</a></li>';
		}
		$html .= '</ul></details>';

		return $html;
	}

	/**
	 * Search, category filter and view switcher.
	 *
	 * @param string                $view            Current view.
	 * @param array<string, string> $views           Available views.
	 * @param array<string, mixed>  $filters         Active filters.
	 * @param bool                  $category_locked Whether the block fixes the category.
	 * @return string
	 */
	private static function toolbar( string $view, array $views, array $filters, bool $category_locked ): string {
		$search_id = wp_unique_id( 'rayec-s-' );
		$action    = strtok( remove_query_arg( array_merge( array_keys( self::FILTER_ARGS ), array( 'rayec_page', 'rayec_view' ) ) ), '?' );

		$html  = '<div class="rayec-tools">';
		$html .= '<form class="rayec-tools__form" role="search" method="get" data-wp-on--submit="actions.search" action="' . esc_url( (string) $action ) . '">';

		// Keep any non-calendar parameters (plain permalinks use ?page_id=).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; values are re-emitted escaped.
		foreach ( array_keys( $_GET ) as $key ) {
			$key = (string) $key;
			if ( 0 !== strpos( $key, 'rayec_' ) && '' !== self::param( $key ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( self::param( $key ) ) . '">';
			}
		}
		$html .= '<input type="hidden" name="rayec_view" value="' . esc_attr( $view ) . '">';

		// Filtering keeps the period on show, rather than jumping back to today.
		foreach ( array( 'rayec_month', 'rayec_from' ) as $keep ) {
			if ( '' !== self::param( $keep ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( $keep ) . '" value="' . esc_attr( self::param( $keep ) ) . '">';
			}
		}

		$html .= '<div class="rayec-tools__field">' . self::icon( 'search' );
		$html .= '<label class="rayec-sr" for="' . esc_attr( $search_id ) . '">' . esc_html__( 'Search for events', 'rayetun-events-calendar' ) . '</label>';
		$html .= '<input type="search" id="' . esc_attr( $search_id ) . '" name="rayec_s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search for events', 'rayetun-events-calendar' ) . '"></div>';

		if ( ! $category_locked ) {
			$terms = get_terms(
				array(
					'taxonomy'   => Post_Types::CATEGORY,
					'hide_empty' => true,
				)
			);
			if ( is_array( $terms ) && $terms ) {
				$options = array();
				foreach ( $terms as $term ) {
					$options[ (string) $term->term_id ] = $term->name;
				}
				$html .= self::select( 'rayec_cat', __( 'Category', 'rayetun-events-calendar' ), __( 'All categories', 'rayetun-events-calendar' ), $options, (string) $filters['category'] );
			}
		}
		$html .= '<button type="submit" class="rayec-btn rayec-btn--primary">' . esc_html__( 'Find events', 'rayetun-events-calendar' ) . '</button>';

		$html .= self::more_filters( $filters );
		$html .= '</form>';

		$html .= '<ul class="rayec-tools__views" aria-label="' . esc_attr__( 'Calendar view', 'rayetun-events-calendar' ) . '">';
		foreach ( $views as $key => $label ) {
			$url   = add_query_arg( 'rayec_view', $key, remove_query_arg( array( 'rayec_page', 'rayec_from' ) ) );
			$html .= '<li><a class="rayec-btn" data-rayec-nav href="' . esc_url( $url ) . '"' . ( $key === $view ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * Month, week or day navigation link.
	 *
	 * @param DateTimeImmutable $target Where it leads.
	 * @param string            $label  Accessible label.
	 * @param string            $glyph  Visible glyph.
	 * @param string            $view   Which view it is navigating.
	 * @return string
	 */
	private static function nav_link( DateTimeImmutable $target, string $label, string $glyph, string $view = 'month' ): string {
		if ( 'month' === $view ) {
			$when = wp_date( 'F Y', $target->getTimestamp(), $target->getTimezone() );
			$url  = add_query_arg( 'rayec_month', $target->format( 'Y-m' ) );
		} else {
			$when = wp_date( (string) get_option( 'date_format', 'F j, Y' ), $target->getTimestamp(), $target->getTimezone() );
			$url  = add_query_arg( 'rayec_from', $target->format( 'Y-m-d' ), remove_query_arg( 'rayec_page' ) );
		}
		/* translators: 1: "Previous week", "Next month" and so on, 2: the date or month it leads to. */
		$full = sprintf( __( '%1$s, %2$s', 'rayetun-events-calendar' ), $label, $when );
		return '<a class="rayec-btn rayec-btn--icon" data-rayec-nav rel="nofollow" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $full ) . '"><span aria-hidden="true">' . esc_html( $glyph ) . '</span></a>';
	}

	/**
	 * Occurrences in a local range.
	 *
	 * @param DateTimeImmutable    $from    Range start (local midnight).
	 * @param DateTimeImmutable    $to      Range end (local midnight, exclusive).
	 * @param array<string, mixed> $filters Category and search filters.
	 * @param int                  $limit   Row limit.
	 * @return array<int, array<string, mixed>>
	 */
	private static function occurrences( DateTimeImmutable $from, DateTimeImmutable $to, array $filters, int $limit = 1500 ): array {
		return self::hydrate( self::occurrence_rows( $from, $to, $filters, $limit ) );
	}

	/**
	 * The index rows for a range, with no events loaded.
	 *
	 * Splitting this from hydrate() lets the month grid decide which rows it
	 * is going to draw before paying for the events behind them.
	 *
	 * @param DateTimeImmutable    $from    Range start.
	 * @param DateTimeImmutable    $to      Range end.
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit   Row limit.
	 * @return array<int, object>
	 */
	private static function occurrence_rows( DateTimeImmutable $from, DateTimeImmutable $to, array $filters, int $limit = 1500 ): array {
		$utc = new DateTimeZone( 'UTC' );
		return Occurrences::query(
			array(
				'start'     => $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'end'       => $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'category'  => $filters['category'] ?? 0,
				'search'    => $filters['search'] ?? '',
				'venue'     => $filters['venue'] ?? 0,
				'organizer' => $filters['organizer'] ?? 0,
				'format'    => $filters['format'] ?? '',
				'access'    => $filters['access'] ?? '',
				'limit'     => $limit,
			)
		);
	}

	/**
	 * Index rows with their events loaded.
	 *
	 * @param array<int, object> $rows Index rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function hydrate( array $rows ): array {
		// One trip for every post, one for all their meta, one for all their
		// terms, before anything asks for an event. Otherwise a busy month is
		// three queries per event.
		Event::prime( wp_list_pluck( $rows, 'event_id' ) );

		$events = array();
		foreach ( $rows as $row ) {
			$event_id = (int) $row->event_id;
			if ( ! array_key_exists( $event_id, $events ) ) {
				$events[ $event_id ] = Event::get( $event_id );
			}
		}

		// Places left, for the dates of events that take bookings with a
		// limit: one query for the whole view, and none when nothing does.
		$counted = array_filter( $rows, static fn( $row ) => ! empty( $events[ (int) $row->event_id ] ) && Registrations::is_open( $events[ (int) $row->event_id ] ) && $events[ (int) $row->event_id ]['rsvp']['capacity'] );
		$taken   = $counted ? Registrations::taken_for_rows( $counted ) : array();
		$now     = time();

		$out = array();
		foreach ( $rows as $key => $row ) {
			$event = $events[ (int) $row->event_id ];
			if ( ! $event ) {
				continue;
			}
			list( $start, $end ) = Occurrences::times( $row );

			$left = null;
			if ( isset( $counted[ $key ] ) && $start->getTimestamp() > $now ) {
				$left = max( 0, (int) $event['rsvp']['capacity'] - ( $taken[ (int) $row->event_id . '|' . $row->start_local ] ?? 0 ) );
			}

			$out[] = array(
				'event'   => $event,
				'start'   => $start,
				'end'     => $end,
				'all_day' => (bool) $row->all_day,
				'url'     => self::occurrence_url( $event, $start ),
				'left'    => $left,
			);
		}
		return $out;
	}

	/**
	 * Link to one occurrence of an event.
	 *
	 * The date alone is enough for a series, but an event that runs more than
	 * once a day needs the time too, or every session leads to the same page.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param DateTimeImmutable    $start Occurrence start.
	 * @return string
	 */
	public static function occurrence_url( array $event, DateTimeImmutable $start ): string {
		$args = array();
		if ( $event['rrule'] || $event['extra_times'] ) {
			$args['rayec_date'] = $start->format( 'Y-m-d' );
		}
		if ( $event['extra_times'] ) {
			$args['rayec_time'] = $start->format( 'H:i' );
		}
		return $args ? add_query_arg( $args, $event['permalink'] ) : $event['permalink'];
	}

	/**
	 * Short status label, or an empty string when the event is going ahead.
	 *
	 * @param string $status Event status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'cancelled'    => __( 'Cancelled', 'rayetun-events-calendar' ),
			'postponed'    => __( 'Postponed', 'rayetun-events-calendar' ),
			'moved_online' => __( 'Moved online', 'rayetun-events-calendar' ),
		);
		return $labels[ $status ] ?? '';
	}

	/**
	 * "Repeats" marker with a text alternative.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function repeat_mark( array $event ): string {
		if ( ! $event['rrule'] ) {
			return '';
		}
		return ' <span class="rayec-repeat" title="' . esc_attr( $event['rrule_text'] ) . '">' . self::icon( 'repeat' ) . '<span class="rayec-sr">' . esc_html( $event['rrule_text'] ) . '</span></span>';
	}

	/**
	 * Month grid: a real table, one cell per day.
	 *
	 * @param DateTimeImmutable    $month   First day of the month.
	 * @param DateTimeImmutable    $today   Today.
	 * @param array<string, mixed> $filters Filters.
	 * @return string
	 */
	private static function month_view( DateTimeImmutable $month, DateTimeImmutable $today, array $filters ): string {
		global $wp_locale;

		$week_start = (int) Plugin::setting( 'week_starts_on' );
		$lead       = ( (int) $month->format( 'w' ) - $week_start + 7 ) % 7;
		$grid_start = $month->modify( "-{$lead} days" );
		$weeks      = (int) ceil( ( $lead + (int) $month->format( 't' ) ) / 7 );
		$grid_end   = $grid_start->modify( '+' . ( $weeks * 7 ) . ' days' );

		list( $by_day, $totals ) = self::grid_days( $grid_start, $grid_end, $filters );

		$uid      = wp_unique_id( 'rayec-pv-' );
		$previews = array();

		$html  = '<table class="rayec-month" aria-labelledby="{{title_id}}" data-rayec-grid>';
		$html .= '<thead><tr>';
		for ( $i = 0; $i < 7; $i++ ) {
			$name  = $wp_locale->get_weekday( ( $week_start + $i ) % 7 );
			$html .= '<th scope="col"><abbr title="' . esc_attr( $name ) . '">' . esc_html( $wp_locale->get_weekday_abbrev( $name ) ) . '</abbr></th>';
		}
		$html .= '</tr></thead><tbody>';

		$day         = $grid_start;
		$date_format = (string) get_option( 'date_format', 'F j, Y' );

		for ( $week = 0; $week < $weeks; $week++ ) {
			$html .= '<tr>';
			for ( $i = 0; $i < 7; $i++ ) {
				$key      = $day->format( 'Y-m-d' );
				$items    = $by_day[ $key ] ?? array();
				$is_today = $key === $today->format( 'Y-m-d' );
				$classes  = array( 'rayec-month__cell' );
				if ( $day->format( 'Y-m' ) !== $month->format( 'Y-m' ) ) {
					$classes[] = 'is-outside';
				}
				if ( $key < $today->format( 'Y-m-d' ) ) {
					$classes[] = 'is-past';
				}
				if ( $is_today ) {
					$classes[] = 'is-today';
				}
				if ( $items ) {
					$classes[] = 'has-events';
				}

				$total = $totals[ $key ] ?? count( $items );
				$full  = wp_date( 'l, ' . $date_format, $day->getTimestamp(), $day->getTimezone() );
				/* translators: %d: number of events. */
				$count = $items ? sprintf( _n( '%d event', '%d events', $total, 'rayetun-events-calendar' ), $total ) : __( 'No events', 'rayetun-events-calendar' );

				$html .= '<td class="' . esc_attr( implode( ' ', $classes ) ) . '" data-rayec-date="' . esc_attr( $key ) . '"' . ( $is_today ? ' aria-current="date"' : '' ) . '>';
				$html .= '<div class="rayec-month__day"><span class="rayec-month__num" aria-hidden="true">' . esc_html( $day->format( 'j' ) ) . '</span>';
				$html .= '<span class="rayec-month__full">' . esc_html( $full ) . '</span>';
				$html .= '<span class="rayec-sr">, ' . esc_html( $is_today ? __( 'today', 'rayetun-events-calendar' ) . ', ' . $count : $count ) . '</span></div>';

				if ( $items ) {
					$html .= self::chips( $items, $key, $full, $uid, $previews, self::MONTH_CHIPS, $total );
				}
				$html .= '</td>';
				$day   = $day->modify( '+1 day' );
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		if ( ! $by_day ) {
			$html .= '<p class="rayec-cal__empty">' . esc_html__( 'There are no events this month.', 'rayetun-events-calendar' ) . '</p>';
		}

		$html .= self::preview_sources( $previews, $uid );

		return $html;
	}

	/**
	 * Small labels describing an event: category, format, cost, access.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function tags( array $event ): string {
		$html = '';
		foreach ( $event['categories'] as $category ) {
			$color = sanitize_hex_color( (string) $category['color'] );
			$icons = self::category_icons();
			$icon  = isset( $icons[ $category['icon'] ?? '' ] ) ? '<svg class="rayec-ico" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $icons[ $category['icon'] ]['path'] ) . '"/></svg>' : '';
			$html .= '<li class="rayec-tag rayec-tag--cat"' . ( $color ? ' style="--rayec-chip:' . esc_attr( $color ) . '"' : '' ) . '>' . $icon . esc_html( $category['name'] ) . '</li>';
		}
		if ( 'in_person' !== $event['format'] || 'moved_online' === $event['event_status'] ) {
			$html .= '<li class="rayec-tag">' . self::icon( 'screen' ) . esc_html( 'hybrid' === $event['format'] && 'moved_online' !== $event['event_status'] ? __( 'In person and online', 'rayetun-events-calendar' ) : __( 'Online', 'rayetun-events-calendar' ) ) . '</li>';
		}
		if ( '' !== $event['cost'] ) {
			$html .= '<li class="rayec-tag">' . esc_html( $event['cost'] ) . '</li>';
		}
		$features = array_intersect_key( Event::access_features(), array_flip( (array) ( $event['access'] ?? array() ) ) );
		if ( ! empty( $event['venue']['access'] ) && 'online' !== $event['format'] ) {
			$features += array_intersect_key( Venue::access_features(), array_flip( $event['venue']['access'] ) );
		}
		if ( $features ) {
			foreach ( array_slice( $features, 0, 3 ) as $label ) {
				$html .= '<li class="rayec-tag rayec-tag--access">' . self::icon( 'check' ) . esc_html( $label ) . '</li>';
			}
		}
		return $html ? '<ul class="rayec-tags">' . $html . '</ul>' : '';
	}

	/**
	 * List view: occurrences from a date onwards, grouped by month.
	 *
	 * @param DateTimeImmutable    $from    First day to show.
	 * @param array<string, mixed> $filters Filters.
	 * @return string
	 */
	private static function list_view( DateTimeImmutable $from, array $filters ): string {
		$per_page = (int) Plugin::setting( 'list_per_page' );
		$page     = max( 1, (int) self::param( 'rayec_page' ) );
		$all      = self::occurrence_rows( $from, $from->modify( '+' . Occurrences::WINDOW_MONTHS . ' months' ), $filters, $per_page * $page + 1 );
		$items    = self::hydrate( array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) );

		if ( ! $items ) {
			return '<p class="rayec-cal__empty">' . esc_html( '' !== $filters['search'] ? __( 'No events match your search.', 'rayetun-events-calendar' ) : __( 'There are no upcoming events.', 'rayetun-events-calendar' ) ) . '</p>';
		}

		$html    = '<div class="rayec-list" aria-labelledby="{{title_id}}" role="group">';
		$current = '';
		foreach ( $items as $item ) {
			$group = $item['start']->format( 'Y-m' );
			if ( $group !== $current ) {
				$html   .= ( '' !== $current ? '</ul>' : '' ) . '<h3 class="rayec-list__month"><span>' . esc_html( wp_date( 'F Y', $item['start']->getTimestamp(), $item['start']->getTimezone() ) ) . '</span></h3><ul class="rayec-list__items">';
				$current = $group;
			}
			$html .= self::list_item( $item, 'h4' );
		}
		$html .= '</ul></div>';

		if ( $page > 1 || count( $all ) > $page * $per_page ) {
			$html .= '<nav class="rayec-cal__pager" aria-label="' . esc_attr__( 'More events', 'rayetun-events-calendar' ) . '">';
			if ( $page > 1 ) {
				$html .= '<a class="rayec-btn" data-rayec-nav rel="nofollow" href="' . esc_url( add_query_arg( 'rayec_page', $page - 1 ) ) . '">' . esc_html__( 'Earlier events', 'rayetun-events-calendar' ) . '</a>';
			}
			if ( count( $all ) > $page * $per_page ) {
				$html .= '<a class="rayec-btn" data-rayec-nav rel="nofollow" href="' . esc_url( add_query_arg( 'rayec_page', $page + 1 ) ) . '">' . esc_html__( 'Later events', 'rayetun-events-calendar' ) . '</a>';
			}
			$html .= '</nav>';
		}

		return $html;
	}

	/**
	 * The views a visitor can switch between.
	 *
	 * @return array<string, string>
	 */
	public static function views(): array {
		return array(
			'month' => __( 'Month', 'rayetun-events-calendar' ),
			'week'  => __( 'Week', 'rayetun-events-calendar' ),
			'day'   => __( 'Day', 'rayetun-events-calendar' ),
			'list'  => __( 'List', 'rayetun-events-calendar' ),
		);
	}

	/**
	 * Plain-language name for an event format.
	 *
	 * @param string $format Format key.
	 * @return string
	 */
	private static function format_label( string $format ): string {
		$labels = array(
			'in_person' => __( 'In person', 'rayetun-events-calendar' ),
			'online'    => __( 'Online', 'rayetun-events-calendar' ),
			'hybrid'    => __( 'In person and online', 'rayetun-events-calendar' ),
		);
		return $labels[ $format ] ?? '';
	}

	/**
	 * A labelled select with an "any" option first.
	 *
	 * @param string                $name    Field name.
	 * @param string                $label   Accessible label.
	 * @param string                $any     Label of the empty option.
	 * @param array<string, string> $options value => label.
	 * @param string                $current Selected value.
	 * @return string
	 */
	private static function select( string $name, string $label, string $any, array $options, string $current ): string {
		// With the module loaded a choice applies itself; without it, "Find events" still does.
		$html  = '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( $label ) . '" data-wp-on--change="actions.apply">';
		$html .= '<option value="">' . esc_html( $any ) . '</option>';
		foreach ( $options as $value => $text ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( $text ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * The access filter: what is laid on at the event, and what the venue has.
	 *
	 * @param string $current Selected feature.
	 * @return string
	 */
	private static function access_select( string $current ): string {
		$groups = array(
			__( 'At the event', 'rayetun-events-calendar' ) => Event::access_features(),
			__( 'At the venue', 'rayetun-events-calendar' ) => Venue::access_features(),
		);
		$html   = '<select name="rayec_access" aria-label="' . esc_attr__( 'Access', 'rayetun-events-calendar' ) . '" data-wp-on--change="actions.apply">';
		$html  .= '<option value="">' . esc_html__( 'Any access', 'rayetun-events-calendar' ) . '</option>';
		foreach ( $groups as $label => $options ) {
			$html .= '<optgroup label="' . esc_attr( $label ) . '">';
			foreach ( $options as $value => $text ) {
				$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( $text ) . '</option>';
			}
			$html .= '</optgroup>';
		}
		return $html . '</select>';
	}

	/**
	 * Venue, organizer, format and access filters, folded away until wanted.
	 *
	 * @param array<string, mixed> $filters Active filters.
	 * @return string
	 */
	private static function more_filters( array $filters ): string {
		$fields = '';

		$venues = Venue::all();
		if ( count( $venues ) > 1 ) {
			$options = array();
			foreach ( $venues as $venue ) {
				$options[ (string) $venue['id'] ] = $venue['city'] ? $venue['name'] . ', ' . $venue['city'] : $venue['name'];
			}
			$fields .= self::select( 'rayec_venue', __( 'Venue', 'rayetun-events-calendar' ), __( 'Any venue', 'rayetun-events-calendar' ), $options, (string) $filters['venue'] );
		}

		$organizers = Organizer::all();
		if ( count( $organizers ) > 1 ) {
			$options = array();
			foreach ( $organizers as $organizer ) {
				$options[ (string) $organizer['id'] ] = $organizer['name'];
			}
			$fields .= self::select( 'rayec_org', __( 'Organizer', 'rayetun-events-calendar' ), __( 'Any organizer', 'rayetun-events-calendar' ), $options, (string) $filters['organizer'] );
		}

		$formats = array();
		foreach ( Event::FORMATS as $format ) {
			$formats[ $format ] = self::format_label( $format );
		}
		$fields .= self::select( 'rayec_format', __( 'How it runs', 'rayetun-events-calendar' ), __( 'In person or online', 'rayetun-events-calendar' ), $formats, (string) $filters['format'] );

		$fields .= self::access_select( (string) $filters['access'] );

		if ( '' === $fields ) {
			return '';
		}

		// Open already when one of these is in use, so nothing is hidden from the visitor.
		$open = $filters['venue'] || $filters['organizer'] || '' !== $filters['format'] || '' !== $filters['access'];

		$html  = '<details class="rayec-tools__more"' . ( $open ? ' open' : '' ) . '>';
		$html .= '<summary>' . esc_html__( 'More filters', 'rayetun-events-calendar' ) . '</summary>';
		$html .= '<div class="rayec-tools__panel">' . $fields . '</div></details>';

		return $html;
	}

	/**
	 * What is being filtered on now, each with a link that removes it.
	 *
	 * @param array<string, mixed> $filters         Active filters.
	 * @param bool                 $category_locked Whether the block fixes the category.
	 * @return string
	 */
	private static function active_filters( array $filters, bool $category_locked = false ): string {
		$chips = array();

		if ( '' !== $filters['search'] ) {
			/* translators: %s: search words. */
			$chips['rayec_s'] = sprintf( __( 'Matching “%s”', 'rayetun-events-calendar' ), $filters['search'] );
		}
		if ( $filters['category'] && ! $category_locked ) {
			$term = get_term( (int) $filters['category'], Post_Types::CATEGORY );
			if ( $term && ! is_wp_error( $term ) ) {
				$chips['rayec_cat'] = $term->name;
			}
		}
		if ( $filters['venue'] ) {
			$venue = Venue::get( (int) $filters['venue'] );
			if ( $venue ) {
				$chips['rayec_venue'] = $venue['name'];
			}
		}
		if ( $filters['organizer'] ) {
			$organizer = Organizer::get( (int) $filters['organizer'] );
			if ( $organizer ) {
				$chips['rayec_org'] = $organizer['name'];
			}
		}
		if ( '' !== $filters['format'] ) {
			$chips['rayec_format'] = self::format_label( $filters['format'] );
		}
		if ( '' !== $filters['access'] ) {
			$features              = Event::access_features() + Venue::access_features();
			$chips['rayec_access'] = $features[ $filters['access'] ] ?? '';
		}

		$chips = array_filter( $chips );
		if ( ! $chips ) {
			return '';
		}

		$html = '<div class="rayec-cal__active"><p class="rayec-cal__active-label" role="status">' . esc_html__( 'Showing only:', 'rayetun-events-calendar' ) . '</p><ul>';
		foreach ( $chips as $arg => $label ) {
			/* translators: %s: name of the filter being removed. */
			$remove = sprintf( __( 'Stop filtering by %s', 'rayetun-events-calendar' ), $label );
			$html  .= '<li><a data-rayec-nav href="' . esc_url( remove_query_arg( array( $arg, 'rayec_page' ) ) ) . '"><span>' . esc_html( $label ) . '</span><span class="rayec-sr">. ' . esc_html( $remove ) . '</span><span aria-hidden="true">×</span></a></li>';
		}
		if ( count( $chips ) > 1 ) {
			$html .= '<li><a class="rayec-cal__clear" data-rayec-nav href="' . esc_url( remove_query_arg( array_merge( array_keys( self::FILTER_ARGS ), array( 'rayec_page' ) ) ) ) . '">' . esc_html__( 'Clear all filters', 'rayetun-events-calendar' ) . '</a></li>';
		}

		return $html . '</ul></div>';
	}

	/**
	 * A grid's days, with only the events it will actually draw loaded.
	 *
	 * The rows are bucketed by day before a single event is fetched, each day
	 * is cut to what it can show, and only then are the events behind those
	 * rows loaded. On a site with ten thousand events that is the difference
	 * between loading a thousand of them to draw three hundred, and loading
	 * the three hundred. The count each day really has is returned alongside,
	 * because "+ N more" has to tell the truth about what is not shown.
	 *
	 * @param DateTimeImmutable    $from    First day of the grid.
	 * @param DateTimeImmutable    $to      Day after the last (exclusive).
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: array<string, array<int, array<string, mixed>>>, 1: array<string, int>}
	 */
	private static function grid_days( DateTimeImmutable $from, DateTimeImmutable $to, array $filters ): array {
		$by_day = self::rows_by_day( self::occurrence_rows( $from, $to, $filters ), $from, $to );
		$totals = array();
		$keep   = array();

		foreach ( $by_day as $key => $day_rows ) {
			$totals[ $key ] = count( $day_rows );
			$by_day[ $key ] = array_slice( $day_rows, 0, self::CHIP_CEILING );
			$keep           = array_merge( $keep, $by_day[ $key ] );
		}

		$loaded = array();
		foreach ( self::hydrate( $keep ) as $item ) {
			$loaded[ $item['event']['id'] . '|' . $item['start']->format( 'c' ) ] = $item;
		}

		foreach ( $by_day as $key => $day_rows ) {
			$items = array();
			foreach ( $day_rows as $row ) {
				list( $start ) = Occurrences::times( $row );
				$found         = $loaded[ $row->event_id . '|' . $start->format( 'c' ) ] ?? null;
				if ( $found ) {
					$items[] = $found;
				}
			}
			$by_day[ $key ] = $items;
		}

		return array( $by_day, $totals );
	}

	/**
	 * Index rows grouped by the days they cover, with no events loaded.
	 *
	 * The same walk as by_day(), done on the raw rows so the grid can decide
	 * what it is drawing before paying to load it.
	 *
	 * @param array<int, object> $rows Index rows.
	 * @param DateTimeImmutable  $from First day of the grid.
	 * @param DateTimeImmutable  $to   Day after the last (exclusive).
	 * @return array<string, array<int, object>>
	 */
	private static function rows_by_day( array $rows, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$by_day = array();
		foreach ( $rows as $row ) {
			list( $start, $end ) = Occurrences::times( $row );

			$day = $start < $from ? $from : $start;
			// An event ending exactly at midnight does not spill into the next day.
			$last = max( $end->modify( '-1 second' )->format( 'Y-m-d' ), $start->format( 'Y-m-d' ) );
			$key  = $day->format( 'Y-m-d' );
			for ( $i = 0; $i < 42 && $key <= $last && $day < $to; $i++ ) {
				$by_day[ $key ][] = $row;
				$day              = $day->modify( '+1 day' )->setTime( 0, 0 );
				$key              = $day->format( 'Y-m-d' );
			}
		}
		return $by_day;
	}

	/**
	 * One day's events as small chips.
	 *
	 * @param array<int, array<string, mixed>> $items    Occurrences on this day.
	 * @param string                           $key      "Y-m-d".
	 * @param string                           $full     The date written out, for labels.
	 * @param string                           $uid      Prefix for preview-card IDs.
	 * @param array<int, array>                $previews Collects the events needing a preview card.
	 * @param int                              $max      Chips to show before "+ N more"; 0 shows them all.
	 * @param int                              $total    Events on this day, including any not passed in.
	 * @return string
	 */
	private static function chips( array $items, string $key, string $full, string $uid, array &$previews, int $max, int $total = 0 ): string {
		$time_format = Plugin::time_format();

		// The grid shows a few and hides the rest behind CSS, and a phone
		// shows the hidden ones too. That is right for a day with six events
		// and absurd for a day with ninety: the page would carry every one of
		// them, and a preview card each, so that a phone could scroll past
		// them.
		//
		// So past the ceiling the day stops listing and starts pointing: only
		// the chips a reader sees anyway are written, and one link carries the
		// rest to the day view. That also keeps the count honest, because a
		// day that hides nothing in CSS can say one number that is true in
		// both layouts.
		$total   = max( $total, count( $items ) );
		$spilled = $total > self::CHIP_CEILING;
		$visible = $spilled ? ( $max > 0 ? $max : self::CHIP_CEILING ) : count( $items );
		$shown   = array_slice( $items, 0, $visible );

		$html = '<ul class="rayec-month__events">';
		foreach ( $shown as $index => $item ) {
			$event  = $item['event'];
			$status = self::status_label( $event['event_status'] );
			$color  = sanitize_hex_color( (string) ( $event['categories'][0]['color'] ?? '' ) );
			$time   = $item['all_day'] || $item['start']->format( 'Y-m-d' ) !== $key ? '' : wp_date( $time_format, $item['start']->getTimestamp(), $item['start']->getTimezone() );

			$previews[ $event['id'] ] = $event;

			$html .= '<li' . ( $max && $index >= $max ? ' class="is-extra"' : '' ) . '>';
			$html .= '<a class="rayec-chip' . ( $status ? ' is-' . esc_attr( $event['event_status'] ) : '' ) . '" href="' . esc_url( $item['url'] ) . '"' . ( $color ? ' style="--rayec-chip:' . esc_attr( $color ) . '"' : '' );
			$html .= ' data-rayec-pv="' . esc_attr( $uid . '-' . $event['id'] ) . '" data-rayec-when="' . esc_attr( Occurrences::format_range( $item['start'], $item['end'], $item['all_day'] ) ) . '">';
			if ( $time ) {
				$html .= '<span class="rayec-chip__time">' . esc_html( $time ) . '</span> ';
			}
			$html .= '<span class="rayec-chip__title">' . esc_html( $event['title'] ) . '</span>';
			$html .= self::repeat_mark( $event );
			if ( $status ) {
				$html .= ' <span class="rayec-chip__status">' . esc_html( $status ) . '</span>';
			}
			$places = $status ? null : self::places_label( $item, true );
			if ( $places ) {
				$html .= ' <span class="rayec-chip__places rayec-chip__places--' . esc_attr( $places[1] ) . '">' . esc_html( $places[0] ) . '</span>';
			}
			$html .= '</a></li>';
		}

		// What the link counts is whatever this day is not showing. Where
		// nothing spilled, the hidden-in-CSS chips are still on the page, so
		// the figure is counted from the few on show; where it spilled, every
		// chip written is on show, so it is counted from those.
		$held = $spilled ? count( $shown ) : min( $max, count( $shown ) );
		if ( ( $max && $total > $max ) || $spilled ) {
			$more = max( 1, $total - $held );
			$url  = add_query_arg(
				array(
					'rayec_view' => 'day',
					'rayec_from' => $key,
				),
				remove_query_arg( array( 'rayec_month', 'rayec_page' ) )
			);
			/* translators: 1: number of further events, 2: date. */
			$label = sprintf( _n( '%1$d more event on %2$s', '%1$d more events on %2$s', $more, 'rayetun-events-calendar' ), $more, $full );
			// When the ceiling bit, the link has to stay visible on a phone
			// too, or the events past it would be silently gone.
			/* translators: %d: number of further events. */
			$html .= '<li class="rayec-month__more' . ( $spilled ? ' is-always' : '' ) . '"><a data-rayec-nav href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html( sprintf( __( '+ %d more', 'rayetun-events-calendar' ), $more ) ) . '</a></li>';
		}

		return $html . '</ul>';
	}

	/**
	 * Hidden preview cards, shown by the script on hover and keyboard focus.
	 *
	 * @param array<int, array<string, mixed>> $previews Events.
	 * @param string                           $uid      Prefix for the IDs.
	 * @return string
	 */
	private static function preview_sources( array $previews, string $uid ): string {
		$html = '';
		foreach ( $previews as $event ) {
			$html .= '<div class="rayec-pv-src" id="' . esc_attr( $uid . '-' . $event['id'] ) . '" hidden>';
			if ( $event['image'] ) {
				$html .= '<img src="' . esc_url( $event['image']['url'] ) . '" alt="" loading="lazy" decoding="async">';
			}
			$html .= '<div class="rayec-pv__body"><p class="rayec-pv__when"></p><p class="rayec-pv__title">' . esc_html( $event['title'] ) . '</p>';
			$where = self::where_line( $event );
			if ( $where ) {
				$html .= '<p class="rayec-pv__where">' . self::icon( 'pin' ) . esc_html( $where ) . '</p>';
			}
			if ( '' !== $event['summary'] ) {
				$html .= '<p class="rayec-pv__text">' . esc_html( $event['summary'] ) . '</p>';
			}
			$html .= '</div></div>';
		}
		return $html;
	}

	/**
	 * One event in the list and day views.
	 *
	 * @param array<string, mixed> $item    Occurrence.
	 * @param string               $heading Heading tag for the title.
	 * @return string
	 */
	private static function list_item( array $item, string $heading = 'h4' ): string {
		$event   = $item['event'];
		$status  = self::status_label( $event['event_status'] );
		$heading = in_array( $heading, array( 'h3', 'h4' ), true ) ? $heading : 'h4';

		$html  = '<li class="rayec-item' . ( $status ? ' is-' . esc_attr( $event['event_status'] ) : '' ) . ( $event['image'] ? ' has-image' : '' ) . '">';
		$html .= '<div class="rayec-item__date" aria-hidden="true"><span class="rayec-item__dow">' . esc_html( wp_date( 'D', $item['start']->getTimestamp(), $item['start']->getTimezone() ) ) . '</span><span class="rayec-item__num">' . esc_html( $item['start']->format( 'j' ) ) . '</span></div>';

		$html .= '<div class="rayec-item__body">';
		$html .= '<p class="rayec-item__when"><time datetime="' . esc_attr( $item['start']->format( $item['all_day'] ? 'Y-m-d' : 'c' ) ) . '">' . esc_html( Occurrences::format_range( $item['start'], $item['end'], $item['all_day'] ) ) . '</time>' . self::repeat_mark( $event ) . '</p>';
		$html .= '<' . $heading . ' class="rayec-item__title"><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $event['title'] ) . '</a>';
		if ( $status ) {
			$html .= ' <span class="rayec-badge">' . esc_html( $status ) . '</span>';
		}
		$places = self::places_label( $item );
		if ( $places ) {
			$html .= ' <span class="rayec-badge rayec-badge--' . esc_attr( $places[1] ) . '">' . esc_html( $places[0] ) . '</span>';
		}
		$html .= '</' . $heading . '>';

		if ( ! empty( $event['venue'] ) && 'online' !== $event['format'] ) {
			$address = implode( ', ', array_filter( array( $event['venue']['address'], $event['venue']['city'] ) ) );
			$html   .= '<p class="rayec-item__where"><strong>' . esc_html( $event['venue']['name'] ) . '</strong>' . ( $address ? ' ' . esc_html( $address ) : '' ) . '</p>';
		}
		if ( '' !== $event['summary'] ) {
			$html .= '<p class="rayec-item__text">' . esc_html( $event['summary'] ) . '</p>';
		}
		$html .= self::tags( $event );
		$html .= '</div>';

		if ( $event['image'] ) {
			// The title link already names the destination; the image link is a mouse convenience only.
			$html .= '<a class="rayec-item__media" href="' . esc_url( $item['url'] ) . '" tabindex="-1" aria-hidden="true"><img src="' . esc_url( $event['image']['url'] ) . '" alt="" loading="lazy" decoding="async" width="' . esc_attr( (string) $event['image']['width'] ) . '" height="' . esc_attr( (string) $event['image']['height'] ) . '"></a>';
		}

		return $html . '</li>';
	}

	/**
	 * "3 places left" or "Full" for a date, when either is worth saying.
	 *
	 * @param array<string, mixed> $item  Hydrated occurrence.
	 * @param bool                 $short "3 left" rather than "3 places left", for a chip.
	 * @return array{0: string, 1: string}|null Text and modifier class.
	 */
	public static function places_label( array $item, bool $short = false ): ?array {
		if ( null === ( $item['left'] ?? null ) ) {
			return null;
		}
		$left = (int) $item['left'];
		if ( 0 === $left ) {
			return array( __( 'Full', 'rayetun-events-calendar' ), 'full' );
		}
		if ( ! Registrations::few_left( $item['event'], $left ) ) {
			return null;
		}
		return array(
			$short
				/* translators: %d: places left. */
				? sprintf( _n( '%d left', '%d left', $left, 'rayetun-events-calendar' ), $left )
				/* translators: %d: places left. */
				: sprintf( _n( '%d place left', '%d places left', $left, 'rayetun-events-calendar' ), $left ),
			'few',
		);
	}

	/**
	 * Week grid: seven day columns, each listing that day's events in full.
	 *
	 * @param DateTimeImmutable    $week_start First day of the week.
	 * @param DateTimeImmutable    $today      Today.
	 * @param array<string, mixed> $filters    Filters.
	 * @return string
	 */
	private static function week_view( DateTimeImmutable $week_start, DateTimeImmutable $today, array $filters ): string {
		$week_end                = $week_start->modify( '+7 days' );
		list( $by_day, $totals ) = self::grid_days( $week_start, $week_end, $filters );

		$uid         = wp_unique_id( 'rayec-pv-' );
		$previews    = array();
		$date_format = (string) get_option( 'date_format', 'F j, Y' );

		$head = '';
		$body = '';
		$day  = $week_start;
		for ( $i = 0; $i < 7; $i++ ) {
			$key      = $day->format( 'Y-m-d' );
			$items    = $by_day[ $key ] ?? array();
			$is_today = $key === $today->format( 'Y-m-d' );
			$full     = wp_date( 'l, ' . $date_format, $day->getTimestamp(), $day->getTimezone() );

			$classes = array( 'rayec-week__cell' );
			if ( $key < $today->format( 'Y-m-d' ) ) {
				$classes[] = 'is-past';
			}
			if ( $is_today ) {
				$classes[] = 'is-today';
			}
			if ( $items ) {
				$classes[] = 'has-events';
			}

			$head .= '<th scope="col" class="' . esc_attr( $is_today ? 'is-today' : '' ) . '"' . ( $is_today ? ' aria-current="date"' : '' ) . '>';
			$head .= '<a data-rayec-nav href="' . esc_url(
				add_query_arg(
					array(
						'rayec_view' => 'day',
						'rayec_from' => $key,
					),
					remove_query_arg( 'rayec_page' )
				)
			) . '">';
			$head .= '<span class="rayec-week__dow" aria-hidden="true">' . esc_html( wp_date( 'D', $day->getTimestamp(), $day->getTimezone() ) ) . '</span>';
			$head .= '<span class="rayec-week__num" aria-hidden="true">' . esc_html( $day->format( 'j' ) ) . '</span>';
			$head .= '<span class="rayec-sr">' . esc_html( $full ) . '</span></a></th>';

			$body .= '<td class="' . esc_attr( implode( ' ', $classes ) ) . '" data-rayec-date="' . esc_attr( $key ) . '"' . ( $is_today ? ' aria-current="date"' : '' ) . '>';
			$body .= '<span class="rayec-week__full">' . esc_html( $full ) . '</span>';
			if ( $items ) {
				$body .= self::chips( $items, $key, $full, $uid, $previews, 0, $totals[ $key ] ?? 0 );
			} else {
				$body .= '<p class="rayec-week__none">' . esc_html__( 'No events', 'rayetun-events-calendar' ) . '</p>';
			}
			$body .= '</td>';

			$day = $day->modify( '+1 day' );
		}

		$html  = '<table class="rayec-week" aria-labelledby="{{title_id}}" data-rayec-grid>';
		$html .= '<thead><tr>' . $head . '</tr></thead><tbody><tr>' . $body . '</tr></tbody></table>';

		if ( ! $by_day ) {
			$html .= '<p class="rayec-cal__empty">' . esc_html__( 'There are no events this week.', 'rayetun-events-calendar' ) . '</p>';
		}

		return $html . self::preview_sources( $previews, $uid );
	}

	/**
	 * One day in full.
	 *
	 * @param DateTimeImmutable    $day     The day.
	 * @param array<string, mixed> $filters Filters.
	 * @return string
	 */
	private static function day_view( DateTimeImmutable $day, array $filters ): string {
		// This is where "+ N more" sends people, so it is the one view that
		// has to cope with a day holding hundreds. It pages like the list.
		$per_page = (int) Plugin::setting( 'list_per_page' );
		$page     = max( 1, (int) self::param( 'rayec_page' ) );
		$all      = self::occurrence_rows( $day, $day->modify( '+1 day' ), $filters, $per_page * $page + 1 );
		$items    = self::hydrate( array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) );

		if ( ! $items ) {
			return '<p class="rayec-cal__empty">' . esc_html__( 'There are no events on this day.', 'rayetun-events-calendar' ) . '</p>';
		}

		$html = '<div class="rayec-list rayec-list--day" aria-labelledby="{{title_id}}" role="group"><ul class="rayec-list__items">';
		foreach ( $items as $item ) {
			$html .= self::list_item( $item, 'h3' );
		}
		$html .= '</ul></div>';

		if ( $page > 1 || count( $all ) > $page * $per_page ) {
			$html .= '<nav class="rayec-cal__pager" aria-label="' . esc_attr__( 'More events', 'rayetun-events-calendar' ) . '">';
			if ( $page > 1 ) {
				$html .= '<a class="rayec-btn" data-rayec-nav rel="nofollow" href="' . esc_url( add_query_arg( 'rayec_page', $page - 1 ) ) . '">' . esc_html__( 'Earlier that day', 'rayetun-events-calendar' ) . '</a>';
			}
			if ( count( $all ) > $page * $per_page ) {
				$html .= '<a class="rayec-btn" data-rayec-nav rel="nofollow" href="' . esc_url( add_query_arg( 'rayec_page', $page + 1 ) ) . '">' . esc_html__( 'Later that day', 'rayetun-events-calendar' ) . '</a>';
			}
			$html .= '</nav>';
		}

		return $html;
	}

	/**
	 * One-line location.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	public static function where_line( array $event ): string {
		if ( 'online' === $event['format'] || 'moved_online' === $event['event_status'] ) {
			return __( 'Online', 'rayetun-events-calendar' );
		}
		if ( empty( $event['venue'] ) ) {
			return '';
		}
		$line = implode( ', ', array_filter( array( $event['venue']['name'], $event['venue']['city'] ) ) );
		if ( 'hybrid' === $event['format'] ) {
			/* translators: %s: venue. */
			$line = sprintf( __( '%s and online', 'rayetun-events-calendar' ), $line );
		}
		return $line;
	}

	/**
	 * The occurrence a single-event page is about, plus the dates after it.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param int                  $more  How many following dates to return.
	 * @return array<int, array{0: DateTimeImmutable, 1: DateTimeImmutable}>
	 */
	public static function current_occurrences( array $event, int $more = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . Occurrences::TABLE;
		$date  = self::param( 'rayec_date' );
		$time  = self::param( 'rayec_time' );
		$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date . ' ' . ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time . ':00' : '00:00:00' ) : '';

		if ( '' === $from ) {
			$next = Occurrences::next_for_events( array( $event['id'] ) );
			$from = isset( $next[ $event['id'] ] ) ? $next[ $event['id'] ]->start_local : '';
		}
		if ( '' === $from ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d AND start_local >= %s ORDER BY start_local ASC LIMIT %d", $event['id'], $from, $more + 1 ) );
		return array_map( array( Occurrences::class, 'times' ), (array) $rows );
	}

	/**
	 * Icons a category can carry, drawn as single-path line icons.
	 *
	 * @return array<string, array{label: string, path: string}>
	 */
	public static function category_icons(): array {
		return array(
			'music'  => array(
				'label' => __( 'Music', 'rayetun-events-calendar' ),
				'path'  => 'M9 18V5l11-2v13M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM20 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
			),
			'food'   => array(
				'label' => __( 'Food and drink', 'rayetun-events-calendar' ),
				'path'  => 'M6 3v8a2 2 0 0 0 2 2v8M4 3v5M8 3v5M17 3c-2 2-3 5-3 8h3v10',
			),
			'learn'  => array(
				'label' => __( 'Learning', 'rayetun-events-calendar' ),
				'path'  => 'M3 9l9-5 9 5-9 5-9-5zM7 11.5V16c1.5 1.5 3 2 5 2s3.5-.5 5-2v-4.5M21 9v6',
			),
			'art'    => array(
				'label' => __( 'Art and craft', 'rayetun-events-calendar' ),
				'path'  => 'M12 3a9 9 0 1 0 0 18c1.5 0 2-1 2-2 0-1.5-1-1.5-1-3s1-2 2.5-2H18a3 3 0 0 0 3-3c0-4.5-4-8-9-8zM7.5 12h.01M9.5 8h.01M14.5 8h.01',
			),
			'people' => array(
				'label' => __( 'Community', 'rayetun-events-calendar' ),
				'path'  => 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM20 20v-1.5a3.5 3.5 0 0 0-2.5-3.3M15.5 4.2a3.5 3.5 0 0 1 0 6.6',
			),
			'health' => array(
				'label' => __( 'Health and wellbeing', 'rayetun-events-calendar' ),
				'path'  => 'M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 10c0 5.5-7 10-7 10z',
			),
			'nature' => array(
				'label' => __( 'Outdoors', 'rayetun-events-calendar' ),
				'path'  => 'M12 21v-7M12 14c-4 0-6-3-6-6 3 0 6 2 6 6zM12 14c4 0 6-3 6-6-3 0-6 2-6 6z',
			),
			'sport'  => array(
				'label' => __( 'Sport', 'rayetun-events-calendar' ),
				'path'  => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM5.6 5.6c3 3 3 9.8 0 12.8M18.4 5.6c-3 3-3 9.8 0 12.8M3 12h18',
			),
			'talk'   => array(
				'label' => __( 'Talks and meetings', 'rayetun-events-calendar' ),
				'path'  => 'M4 5h16v11H9l-5 4z',
			),
			'star'   => array(
				'label' => __( 'Special', 'rayetun-events-calendar' ),
				'path'  => 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z',
			),
		);
	}

	/**
	 * Other events in the same program.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function program_events( array $event ): string {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => array( $event['id'] ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- one ID, small result set.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- single-term lookup on the event page.
				'tax_query'      => array(
					array(
						'taxonomy' => Post_Types::PROGRAM,
						'terms'    => $event['program']['id'],
					),
				),
			)
		);
		$next = Occurrences::next_for_events( array_map( 'intval', $ids ) );
		if ( ! $next ) {
			return '';
		}
		uasort( $next, static fn( $a, $b ) => strcmp( $a->start_utc, $b->start_utc ) );

		/* translators: %s: program name. */
		$title = sprintf( __( 'More in %s', 'rayetun-events-calendar' ), $event['program']['name'] );
		$html  = '<section class="rayec-section rayec-dates rayec-program"><h2 class="rayec-section__title">' . esc_html( $title ) . '</h2><ul>';
		foreach ( array_slice( $next, 0, 6, true ) as $event_id => $row ) {
			list( $start, $end ) = Occurrences::times( $row );
			$html               .= '<li><a href="' . esc_url( get_permalink( $event_id ) ) . '"><span class="rayec-program__name">' . esc_html( get_the_title( $event_id ) ) . '<span class="rayec-program__when">' . esc_html( Occurrences::format_range( $start, $end, (bool) $row->all_day ) ) . '</span></span><span class="rayec-dates__go" aria-hidden="true">&rsaquo;</span></a></li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * The people speaking at an event.
	 *
	 * @param array<int, array<string, mixed>> $speakers Speakers.
	 * @return string
	 */
	private static function speakers( array $speakers ): string {
		$title = _n( 'Speaker', 'Speakers', count( $speakers ), 'rayetun-events-calendar' );
		$html  = '<section class="rayec-section rayec-speakers"><h2 class="rayec-section__title">' . esc_html( $title ) . '</h2><ul>';
		foreach ( $speakers as $speaker ) {
			$html .= '<li class="rayec-speaker">';
			if ( $speaker['photo'] ) {
				// The name is right beside the photo, so the photo itself is decorative.
				$html .= '<img class="rayec-speaker__photo" src="' . esc_url( $speaker['photo'] ) . '" alt="" width="72" height="72" loading="lazy" />';
			}
			$html .= '<div class="rayec-speaker__body"><h3 class="rayec-speaker__name">' . esc_html( $speaker['name'] ) . '</h3>';
			if ( '' !== $speaker['role'] ) {
				$html .= '<p class="rayec-speaker__role">' . esc_html( $speaker['role'] ) . '</p>';
			}
			if ( '' !== $speaker['bio'] ) {
				$html .= '<p class="rayec-speaker__bio">' . nl2br( esc_html( $speaker['bio'] ) ) . '</p>';
			}
			if ( '' !== $speaker['website'] ) {
				$html .= '<p class="rayec-speaker__link"><a href="' . esc_url( $speaker['website'] ) . '">' . esc_html__( 'Website', 'rayetun-events-calendar' ) . '<span class="rayec-sr"> ' . esc_html( $speaker['name'] ) . '</span></a></p>';
			}
			$html .= '</div></li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * Venue block with access details and a map.
	 *
	 * @param array<string, mixed> $venue Venue.
	 * @param string               $room  Room within the venue, if any.
	 * @return string
	 */
	private static function place( array $venue, string $room = '' ): string {
		$address = implode( ', ', array_filter( array( $venue['address'], $venue['city'] ) ) );
		$has_pin = '' !== $venue['lat'] && '' !== $venue['lng'];
		$query   = rawurlencode( trim( $venue['name'] . ', ' . $address, ', ' ) );
		$osm     = $has_pin
			? 'https://www.openstreetmap.org/?mlat=' . rawurlencode( $venue['lat'] ) . '&mlon=' . rawurlencode( $venue['lng'] ) . '#map=17/' . rawurlencode( $venue['lat'] ) . '/' . rawurlencode( $venue['lng'] )
			: 'https://www.openstreetmap.org/search?query=' . $query;

		$html  = '<section class="rayec-section rayec-place' . ( $has_pin ? ' has-map' : '' ) . '">';
		$html .= '<div class="rayec-place__info"><h2 class="rayec-section__title rayec-place__name">' . esc_html( $venue['name'] ) . '</h2>';
		if ( '' !== $room ) {
			/* translators: %s: name of a room inside the venue. */
			$html .= '<p class="rayec-place__room">' . esc_html( sprintf( __( 'Room: %s', 'rayetun-events-calendar' ), $room ) ) . '</p>';
		}
		if ( $address ) {
			$html .= '<p class="rayec-place__address">' . esc_html( $address ) . '</p>';
		}

		$features = array_intersect_key( Venue::access_features(), array_flip( $venue['access'] ) );
		if ( $features || '' !== $venue['access_notes'] ) {
			$html .= '<h3 class="rayec-place__sub">' . esc_html__( 'Access', 'rayetun-events-calendar' ) . '</h3>';
			if ( $features ) {
				$html .= '<ul class="rayec-access">';
				foreach ( $features as $label ) {
					$html .= '<li>' . self::icon( 'check' ) . esc_html( $label ) . '</li>';
				}
				$html .= '</ul>';
			}
			if ( '' !== $venue['access_notes'] ) {
				$html .= '<p class="rayec-place__notes">' . esc_html( $venue['access_notes'] ) . '</p>';
			}
		}
		// Written directions (the part of "where" that a map cannot say), then
		// the route by each way of travelling.
		$html .= '<h3 class="rayec-place__sub">' . esc_html__( 'Getting here', 'rayetun-events-calendar' ) . '</h3>';
		if ( '' !== ( $venue['directions'] ?? '' ) ) {
			$html .= wp_kses_post( wpautop( esc_html( $venue['directions'] ) ) );
		}
		$html .= Event_Page::routes( $venue );

		$html .= '<p class="rayec-place__links">';
		/* translators: %s: venue name. */
		$html .= '<a href="' . esc_url( $osm ) . '" rel="noopener">' . esc_html( sprintf( __( 'Open %s in OpenStreetMap', 'rayetun-events-calendar' ), $venue['name'] ) ) . '</a>';
		$html .= '</p>';
		$html .= '</div>';

		$html .= self::map( $venue, $has_pin );
		$html .= '</section>';

		return $html;
	}

	/**
	 * The map, in whichever of the three ways the owner chose.
	 *
	 * @param array<string, mixed> $venue   Venue.
	 * @param bool                 $has_pin Whether the venue has coordinates.
	 * @return string
	 */
	private static function map( array $venue, bool $has_pin ): string {
		$mode = (string) Plugin::setting( 'map_mode' );
		if ( ! $has_pin || 'text' === $mode ) {
			return '';
		}

		/* translators: %s: venue name. */
		$title = sprintf( __( 'Map showing %s', 'rayetun-events-calendar' ), $venue['name'] );

		if ( 'static' === $mode ) {
			$still = Map::url( (string) $venue['lat'], (string) $venue['lng'] );
			if ( '' !== $still ) {
				$html  = '<figure class="rayec-map is-still">';
				$html .= '<img src="' . esc_url( $still ) . '" width="' . (int) Map::WIDTH . '" height="' . (int) Map::HEIGHT . '" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async">';
				$html .= '<figcaption>' . wp_kses_post(
					sprintf(
						/* translators: %s: a link reading "OpenStreetMap contributors". */
						__( 'Map data from %s.', 'rayetun-events-calendar' ),
						'<a href="https://www.openstreetmap.org/copyright" rel="noopener">' . esc_html__( 'OpenStreetMap contributors', 'rayetun-events-calendar' ) . '</a>'
					)
				) . '</figcaption>';
				return $html . '</figure>';
			}
			// Nothing built yet: fall through to the ask-first map for now.
		}

		// The map is third-party content, so it loads only when the visitor asks for it.
		$html  = '<div class="rayec-map" data-rayec-map data-lat="' . esc_attr( $venue['lat'] ) . '" data-lng="' . esc_attr( $venue['lng'] ) . '" data-title="' . esc_attr( $title ) . '">';
		$html .= '<button type="button" class="rayec-btn" hidden>' . self::icon( 'pin' ) . esc_html__( 'Show map', 'rayetun-events-calendar' ) . '</button>';
		$html .= '<p class="rayec-map__note">' . esc_html__( 'The map is loaded from OpenStreetMap when you choose to show it.', 'rayetun-events-calendar' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Add event details around the description on single event pages.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function single_content( $content ) {
		if ( ! is_singular( Post_Types::EVENT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$event = Event::get( get_the_ID() );
		if ( ! $event ) {
			return $content;
		}

		wp_enqueue_style( 'rayec-frontend' );
		wp_enqueue_script( 'rayec-calendar' );

		$dates  = self::current_occurrences( $event, $event['rrule'] ? 5 : 0 );
		$times  = $dates[0] ?? null;
		$status = self::status_label( $event['event_status'] );

		$before = '<div class="rayec-single' . self::theme_class() . '">';

		if ( $status ) {
			$messages = array(
				'cancelled'    => __( 'This event has been cancelled.', 'rayetun-events-calendar' ),
				'postponed'    => __( 'This event has been postponed. A new date will be announced.', 'rayetun-events-calendar' ),
				'moved_online' => __( 'This event has moved online.', 'rayetun-events-calendar' ),
			);
			$before  .= '<p class="rayec-banner rayec-banner--' . esc_attr( $event['event_status'] ) . '" role="status"><strong>' . esc_html( $status ) . '.</strong> ' . esc_html( $messages[ $event['event_status'] ] ) . '</p>';
		}

		$before .= self::tags( $event );
		$before .= self::facts( $event, $times );
		$before .= '</div>';

		/**
		 * Filters the HTML shown before the event description.
		 *
		 * @param string               $before HTML.
		 * @param array<string, mixed> $event  Event data.
		 */
		$before = (string) apply_filters( 'rayec_single_before', $before, $event );

		$after  = '<div class="rayec-single rayec-single--after' . self::theme_class() . '">';
		$after .= Event_Page::good_to_know( $event, $times );
		$after .= Registrations::render( $event, $times );
		if ( ! empty( $event['venue'] ) && 'online' !== $event['format'] ) {
			$after .= self::place( $event['venue'], (string) ( $event['room'] ?? '' ) );
		}
		if ( ! empty( $event['speakers'] ) ) {
			$after .= self::speakers( $event['speakers'] );
		}
		if ( ! empty( $event['program'] ) ) {
			$after .= self::program_events( $event );
		}

		if ( count( $dates ) > 1 ) {
			$after .= '<section class="rayec-section rayec-dates"><h2 class="rayec-section__title">' . esc_html__( 'More dates', 'rayetun-events-calendar' ) . '</h2><ul>';
			foreach ( array_slice( $dates, 1 ) as $next ) {
				$after .= '<li><a href="' . esc_url( self::occurrence_url( $event, $next[0] ) ) . '"><span>' . esc_html( Occurrences::format_range( $next[0], $next[1], $event['all_day'] ) ) . '</span><span class="rayec-dates__go" aria-hidden="true">&rsaquo;</span></a></li>';
			}
			$after .= '</ul></section>';
		}
		$after .= Event_Page::more_like_this( $event );
		$after .= '</div>';

		/**
		 * Filters the HTML shown after the event description.
		 *
		 * @param string               $after HTML.
		 * @param array<string, mixed> $event Event data.
		 */
		$after = (string) apply_filters( 'rayec_single_after', $after, $event );

		// A date that can be booked gets the booking box beside the
		// description, kept in view while reading. The wrapper asks the theme
		// for its wide width; where there is not room for two columns (a
		// phone, or a theme without wide width) the box is not shown and the
		// page reads exactly as it does without it.
		$aside = Registrations::aside( $event, $times );
		if ( '' === $aside ) {
			return $before . $content . $after;
		}
		return '<div class="rayec-event-layout alignwide"><div class="rayec-event-layout__grid"><div class="rayec-event-layout__main">' . $before . $content . $after . '</div>' . $aside . '</div></div>';
	}

	/**
	 * The facts panel: when, where, who, and the add-to-calendar buttons.
	 *
	 * It repeats nothing from the description, and every row is a term and its
	 * value, so a screen reader can jump through them.
	 *
	 * @param array<string, mixed>                                   $event Event.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $times The occurrence being shown.
	 * @return string
	 */
	private static function facts( array $event, ?array $times ): string {
		$rows = array();

		if ( $times ) {
			// The day, then the time on its own line, rather than one long line that wraps anywhere.
			$parts = Occurrences::split_range( $times[0], $times[1], (bool) $event['all_day'] );
			$when  = '<p class="rayec-fact__main"><time datetime="' . esc_attr( $times[0]->format( $event['all_day'] ? 'Y-m-d' : 'c' ) ) . '">' . esc_html( $parts['date'] ) . '</time></p>';
			$when .= '<p class="rayec-fact__note rayec-fact__time">' . self::icon( 'clock' ) . esc_html( $parts['time'] ) . '</p>';
			if ( $event['rrule_text'] ) {
				$when .= '<p class="rayec-fact__note">' . self::icon( 'repeat' ) . esc_html( $event['rrule_text'] ) . '</p>';
			}
			if ( $event['extra_times'] ) {
				// Name only the other starts on this day; this one is above.
				$shown  = $times[0]->format( 'H:i' );
				$others = array();
				foreach ( array_merge( array( substr( $event['start'], 11, 5 ) ), $event['extra_times'] ) as $time ) {
					if ( $time === $shown ) {
						continue;
					}
					$session  = $times[0]->setTime( (int) substr( $time, 0, 2 ), (int) substr( $time, 3, 2 ) );
					$others[] = wp_date( Plugin::time_format(), $session->getTimestamp(), $session->getTimezone() );
				}
				if ( $others ) {
					/* translators: %s: list of other start times on the same day, such as "2:00 pm". */
					$when .= '<p class="rayec-fact__note">' . self::icon( 'clock' ) . esc_html( sprintf( __( 'Also starts at %s', 'rayetun-events-calendar' ), implode( ', ', $others ) ) ) . '</p>';
				}
			}
			$rows[] = array( __( 'When', 'rayetun-events-calendar' ), $when );
		}

		$access_row = null;
		$laid_on    = array_intersect_key( Event::access_features(), array_flip( (array) ( $event['access'] ?? array() ) ) );
		$notes      = trim( (string) ( $event['access_notes'] ?? '' ) );
		if ( $laid_on || '' !== $notes ) {
			$access     = $laid_on ? '<ul class="rayec-fact__list">' . implode( '', array_map( static fn( $label ) => '<li>' . self::icon( 'check' ) . esc_html( $label ) . '</li>', $laid_on ) ) . '</ul>' : '';
			$access    .= '' !== $notes ? '<p class="rayec-fact__note">' . esc_html( $notes ) . '</p>' : '';
			$access_row = array( __( 'Access', 'rayetun-events-calendar' ), $access );
		}

		$places = Registrations::fact( $event, $times );
		if ( $places ) {
			$rows[] = $places;
		}

		$online    = in_array( $event['format'], array( 'online', 'hybrid' ), true ) || 'moved_online' === $event['event_status'];
		$in_person = 'online' !== $event['format'] && 'moved_online' !== $event['event_status'] && ! empty( $event['venue'] );
		$where     = '';

		if ( $in_person ) {
			$where .= '<p class="rayec-fact__main">' . esc_html( $event['venue']['name'] ) . '</p>';
			if ( '' !== (string) ( $event['room'] ?? '' ) ) {
				/* translators: %s: name of a room inside the venue. */
				$where .= '<p class="rayec-fact__note">' . esc_html( sprintf( __( 'Room: %s', 'rayetun-events-calendar' ), $event['room'] ) ) . '</p>';
			}
			if ( '' !== (string) $event['venue']['city'] ) {
				$where .= '<p class="rayec-fact__note">' . esc_html( $event['venue']['city'] ) . '</p>';
			}
		}
		if ( $online ) {
			if ( ! $in_person ) {
				$where .= '<p class="rayec-fact__main">' . esc_html__( 'Online', 'rayetun-events-calendar' ) . '</p>';
			}
			$where .= '<p class="rayec-fact__note">' . self::icon( 'screen' );
			$where .= $event['online_url']
				? '<a href="' . esc_url( $event['online_url'] ) . '">' . esc_html__( 'Join this event online', 'rayetun-events-calendar' ) . '</a>'
				: esc_html__( 'Joining details will be shared before the event.', 'rayetun-events-calendar' );
			$where .= '</p>';
		}
		if ( '' !== $where ) {
			$rows[] = array( __( 'Where', 'rayetun-events-calendar' ), $where );
		}
		if ( $access_row ) {
			$rows[] = $access_row;
		}

		if ( ! empty( $event['organizer'] ) ) {
			$organizer = $event['organizer'];
			$who       = '<p class="rayec-fact__main">' . esc_html( $organizer['name'] ) . '</p>';
			$contacts  = array();
			if ( $organizer['website'] ) {
				$contacts[] = '<a href="' . esc_url( $organizer['website'] ) . '">' . esc_html__( 'Website', 'rayetun-events-calendar' ) . '<span class="rayec-sr"> ' . esc_html( $organizer['name'] ) . '</span></a>';
			}
			if ( $organizer['email'] ) {
				$contacts[] = '<a href="' . esc_url( 'mailto:' . $organizer['email'] ) . '">' . esc_html( $organizer['email'] ) . '</a>';
			}
			if ( $organizer['phone'] ) {
				$contacts[] = '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $organizer['phone'] ) ) . '">' . esc_html( $organizer['phone'] ) . '</a>';
			}
			if ( $contacts ) {
				$who .= '<p class="rayec-fact__note">' . implode( ' &middot; ', $contacts ) . '</p>';
			}
			$rows[] = array( __( 'Organised by', 'rayetun-events-calendar' ), $who );
		}

		if ( ! empty( $event['program'] ) ) {
			$name   = $event['program']['url']
				? '<a href="' . esc_url( $event['program']['url'] ) . '">' . esc_html( $event['program']['name'] ) . '</a>'
				: esc_html( $event['program']['name'] );
			$rows[] = array( __( 'Part of', 'rayetun-events-calendar' ), '<p class="rayec-fact__main">' . $name . '</p>' );
		}

		$buttons = self::calendar_buttons( $event, $times );
		if ( ! $rows && '' === $buttons ) {
			return '';
		}

		// When the booking card books in place, the places left here are
		// redrawn with it.
		$region = empty( $event['rsvp']['enabled'] ) ? '' : ' data-wp-interactive="rayec" data-wp-router-region="rayec-facts-' . (int) $event['id'] . '"';
		$html   = '<div class="rayec-facts"' . $region . '><dl class="rayec-facts__grid">';
		foreach ( $rows as $row ) {
			$html .= '<div class="rayec-fact"><dt>' . esc_html( $row[0] ) . '</dt><dd>' . $row[1] . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		}
		return $html . '</dl>' . $buttons . '</div>';
	}

	/**
	 * Add-to-calendar buttons for the occurrence being shown.
	 *
	 * @param array<string, mixed>                                   $event Event.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $times Occurrence.
	 * @return string
	 */
	private static function calendar_buttons( array $event, ?array $times ): string {
		if ( ! $times || 'cancelled' === $event['event_status'] ) {
			return '';
		}
		// WCAG 2.5.3, Label in Name: whatever a screen reader says has to
		// contain the words on the button, or someone speaking to their
		// computer ("click Add to Google Calendar") is not understood. So the
		// event's name is added after the visible words in a span only a
		// screen reader reads, rather than replacing them with an aria-label.
		/* translators: %s: event title. */
		$which = sprintf( __( 'for %s', 'rayetun-events-calendar' ), $event['title'] );
		$named = ' <span class="rayec-sr">' . esc_html( $which ) . '</span>';

		// When the date can be booked, Register is the one primary button here.
		// Every button in the row shares one shape, so they read as a set.
		$cta    = Registrations::cta( $event, $times );
		$google = '' === $cta ? Registrations::button_class() : Registrations::secondary_class();
		$html   = '<p class="rayec-actions">' . $cta;
		$html  .= '<a class="' . esc_attr( $google ) . '" href="' . esc_url( Ics::google_url( $event, $times[0], $times[1] ) ) . '">' . esc_html__( 'Add to Google Calendar', 'rayetun-events-calendar' ) . $named . '</a> ';
		$html  .= '<a class="' . esc_attr( Registrations::secondary_class() ) . '" href="' . esc_url( Ics::feed_url( $event['id'] ) ) . '">' . esc_html__( 'Apple, Outlook and others (.ics)', 'rayetun-events-calendar' ) . $named . '</a></p>';

		return $html;
	}

	/**
	 * Event structured data on single event pages.
	 *
	 * @return void
	 */
	public static function structured_data(): void {
		if ( ! is_singular( Post_Types::EVENT ) ) {
			return;
		}
		$event = Event::get( get_queried_object_id() );
		$dates = $event ? self::current_occurrences( $event ) : array();
		$times = $dates[0] ?? null;
		if ( ! $event || ! $times ) {
			return;
		}

		$status_map = array(
			'scheduled'    => 'EventScheduled',
			'cancelled'    => 'EventCancelled',
			'postponed'    => 'EventPostponed',
			'moved_online' => 'EventMovedOnline',
		);
		$mode_map   = array(
			'in_person' => 'OfflineEventAttendanceMode',
			'online'    => 'OnlineEventAttendanceMode',
			'hybrid'    => 'MixedEventAttendanceMode',
		);
		$format     = 'moved_online' === $event['event_status'] ? 'online' : $event['format'];

		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $event['title'],
			'url'                 => $event['permalink'],
			'startDate'           => $times[0]->format( $event['all_day'] ? 'Y-m-d' : 'c' ),
			'endDate'             => $times[1]->format( $event['all_day'] ? 'Y-m-d' : 'c' ),
			'eventStatus'         => 'https://schema.org/' . $status_map[ $event['event_status'] ],
			'eventAttendanceMode' => 'https://schema.org/' . $mode_map[ $format ],
		);

		if ( '' !== $event['summary'] ) {
			$data['description'] = $event['summary'];
		}
		if ( $event['image'] ) {
			$data['image'] = $event['image']['url'];
		}

		$locations = array();
		if ( 'online' !== $format && ! empty( $event['venue'] ) ) {
			$place = array(
				'@type'   => 'Place',
				'name'    => $event['venue']['name'],
				'address' => implode( ', ', array_filter( array( $event['venue']['address'], $event['venue']['city'] ) ) ),
			);
			if ( '' !== $event['venue']['lat'] && '' !== $event['venue']['lng'] ) {
				$place['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) $event['venue']['lat'],
					'longitude' => (float) $event['venue']['lng'],
				);
			}
			$locations[] = $place;
		}
		if ( 'in_person' !== $format && $event['online_url'] ) {
			$locations[] = array(
				'@type' => 'VirtualLocation',
				'url'   => $event['online_url'],
			);
		}
		if ( $locations ) {
			$data['location'] = 1 === count( $locations ) ? $locations[0] : $locations;
		}
		if ( $event['doors'] && ! $event['all_day'] ) {
			$data['doorTime'] = $times[0]->modify( '-' . (int) $event['doors'] . ' minutes' )->format( 'c' );
		}
		$min_age = array(
			'12' => '12-',
			'16' => '16-',
			'18' => '18-',
			'21' => '21-',
		);
		if ( isset( $min_age[ $event['age'] ] ) ) {
			$data['typicalAgeRange'] = $min_age[ $event['age'] ];
		}
		if ( ! empty( $event['organizer'] ) ) {
			$data['organizer'] = array_filter(
				array(
					'@type' => 'Organization',
					'name'  => $event['organizer']['name'],
					'url'   => $event['organizer']['website'],
				)
			);
		}

		if ( ! empty( $event['speakers'] ) ) {
			$data['performer'] = array_map(
				static fn( $speaker ) => array_filter(
					array(
						'@type'    => 'Person',
						'name'     => $speaker['name'],
						'jobTitle' => $speaker['role'],
						'url'      => $speaker['website'],
					)
				),
				$event['speakers']
			);
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
	}

	/**
	 * Order the event archive by start date instead of publish date.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function order_archive( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// The event archive shows the calendar, which is built from the
		// occurrence index. WordPress's own query for that page is never
		// read, and on a site with ten thousand events it is not cheap: a
		// join onto postmeta to sort by start date, and a row count nobody
		// asks for. So it is asked for as little as it can be.
		if ( $query->is_post_type_archive( Post_Types::EVENT ) && ! $query->is_feed() ) {
			// Left alone: how many posts a page holds, so that /events/page/2/
			// answers the way it always has rather than turning into a 404.
			$query->set( 'no_found_rows', true );
			$query->set( 'update_post_meta_cache', false );
			$query->set( 'update_post_term_cache', false );
			return;
		}

		if ( ! $query->is_tax( array( Post_Types::PROGRAM, Post_Types::CATEGORY, Post_Types::TAG ) ) ) {
			return;
		}
		$query->set( 'meta_key', '_rayec_start' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- archive is ordered by event start.
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' );
	}
}
