<?php
/**
 * The second half of the event page: Good to know, the route there, and
 * where to go next.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use DateTimeImmutable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sections a visitor reads after the description.
 */
class Event_Page {

	/**
	 * How long an occurrence lasts, in words.
	 *
	 * @param DateTimeImmutable $start   Start.
	 * @param DateTimeImmutable $end     End.
	 * @param bool              $all_day Whether it is an all-day event.
	 * @return string
	 */
	public static function duration( DateTimeImmutable $start, DateTimeImmutable $end, bool $all_day ): string {
		if ( $all_day ) {
			// The stored end of an all-day event is the start of the day after.
			$last = $end > $start ? $end->modify( '-1 second' ) : $start;
			$days = (int) $start->setTime( 0, 0 )->diff( $last->setTime( 0, 0 ) )->days + 1;
			/* translators: %d: number of days. */
			return 1 === $days ? __( 'All day', 'rayetun-events-calendar' ) : sprintf( _n( '%d day', '%d days', $days, 'rayetun-events-calendar' ), $days );
		}

		$minutes = (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / 60 );
		if ( $minutes <= 0 ) {
			return '';
		}
		if ( $minutes >= DAY_IN_SECONDS / 60 ) {
			// Over a day: count the calendar days it touches, as people do.
			$days = (int) $start->setTime( 0, 0 )->diff( $end->modify( '-1 second' )->setTime( 0, 0 ) )->days + 1;
			/* translators: %d: number of days. */
			return sprintf( _n( '%d day', '%d days', $days, 'rayetun-events-calendar' ), $days );
		}

		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		/* translators: %d: number of hours. */
		$hours_text = sprintf( _n( '%d hour', '%d hours', $hours, 'rayetun-events-calendar' ), $hours );
		/* translators: %d: number of minutes. */
		$rest_text = sprintf( _n( '%d minute', '%d minutes', $rest, 'rayetun-events-calendar' ), $rest );
		if ( ! $hours ) {
			return $rest_text;
		}
		if ( ! $rest ) {
			return $hours_text;
		}
		/* translators: 1: hours, such as "1 hour", 2: minutes, such as "30 minutes". */
		return sprintf( __( '%1$s %2$s', 'rayetun-events-calendar' ), $hours_text, $rest_text );
	}

	/**
	 * "Good to know": the facts a visitor checks before booking, as tiles,
	 * then the cancellation policy.
	 *
	 * @param array<string, mixed>                                   $event Event.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $times The occurrence being shown.
	 * @return string
	 */
	public static function good_to_know( array $event, ?array $times ): string {
		$items = array();

		if ( $times ) {
			$length = self::duration( $times[0], $times[1], (bool) $event['all_day'] );
			if ( '' !== $length ) {
				$items['duration'] = array(
					'icon'  => 'timer',
					'label' => __( 'Duration', 'rayetun-events-calendar' ),
					'value' => $length,
				);
			}
			if ( $event['doors'] && ! $event['all_day'] ) {
				$doors          = $times[0]->modify( '-' . (int) $event['doors'] . ' minutes' );
				$items['doors'] = array(
					'icon'  => 'door',
					'label' => __( 'Doors open', 'rayetun-events-calendar' ),
					'value' => wp_date( Plugin::time_format(), $doors->getTimestamp(), $doors->getTimezone() ),
				);
			}
		}

		$format          = 'moved_online' === $event['event_status'] ? 'online' : $event['format'];
		$formats         = array(
			'in_person' => __( 'In person', 'rayetun-events-calendar' ),
			'online'    => __( 'Online', 'rayetun-events-calendar' ),
			'hybrid'    => __( 'In person and online', 'rayetun-events-calendar' ),
		);
		$items['format'] = array(
			'icon'  => 'in_person' === $format ? 'pin' : 'screen',
			'label' => __( 'Format', 'rayetun-events-calendar' ),
			'value' => $formats[ $format ] ?? $formats['in_person'],
		);

		$ages = Event::ages();
		if ( isset( $ages[ $event['age'] ] ) ) {
			$items['age'] = array(
				'icon'  => 'users',
				'label' => __( 'Age', 'rayetun-events-calendar' ),
				'value' => $ages[ $event['age'] ],
			);
		}
		if ( '' !== (string) $event['cost'] ) {
			$items['price'] = array(
				'icon'  => 'ticket',
				'label' => __( 'Price', 'rayetun-events-calendar' ),
				'value' => (string) $event['cost'],
			);
		}
		$booking = ! empty( $event['rsvp']['enabled'] ) && 'cancelled' !== $event['event_status'];
		if ( $booking ) {
			$items['booking'] = array(
				'icon'  => 'calendar',
				'label' => __( 'Booking', 'rayetun-events-calendar' ),
				'value' => __( 'Book ahead', 'rayetun-events-calendar' ),
			);
		}

		/**
		 * Filters the tiles under "Good to know" on the event page.
		 *
		 * Each is an array with `icon` (a name from Frontend::icon()), `label`
		 * and `value`, all plain text.
		 *
		 * @param array<string, array<string, string>> $items Tiles, keyed by name.
		 * @param array<string, mixed>                 $event Event.
		 */
		$items = (array) apply_filters( 'rayec_good_to_know', $items, $event );

		$policy = trim( (string) $event['policy'] );
		if ( '' === $policy && $booking ) {
			$policy = __( 'Booked a place and cannot come? Cancel it any time with the link in your confirmation email, so someone else can have it.', 'rayetun-events-calendar' );
		}

		// Duration and format alone say little a visitor has not already read.
		if ( count( $items ) < 3 && '' === $policy ) {
			return '';
		}

		$html  = '<section class="rayec-section rayec-know"><h2 class="rayec-section__title">' . esc_html__( 'Good to know', 'rayetun-events-calendar' ) . '</h2>';
		$html .= '<ul class="rayec-know__grid">';
		foreach ( $items as $key => $item ) {
			$html .= '<li class="rayec-know__item rayec-know__item--' . esc_attr( (string) $key ) . '">';
			$html .= '<span class="rayec-know__icon">' . Frontend::icon( (string) ( $item['icon'] ?? 'check' ) ) . '</span>';
			$html .= '<span class="rayec-know__text"><span class="rayec-know__label">' . esc_html( (string) ( $item['label'] ?? '' ) ) . '</span>';
			$html .= '<span class="rayec-know__value">' . esc_html( (string) ( $item['value'] ?? '' ) ) . '</span></span></li>';
		}
		$html .= '</ul>';

		if ( '' !== $policy ) {
			$html .= '<div class="rayec-know__policy"><span class="rayec-know__icon">' . Frontend::icon( 'shield' ) . '</span><div>';
			$html .= '<h3 class="rayec-know__heading">' . esc_html__( 'Cancellation policy', 'rayetun-events-calendar' ) . '</h3>';
			$html .= wp_kses_post( wpautop( esc_html( $policy ) ) );
			$html .= '</div></div>';
		}

		return $html . '</section>';
	}

	/**
	 * Directions to a venue by each way of travelling, from the visitor's
	 * own location. Plain links: nothing loads until one is pressed.
	 *
	 * @param array<string, mixed> $venue Venue.
	 * @return string
	 */
	public static function routes( array $venue ): string {
		$has_pin  = '' !== $venue['lat'] && '' !== $venue['lng'];
		$place    = $has_pin
			? $venue['lat'] . ',' . $venue['lng']
			: trim( $venue['name'] . ', ' . implode( ', ', array_filter( array( $venue['address'], $venue['city'] ) ) ), ', ' );
		$provider = (string) Plugin::setting( 'directions' );
		$names    = array(
			'google' => __( 'Google Maps', 'rayetun-events-calendar' ),
			'apple'  => __( 'Apple Maps', 'rayetun-events-calendar' ),
			'osm'    => __( 'OpenStreetMap', 'rayetun-events-calendar' ),
		);
		if ( ! isset( $names[ $provider ] ) ) {
			$provider = 'google';
		}

		// Each provider's own word for the mode; null where it has none.
		$codes  = array(
			'google' => array(
				'walk'    => 'walking',
				'cycle'   => 'bicycling',
				'transit' => 'transit',
				'drive'   => 'driving',
			),
			'apple'  => array(
				'walk'    => 'w',
				'cycle'   => null,
				'transit' => 'r',
				'drive'   => 'd',
			),
			'osm'    => array(
				'walk'    => 'fossgis_osrm_foot',
				'cycle'   => 'fossgis_osrm_bike',
				'transit' => null,
				'drive'   => 'fossgis_osrm_car',
			),
		);
		$labels = array(
			'walk'    => array( __( 'Walk', 'rayetun-events-calendar' ), 'walk' ),
			'cycle'   => array( __( 'Cycle', 'rayetun-events-calendar' ), 'bike' ),
			'transit' => array( __( 'Public transport', 'rayetun-events-calendar' ), 'bus' ),
			'drive'   => array( __( 'Drive', 'rayetun-events-calendar' ), 'car' ),
		);

		$modes = array();
		foreach ( $labels as $mode => $label ) {
			$code = $codes[ $provider ][ $mode ];
			if ( null === $code ) {
				continue;
			}
			if ( 'google' === $provider ) {
				$url = add_query_arg(
					array(
						'api'         => 1,
						'destination' => rawurlencode( $place ),
						'travelmode'  => $code,
					),
					'https://www.google.com/maps/dir/'
				);
			} elseif ( 'apple' === $provider ) {
				$url = add_query_arg(
					array(
						'daddr'  => rawurlencode( $place ),
						'dirflg' => $code,
					),
					'https://maps.apple.com/'
				);
			} else {
				$url = add_query_arg(
					array(
						'engine' => $code,
						'to'     => rawurlencode( $place ),
					),
					'https://www.openstreetmap.org/directions'
				);
			}
			$modes[ $mode ] = array(
				'label' => $label[0],
				'icon'  => $label[1],
				'url'   => $url,
			);
		}

		/**
		 * Filters the travel-mode buttons under a venue.
		 *
		 * @param array<string, array<string, string>> $modes    Modes keyed by name, each with `label`, `icon` and `url`.
		 * @param array<string, mixed>                 $venue    Venue.
		 * @param string                               $provider google, apple or osm.
		 */
		$modes = (array) apply_filters( 'rayec_directions_modes', $modes, $venue, $provider );
		if ( ! $modes ) {
			return '';
		}

		$html = '<ul class="rayec-route">';
		foreach ( $modes as $mode ) {
			$html .= '<li><a class="rayec-route__link" href="' . esc_url( (string) $mode['url'] ) . '" rel="noopener">' . Frontend::icon( (string) ( $mode['icon'] ?? 'arrow' ) );
			/* translators: %s: venue name. */
			$html .= esc_html( (string) $mode['label'] ) . '<span class="rayec-sr"> ' . esc_html( sprintf( __( 'to %s', 'rayetun-events-calendar' ), $venue['name'] ) ) . '</span></a></li>';
		}
		$html .= '</ul>';
		/* translators: %s: name of a map service, such as "Google Maps". */
		$html .= '<p class="rayec-route__note">' . esc_html( sprintf( __( 'Opens %s with the route from where you are.', 'rayetun-events-calendar' ), $names[ $provider ] ) ) . '</p>';

		return $html;
	}

	/**
	 * Where the calendar lives, for the links onward.
	 *
	 * @return string
	 */
	private static function calendar_url(): string {
		$page = Rest::calendar_page_id();
		$url  = $page ? get_permalink( $page ) : get_post_type_archive_link( Post_Types::EVENT );
		return $url ? (string) $url : home_url( '/' );
	}

	/**
	 * Coming events like this one: from its categories, or else at its venue.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param int                  $limit How many.
	 * @return array{0: string, 1: array<int, array<string, mixed>>} How they are alike ('category' or 'venue'), and the hydrated dates.
	 */
	public static function related( array $event, int $limit = 3 ): array {
		$base = array(
			'post_type'      => Post_Types::EVENT,
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => array( (int) $event['id'] ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- one ID, small result set.
		);
		// Other events in its program are listed under "More in" already.
		$program = ! empty( $event['program']['id'] )
			? array(
				'taxonomy' => Post_Types::PROGRAM,
				'terms'    => array( (int) $event['program']['id'] ),
				'operator' => 'NOT IN',
			)
			: null;

		$tries = array();
		$cats  = array_map( 'intval', wp_list_pluck( (array) $event['categories'], 'id' ) );
		if ( $cats ) {
			$tax = array(
				array(
					'taxonomy' => Post_Types::CATEGORY,
					'terms'    => $cats,
				),
			);
			if ( $program ) {
				$tax[]           = $program;
				$tax['relation'] = 'AND';
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a small term lookup on one event page.
			$tries['category'] = $base + array( 'tax_query' => $tax );
		}
		if ( ! empty( $event['venue_id'] ) && 'online' !== $event['format'] ) {
			$args = $base + array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key, on one event page.
				'meta_query' => array(
					array(
						'key'   => '_rayec_venue_id',
						'value' => (int) $event['venue_id'],
						'type'  => 'NUMERIC',
					),
				),
			);
			if ( $program ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a small term lookup on one event page.
				$args['tax_query'] = array( $program );
			}
			$tries['venue'] = $args;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $tries as $kind => $args ) {
			/**
			 * Filters the query for "More events like this".
			 *
			 * @param array<string, mixed> $args  get_posts() arguments.
			 * @param array<string, mixed> $event The event being shown.
			 * @param string               $kind  'category' or 'venue'.
			 */
			$args = (array) apply_filters( 'rayec_related_events_args', $args, $event, $kind );
			$ids  = get_posts( $args );
			if ( ! $ids ) {
				continue;
			}
			$next = array_filter( Occurrences::next_for_events( array_map( 'intval', $ids ) ), static fn( $row ) => $row->end_utc >= $now );
			if ( ! $next ) {
				continue;
			}
			uasort( $next, static fn( $a, $b ) => strcmp( $a->start_utc, $b->start_utc ) );
			$items = array();
			$seen  = array( strtolower( (string) $event['title'] ) => true );
			foreach ( Frontend::hydrate( array_values( $next ) ) as $item ) {
				// A copy of an event under the same name is not "another" event.
				$name = strtolower( (string) $item['event']['title'] );
				if ( 'cancelled' === $item['event']['event_status'] || isset( $seen[ $name ] ) ) {
					continue;
				}
				$seen[ $name ] = true;
				$items[]       = $item;
			}
			if ( $items ) {
				return array( $kind, array_slice( array_values( $items ), 0, $limit ) );
			}
		}
		return array( '', array() );
	}

	/**
	 * "More events like this": cards, then links to the calendar filtered by
	 * the event's category, venue and organiser.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	public static function more_like_this( array $event ): string {
		if ( ! Plugin::setting( 'related_events' ) ) {
			return '';
		}
		list( $kind, $items ) = self::related( $event );
		$links                = self::explore_links( $event );

		if ( ! $items ) {
			if ( ! $links ) {
				return '';
			}
			return '<section class="rayec-section rayec-related is-links-only"><h2 class="rayec-section__title">' . esc_html__( 'Keep exploring', 'rayetun-events-calendar' ) . '</h2>' . $links . '</section>';
		}

		$title = 'venue' === $kind
			/* translators: %s: venue name. */
			? sprintf( __( 'More at %s', 'rayetun-events-calendar' ), $event['venue']['name'] )
			: __( 'More events like this', 'rayetun-events-calendar' );

		$html  = '<section class="rayec-section rayec-related"><h2 class="rayec-section__title">' . esc_html( $title ) . '</h2>';
		$html .= '<ul class="rayec-cards">';
		foreach ( $items as $item ) {
			$html .= self::card( $item );
		}
		$html .= '</ul>';
		if ( $links ) {
			$html .= '<nav class="rayec-related__nav" aria-label="' . esc_attr__( 'Keep exploring', 'rayetun-events-calendar' ) . '">' . $links . '</nav>';
		}
		return $html . '</section>';
	}

	/**
	 * One event as a card: picture, date badge, title, when, where, places.
	 *
	 * @param array<string, mixed> $item Hydrated occurrence.
	 * @return string
	 */
	private static function card( array $item ): string {
		$event = $item['event'];
		$start = $item['start'];

		$html  = '<li class="rayec-card">';
		$html .= '<div class="rayec-card__media">';
		if ( $event['image'] ) {
			$html .= '<img src="' . esc_url( $event['image']['url'] ) . '" alt="" loading="lazy" decoding="async" width="' . esc_attr( (string) $event['image']['width'] ) . '" height="' . esc_attr( (string) $event['image']['height'] ) . '">';
		} else {
			// No picture: the category's colour and icon stand in.
			$category = $event['categories'][0] ?? null;
			$color    = $category ? sanitize_hex_color( (string) $category['color'] ) : '';
			$icons    = Frontend::category_icons();
			$path     = $category && isset( $icons[ $category['icon'] ] ) ? $icons[ $category['icon'] ]['path'] : '';
			$html    .= '<span class="rayec-card__art"' . ( $color ? ' style="--rayec-chip:' . esc_attr( $color ) . '"' : '' ) . '>';
			$html    .= $path
				? '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $path ) . '"/></svg>'
				: Frontend::icon( 'calendar' );
			$html    .= '</span>';
		}
		// The full date is in the text below; the badge is for the eye.
		$html .= '<span class="rayec-card__date" aria-hidden="true"><span class="rayec-card__month">' . esc_html( wp_date( 'M', $start->getTimestamp(), $start->getTimezone() ) ) . '</span><span class="rayec-card__day">' . esc_html( $start->format( 'j' ) ) . '</span></span>';
		$html .= '</div>';

		$html  .= '<div class="rayec-card__body">';
		$html  .= '<h3 class="rayec-card__title"><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $event['title'] ) . '</a></h3>';
		$status = Frontend::status_label( $event['event_status'] );
		if ( $status ) {
			$html .= '<p class="rayec-card__status"><span class="rayec-badge">' . esc_html( $status ) . '</span></p>';
		}
		$html .= '<p class="rayec-card__line">' . Frontend::icon( 'clock' ) . '<time datetime="' . esc_attr( $start->format( $item['all_day'] ? 'Y-m-d' : 'c' ) ) . '">' . esc_html( self::short_when( $start, $item['end'], (bool) $item['all_day'] ) ) . '</time></p>';
		$where = Frontend::where_line( $event );
		if ( '' !== $where ) {
			$online = 'online' === $event['format'] || 'moved_online' === $event['event_status'];
			$html  .= '<p class="rayec-card__line">' . Frontend::icon( $online ? 'screen' : 'pin' ) . esc_html( $where ) . '</p>';
		}
		$places = Frontend::places_label( $item );
		if ( $places ) {
			$html .= '<p class="rayec-card__places"><span class="rayec-badge rayec-badge--' . esc_attr( $places[1] ) . '">' . esc_html( $places[0] ) . '</span></p>';
		}
		$html .= '</div></li>';

		return $html;
	}

	/**
	 * A date short enough for a card: "Sat, Nov 21 · 6:00 pm".
	 *
	 * @param DateTimeImmutable $start   Start.
	 * @param DateTimeImmutable $end     End.
	 * @param bool              $all_day Whether it is an all-day event.
	 * @return string
	 */
	private static function short_when( DateTimeImmutable $start, DateTimeImmutable $end, bool $all_day ): string {
		/* translators: PHP date format for a short date on event cards, such as "Sat, Nov 21". */
		$format = __( 'D, M j', 'rayetun-events-calendar' );
		$day    = wp_date( $format, $start->getTimestamp(), $start->getTimezone() );
		$last   = $all_day ? $end->modify( '-1 second' ) : $end;
		if ( $last->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) && $last > $start ) {
			/* translators: 1: first day, 2: last day, both short dates. */
			return sprintf( __( '%1$s to %2$s', 'rayetun-events-calendar' ), $day, wp_date( $format, $last->getTimestamp(), $last->getTimezone() ) );
		}
		$time = $all_day ? __( 'All day', 'rayetun-events-calendar' ) : wp_date( Plugin::time_format(), $start->getTimestamp(), $start->getTimezone() );
		/* translators: 1: short date, 2: start time or "All day". */
		return sprintf( __( '%1$s · %2$s', 'rayetun-events-calendar' ), $day, $time );
	}

	/**
	 * Links to the calendar, filtered to what this event belongs to.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function explore_links( array $event ): string {
		$base  = self::calendar_url();
		$links = array();
		$icons = Frontend::category_icons();

		foreach ( array_slice( (array) $event['categories'], 0, 2 ) as $category ) {
			$icon = isset( $icons[ $category['icon'] ?? '' ] )
				? '<svg class="rayec-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $icons[ $category['icon'] ]['path'] ) . '"/></svg>'
				: Frontend::icon( 'tag' );
			/* translators: %s: category name. */
			$links[] = array( add_query_arg( 'rayec_cat', (int) $category['id'], $base ), $icon, sprintf( __( 'More in %s', 'rayetun-events-calendar' ), $category['name'] ) );
		}
		if ( ! empty( $event['venue'] ) && 'online' !== $event['format'] ) {
			/* translators: %s: venue name. */
			$links[] = array( add_query_arg( 'rayec_venue', (int) $event['venue_id'], $base ), Frontend::icon( 'pin' ), sprintf( __( 'More at %s', 'rayetun-events-calendar' ), $event['venue']['name'] ) );
		}
		if ( ! empty( $event['organizer'] ) ) {
			/* translators: %s: organiser name. */
			$links[] = array( add_query_arg( 'rayec_org', (int) $event['organizer_id'], $base ), Frontend::icon( 'users' ), sprintf( __( 'More from %s', 'rayetun-events-calendar' ), $event['organizer']['name'] ) );
		}
		if ( ! $links ) {
			return '';
		}
		$links[] = array( $base, Frontend::icon( 'calendar' ), __( 'All events', 'rayetun-events-calendar' ) );

		$html = '<ul class="rayec-explore">';
		foreach ( $links as $link ) {
			$html .= '<li><a href="' . esc_url( $link[0] ) . '">' . $link[1] . '<span>' . esc_html( $link[2] ) . '</span>' . Frontend::icon( 'arrow' ) . '</a></li>';
		}
		return $html . '</ul>';
	}
}
