<?php
/**
 * Recurrence engine: expands an RRULE into concrete start times.
 *
 * Expansion happens in the event's own time zone so that a 6pm class stays at
 * 6pm on both sides of a daylight-saving change.
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
 * RRULE subset: FREQ, INTERVAL, COUNT, UNTIL, BYDAY, BYMONTHDAY, BYMONTH.
 */
class Recurrence {

	const DAYS = array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );

	/**
	 * Parse an RRULE string into its parts.
	 *
	 * @param string $rrule RRULE, with or without the "RRULE:" prefix.
	 * @return array<string, mixed>
	 */
	public static function parse( string $rrule ): array {
		$rule = array(
			'FREQ'       => '',
			'INTERVAL'   => 1,
			'COUNT'      => 0,
			'UNTIL'      => '',
			'BYDAY'      => array(),
			'BYMONTHDAY' => array(),
			'BYMONTH'    => array(),
		);

		$rrule = strtoupper( trim( (string) preg_replace( '/^RRULE:/i', '', trim( $rrule ) ) ) );
		if ( '' === $rrule ) {
			return $rule;
		}

		foreach ( explode( ';', $rrule ) as $part ) {
			if ( false === strpos( $part, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $part, 2 );

			switch ( $key ) {
				case 'FREQ':
					if ( in_array( $value, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
						$rule['FREQ'] = $value;
					}
					break;
				case 'INTERVAL':
					$rule['INTERVAL'] = max( 1, (int) $value );
					break;
				case 'COUNT':
					$rule['COUNT'] = max( 0, (int) $value );
					break;
				case 'UNTIL':
					if ( preg_match( '/^\d{8}(T\d{6}Z?)?$/', $value ) ) {
						$rule['UNTIL'] = $value;
					}
					break;
				case 'BYDAY':
					foreach ( explode( ',', $value ) as $token ) {
						if ( preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $token, $m ) ) {
							$rule['BYDAY'][] = array(
								'ord' => '' === $m[1] ? 0 : (int) $m[1],
								'day' => $m[2],
							);
						}
					}
					break;
				case 'BYMONTHDAY':
					foreach ( explode( ',', $value ) as $token ) {
						$day = (int) $token;
						if ( 0 !== $day && abs( $day ) <= 31 ) {
							$rule['BYMONTHDAY'][] = $day;
						}
					}
					break;
				case 'BYMONTH':
					foreach ( explode( ',', $value ) as $token ) {
						$month = (int) $token;
						if ( $month >= 1 && $month <= 12 ) {
							$rule['BYMONTH'][] = $month;
						}
					}
					break;
			}
		}

		return $rule;
	}

	/**
	 * Whether a string is a usable recurrence rule.
	 *
	 * @param string $rrule RRULE.
	 * @return bool
	 */
	public static function is_valid( string $rrule ): bool {
		$rule = self::parse( $rrule );
		return '' !== $rule['FREQ'];
	}

	/**
	 * Rebuild a clean RRULE string from user input.
	 *
	 * @param string $rrule RRULE.
	 * @return string Normalised rule, or an empty string when invalid.
	 */
	public static function normalize( string $rrule ): string {
		$rule = self::parse( $rrule );
		if ( '' === $rule['FREQ'] ) {
			return '';
		}

		// Two rules that mean the same thing come out as the same string, so
		// that comparing them is comparing strings. "MO,WE" and "WE,MO" are
		// one rule.
		$parts = array( 'FREQ=' . $rule['FREQ'] );
		if ( $rule['INTERVAL'] > 1 ) {
			$parts[] = 'INTERVAL=' . $rule['INTERVAL'];
		}
		if ( $rule['BYMONTH'] ) {
			$months = $rule['BYMONTH'];
			sort( $months, SORT_NUMERIC );
			$parts[] = 'BYMONTH=' . implode( ',', $months );
		}
		if ( $rule['BYMONTHDAY'] ) {
			$days = $rule['BYMONTHDAY'];
			sort( $days, SORT_NUMERIC );
			$parts[] = 'BYMONTHDAY=' . implode( ',', $days );
		}
		if ( $rule['BYDAY'] ) {
			$by_day = $rule['BYDAY'];
			usort(
				$by_day,
				static function ( $a, $b ) {
					if ( $a['ord'] !== $b['ord'] ) {
						return $a['ord'] <=> $b['ord'];
					}
					return array_search( $a['day'], self::DAYS, true ) <=> array_search( $b['day'], self::DAYS, true );
				}
			);
			$parts[] = 'BYDAY=' . implode(
				',',
				array_map(
					static fn( $d ) => ( 0 !== $d['ord'] ? $d['ord'] : '' ) . $d['day'],
					$by_day
				)
			);
		}
		if ( $rule['COUNT'] > 0 ) {
			$parts[] = 'COUNT=' . $rule['COUNT'];
		} elseif ( '' !== $rule['UNTIL'] ) {
			$parts[] = 'UNTIL=' . $rule['UNTIL'];
		}

		return implode( ';', $parts );
	}

	/**
	 * End a rule on a given date, replacing any COUNT or UNTIL it had.
	 *
	 * @param string $rrule RRULE.
	 * @param string $until Last allowed date as "Ymd".
	 * @return string
	 */
	public static function with_until( string $rrule, string $until ): string {
		$parts = array_filter(
			explode( ';', self::normalize( $rrule ) ),
			static fn( $part ) => 0 !== strpos( $part, 'COUNT=' ) && 0 !== strpos( $part, 'UNTIL=' )
		);
		if ( ! $parts ) {
			return '';
		}
		$parts[] = 'UNTIL=' . $until;
		return implode( ';', $parts );
	}

	/**
	 * Expand a rule into start times.
	 *
	 * @param DateTimeImmutable      $start      First occurrence, in the event's time zone.
	 * @param string                 $rrule      RRULE.
	 * @param DateTimeImmutable|null $window_end Do not generate past this moment.
	 * @param int                    $limit      Maximum number of occurrences.
	 * @return DateTimeImmutable[]
	 */
	public static function expand( DateTimeImmutable $start, string $rrule, ?DateTimeImmutable $window_end = null, int $limit = 1000 ): array {
		$rule = self::parse( $rrule );
		if ( '' === $rule['FREQ'] ) {
			return array( $start );
		}

		$tz    = $start->getTimezone();
		$until = self::parse_until( $rule['UNTIL'], $tz );
		if ( $window_end && ( ! $until || $window_end < $until ) ) {
			$hard_stop = $window_end;
		} else {
			$hard_stop = $until;
		}
		if ( $rule['COUNT'] > 0 ) {
			$limit = min( $limit, $rule['COUNT'] );
		}

		$results = array( $start );
		$seen    = array( $start->format( 'Y-m-d H:i' ) => true );
		$hour    = (int) $start->format( 'G' );
		$minute  = (int) $start->format( 'i' );

		$found = count( $results );
		for ( $n = 0; $n < 6000 && $found < $limit; $n++ ) {
			$step       = $n * $rule['INTERVAL'];
			$candidates = self::period_candidates( $rule, $start, $step, $hour, $minute );
			$period_min = self::period_start( $rule['FREQ'], $start, $step );

			if ( $hard_stop && $period_min > $hard_stop ) {
				break;
			}

			foreach ( $candidates as $candidate ) {
				if ( $candidate <= $start ) {
					continue;
				}
				if ( $hard_stop && $candidate > $hard_stop ) {
					break 2;
				}
				$key = $candidate->format( 'Y-m-d H:i' );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$results[]    = $candidate;
				if ( count( $results ) >= $limit ) {
					break 2;
				}
			}
		}

		return $results;
	}

	/**
	 * Parse an UNTIL value.
	 *
	 * @param string       $until Raw UNTIL.
	 * @param DateTimeZone $tz    Event time zone.
	 * @return DateTimeImmutable|null
	 */
	private static function parse_until( string $until, DateTimeZone $tz ): ?DateTimeImmutable {
		if ( '' === $until ) {
			return null;
		}
		if ( 8 === strlen( $until ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Ymd', $until, $tz );
			return $date ? $date->setTime( 23, 59, 59 ) : null;
		}
		if ( 'Z' === substr( $until, -1 ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Ymd\THis\Z', $until, new DateTimeZone( 'UTC' ) );
			return $date ? $date : null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Ymd\THis', $until, $tz );
		return $date ? $date : null;
	}

	/**
	 * First moment of the nth period, used to know when to stop.
	 *
	 * @param string            $freq  Frequency.
	 * @param DateTimeImmutable $start Series start.
	 * @param int               $step  Periods from the start.
	 * @return DateTimeImmutable
	 */
	private static function period_start( string $freq, DateTimeImmutable $start, int $step ): DateTimeImmutable {
		$day = $start->setTime( 0, 0, 0 );
		switch ( $freq ) {
			case 'DAILY':
				return $day->modify( "+{$step} days" );
			case 'WEEKLY':
				$monday = $day->modify( '-' . ( (int) $day->format( 'N' ) - 1 ) . ' days' );
				return $monday->modify( "+{$step} weeks" );
			case 'MONTHLY':
				return $day->modify( 'first day of this month' )->modify( "+{$step} months" );
			default:
				return $day->setDate( (int) $day->format( 'Y' ) + $step, 1, 1 );
		}
	}

	/**
	 * Candidate start times inside the nth period, in order.
	 *
	 * @param array<string, mixed> $rule   Parsed rule.
	 * @param DateTimeImmutable    $start  Series start.
	 * @param int                  $step   Periods from the start.
	 * @param int                  $hour   Wall-clock hour.
	 * @param int                  $minute Wall-clock minute.
	 * @return DateTimeImmutable[]
	 */
	private static function period_candidates( array $rule, DateTimeImmutable $start, int $step, int $hour, int $minute ): array {
		$base = self::period_start( $rule['FREQ'], $start, $step );
		$out  = array();

		switch ( $rule['FREQ'] ) {
			case 'DAILY':
				if ( $rule['BYDAY'] ) {
					$allowed = array_column( $rule['BYDAY'], 'day' );
					if ( ! in_array( self::DAYS[ (int) $base->format( 'N' ) - 1 ], $allowed, true ) ) {
						break;
					}
				}
				$out[] = $base->setTime( $hour, $minute );
				break;

			case 'WEEKLY':
				$days = $rule['BYDAY'] ? array_column( $rule['BYDAY'], 'day' ) : array( self::DAYS[ (int) $start->format( 'N' ) - 1 ] );
				foreach ( self::DAYS as $offset => $code ) {
					if ( in_array( $code, $days, true ) ) {
						$out[] = $base->modify( "+{$offset} days" )->setTime( $hour, $minute );
					}
				}
				break;

			case 'MONTHLY':
				$out = self::month_candidates( $rule, $start, (int) $base->format( 'Y' ), (int) $base->format( 'n' ), $hour, $minute );
				break;

			case 'YEARLY':
				$year   = (int) $base->format( 'Y' );
				$months = $rule['BYMONTH'] ? $rule['BYMONTH'] : array( (int) $start->format( 'n' ) );
				sort( $months );
				foreach ( $months as $month ) {
					$out = array_merge( $out, self::month_candidates( $rule, $start, $year, $month, $hour, $minute ) );
				}
				break;
		}

		return $out;
	}

	/**
	 * Candidate start times inside one calendar month.
	 *
	 * @param array<string, mixed> $rule   Parsed rule.
	 * @param DateTimeImmutable    $start  Series start.
	 * @param int                  $year   Year.
	 * @param int                  $month  Month.
	 * @param int                  $hour   Wall-clock hour.
	 * @param int                  $minute Wall-clock minute.
	 * @return DateTimeImmutable[]
	 */
	private static function month_candidates( array $rule, DateTimeImmutable $start, int $year, int $month, int $hour, int $minute ): array {
		$days_in_month = (int) $start->setDate( $year, $month, 1 )->format( 't' );
		$days          = array();

		if ( $rule['BYMONTHDAY'] ) {
			foreach ( $rule['BYMONTHDAY'] as $day ) {
				$resolved = $day > 0 ? $day : $days_in_month + $day + 1;
				if ( $resolved >= 1 && $resolved <= $days_in_month ) {
					$days[] = $resolved;
				}
			}
		} elseif ( $rule['BYDAY'] ) {
			foreach ( $rule['BYDAY'] as $by_day ) {
				$weekday = array_search( $by_day['day'], self::DAYS, true ) + 1;
				$matches = array();
				for ( $d = 1; $d <= $days_in_month; $d++ ) {
					if ( (int) $start->setDate( $year, $month, $d )->format( 'N' ) === $weekday ) {
						$matches[] = $d;
					}
				}
				if ( 0 === $by_day['ord'] ) {
					$days = array_merge( $days, $matches );
				} elseif ( $by_day['ord'] > 0 && isset( $matches[ $by_day['ord'] - 1 ] ) ) {
					$days[] = $matches[ $by_day['ord'] - 1 ];
				} elseif ( $by_day['ord'] < 0 && isset( $matches[ count( $matches ) + $by_day['ord'] ] ) ) {
					$days[] = $matches[ count( $matches ) + $by_day['ord'] ];
				}
			}
		} else {
			$day = (int) $start->format( 'j' );
			if ( $day <= $days_in_month ) {
				$days[] = $day;
			}
		}

		$days = array_unique( $days );
		sort( $days );

		return array_map(
			static fn( $d ) => $start->setDate( $year, $month, $d )->setTime( $hour, $minute ),
			$days
		);
	}

	/**
	 * Describe a rule in plain language.
	 *
	 * @param string            $rrule RRULE.
	 * @param DateTimeImmutable $start Series start.
	 * @return string
	 */
	public static function describe( string $rrule, DateTimeImmutable $start ): string {
		$rule = self::parse( $rrule );
		if ( '' === $rule['FREQ'] ) {
			return __( 'Does not repeat', 'rayetun-events-calendar' );
		}

		$names = array(
			'MO' => __( 'Monday', 'rayetun-events-calendar' ),
			'TU' => __( 'Tuesday', 'rayetun-events-calendar' ),
			'WE' => __( 'Wednesday', 'rayetun-events-calendar' ),
			'TH' => __( 'Thursday', 'rayetun-events-calendar' ),
			'FR' => __( 'Friday', 'rayetun-events-calendar' ),
			'SA' => __( 'Saturday', 'rayetun-events-calendar' ),
			'SU' => __( 'Sunday', 'rayetun-events-calendar' ),
		);
		$ords  = array(
			1  => __( 'first', 'rayetun-events-calendar' ),
			2  => __( 'second', 'rayetun-events-calendar' ),
			3  => __( 'third', 'rayetun-events-calendar' ),
			4  => __( 'fourth', 'rayetun-events-calendar' ),
			5  => __( 'fifth', 'rayetun-events-calendar' ),
			-1 => __( 'last', 'rayetun-events-calendar' ),
		);

		$interval = $rule['INTERVAL'];
		switch ( $rule['FREQ'] ) {
			case 'DAILY':
				/* translators: %d: number of days. */
				$text = 1 === $interval ? __( 'Every day', 'rayetun-events-calendar' ) : sprintf( __( 'Every %d days', 'rayetun-events-calendar' ), $interval );
				break;
			case 'WEEKLY':
				/* translators: %d: number of weeks. */
				$text = 1 === $interval ? __( 'Every week', 'rayetun-events-calendar' ) : sprintf( __( 'Every %d weeks', 'rayetun-events-calendar' ), $interval );
				break;
			case 'MONTHLY':
				/* translators: %d: number of months. */
				$text = 1 === $interval ? __( 'Every month', 'rayetun-events-calendar' ) : sprintf( __( 'Every %d months', 'rayetun-events-calendar' ), $interval );
				break;
			default:
				/* translators: %d: number of years. */
				$text = 1 === $interval ? __( 'Every year', 'rayetun-events-calendar' ) : sprintf( __( 'Every %d years', 'rayetun-events-calendar' ), $interval );
		}

		if ( $rule['BYDAY'] ) {
			$labels = array();
			foreach ( $rule['BYDAY'] as $by_day ) {
				$label = $names[ $by_day['day'] ];
				if ( 0 !== $by_day['ord'] && isset( $ords[ $by_day['ord'] ] ) ) {
					/* translators: 1: ordinal such as "second", 2: weekday name. */
					$label = sprintf( __( 'the %1$s %2$s', 'rayetun-events-calendar' ), $ords[ $by_day['ord'] ], $label );
				}
				$labels[] = $label;
			}
			/* translators: 1: frequency such as "Every week", 2: list of weekdays. */
			$text = sprintf( __( '%1$s on %2$s', 'rayetun-events-calendar' ), $text, wp_sprintf( '%l', $labels ) );
		} elseif ( $rule['BYMONTHDAY'] ) {
			/* translators: 1: frequency such as "Every month", 2: list of day numbers. */
			$text = sprintf( __( '%1$s on day %2$s', 'rayetun-events-calendar' ), $text, wp_sprintf( '%l', array_map( 'strval', $rule['BYMONTHDAY'] ) ) );
		}

		if ( $rule['COUNT'] > 0 ) {
			/* translators: 1: recurrence description, 2: number of occurrences. */
			$text = sprintf( __( '%1$s, %2$d times', 'rayetun-events-calendar' ), $text, $rule['COUNT'] );
		} else {
			$until = self::parse_until( $rule['UNTIL'], $start->getTimezone() );
			if ( $until ) {
				/* translators: 1: recurrence description, 2: end date. */
				$text = sprintf( __( '%1$s until %2$s', 'rayetun-events-calendar' ), $text, wp_date( get_option( 'date_format' ), $until->getTimestamp(), $start->getTimezone() ) );
			}
		}

		return $text;
	}
}
