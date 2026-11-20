<?php
/**
 * Bringing in events from elsewhere.
 *
 * Two rules shape this file. Nothing is written until the owner has seen what
 * would happen — every import is planned first and the plan is what the screen
 * shows. And an event that came from somewhere else remembers where it came
 * from, so importing the same calendar twice updates those events instead of
 * making a second copy of each.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The iCalendar importer.
 */
class Import {

	/**
	 * Where an imported event came from, and its ID over there.
	 *
	 * @var string
	 */
	const SOURCE_META = '_rayec_source';
	const UID_META    = '_rayec_source_uid';

	/**
	 * Most events read from one file.
	 *
	 * @var int
	 */
	const MAX_EVENTS = 500;

	/**
	 * Largest file or download accepted, in bytes.
	 *
	 * @var int
	 */
	const MAX_BYTES = 5242880;

	/**
	 * Fetch a calendar from a URL.
	 *
	 * @param string $url Feed address; webcal:// is accepted and treated as https.
	 * @return string|WP_Error The file's text.
	 */
	public static function fetch( string $url ) {
		$url = (string) preg_replace( '#^webcal://#i', 'https://', trim( $url ) );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'rayec_bad_url', __( 'That does not look like an address this site can reach.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'RayEtunEventsCalendar/' . RAYEC_VERSION . ' (WordPress plugin)',
				'headers'    => array( 'Accept' => 'text/calendar, text/plain;q=0.8, */*;q=0.5' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rayec_fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'rayec_fetch_failed',
				/* translators: %d: an HTTP status code, such as 404. */
				sprintf( __( 'That address answered with %d rather than a calendar.', 'rayetun-events-calendar' ), $code ),
				array( 'status' => 502 )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'rayec_too_big', __( 'That calendar is too large to read in one go.', 'rayetun-events-calendar' ), array( 'status' => 413 ) );
		}
		return $body;
	}

	/**
	 * Read an iCalendar file into plain event data.
	 *
	 * @param string $text The file.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function parse( string $text ) {
		if ( false === strpos( $text, 'BEGIN:VEVENT' ) ) {
			return new WP_Error( 'rayec_not_ics', __( 'That file holds no events. An iCalendar file starts with BEGIN:VCALENDAR.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		// Unfold: a line continued on the next one begins with a space or a tab.
		$text  = (string) preg_replace( '/\r\n|\r/', "\n", $text );
		$text  = (string) preg_replace( "/\n[ \t]/", '', $text );
		$items = array();
		$open  = null;

		foreach ( explode( "\n", $text ) as $line ) {
			if ( 'BEGIN:VEVENT' === rtrim( $line ) ) {
				$open = array();
				continue;
			}
			if ( 'END:VEVENT' === rtrim( $line ) ) {
				if ( null !== $open ) {
					$item = self::event( $open );
					if ( $item ) {
						$items[] = $item;
					}
				}
				$open = null;
				if ( count( $items ) >= self::MAX_EVENTS ) {
					break;
				}
				continue;
			}
			if ( null === $open || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $head, $value ) = explode( ':', $line, 2 );
			$parts                = explode( ';', $head );
			$name                 = strtoupper( array_shift( $parts ) );
			$params               = array();
			foreach ( $parts as $part ) {
				if ( false !== strpos( $part, '=' ) ) {
					list( $key, $val )            = explode( '=', $part, 2 );
					$params[ strtoupper( $key ) ] = trim( $val, '"' );
				}
			}
			// Keep the first of any repeated property, except the ones that stack.
			if ( in_array( $name, array( 'EXDATE', 'CATEGORIES' ), true ) ) {
				$open[ $name ][] = array( $params, $value );
			} elseif ( ! isset( $open[ $name ] ) ) {
				$open[ $name ] = array( array( $params, $value ) );
			}
		}

		if ( ! $items ) {
			return new WP_Error( 'rayec_no_events', __( 'No events in that file could be read.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		return $items;
	}

	/**
	 * One VEVENT's properties as event data.
	 *
	 * @param array<string, array<int, array{0: array<string, string>, 1: string}>> $props Properties.
	 * @return array<string, mixed>|null Null when there is no usable start.
	 */
	private static function event( array $props ): ?array {
		$get = static function ( string $name ) use ( $props ): array {
			return $props[ $name ][0] ?? array( array(), '' );
		};

		list( $start_params, $start_raw ) = $get( 'DTSTART' );
		$site                             = wp_timezone();
		$start                            = self::moment( $start_raw, $start_params, $site );
		if ( ! $start ) {
			// Kept, with no date, so the plan can say why it was left out.
			$title = self::text( (string) $get( 'SUMMARY' )[1] );
			return array(
				'uid'         => trim( (string) $get( 'UID' )[1] ),
				'title'       => '' !== $title ? $title : __( 'Untitled event', 'rayetun-events-calendar' ),
				'description' => '',
				'start'       => '',
				'end'         => '',
				'all_day'     => false,
				'timezone'    => $site->getName(),
				'rrule'       => '',
				'dropped'     => '',
				'exdates'     => array(),
				'location'    => '',
				'url'         => '',
				'categories'  => array(),
				'status'      => 'scheduled',
			);
		}

		$all_day  = isset( $start_params['VALUE'] ) && 'DATE' === strtoupper( $start_params['VALUE'] );
		$timezone = $start_params['TZID'] ?? '';
		$timezone = ( '' !== $timezone && in_array( $timezone, timezone_identifiers_list(), true ) ) ? $timezone : $site->getName();

		list( $end_params, $end_raw ) = $get( 'DTEND' );
		$end                          = self::moment( $end_raw, $end_params, $site );
		if ( ! $end ) {
			$end = self::after( $start, (string) $get( 'DURATION' )[1] );
		}
		if ( $all_day && $end ) {
			// DTEND on an all-day event is the morning after it finishes.
			$end = $end->modify( '-1 day' );
			if ( $end < $start ) {
				$end = $start;
			}
		}

		$rrule  = strtoupper( trim( (string) $get( 'RRULE' )[1] ) );
		$status = strtoupper( trim( (string) $get( 'STATUS' )[1] ) );

		$categories = array();
		foreach ( $props['CATEGORIES'] ?? array() as $row ) {
			foreach ( explode( ',', self::text( $row[1] ) ) as $term ) {
				$term = trim( $term );
				if ( '' !== $term ) {
					$categories[] = $term;
				}
			}
		}

		$exdates = array();
		foreach ( $props['EXDATE'] ?? array() as $row ) {
			foreach ( explode( ',', $row[1] ) as $value ) {
				$moment = self::moment( $value, $row[0], $site );
				if ( $moment ) {
					$exdates[] = $moment->format( 'Y-m-d' );
				}
			}
		}

		$title = self::text( (string) $get( 'SUMMARY' )[1] );
		$keeps = Recurrence::is_valid( $rrule ) && ! self::beyond_us( $rrule );

		return array(
			'uid'         => trim( (string) $get( 'UID' )[1] ),
			'title'       => '' !== $title ? $title : __( 'Untitled event', 'rayetun-events-calendar' ),
			'description' => self::text( (string) $get( 'DESCRIPTION' )[1] ),
			'start'       => $start ? $start->format( 'Y-m-d\TH:i' ) : '',
			'end'         => ( $end ? $end : $start )->format( 'Y-m-d\TH:i' ),
			'all_day'     => $all_day,
			'timezone'    => $timezone,
			// A rule this calendar cannot keep would be worse than no rule at all.
			'rrule'       => $keeps ? Recurrence::normalize( $rrule ) : '',
			'dropped'     => ( '' !== $rrule && ! $keeps ) ? $rrule : '',
			'exdates'     => array_values( array_unique( $exdates ) ),
			'location'    => self::text( (string) $get( 'LOCATION' )[1] ),
			'url'         => esc_url_raw( trim( (string) $get( 'URL' )[1] ) ),
			'categories'  => array_slice( array_unique( $categories ), 0, 5 ),
			'status'      => 'CANCELLED' === $status ? 'cancelled' : ( 'TENTATIVE' === $status ? 'postponed' : 'scheduled' ),
		);
	}

	/**
	 * Whether a repeat rule asks for something this calendar cannot do.
	 *
	 * Keeping only the parts we understand would quietly change what the rule
	 * means — "the last working Monday of the month" would become "every
	 * Monday" — so a rule like that is dropped instead, and the plan says so.
	 *
	 * @param string $rrule RRULE from the file.
	 * @return bool
	 */
	private static function beyond_us( string $rrule ): bool {
		// WKST only settles which day a week starts on, which the site decides anyway.
		$known = array( 'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'WKST' );
		foreach ( explode( ';', strtoupper( $rrule ) ) as $part ) {
			$key = strtok( trim( $part ), '=' );
			if ( '' !== (string) $key && false !== $key && ! in_array( $key, $known, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One iCalendar date or date-time as local wall-clock time.
	 *
	 * @param string                $value  Raw value.
	 * @param array<string, string> $params Property parameters.
	 * @param DateTimeZone          $site   The site's own zone.
	 * @return DateTimeImmutable|null
	 */
	private static function moment( string $value, array $params, DateTimeZone $site ): ?DateTimeImmutable {
		$value = trim( $value );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
			return self::make( "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", $site );
		}
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $value, $m ) ) {
			return null;
		}
		$stamp = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";

		if ( ! empty( $m[7] ) ) {
			// Written in UTC: read it there and put it into the site's own clock.
			$utc = self::make( $stamp, new DateTimeZone( 'UTC' ) );
			return $utc ? $utc->setTimezone( $site ) : null;
		}
		// With a named zone, or floating, the digits are already the wall clock.
		return self::make( $stamp, $site );
	}

	/**
	 * A date in a zone, or null if it is not a date at all.
	 *
	 * @param string       $stamp "Y-m-d H:i:s".
	 * @param DateTimeZone $zone  Zone.
	 * @return DateTimeImmutable|null
	 */
	private static function make( string $stamp, DateTimeZone $zone ): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable( $stamp, $zone );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Apply an iCalendar DURATION to a start.
	 *
	 * @param DateTimeImmutable $start    Start.
	 * @param string            $duration For example PT1H30M.
	 * @return DateTimeImmutable
	 */
	private static function after( DateTimeImmutable $start, string $duration ): DateTimeImmutable {
		if ( ! preg_match( '/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim( $duration ), $m ) ) {
			return $start->modify( '+1 hour' );
		}
		$seconds = ( (int) ( $m[1] ?? 0 ) ) * WEEK_IN_SECONDS
			+ ( (int) ( $m[2] ?? 0 ) ) * DAY_IN_SECONDS
			+ ( (int) ( $m[3] ?? 0 ) ) * HOUR_IN_SECONDS
			+ ( (int) ( $m[4] ?? 0 ) ) * MINUTE_IN_SECONDS
			+ (int) ( $m[5] ?? 0 );
		return $seconds > 0 ? $start->modify( '+' . $seconds . ' seconds' ) : $start->modify( '+1 hour' );
	}

	/**
	 * Undo the escaping iCalendar puts on text (RFC 5545 section 3.3.11).
	 *
	 * @param string $text Raw value.
	 * @return string
	 */
	private static function text( string $text ): string {
		return str_replace(
			array( '\\n', '\\N', '\\,', '\\;', '\\\\' ),
			array( "\n", "\n", ',', ';', '\\' ),
			trim( $text )
		);
	}

	/**
	 * The fields a spreadsheet column can be read as.
	 *
	 * @return array<string, string> Field name => what to call it on screen.
	 */
	public static function csv_fields(): array {
		return array(
			''            => __( 'Ignore this column', 'rayetun-events-calendar' ),
			'title'       => __( 'Name', 'rayetun-events-calendar' ),
			'start_date'  => __( 'Start date', 'rayetun-events-calendar' ),
			'start_time'  => __( 'Start time', 'rayetun-events-calendar' ),
			'end_date'    => __( 'End date', 'rayetun-events-calendar' ),
			'end_time'    => __( 'End time', 'rayetun-events-calendar' ),
			'all_day'     => __( 'All day (yes or no)', 'rayetun-events-calendar' ),
			'timezone'    => __( 'Time zone', 'rayetun-events-calendar' ),
			'description' => __( 'Description', 'rayetun-events-calendar' ),
			'location'    => __( 'Place', 'rayetun-events-calendar' ),
			'categories'  => __( 'Categories', 'rayetun-events-calendar' ),
			'status'      => __( 'Status (cancelled, postponed)', 'rayetun-events-calendar' ),
			'url'         => __( 'Link', 'rayetun-events-calendar' ),
			'uid'         => __( 'ID in the other system', 'rayetun-events-calendar' ),
		);
	}

	/**
	 * Read a spreadsheet into its header row and its rows.
	 *
	 * @param string $text The file.
	 * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}|WP_Error
	 */
	public static function read_csv( string $text ) {
		// A spreadsheet saved by Excel often starts with a byte-order mark.
		$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		// An in-memory stream, so that fgetcsv() can handle quoted line breaks
		// properly. Nothing here touches the disk, which is what WP_Filesystem is for.
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a memory stream, not a file.
		if ( ! $handle ) {
			return new WP_Error( 'rayec_no_read', __( 'That file could not be read.', 'rayetun-events-calendar' ), array( 'status' => 500 ) );
		}
		fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a memory stream, not a file.
		rewind( $handle );

		// Semicolons are what a spreadsheet writes in much of Europe.
		$first     = (string) strtok( $text, "\n" );
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';

		$headers = array();
		$rows    = array();
		$row     = fgetcsv( $handle, 0, $delimiter, '"', '\\' );
		while ( false !== $row ) {
			if ( array( null ) === $row ) {
				$row = fgetcsv( $handle, 0, $delimiter, '"', '\\' );
				continue; // A blank line.
			}
			$cells = array_map( static fn( $cell ) => trim( (string) $cell ), $row );
			$row   = fgetcsv( $handle, 0, $delimiter, '"', '\\' );

			if ( ! $headers ) {
				$headers = $cells;
				continue;
			}
			if ( '' === implode( '', $cells ) ) {
				continue;
			}
			$rows[] = $cells;
			if ( count( $rows ) >= self::MAX_EVENTS ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a memory stream, not a file.

		if ( ! $headers ) {
			return new WP_Error( 'rayec_no_columns', __( 'That file has no header row, so there is nothing to match columns against.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( ! $rows ) {
			return new WP_Error( 'rayec_no_rows', __( 'That file has a header row and nothing under it.', 'rayetun-events-calendar' ), array( 'status' => 400 ) );
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * A first guess at which column is which, from the header names.
	 *
	 * @param array<int, string> $headers Header row.
	 * @return array<int, string> Column index => field name.
	 */
	public static function guess_mapping( array $headers ): array {
		$by_name = array(
			'title'       => array( 'title', 'name', 'event', 'eventname', 'summary', 'subject' ),
			'start_date'  => array( 'startdate', 'date', 'start', 'eventdate', 'begins', 'from' ),
			'start_time'  => array( 'starttime', 'time', 'begintime' ),
			'end_date'    => array( 'enddate', 'finish', 'until', 'to' ),
			'end_time'    => array( 'endtime', 'finishtime' ),
			'all_day'     => array( 'allday', 'fullday', 'wholeday' ),
			'timezone'    => array( 'timezone', 'tz' ),
			'description' => array( 'description', 'details', 'notes', 'content', 'excerpt' ),
			'location'    => array( 'location', 'venue', 'place', 'where', 'address' ),
			'categories'  => array( 'categories', 'category', 'tags', 'type' ),
			'status'      => array( 'status', 'eventstatus' ),
			'url'         => array( 'url', 'link', 'website', 'permalink' ),
			'uid'         => array( 'uid', 'id', 'eventid', 'reference' ),
		);

		$mapping = array();
		$taken   = array();
		foreach ( $headers as $index => $header ) {
			$slug = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $header ) );
			foreach ( $by_name as $field => $names ) {
				if ( in_array( $field, $taken, true ) ) {
					continue;
				}
				if ( in_array( $slug, $names, true ) ) {
					$mapping[ $index ] = $field;
					$taken[]           = $field;
					continue 2;
				}
			}
			$mapping[ $index ] = '';
		}
		return $mapping;
	}

	/**
	 * Turn mapped spreadsheet rows into the same event data an .ics file gives.
	 *
	 * @param array<int, array<int, string>> $rows      Rows under the header.
	 * @param array<int, string>             $mapping   Column index => field.
	 * @param bool                           $day_first Whether 3/4 means 3 April.
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_csv( array $rows, array $mapping, bool $day_first ): array {
		$site  = wp_timezone();
		$items = array();

		foreach ( $rows as $row ) {
			$cell = array();
			foreach ( $mapping as $index => $field ) {
				if ( '' === $field ) {
					continue;
				}
				$value = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
				// Two columns on the same field: the first one filled wins.
				if ( '' !== $value && '' === ( $cell[ $field ] ?? '' ) ) {
					$cell[ $field ] = $value;
				}
			}

			$all_day = self::yes( $cell['all_day'] ?? '' );
			$zone    = ( isset( $cell['timezone'] ) && in_array( $cell['timezone'], timezone_identifiers_list(), true ) )
				? new DateTimeZone( $cell['timezone'] )
				: $site;

			$start = self::csv_moment( $cell['start_date'] ?? '', $cell['start_time'] ?? '', $day_first, $zone );
			$end   = self::csv_moment( $cell['end_date'] ?? ( $cell['start_date'] ?? '' ), $cell['end_time'] ?? '', $day_first, $zone );
			if ( $start && ( ! $end || $end < $start ) ) {
				$end = $all_day ? $start : $start->modify( '+1 hour' );
			}

			$status = strtolower( $cell['status'] ?? '' );
			$title  = sanitize_text_field( $cell['title'] ?? '' );

			$items[] = array(
				// Without an ID of its own, a row is matched on its name and date.
				'uid'         => '' !== ( $cell['uid'] ?? '' )
					? $cell['uid']
					: ( $start && '' !== $title ? 'csv-' . md5( $title . '|' . $start->format( 'Y-m-d H:i' ) ) : '' ),
				'title'       => $title,
				'description' => sanitize_textarea_field( $cell['description'] ?? '' ),
				'start'       => $start ? $start->format( 'Y-m-d\TH:i' ) : '',
				'end'         => $end ? $end->format( 'Y-m-d\TH:i' ) : '',
				'all_day'     => $all_day,
				'timezone'    => $zone->getName(),
				'rrule'       => '',
				'dropped'     => '',
				'exdates'     => array(),
				'location'    => sanitize_text_field( $cell['location'] ?? '' ),
				'url'         => esc_url_raw( $cell['url'] ?? '' ),
				'categories'  => array_slice(
					array_values(
						array_filter(
							array_map( 'trim', (array) preg_split( '/[,;|]/', $cell['categories'] ?? '' ) )
						)
					),
					0,
					5
				),
				'status'      => in_array( $status, Event::STATUSES, true ) ? $status : 'scheduled',
			);
		}

		return $items;
	}

	/**
	 * A date and a time from a spreadsheet, read as wall-clock time.
	 *
	 * Only the order of day and month is ever guessed, and that guess is the
	 * owner's to make, because 3/4/2026 is two different days either side of
	 * the Atlantic. Everything else is read literally.
	 *
	 * @param string       $date      Date cell.
	 * @param string       $time      Time cell, which may be empty.
	 * @param bool         $day_first Whether 3/4 means 3 April.
	 * @param DateTimeZone $zone      Zone to read it in.
	 * @return DateTimeImmutable|null
	 */
	private static function csv_moment( string $date, string $time, bool $day_first, DateTimeZone $zone ): ?DateTimeImmutable {
		$date = trim( $date );
		$time = trim( $time );
		if ( '' === $date ) {
			return null;
		}

		// A date cell may carry the time as well.
		if ( '' === $time && preg_match( '/[T ](\d{1,2}[:.]\d{2}(?::\d{2})?\s*(?:am|pm)?)$/i', $date, $m ) ) {
			$time = $m[1];
			$date = trim( substr( $date, 0, -strlen( $m[0] ) ) );
		}

		$ymd = '';
		if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $date, $m ) ) {
			$ymd = sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
		} elseif ( preg_match( '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $date, $m ) ) {
			$year  = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
			$day   = $day_first ? (int) $m[1] : (int) $m[2];
			$month = $day_first ? (int) $m[2] : (int) $m[1];
			if ( ! checkdate( $month, $day, $year ) ) {
				// The other way round is the only reading that can work.
				list( $day, $month ) = array( $month, $day );
			}
			if ( ! checkdate( $month, $day, $year ) ) {
				return null;
			}
			$ymd = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		} else {
			// Written out: "4 March 2026", "March 4, 2026".
			$stamp = strtotime( $date . ' 00:00:00 UTC' );
			if ( false === $stamp ) {
				return null;
			}
			$ymd = gmdate( 'Y-m-d', $stamp );
		}

		$clock = '00:00';
		if ( '' !== $time ) {
			$stamp = strtotime( '1970-01-01 ' . str_replace( '.', ':', $time ) . ' UTC' );
			if ( false !== $stamp ) {
				$clock = gmdate( 'H:i', $stamp );
			}
		}

		return self::make( $ymd . ' ' . $clock . ':00', $zone );
	}

	/**
	 * Whether a cell means yes.
	 *
	 * @param string $value Cell.
	 * @return bool
	 */
	private static function yes( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'yes', 'y', 'true', '1', 'all day', 'all-day' ), true );
	}

	/**
	 * What an import would do, event by event, without doing any of it.
	 *
	 * @param array<int, array<string, mixed>> $items   Parsed events.
	 * @param array<string, mixed>             $options Import options.
	 * @return array<string, mixed>
	 */
	public static function plan( array $items, array $options ): array {
		$source = (string) ( $options['source'] ?? '' );
		$rows   = array();
		$counts = array(
			'new'     => 0,
			'update'  => 0,
			'skipped' => 0,
		);

		foreach ( $items as $item ) {
			$existing = self::existing( (string) $item['uid'], $source );
			$action   = $existing ? 'update' : 'new';
			$note     = '';

			if ( '' === trim( (string) $item['title'] ) ) {
				$action = 'skipped';
				$note   = __( 'No name.', 'rayetun-events-calendar' );
			} elseif ( '' === (string) $item['start'] ) {
				$action = 'skipped';
				$note   = __( 'The file gives it no date.', 'rayetun-events-calendar' );
			} elseif ( '' === (string) $item['uid'] ) {
				// Without a UID there is nothing to match on a second import.
				$note = __( 'No ID in the file, so a later import will add it again.', 'rayetun-events-calendar' );
			} elseif ( '' !== (string) $item['dropped'] ) {
				/* translators: %s: an iCalendar repeat rule this calendar cannot keep. */
				$note = sprintf( __( 'Its repeat rule (%s) is one this calendar cannot keep, so it comes in as a single event.', 'rayetun-events-calendar' ), $item['dropped'] );
			}

			++$counts[ $action ];
			$rows[] = array(
				'uid'       => $item['uid'],
				'title'     => $item['title'],
				'start'     => $item['start'],
				'all_day'   => $item['all_day'],
				'repeats'   => '' !== $item['rrule'],
				'location'  => $item['location'],
				'action'    => $action,
				'note'      => $note,
				'event_id'  => $existing,
				'edit_link' => $existing ? (string) get_edit_post_link( $existing, 'raw' ) : '',
			);
		}

		return array(
			'counts' => $counts,
			'rows'   => $rows,
		);
	}

	/**
	 * Carry out the import.
	 *
	 * @param array<int, array<string, mixed>> $items   Parsed events.
	 * @param array<string, mixed>             $options Import options.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( array $items, array $options ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'rayec_forbidden', __( 'You cannot add events.', 'rayetun-events-calendar' ), array( 'status' => 403 ) );
		}

		$source     = (string) ( $options['source'] ?? '' );
		$status     = in_array( $options['post_status'] ?? '', array( 'publish', 'draft', 'pending' ), true ) ? (string) $options['post_status'] : 'draft';
		$category   = (int) ( $options['category'] ?? 0 );
		$use_venues = ! empty( $options['create_venues'] );
		$use_terms  = ! empty( $options['use_categories'] );
		$added      = 0;
		$updated    = 0;
		$failed     = array();
		$event_ids  = array();

		// Someone who cannot publish sends new events for review instead.
		$type = get_post_type_object( Post_Types::EVENT );
		if ( 'publish' === $status && ! ( $type && current_user_can( $type->cap->publish_posts ) ) ) {
			$status = 'pending';
		}

		foreach ( $items as $item ) {
			if ( '' === trim( (string) $item['title'] ) || '' === trim( (string) $item['start'] ) ) {
				continue;
			}
			$existing = self::existing( (string) $item['uid'], $source );

			// An event imported before is only updated by someone who may edit it.
			if ( $existing && ! current_user_can( 'edit_post', $existing ) ) {
				$failed[] = array(
					'title'  => $item['title'],
					'reason' => __( 'This event is already on the site, and you are not allowed to change it.', 'rayetun-events-calendar' ),
				);
				continue;
			}

			$categories = $category ? array( $category ) : array();
			if ( $use_terms ) {
				foreach ( (array) $item['categories'] as $name ) {
					$term = self::term( (string) $name );
					if ( $term ) {
						$categories[] = $term;
					}
				}
			}

			$data = array(
				'title'        => $item['title'],
				'description'  => '' !== $item['description'] ? wpautop( esc_html( $item['description'] ) ) : '',
				'start'        => $item['start'],
				'end'          => $item['end'],
				'all_day'      => $item['all_day'],
				'timezone'     => $item['timezone'],
				'rrule'        => $item['rrule'],
				'exdates'      => $item['exdates'],
				'event_status' => $item['status'],
				'post_status'  => $existing ? get_post_status( $existing ) : $status,
				'category_ids' => array_values( array_unique( $categories ) ),
			);
			if ( $use_venues && '' !== $item['location'] ) {
				$data['venue_id'] = self::venue( (string) $item['location'] );
			}

			$result = Event::save( $data, $existing ? $existing : 0 );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'title'  => $item['title'],
					'reason' => $result->get_error_message(),
				);
				continue;
			}

			$id          = (int) $result;
			$event_ids[] = $id;
			if ( $existing ) {
				++$updated;
			} else {
				++$added;
				update_post_meta( $id, self::SOURCE_META, $source );
				if ( '' !== (string) $item['uid'] ) {
					update_post_meta( $id, self::UID_META, (string) $item['uid'] );
				}
			}
		}

		return array(
			'added'   => $added,
			'updated' => $updated,
			'failed'  => $failed,
			'ids'     => $event_ids,
		);
	}

	/**
	 * The event already imported for a given UID, if there is one.
	 *
	 * @param string $uid    UID from the file.
	 * @param string $source Where the file came from.
	 * @return int Event ID, or 0.
	 */
	private static function existing( string $uid, string $source ): int {
		if ( '' === $uid ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'      => Post_Types::EVENT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- matching an imported event by its own ID.
				'meta_query'     => array(
					array(
						'key'   => self::UID_META,
						'value' => $uid,
					),
				),
			)
		);
		if ( ! $found ) {
			return 0;
		}
		$id = (int) $found[0];
		// Two calendars may use the same UID; only the one we imported counts.
		$from = (string) get_post_meta( $id, self::SOURCE_META, true );
		return ( '' === $source || '' === $from || $from === $source ) ? $id : 0;
	}

	/**
	 * A category term ID for a name from the file, making it if needed.
	 *
	 * @param string $name Term name.
	 * @return int
	 */
	private static function term( string $name ): int {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return 0;
		}
		$term = get_term_by( 'name', $name, Post_Types::CATEGORY );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$taxonomy = get_taxonomy( Post_Types::CATEGORY );
		if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
			return 0;
		}
		$made = wp_insert_term( $name, Post_Types::CATEGORY );
		return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
	}

	/**
	 * A venue for an iCalendar LOCATION, making it if needed.
	 *
	 * The first part before a comma is treated as the name and the rest as the
	 * address, which is how most calendars write it.
	 *
	 * @param string $location LOCATION value.
	 * @return int Venue ID, or 0.
	 */
	private static function venue( string $location ): int {
		$parts = array_map( 'trim', explode( ',', $location ) );
		$name  = (string) array_shift( $parts );
		if ( '' === $name ) {
			return 0;
		}

		$existing = get_posts(
			array(
				'post_type'      => Post_Types::VENUE,
				'post_status'    => 'any',
				'title'          => $name,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}

		$made = Venue::save(
			array(
				'name'    => $name,
				'address' => implode( ', ', $parts ),
			)
		);
		return is_wp_error( $made ) ? 0 : (int) $made;
	}
}
