<?php
/**
 * The iCalendar feed, single-event downloads and Google Calendar links.
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
 * Calendar export.
 */
class Ics {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
		// The site's RSS feed for events says when each one is, not just what it is.
		add_filter( 'the_content_feed', array( __CLASS__, 'feed_content' ) );
		add_filter( 'the_excerpt_rss', array( __CLASS__, 'feed_content' ) );
	}

	/**
	 * Feed URL, or a single event's download URL.
	 *
	 * @param int                  $event_id Event ID, or 0 for the whole calendar.
	 * @param array<string, mixed> $filters  Category and venue to narrow it to.
	 * @return string
	 */
	public static function feed_url( int $event_id = 0, array $filters = array() ): string {
		$args = array( 'rayec_ics' => $event_id ? $event_id : 'all' );
		if ( ! $event_id ) {
			foreach ( array(
				'category' => 'rayec_cat',
				'venue'    => 'rayec_venue',
			) as $key => $arg ) {
				if ( ! empty( $filters[ $key ] ) ) {
					$args[ $arg ] = (int) $filters[ $key ];
				}
			}
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * The same feed as an address a calendar program will offer to subscribe to.
	 *
	 * @param array<string, mixed> $filters Category and venue.
	 * @return string
	 */
	public static function webcal_url( array $filters = array() ): string {
		return (string) preg_replace( '#^https?://#i', 'webcal://', self::feed_url( 0, $filters ) );
	}

	/**
	 * Links that add this feed to the calendar programs people actually use.
	 *
	 * @param array<string, mixed> $filters Category and venue.
	 * @return array<string, array{label: string, url: string}>
	 */
	public static function subscribe_links( array $filters = array() ): array {
		$feed   = self::feed_url( 0, $filters );
		$webcal = self::webcal_url( $filters );
		$name   = get_bloginfo( 'name' );

		return array(
			'google'  => array(
				'label' => __( 'Google Calendar', 'rayetun-events-calendar' ),
				'url'   => add_query_arg( 'cid', rawurlencode( $webcal ), 'https://calendar.google.com/calendar/r' ),
			),
			'outlook' => array(
				'label' => __( 'Outlook', 'rayetun-events-calendar' ),
				'url'   => add_query_arg(
					array(
						'url'  => rawurlencode( $feed ),
						'name' => rawurlencode( $name ),
					),
					'https://outlook.live.com/calendar/0/addfromweb'
				),
			),
			'apple'   => array(
				'label' => __( 'Apple Calendar and others', 'rayetun-events-calendar' ),
				'url'   => $webcal,
			),
			'file'    => array(
				'label' => __( 'Download the file', 'rayetun-events-calendar' ),
				'url'   => $feed,
			),
		);
	}

	/**
	 * Put the date at the top of an event in the site's RSS feed.
	 *
	 * @param string $content Feed content.
	 * @return string
	 */
	public static function feed_content( $content ) {
		if ( Post_Types::EVENT !== get_post_type() ) {
			return $content;
		}
		$event = Event::get( (int) get_the_ID() );
		if ( ! $event ) {
			return $content;
		}
		$times = Frontend::current_occurrences( $event, 0 );
		if ( ! $times ) {
			return $content;
		}
		$when  = Occurrences::format_range( $times[0][0], $times[0][1], $event['all_day'] );
		$where = Frontend::where_line( $event );

		/* translators: %s: when the event is. */
		$line = '<p>' . esc_html( sprintf( __( 'When: %s', 'rayetun-events-calendar' ), $when ) );
		if ( '' !== $where ) {
			/* translators: %s: where the event is. */
			$line .= '<br>' . esc_html( sprintf( __( 'Where: %s', 'rayetun-events-calendar' ), $where ) );
		}
		return $line . '</p>' . $content;
	}

	/**
	 * "Add to Google Calendar" URL.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param DateTimeImmutable    $start Occurrence start.
	 * @param DateTimeImmutable    $end   Occurrence end.
	 * @return string
	 */
	public static function google_url( array $event, DateTimeImmutable $start, DateTimeImmutable $end ): string {
		$utc = new DateTimeZone( 'UTC' );
		if ( $event['all_day'] ) {
			$dates = $start->format( 'Ymd' ) . '/' . $end->modify( '+1 day' )->format( 'Ymd' );
		} else {
			$dates = $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ) . '/' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		}

		$args = array(
			'action'   => 'TEMPLATE',
			'text'     => rawurlencode( $event['title'] ),
			'dates'    => $dates,
			'details'  => rawurlencode( $event['permalink'] ),
			'location' => rawurlencode( self::location( $event ) ),
		);
		return add_query_arg( $args, 'https://calendar.google.com/calendar/render' );
	}

	/**
	 * Location text for an event.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function location( array $event ): string {
		if ( 'online' === $event['format'] || 'moved_online' === $event['event_status'] ) {
			return $event['online_url'];
		}
		if ( empty( $event['venue'] ) ) {
			return '';
		}
		return implode( ', ', array_filter( array( $event['room'] ?? '', $event['venue']['name'], $event['venue']['address'], $event['venue']['city'] ) ) );
	}

	/**
	 * Serve the feed when requested.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only calendar feed.
		if ( ! isset( $_GET['rayec_ics'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only calendar feed.
		$requested = sanitize_text_field( wp_unslash( $_GET['rayec_ics'] ) );

		if ( 'all' === $requested ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only calendar feed.
			$category = isset( $_GET['rayec_cat'] ) ? absint( wp_unslash( $_GET['rayec_cat'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only calendar feed.
			$venue = isset( $_GET['rayec_venue'] ) ? absint( wp_unslash( $_GET['rayec_venue'] ) ) : 0;

			$ids  = get_posts(
				array(
					'post_type'      => Post_Types::EVENT,
					'post_status'    => 'publish',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- a deliberate ceiling on one feed.
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'post__in'       => self::recent_event_ids( $category, $venue ),
				)
			);
			$name = self::feed_name( $category, $venue );
			$file = 'events.ics';
		} else {
			$id   = absint( $requested );
			$ids  = ( Post_Types::EVENT === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) ? array( $id ) : array();
			$name = $ids ? get_the_title( $id ) : '';
			$file = $ids ? sanitize_title( $name ) . '.ics' : 'event.ics';
			if ( ! $ids ) {
				status_header( 404 );
				nocache_headers();
				exit;
			}
		}

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//RayEtun//Events Calendar ' . RAYEC_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape( $name ),
		);
		Event::prime( $ids );
		foreach ( $ids as $id ) {
			$event = Event::get( (int) $id );
			if ( $event && '' !== $event['start'] ) {
				$lines = array_merge( $lines, self::vevent( $event ) );
				// Each extra start time on the day is its own entry in the file.
				foreach ( $event['extra_times'] as $time ) {
					$lines = array_merge( $lines, self::vevent( $event, $time ) );
				}
			}
		}
		$lines[] = 'END:VCALENDAR';

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . ( 'all' === $requested ? 'inline' : 'attachment' ) . '; filename="' . $file . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text; every value is escaped per RFC 5545 in self::escape().
		echo implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
		exit;
	}

	/**
	 * Events with an occurrence from 30 days ago onwards.
	 *
	 * @param int $category Category term ID, or 0 for all.
	 * @param int $venue    Venue ID, or 0 for all.
	 * @return int[]
	 */
	private static function recent_event_ids( int $category = 0, int $venue = 0 ): array {
		if ( $category || $venue ) {
			$rows = Occurrences::query(
				array(
					'start'    => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
					'category' => $category,
					'venue'    => $venue,
					'limit'    => 500,
				)
			);
			$ids  = array_values( array_unique( array_map( static fn( $row ) => (int) $row->event_id, $rows ) ) );
			return $ids ? $ids : array( 0 );
		}

		global $wpdb;
		$table = $wpdb->prefix . Occurrences::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table name is internal.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$table} WHERE end_utc >= %s LIMIT 500", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ) );
		return $ids ? $ids : array( 0 );
	}

	/**
	 * What to call a feed that covers only part of the calendar.
	 *
	 * @param int $category Category term ID, or 0.
	 * @param int $venue    Venue ID, or 0.
	 * @return string
	 */
	private static function feed_name( int $category, int $venue ): string {
		$site = get_bloginfo( 'name' );
		$term = $category ? get_term( $category, Post_Types::CATEGORY ) : null;
		$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
		if ( '' === $name && $venue && Post_Types::VENUE === get_post_type( $venue ) ) {
			$name = get_the_title( $venue );
		}
		/* translators: 1: site name, 2: a category or venue name. */
		return '' !== $name ? sprintf( __( '%1$s: %2$s', 'rayetun-events-calendar' ), $site, $name ) : $site;
	}

	/**
	 * VEVENT lines for an event (the series master, with its rule).
	 *
	 * @param array<string, mixed> $event Event.
	 * @param string               $time  One of the day's other start times, as "HH:MM".
	 * @return string[]
	 */
	private static function vevent( array $event, string $time = '' ): array {
		$tz    = Event::timezone( $event['timezone'] );
		$utc   = new DateTimeZone( 'UTC' );
		$start = Event::local_datetime( $event['start'], $tz );
		$end   = Event::local_datetime( $event['end'], $tz );
		if ( ! $start || ! $end ) {
			return array();
		}
		if ( '' !== $time ) {
			$length = $end->getTimestamp() - $start->getTimestamp();
			$start  = $start->setTime( (int) substr( $time, 0, 2 ), (int) substr( $time, 3, 2 ) );
			$end    = $start->setTimestamp( $start->getTimestamp() + $length );
		}

		// Named zones keep wall-clock time across DST; fixed offsets are written in UTC.
		$named = false !== strpos( $tz->getName(), '/' );
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$lines = array(
			'BEGIN:VEVENT',
			'UID:rayec-' . $event['id'] . ( '' !== $time ? '-' . str_replace( ':', '', $time ) : '' ) . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		);

		if ( $event['all_day'] ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . $start->format( 'Ymd' );
			$lines[] = 'DTEND;VALUE=DATE:' . $end->modify( '+1 day' )->format( 'Ymd' );
		} elseif ( $named ) {
			$lines[] = 'DTSTART;TZID=' . $tz->getName() . ':' . $start->format( 'Ymd\THis' );
			$lines[] = 'DTEND;TZID=' . $tz->getName() . ':' . $end->format( 'Ymd\THis' );
		} else {
			$lines[] = 'DTSTART:' . $start->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			$lines[] = 'DTEND:' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		}

		if ( $event['rrule'] ) {
			$lines[] = 'RRULE:' . $event['rrule'];
			foreach ( $event['exdates'] as $date ) {
				$excluded = Event::local_datetime( $date . ' ' . $start->format( 'H:i' ), $tz );
				if ( ! $excluded ) {
					continue;
				}
				if ( $event['all_day'] ) {
					$lines[] = 'EXDATE;VALUE=DATE:' . $excluded->format( 'Ymd' );
				} elseif ( $named ) {
					$lines[] = 'EXDATE;TZID=' . $tz->getName() . ':' . $excluded->format( 'Ymd\THis' );
				} else {
					$lines[] = 'EXDATE:' . $excluded->setTimezone( $utc )->format( 'Ymd\THis\Z' );
				}
			}
		}

		$lines[] = 'SUMMARY:' . self::escape( $event['title'] );
		$summary = wp_trim_words( wp_strip_all_tags( $event['description'] ), 80 );
		if ( '' !== $summary ) {
			$lines[] = 'DESCRIPTION:' . self::escape( $summary );
		}
		$location = self::location( $event );
		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::escape( $location );
		}
		$lines[] = 'URL:' . esc_url_raw( $event['permalink'] );
		$lines[] = 'STATUS:' . ( 'cancelled' === $event['event_status'] ? 'CANCELLED' : ( 'postponed' === $event['event_status'] ? 'TENTATIVE' : 'CONFIRMED' ) );
		$lines[] = 'END:VEVENT';

		return $lines;
	}

	/**
	 * Escape a text value (RFC 5545 section 3.3.11).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function escape( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $text );
		return (string) preg_replace( '/\r\n|\r|\n/', '\\n', $text );
	}

	/**
	 * Fold a content line at 75 octets.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out  = '';
		$left = strlen( $line );
		while ( $left > 75 ) {
			$chunk = mb_strcut( $line, 0, 74, 'UTF-8' );
			$out  .= $chunk . "\r\n ";
			$line  = substr( $line, strlen( $chunk ) );
		}
		return $out . $line;
	}
}
