<?php
/**
 * Bundled public-holiday rules.
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
 * Works out nationwide public holidays for a year from fixed rules.
 *
 * Nothing is fetched from anywhere. Only holidays observed across a whole
 * country are listed; regional and one-off holidays are left for the site
 * owner to add by hand, and the list is always shown for checking before it
 * is saved.
 */
class Holidays {

	/**
	 * Countries with bundled rules.
	 *
	 * @return array<string, string> code => name.
	 */
	public static function countries(): array {
		return array(
			'AU' => __( 'Australia (national)', 'rayetun-events-calendar' ),
			'CA' => __( 'Canada (national)', 'rayetun-events-calendar' ),
			'FR' => __( 'France', 'rayetun-events-calendar' ),
			'DE' => __( 'Germany (nationwide)', 'rayetun-events-calendar' ),
			'IE' => __( 'Ireland', 'rayetun-events-calendar' ),
			'GB' => __( 'United Kingdom (England and Wales)', 'rayetun-events-calendar' ),
			'US' => __( 'United States (federal)', 'rayetun-events-calendar' ),
		);
	}

	/**
	 * Holidays for one country and year.
	 *
	 * @param string $country Country code.
	 * @param int    $year    Year.
	 * @return array<int, array{date: string, label: string}>
	 */
	public static function for_year( string $country, int $year ): array {
		if ( $year < 1970 || $year > 2100 || ! isset( self::countries()[ $country ] ) ) {
			return array();
		}
		$easter = self::easter( $year );
		$day    = static fn( int $month, int $date ) => self::date( $year, $month, $date );
		$list   = array();
		$add    = static function ( DateTimeImmutable $date, string $label ) use ( &$list ) {
			$list[ $date->format( 'Y-m-d' ) ] = $label;
		};

		$new_year  = __( 'New Year’s Day', 'rayetun-events-calendar' );
		$good_fri  = __( 'Good Friday', 'rayetun-events-calendar' );
		$easter_m  = __( 'Easter Monday', 'rayetun-events-calendar' );
		$christmas = __( 'Christmas Day', 'rayetun-events-calendar' );
		$boxing    = __( 'Boxing Day', 'rayetun-events-calendar' );
		$labour    = __( 'Labour Day', 'rayetun-events-calendar' );
		$ascension = __( 'Ascension Day', 'rayetun-events-calendar' );
		$whit      = __( 'Whit Monday', 'rayetun-events-calendar' );

		switch ( $country ) {
			case 'US':
				$fixed = array(
					array( $day( 1, 1 ), $new_year ),
					array( $day( 6, 19 ), __( 'Juneteenth', 'rayetun-events-calendar' ) ),
					array( $day( 7, 4 ), __( 'Independence Day', 'rayetun-events-calendar' ) ),
					array( $day( 11, 11 ), __( 'Veterans Day', 'rayetun-events-calendar' ) ),
					array( $day( 12, 25 ), $christmas ),
				);
				foreach ( $fixed as $holiday ) {
					$add( $holiday[0], $holiday[1] );
					// Federal offices close on the nearest weekday when the date is at a weekend.
					$weekday = (int) $holiday[0]->format( 'N' );
					if ( $weekday >= 6 ) {
						/* translators: %s: holiday name. */
						$add( $holiday[0]->modify( 6 === $weekday ? '-1 day' : '+1 day' ), sprintf( __( '%s (observed)', 'rayetun-events-calendar' ), $holiday[1] ) );
					}
				}
				$add( self::nth( $year, 1, 1, 3 ), __( 'Martin Luther King Jr. Day', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 2, 1, 3 ), __( 'Presidents’ Day', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 5, 1, -1 ), __( 'Memorial Day', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 9, 1, 1 ), __( 'Labor Day', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 10, 1, 2 ), __( 'Columbus Day', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 11, 4, 4 ), __( 'Thanksgiving Day', 'rayetun-events-calendar' ) );
				break;

			case 'GB':
				/* translators: %s: holiday name. */
				$substitute = __( '%s (substitute day)', 'rayetun-events-calendar' );
				$add( $day( 1, 1 ), $new_year );
				$weekday = (int) $day( 1, 1 )->format( 'N' );
				if ( $weekday >= 6 ) {
					$add( $day( 1, 1 )->modify( 6 === $weekday ? '+2 days' : '+1 day' ), sprintf( $substitute, $new_year ) );
				}
				$add( $easter->modify( '-2 days' ), $good_fri );
				$add( $easter->modify( '+1 day' ), $easter_m );
				$add( self::nth( $year, 5, 1, 1 ), __( 'Early May bank holiday', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 5, 1, -1 ), __( 'Spring bank holiday', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 8, 1, -1 ), __( 'Summer bank holiday', 'rayetun-events-calendar' ) );
				foreach ( array( array( $day( 12, 25 ), $christmas ), array( $day( 12, 26 ), $boxing ) ) as $holiday ) {
					$add( $holiday[0], $holiday[1] );
					if ( (int) $holiday[0]->format( 'N' ) >= 6 ) {
						$add( $holiday[0]->modify( '+2 days' ), sprintf( $substitute, $holiday[1] ) );
					}
				}
				break;

			case 'IE':
				$add( $day( 1, 1 ), $new_year );
				// St Brigid's Day: the first Monday in February, or 1 February when that is a Friday.
				$add( 5 === (int) $day( 2, 1 )->format( 'N' ) ? $day( 2, 1 ) : self::nth( $year, 2, 1, 1 ), __( 'St Brigid’s Day', 'rayetun-events-calendar' ) );
				$add( $day( 3, 17 ), __( 'St Patrick’s Day', 'rayetun-events-calendar' ) );
				$add( $easter->modify( '+1 day' ), $easter_m );
				$add( self::nth( $year, 5, 1, 1 ), __( 'May bank holiday', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 6, 1, 1 ), __( 'June bank holiday', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 8, 1, 1 ), __( 'August bank holiday', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 10, 1, -1 ), __( 'October bank holiday', 'rayetun-events-calendar' ) );
				$add( $day( 12, 25 ), $christmas );
				$add( $day( 12, 26 ), __( 'St Stephen’s Day', 'rayetun-events-calendar' ) );
				break;

			case 'CA':
				$add( $day( 1, 1 ), $new_year );
				$add( $easter->modify( '-2 days' ), $good_fri );
				// Victoria Day: the Monday before 25 May.
				$add( $day( 5, 25 )->modify( 'last monday' ), __( 'Victoria Day', 'rayetun-events-calendar' ) );
				$add( $day( 7, 1 ), __( 'Canada Day', 'rayetun-events-calendar' ) );
				$add( self::nth( $year, 9, 1, 1 ), $labour );
				$add( self::nth( $year, 10, 1, 2 ), __( 'Thanksgiving', 'rayetun-events-calendar' ) );
				$add( $day( 12, 25 ), $christmas );
				break;

			case 'AU':
				$add( $day( 1, 1 ), $new_year );
				$add( $day( 1, 26 ), __( 'Australia Day', 'rayetun-events-calendar' ) );
				$add( $easter->modify( '-2 days' ), $good_fri );
				$add( $easter->modify( '+1 day' ), $easter_m );
				$add( $day( 4, 25 ), __( 'Anzac Day', 'rayetun-events-calendar' ) );
				$add( $day( 12, 25 ), $christmas );
				$add( $day( 12, 26 ), $boxing );
				break;

			case 'DE':
				$add( $day( 1, 1 ), $new_year );
				$add( $easter->modify( '-2 days' ), $good_fri );
				$add( $easter->modify( '+1 day' ), $easter_m );
				$add( $day( 5, 1 ), $labour );
				$add( $easter->modify( '+39 days' ), $ascension );
				$add( $easter->modify( '+50 days' ), $whit );
				$add( $day( 10, 3 ), __( 'German Unity Day', 'rayetun-events-calendar' ) );
				$add( $day( 12, 25 ), $christmas );
				$add( $day( 12, 26 ), __( 'Second Day of Christmas', 'rayetun-events-calendar' ) );
				break;

			case 'FR':
				$add( $day( 1, 1 ), $new_year );
				$add( $easter->modify( '+1 day' ), $easter_m );
				$add( $day( 5, 1 ), $labour );
				$add( $day( 5, 8 ), __( 'Victory in Europe Day', 'rayetun-events-calendar' ) );
				$add( $easter->modify( '+39 days' ), $ascension );
				$add( $easter->modify( '+50 days' ), $whit );
				$add( $day( 7, 14 ), __( 'Bastille Day', 'rayetun-events-calendar' ) );
				$add( $day( 8, 15 ), __( 'Assumption Day', 'rayetun-events-calendar' ) );
				$add( $day( 11, 1 ), __( 'All Saints’ Day', 'rayetun-events-calendar' ) );
				$add( $day( 11, 11 ), __( 'Armistice Day', 'rayetun-events-calendar' ) );
				$add( $day( 12, 25 ), $christmas );
				break;
		}

		ksort( $list );
		$out = array();
		foreach ( $list as $date => $label ) {
			$out[] = array(
				'date'  => $date,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * A calendar date at midnight UTC.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @param int $date  Day of the month.
	 * @return DateTimeImmutable
	 */
	private static function date( int $year, int $month, int $date ): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setDate( $year, $month, $date )->setTime( 0, 0 );
	}

	/**
	 * The nth weekday of a month; -1 means the last one.
	 *
	 * @param int $year    Year.
	 * @param int $month   Month.
	 * @param int $weekday 1 (Monday) to 7 (Sunday).
	 * @param int $nth     1 to 5, or -1 for the last.
	 * @return DateTimeImmutable
	 */
	private static function nth( int $year, int $month, int $weekday, int $nth ): DateTimeImmutable {
		if ( $nth < 0 ) {
			$last = self::date( $year, $month, 1 )->modify( 'last day of this month' );
			return $last->modify( '-' . ( ( (int) $last->format( 'N' ) - $weekday + 7 ) % 7 ) . ' days' );
		}
		$first = self::date( $year, $month, 1 );
		return $first->modify( '+' . ( ( $weekday - (int) $first->format( 'N' ) + 7 ) % 7 + 7 * ( $nth - 1 ) ) . ' days' );
	}

	/**
	 * Easter Sunday in the Gregorian calendar (the "anonymous" algorithm).
	 *
	 * @param int $year Year.
	 * @return DateTimeImmutable
	 */
	private static function easter( int $year ): DateTimeImmutable {
		$a     = $year % 19;
		$b     = intdiv( $year, 100 );
		$c     = $year % 100;
		$d     = intdiv( $b, 4 );
		$e     = $b % 4;
		$f     = intdiv( $b + 8, 25 );
		$g     = intdiv( $b - $f + 1, 3 );
		$h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i     = intdiv( $c, 4 );
		$k     = $c % 4;
		$l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m     = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$date  = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
		return self::date( $year, $month, $date );
	}
}
