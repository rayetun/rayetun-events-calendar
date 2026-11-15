<?php
/**
 * Small display blocks: mini calendar, upcoming events and countdown.
 *
 * Each one renders on the server and works with JavaScript off. The countdown
 * is written out in full by PHP; its script only keeps the figure fresh.
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
 * The three sidebar-sized blocks.
 */
class Blocks {

	/**
	 * How many events a mini-calendar hover card names before it counts the rest.
	 *
	 * @var int
	 */
	const PEEK = 4;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// After Frontend::register, which registers the shared style and editor script.
		add_action( 'init', array( __CLASS__, 'register' ), 11 );
	}

	/**
	 * Register the blocks and their shortcode equivalents.
	 *
	 * @return void
	 */
	public static function register(): void {
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		wp_register_script(
			'rayec-countdown',
			RAYEC_URL . 'assets/js/countdown.js',
			array( 'wp-i18n' ),
			$debug ? (string) filemtime( RAYEC_DIR . 'assets/js/countdown.js' ) : RAYEC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( 'rayec-countdown', 'rayetun-events-calendar' );

		$editor   = wp_script_is( 'rayec-calendar-block', 'registered' ) ? 'rayec-calendar-block' : null;
		$category = array(
			'type'    => 'integer',
			'default' => 0,
		);

		$blocks = array(
			'rayec/mini-calendar' => array(
				'title'       => __( 'Mini calendar', 'rayetun-events-calendar' ),
				'description' => __( 'A small month grid marking the days that have events.', 'rayetun-events-calendar' ),
				'icon'        => 'calendar',
				'attributes'  => array( 'category' => $category ),
				'render'      => 'render_mini',
				'shortcode'   => 'rayec_mini_calendar',
			),
			'rayec/upcoming'      => array(
				'title'       => __( 'Upcoming events', 'rayetun-events-calendar' ),
				'description' => __( 'The next few events, as a short list.', 'rayetun-events-calendar' ),
				'icon'        => 'list-view',
				'attributes'  => array(
					'category' => $category,
					'count'    => array(
						'type'    => 'integer',
						'default' => 5,
					),
					'images'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'render'      => 'render_upcoming',
				'shortcode'   => 'rayec_upcoming',
			),
			'rayec/countdown'     => array(
				'title'       => __( 'Event countdown', 'rayetun-events-calendar' ),
				'description' => __( 'How long until an event starts.', 'rayetun-events-calendar' ),
				'icon'        => 'clock',
				'attributes'  => array(
					'category' => $category,
					'eventId'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'render'      => 'render_countdown',
				'shortcode'   => 'rayec_countdown',
			),
		);

		foreach ( $blocks as $name => $block ) {
			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'title'           => $block['title'],
					'description'     => $block['description'],
					'category'        => 'widgets',
					'icon'            => $block['icon'],
					'keywords'        => array( 'events', 'calendar' ),
					'attributes'      => $block['attributes'],
					'supports'        => array(
						'html'  => false,
						'align' => false,
					),
					'editor_script'   => $editor,
					'editor_style'    => 'rayec-frontend',
					'render_callback' => array( __CLASS__, $block['render'] ),
				)
			);
			add_shortcode( $block['shortcode'], array( __CLASS__, $block['render'] ) );
		}
	}

	/**
	 * Block wrapper attributes, or a plain class when used as a shortcode.
	 *
	 * @param string $classes Class name.
	 * @return string
	 */
	private static function wrapper( string $classes ): string {
		$classes .= Frontend::theme_class();
		return \WP_Block_Supports::$block_to_render
			? get_block_wrapper_attributes( array( 'class' => $classes ) )
			: 'class="' . esc_attr( $classes ) . '"';
	}

	/**
	 * The page that shows the full calendar, or the event archive.
	 *
	 * @return string
	 */
	private static function calendar_url(): string {
		$page = Rest::calendar_page_id();
		$url  = $page ? get_permalink( $page ) : get_post_type_archive_link( Post_Types::EVENT );
		return is_string( $url ) ? $url : home_url( '/' );
	}

	/**
	 * How many events fall on each day of a range.
	 *
	 * @param DateTimeImmutable $from     Range start (local midnight).
	 * @param DateTimeImmutable $to       Range end (local midnight, exclusive).
	 * @param int               $category Category term ID, or 0.
	 * @return array<string, array{count: int, items: array<int, array{title: string, time: string}>}> Keyed by "Y-m-d".
	 */
	private static function days_with_events( DateTimeImmutable $from, DateTimeImmutable $to, int $category ): array {
		$utc  = new DateTimeZone( 'UTC' );
		$rows = Occurrences::query(
			array(
				'start'    => $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'end'      => $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'category' => $category,
				'limit'    => 500,
			)
		);

		Event::prime( wp_list_pluck( $rows, 'event_id' ) );

		$time_format = Plugin::time_format();
		$days        = array();
		foreach ( $rows as $row ) {
			list( $start, $end ) = Occurrences::times( $row );
			$day                 = $start < $from ? $from : $start;
			$event               = null;
			// An event ending exactly at midnight does not spill into the next day.
			$last = max( $end->modify( '-1 second' )->format( 'Y-m-d' ), $start->format( 'Y-m-d' ) );
			$key  = $day->format( 'Y-m-d' );
			for ( $i = 0; $i < 42 && $key <= $last && $day < $to; $i++ ) {
				if ( ! isset( $days[ $key ] ) ) {
					$days[ $key ] = array(
						'count' => 0,
						'items' => array(),
					);
				}
				++$days[ $key ]['count'];

				// The hover card names the first few; the rest are only counted.
				if ( count( $days[ $key ]['items'] ) < self::PEEK ) {
					$event = $event ?? Event::get( (int) $row->event_id );
					if ( $event ) {
						$days[ $key ]['items'][] = array(
							'title' => (string) $event['title'],
							'time'  => $row->all_day || $start->format( 'Y-m-d' ) !== $key ? '' : wp_date( $time_format, $start->getTimestamp(), $start->getTimezone() ),
						);
					}
				}
				$day = $day->modify( '+1 day' )->setTime( 0, 0 );
				$key = $day->format( 'Y-m-d' );
			}
		}
		return $days;
	}

	/**
	 * Mini calendar: a month at a glance, with the busy days linked.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 * @return string
	 */
	public static function render_mini( $atts ): string {
		global $wp_locale;

		$atts = shortcode_atts( array( 'category' => 0 ), is_array( $atts ) ? $atts : array(), 'rayec_mini_calendar' );
		wp_enqueue_style( 'rayec-frontend' );
		// For the hover and focus cards. The grid reads the same without it.
		wp_enqueue_script( 'rayec-calendar' );

		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$month = $today->modify( 'first day of this month' );
		// Its own month parameter, so it never fights a full calendar on the same page.
		if ( preg_match( '/^(\d{4})-(\d{2})$/', Frontend::param( 'rayec_mini' ), $m ) && checkdate( (int) $m[2], 1, (int) $m[1] ) ) {
			$month = $month->setDate( (int) $m[1], (int) $m[2], 1 );
		}

		$week_start = (int) Plugin::setting( 'week_starts_on' );
		$lead       = ( (int) $month->format( 'w' ) - $week_start + 7 ) % 7;
		$grid_start = $month->modify( "-{$lead} days" );
		$weeks      = (int) ceil( ( $lead + (int) $month->format( 't' ) ) / 7 );
		$grid_end   = $grid_start->modify( '+' . ( $weeks * 7 ) . ' days' );

		$busy     = self::days_with_events( $grid_start, $grid_end, (int) $atts['category'] );
		$calendar = self::calendar_url();
		$label    = wp_date( 'F Y', $month->getTimestamp(), $tz );

		$html  = '<div ' . self::wrapper( 'rayec-cal rayec-mini' ) . '>';
		$html .= '<div class="rayec-mini__bar">';
		foreach ( array(
			'-1 month' => __( 'Previous month', 'rayetun-events-calendar' ),
			'+1 month' => __( 'Next month', 'rayetun-events-calendar' ),
		) as $step => $text ) {
			$target = $month->modify( $step );
			/* translators: 1: "Previous month" or "Next month", 2: month and year. */
			$full  = sprintf( __( '%1$s, %2$s', 'rayetun-events-calendar' ), $text, wp_date( 'F Y', $target->getTimestamp(), $tz ) );
			$html .= '<a class="rayec-mini__nav" rel="nofollow" href="' . esc_url( add_query_arg( 'rayec_mini', $target->format( 'Y-m' ) ) ) . '" aria-label="' . esc_attr( $full ) . '"><span aria-hidden="true">' . ( '-1 month' === $step ? '&lsaquo;' : '&rsaquo;' ) . '</span></a>';
		}
		$html .= '<p class="rayec-mini__month">' . esc_html( $label ) . '</p></div>';

		$html .= '<table class="rayec-mini__grid"><caption class="rayec-sr">' . esc_html( $label ) . '</caption><thead><tr>';
		for ( $i = 0; $i < 7; $i++ ) {
			$name  = $wp_locale->get_weekday( ( $week_start + $i ) % 7 );
			$html .= '<th scope="col"><abbr title="' . esc_attr( $name ) . '">' . esc_html( mb_substr( $wp_locale->get_weekday_abbrev( $name ), 0, 2 ) ) . '</abbr></th>';
		}
		$html .= '</tr></thead><tbody>';

		$date_format = (string) get_option( 'date_format', 'F j, Y' );
		$uid         = wp_unique_id( 'rayec-mini-' );
		$cards       = '';
		$day         = $grid_start;
		for ( $week = 0; $week < $weeks; $week++ ) {
			$html .= '<tr>';
			for ( $i = 0; $i < 7; $i++ ) {
				$key     = $day->format( 'Y-m-d' );
				$count   = (int) ( $busy[ $key ]['count'] ?? 0 );
				$classes = array();
				if ( $day->format( 'Y-m' ) !== $month->format( 'Y-m' ) ) {
					$classes[] = 'is-outside';
				}
				if ( $key === $today->format( 'Y-m-d' ) ) {
					$classes[] = 'is-today';
				}
				if ( $count ) {
					$classes[] = 'has-events';
				}

				$html .= '<td class="' . esc_attr( implode( ' ', $classes ) ) . '"' . ( $key === $today->format( 'Y-m-d' ) ? ' aria-current="date"' : '' ) . '>';
				if ( $count ) {
					$url   = add_query_arg(
						array(
							'rayec_view' => 'day',
							'rayec_from' => $key,
						),
						$calendar
					);
					$title = sprintf(
						/* translators: 1: date, 2: number of events. */
						__( '%1$s, %2$s', 'rayetun-events-calendar' ),
						wp_date( $date_format, $day->getTimestamp(), $tz ),
						/* translators: %d: number of events. */
						sprintf( _n( '%d event', '%d events', $count, 'rayetun-events-calendar' ), $count )
					);
					// The card repeats the hidden text below, so it is only a convenience for pointer and keyboard users.
					$cards .= self::peek_card( $uid . '-' . $key, (array) ( $busy[ $key ]['items'] ?? array() ), $count );

					$html .= '<a href="' . esc_url( $url ) . '" data-rayec-pv="' . esc_attr( $uid . '-' . $key ) . '" data-rayec-when="' . esc_attr( $title ) . '"><span aria-hidden="true">' . esc_html( $day->format( 'j' ) ) . '</span><span class="rayec-sr">' . esc_html( $title ) . '</span></a>';
				} else {
					$html .= '<span aria-hidden="true">' . esc_html( $day->format( 'j' ) ) . '</span>';
				}
				$html .= '</td>';
				$day   = $day->modify( '+1 day' );
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';
		$html .= '<p class="rayec-mini__all"><a href="' . esc_url( $calendar ) . '">' . esc_html__( 'See the full calendar', 'rayetun-events-calendar' ) . '</a></p>';

		return $html . $cards . '</div>';
	}

	/**
	 * The hidden card the script shows when a busy day is hovered or focused.
	 *
	 * @param string                                         $id    Element ID.
	 * @param array<int, array{title: string, time: string}> $items The first few events of the day.
	 * @param int                                            $count How many there are in all.
	 * @return string
	 */
	private static function peek_card( string $id, array $items, int $count ): string {
		if ( ! $items ) {
			return '';
		}

		$html = '<div class="rayec-pv-src" id="' . esc_attr( $id ) . '" hidden><div class="rayec-pv__body">';
		// The script writes the date into this line from the day's own label.
		$html .= '<p class="rayec-pv__when"></p><ul class="rayec-pv__list">';
		foreach ( $items as $item ) {
			$html .= '<li>';
			if ( '' !== $item['time'] ) {
				$html .= '<span class="rayec-pv__time">' . esc_html( $item['time'] ) . '</span> ';
			}
			$html .= '<span class="rayec-pv__name">' . esc_html( $item['title'] ) . '</span></li>';
		}
		$html .= '</ul>';

		$more = $count - count( $items );
		if ( $more > 0 ) {
			/* translators: %d: number of further events. */
			$html .= '<p class="rayec-pv__text">' . esc_html( sprintf( _n( 'and %d more event', 'and %d more events', $more, 'rayetun-events-calendar' ), $more ) ) . '</p>';
		}

		return $html . '</div></div>';
	}

	/**
	 * Upcoming events: the next few, as a short list.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 * @return string
	 */
	public static function render_upcoming( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => 0,
				'count'    => 5,
				'images'   => false,
			),
			is_array( $atts ) ? $atts : array(),
			'rayec_upcoming'
		);
		wp_enqueue_style( 'rayec-frontend' );

		$count  = max( 1, min( 20, (int) $atts['count'] ) );
		$images = filter_var( $atts['images'], FILTER_VALIDATE_BOOLEAN );
		$tz     = wp_timezone();
		$rows   = Occurrences::query(
			array(
				'start'    => ( new DateTimeImmutable( 'now', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'category' => (int) $atts['category'],
				'limit'    => $count,
			)
		);

		$html = '<div ' . self::wrapper( 'rayec-cal rayec-up' . ( $images ? ' has-images' : '' ) ) . '>';
		if ( ! $rows ) {
			return $html . '<p class="rayec-cal__empty">' . esc_html__( 'There are no upcoming events.', 'rayetun-events-calendar' ) . '</p></div>';
		}

		Event::prime( wp_list_pluck( $rows, 'event_id' ) );

		$html .= '<ul class="rayec-up__list">';
		foreach ( $rows as $row ) {
			$event = Event::get( (int) $row->event_id );
			if ( ! $event ) {
				continue;
			}
			list( $start, $end ) = Occurrences::times( $row );
			$status              = Frontend::status_label( $event['event_status'] );
			$color               = sanitize_hex_color( (string) ( $event['categories'][0]['color'] ?? '' ) );

			$html .= '<li class="rayec-up__item"' . ( $color ? ' style="--rayec-chip:' . esc_attr( $color ) . '"' : '' ) . '>';
			$html .= '<a href="' . esc_url( Frontend::occurrence_url( $event, $start ) ) . '">';
			$html .= '<span class="rayec-up__date" aria-hidden="true"><span class="rayec-up__mon">' . esc_html( wp_date( 'M', $start->getTimestamp(), $tz ) ) . '</span><span class="rayec-up__num">' . esc_html( $start->format( 'j' ) ) . '</span></span>';
			$html .= '<span class="rayec-up__body"><span class="rayec-up__title">' . esc_html( $event['title'] );
			if ( $status ) {
				$html .= ' <span class="rayec-badge">' . esc_html( $status ) . '</span>';
			}
			$html .= '</span><span class="rayec-up__when">' . esc_html( Occurrences::format_range( $start, $end, (bool) $row->all_day ) ) . '</span>';
			$where = Frontend::where_line( $event );
			if ( '' !== $where ) {
				$html .= '<span class="rayec-up__where">' . esc_html( $where ) . '</span>';
			}
			$html .= '</span>';
			if ( $images && $event['image'] ) {
				$html .= '<span class="rayec-up__media"><img src="' . esc_url( $event['image']['url'] ) . '" alt="" loading="lazy" decoding="async"></span>';
			}
			$html .= '</a></li>';
		}
		$html .= '</ul>';
		$html .= '<p class="rayec-mini__all"><a href="' . esc_url( self::calendar_url() ) . '">' . esc_html__( 'See the full calendar', 'rayetun-events-calendar' ) . '</a></p>';

		return $html . '</div>';
	}

	/**
	 * How long is left before something starts, in words.
	 *
	 * @param int $seconds Seconds remaining.
	 * @return string
	 */
	private static function until_text( int $seconds ): string {
		$days    = intdiv( $seconds, DAY_IN_SECONDS );
		$hours   = intdiv( $seconds % DAY_IN_SECONDS, HOUR_IN_SECONDS );
		$minutes = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );

		/* translators: %d: number of days. */
		$day_text = sprintf( _n( '%d day', '%d days', $days, 'rayetun-events-calendar' ), $days );
		/* translators: %d: number of hours. */
		$hour_text = sprintf( _n( '%d hour', '%d hours', $hours, 'rayetun-events-calendar' ), $hours );
		/* translators: %d: number of minutes. */
		$minute_text = sprintf( _n( '%d minute', '%d minutes', $minutes, 'rayetun-events-calendar' ), $minutes );

		/* translators: 1: a longer unit such as "2 days", 2: a shorter one such as "3 hours". */
		$both = __( '%1$s and %2$s', 'rayetun-events-calendar' );

		if ( $days > 0 ) {
			return $hours > 0 ? sprintf( $both, $day_text, $hour_text ) : $day_text;
		}
		if ( $hours > 0 ) {
			return $minutes > 0 ? sprintf( $both, $hour_text, $minute_text ) : $hour_text;
		}
		if ( $minutes > 0 ) {
			return $minute_text;
		}
		return __( 'less than a minute', 'rayetun-events-calendar' );
	}

	/**
	 * Countdown to one event, or to whichever event is next.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 * @return string
	 */
	public static function render_countdown( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => 0,
				'eventId'  => 0,
			),
			is_array( $atts ) ? $atts : array(),
			'rayec_countdown'
		);
		wp_enqueue_style( 'rayec-frontend' );

		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$id  = (int) $atts['eventId'];
		$row = null;

		if ( $id && Post_Types::EVENT === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			$next = Occurrences::next_for_events( array( $id ) );
			$row  = $next[ $id ] ?? null;
		} elseif ( ! $id ) {
			$rows = Occurrences::query(
				array(
					'start'    => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'category' => (int) $atts['category'],
					'limit'    => 1,
				)
			);
			$row  = $rows[0] ?? null;
		}

		$event = $row ? Event::get( (int) $row->event_id ) : null;
		$html  = '<div ' . self::wrapper( 'rayec-cal rayec-countdown' ) . '>';
		if ( ! $event ) {
			return $html . '<p class="rayec-cal__empty">' . esc_html__( 'There is no event to count down to yet.', 'rayetun-events-calendar' ) . '</p></div>';
		}

		list( $start, $end ) = Occurrences::times( $row );
		$seconds             = $start->getTimestamp() - $now->getTimestamp();

		if ( $seconds > 0 ) {
			/* translators: %s: a length of time, such as "2 days and 3 hours". */
			$text = sprintf( __( 'Starts in %s', 'rayetun-events-calendar' ), self::until_text( $seconds ) );
		} elseif ( $end->getTimestamp() > $now->getTimestamp() ) {
			$text = __( 'Happening now', 'rayetun-events-calendar' );
		} else {
			$text = __( 'This event has finished.', 'rayetun-events-calendar' );
		}

		wp_enqueue_script( 'rayec-countdown' );

		$html .= '<p class="rayec-countdown__label">' . esc_html( $atts['eventId'] ? __( 'Coming up', 'rayetun-events-calendar' ) : __( 'Next event', 'rayetun-events-calendar' ) ) . '</p>';
		$html .= '<p class="rayec-countdown__title"><a href="' . esc_url( Frontend::occurrence_url( $event, $start ) ) . '">' . esc_html( $event['title'] ) . '</a></p>';
		$html .= '<p class="rayec-countdown__when"><time datetime="' . esc_attr( $start->format( $event['all_day'] ? 'Y-m-d' : 'c' ) ) . '">' . esc_html( Occurrences::format_range( $start, $end, (bool) $row->all_day ) ) . '</time></p>';
		// Not a live region: a figure that changes every minute would interrupt a screen reader.
		$html .= '<p class="rayec-countdown__left" data-rayec-until="' . esc_attr( $start->format( 'c' ) ) . '" data-rayec-end="' . esc_attr( $end->format( 'c' ) ) . '">' . esc_html( $text ) . '</p>';

		return $html . '</div>';
	}
}
