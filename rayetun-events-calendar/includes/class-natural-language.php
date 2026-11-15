<?php
/**
 * Plain-language scheduling.
 *
 * Turns "Yoga every Tue and Thu at 6pm until June" into a title, a start, an
 * end and an RRULE. English phrases only in this version.
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
 * Sentence parser.
 */
class Natural_Language {

	const DAY   = '(?:mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)s?';
	const MONTH = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
	const TIME  = '(?:\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)?|noon|midday|midnight)';

	/**
	 * Working copy of the sentence; matched phrases are cut out of it.
	 *
	 * @var string
	 */
	private string $text;

	/**
	 * Parse a sentence.
	 *
	 * @param string                 $text Sentence.
	 * @param DateTimeZone           $tz   Time zone to interpret it in.
	 * @param DateTimeImmutable|null $now  Reference "now" (for tests).
	 * @return array<string, mixed>
	 */
	public static function parse( string $text, DateTimeZone $tz, ?DateTimeImmutable $now = null ): array {
		$parser = new self();
		return $parser->run( $text, $tz, $now ? $now->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz ) );
	}

	/**
	 * Cut the first match of a pattern out of the working text.
	 *
	 * @param string $pattern Regex without delimiters.
	 * @return array<int, string>|null Match groups, or null.
	 */
	private function take( string $pattern ): ?array {
		if ( ! preg_match( '/' . $pattern . '/iu', $this->text, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$this->text = substr( $this->text, 0, $m[0][1] ) . ' | ' . substr( $this->text, $m[0][1] + strlen( $m[0][0] ) );
		return array_map( static fn( $group ) => is_array( $group ) ? (string) $group[0] : '', $m );
	}

	/**
	 * Run the parser.
	 *
	 * @param string            $text Sentence.
	 * @param DateTimeZone      $tz   Time zone.
	 * @param DateTimeImmutable $now  Now.
	 * @return array<string, mixed>
	 */
	private function run( string $text, DateTimeZone $tz, DateTimeImmutable $now ): array {
		$this->text = ' ' . trim( wp_strip_all_tags( $text ) ) . ' ';
		$notes      = array();
		$today      = $now->setTime( 0, 0, 0 );

		// Holiday skips are understood but not applied yet.
		if ( $this->take( ',?\s*\b(?:skip(?:ping)?|except|excluding)\s+(?:all\s+)?(?:public\s+|bank\s+)?holidays\b' ) ) {
			$skip_closed = true;
			$notes[]     = Plugin::closed_dates()
				? __( 'The closed dates listed in Settings will be skipped.', 'rayetun-events-calendar' )
				: __( 'Add your public holidays under Settings → Closed dates and this series will skip them.', 'rayetun-events-calendar' );
		}

		// Times.
		$start_time = null;
		$end_time   = null;
		$all_day    = (bool) $this->take( '\ball[\s-]day\b' );

		$backup = $this->text;
		$m      = $this->take( '\b(?:from\s+)?(' . self::TIME . ')\s*(?:-|–|—|to|until|till)\s*(' . self::TIME . ')(?![\w:])' );
		if ( $m && preg_match( '/[apn:]|mid/i', $m[1] . $m[2] ) ) {
			$end_time   = self::parse_time( $m[2], null );
			$start_time = self::parse_time( $m[1], $end_time );
		} elseif ( $m ) {
			// Two bare numbers ("3 to 5") are not a time range; put them back.
			$this->text = $backup;
		}
		if ( null === $start_time ) {
			$m = $this->take( '\b(?:at|@)\s*(' . self::TIME . ')(?![\w:])' );
			if ( ! $m ) {
				$m = $this->take( '(?<![\w:])(\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)|(?:[01]?\d|2[0-3]):[0-5]\d|noon|midday|midnight)(?![\w:])' );
			}
			if ( $m ) {
				$start_time = self::parse_time( $m[1], null );
			}
		}

		// End of the series.
		$until = null;
		$m     = $this->take( '\b(?:until|till|through|thru|ending(?:\s+on)?)\s+(' . self::date_pattern() . ')' );
		if ( $m ) {
			$until = self::parse_date( $m[1], $today, true );
		}
		$count      = 0;
		$count_unit = '';
		$m          = $this->take( '\bfor\s+(\d{1,3})\s+(weeks?|sessions?|times?|occurrences?|classes|days?|months?|years?)\b' );
		if ( $m ) {
			$count      = (int) $m[1];
			$count_unit = strtolower( $m[2] );
		}

		// Recurrence.
		$freq     = '';
		$interval = 1;
		$by_day   = array();
		$by_mday  = 0;

		$ordinals = array(
			'first'  => 1,
			'1st'    => 1,
			'second' => 2,
			'2nd'    => 2,
			'third'  => 3,
			'3rd'    => 3,
			'fourth' => 4,
			'4th'    => 4,
			'last'   => -1,
		);

		$m = $this->take( '\b(?:on\s+)?(?:every\s+|each\s+)?(?:the\s+)?(first|1st|second|2nd|third|3rd|fourth|4th|last)\s+(' . self::DAY . ')\s+(?:of\s+)?(?:every|each|the)\s+month\b' );
		if ( $m ) {
			$freq   = 'MONTHLY';
			$by_day = array( $ordinals[ strtolower( $m[1] ) ] . self::day_code( $m[2] ) );
		}
		if ( '' === $freq ) {
			$m = $this->take( '\b(?:on\s+)?the\s+(\d{1,2})(?:st|nd|rd|th)?\s+(?:day\s+)?of\s+(?:every|each)\s+month\b' );
			if ( $m ) {
				$freq    = 'MONTHLY';
				$by_mday = max( 1, min( 31, (int) $m[1] ) );
			}
		}
		if ( '' === $freq ) {
			$m = $this->take( '\b(?:every|each)\s+(?:other|second|2nd)\s+(day|week|month|year)\b' );
			if ( $m ) {
				$freq     = self::unit_freq( $m[1] );
				$interval = 2;
			}
		}
		if ( '' === $freq ) {
			$m = $this->take( '\b(?:every|each)\s+(\d{1,2})\s+(days?|weeks?|months?|years?)\b' );
			if ( $m ) {
				$freq     = self::unit_freq( $m[2] );
				$interval = max( 1, (int) $m[1] );
			}
		}
		if ( '' === $freq && $this->take( '\b(?:(?:every|each)\s+weekday|(?:on\s+)?weekdays)\b' ) ) {
			$freq   = 'WEEKLY';
			$by_day = array( 'MO', 'TU', 'WE', 'TH', 'FR' );
		}
		if ( '' === $freq && $this->take( '\b(?:fortnightly|bi-?weekly)\b' ) ) {
			$freq     = 'WEEKLY';
			$interval = 2;
		}
		if ( '' === $freq ) {
			$m = $this->take( '\b(daily|weekly|monthly|yearly|annually|(?:every|each)\s+(?:day|week|month|year))\b' );
			if ( $m ) {
				$word = strtolower( $m[1] );
				if ( false !== strpos( $word, 'da' ) ) {
					$freq = 'DAILY';
				} elseif ( false !== strpos( $word, 'week' ) ) {
					$freq = 'WEEKLY';
				} elseif ( false !== strpos( $word, 'month' ) ) {
					$freq = 'MONTHLY';
				} else {
					$freq = 'YEARLY';
				}
			}
		}

		// Weekday lists: "every Tue and Thu", "on Tuesdays".
		$day_list = self::DAY . '(?:\s*(?:,|&|and|\s)\s*(?:and\s+)?' . self::DAY . ')*';
		$m        = $this->take( '\b(?:every|each)\s+(' . $day_list . ')\b' );
		if ( ! $m && in_array( $freq, array( 'WEEKLY', '' ), true ) ) {
			$m = $this->take( '\b(?:on\s+)?((?:' . self::DAY . ')(?<=s)(?:\s*(?:,|&|and)\s*(?:' . self::DAY . '))*)\b' );
			if ( ! $m && 'WEEKLY' === $freq ) {
				$m = $this->take( '\bon\s+(' . $day_list . ')\b' );
			}
		}
		if ( $m && ( '' === $freq || 'WEEKLY' === $freq ) ) {
			preg_match_all( '/' . self::DAY . '/i', $m[1], $found );
			foreach ( $found[0] as $token ) {
				$by_day[] = self::day_code( $token );
			}
			$by_day = array_values( array_unique( $by_day ) );
			$freq   = 'WEEKLY';
		}

		// Start date.
		$start_date = null;
		$m          = $this->take( '\b(?:starting|beginning|begins|starts|from|on)?\s*(?:on\s+)?(' . self::date_pattern( false ) . ')' );
		if ( $m ) {
			$start_date = self::parse_date( $m[1], $today, false );
		}
		if ( ! $start_date ) {
			if ( $this->take( '\b(?:starting\s+)?tomorrow\b' ) ) {
				$start_date = $today->modify( '+1 day' );
			} elseif ( $this->take( '\b(?:starting\s+)?(?:today|tonight)\b' ) ) {
				$start_date = $today;
			} else {
				$m = $this->take( '\b(?:starting\s+)?(?:on\s+)?(next|this)?\s*(' . self::DAY . ')\b' );
				if ( $m ) {
					$start_date = self::next_weekday( $today, self::day_code( $m[2] ), 'next' === strtolower( $m[1] ) );
				}
			}
		}

		// A bare month after "until" was consumed above; a second month-only phrase is the start.
		if ( ! $start_date ) {
			$start_date = $today;
			if ( '' === $freq && null !== $start_time ) {
				$candidate = $today->setTime( (int) floor( $start_time / 60 ), $start_time % 60 );
				if ( $candidate < $now ) {
					$start_date = $today->modify( '+1 day' );
				}
			}
		}

		// Move the start forward to the first day the rule actually lands on.
		if ( 'WEEKLY' === $freq && $by_day ) {
			for ( $i = 0; $i < 7; $i++ ) {
				if ( in_array( Recurrence::DAYS[ (int) $start_date->format( 'N' ) - 1 ], $by_day, true ) ) {
					break;
				}
				$start_date = $start_date->modify( '+1 day' );
			}
		}

		if ( in_array( $freq, array( 'MONTHLY', 'YEARLY' ), true ) && ( $by_day || $by_mday ) ) {
			$probe_rule = 'FREQ=' . $freq . ( $by_mday ? ';BYMONTHDAY=' . $by_mday : '' ) . ( $by_day ? ';BYDAY=' . implode( ',', $by_day ) : '' );
			$probe      = Recurrence::expand( $start_date->modify( '-1 day' ), $probe_rule, null, 2 );
			if ( isset( $probe[1] ) ) {
				$start_date = $probe[1]->setTime( 0, 0 );
			}
		}

		if ( null === $start_time ) {
			$all_day = true;
		}
		if ( $all_day ) {
			$start = $start_date;
			$end   = $start_date->setTime( 23, 59 );
		} else {
			$start = $start_date->setTime( (int) floor( $start_time / 60 ), $start_time % 60 );
			if ( null !== $end_time ) {
				$end = $start_date->setTime( (int) floor( $end_time / 60 ), $end_time % 60 );
				if ( $end <= $start ) {
					$end = $end->modify( '+1 day' );
				}
			} else {
				$end = $start->modify( '+1 hour' );
			}
		}

		// Build the rule.
		$rrule = '';
		if ( '' !== $freq ) {
			$parts = array( 'FREQ=' . $freq );
			if ( $interval > 1 ) {
				$parts[] = 'INTERVAL=' . $interval;
			}
			if ( $by_mday ) {
				$parts[] = 'BYMONTHDAY=' . $by_mday;
			}
			if ( $by_day ) {
				$parts[] = 'BYDAY=' . implode( ',', $by_day );
			}
			if ( $count > 0 ) {
				$per_period = ( 'WEEKLY' === $freq && $by_day ) ? count( $by_day ) : 1;
				$is_period  = (bool) preg_match( '/^(week|day|month|year)/', $count_unit );
				$parts[]    = 'COUNT=' . ( $is_period ? (int) ceil( $count / $interval ) * $per_period : $count );
			} elseif ( $until ) {
				$parts[] = 'UNTIL=' . $until->format( 'Ymd' );
			}
			$rrule = Recurrence::normalize( implode( ';', $parts ) );
		}

		// Whatever was not understood as scheduling is the title.
		$segments = array_filter( array_map( array( __CLASS__, 'clean_title' ), explode( '|', $this->text ) ) );
		$title    = $segments ? (string) reset( $segments ) : '';

		return array(
			'title'       => $title,
			'start'       => $start->format( 'Y-m-d\TH:i' ),
			'end'         => $end->format( 'Y-m-d\TH:i' ),
			'all_day'     => $all_day,
			'rrule'       => $rrule,
			'skip_closed' => ! empty( $skip_closed ) && '' !== $rrule,
			'notes'       => $notes,
			'understood'  => '' !== $title || '' !== $rrule || null !== $start_time,
		);
	}

	/**
	 * Regex for a date phrase.
	 *
	 * @param bool $allow_month_only Whether "June" alone counts.
	 * @return string
	 */
	private static function date_pattern( bool $allow_month_only = true ): string {
		$pattern = '\d{4}-\d{2}-\d{2}'
			. '|' . self::MONTH . '\.?\s+\d{1,2}(?:st|nd|rd|th)?(?!\d|:)(?:,?\s+\d{4})?'
			. '|\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?' . self::MONTH . '\b\.?(?:,?\s+\d{4})?';
		if ( $allow_month_only ) {
			$pattern .= '|' . self::MONTH . '\b(?:\s+\d{4})?';
		}
		return '(?:' . $pattern . ')';
	}

	/**
	 * Parse a date phrase.
	 *
	 * @param string            $phrase       Date phrase.
	 * @param DateTimeImmutable $today        Today at midnight.
	 * @param bool              $end_of_month Use the last day when only a month is given.
	 * @return DateTimeImmutable|null
	 */
	private static function parse_date( string $phrase, DateTimeImmutable $today, bool $end_of_month ): ?DateTimeImmutable {
		$phrase = strtolower( trim( $phrase ) );

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $phrase, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $today->setDate( (int) $m[1], (int) $m[2], (int) $m[3] );
		}

		if ( ! preg_match( '/(' . self::MONTH . ')/i', $phrase, $mm ) ) {
			return null;
		}
		$months = array( 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec' );
		$month  = array_search( substr( $mm[1], 0, 3 ), $months, true ) + 1;
		$rest   = str_replace( $mm[1], ' ', $phrase );
		$year   = preg_match( '/\b(\d{4})\b/', $rest, $ym ) ? (int) $ym[1] : 0;
		$rest   = $year ? str_replace( (string) $year, ' ', $rest ) : $rest;
		$day    = preg_match( '/\b(\d{1,2})/', $rest, $dm ) ? (int) $dm[1] : 0;

		$guess_year = $year ? $year : (int) $today->format( 'Y' );
		if ( 0 === $day ) {
			$day = $end_of_month ? (int) $today->setDate( $guess_year, $month, 1 )->format( 't' ) : 1;
			if ( ! $year && $today->setDate( $guess_year, $month, (int) $today->setDate( $guess_year, $month, 1 )->format( 't' ) ) < $today ) {
				++$guess_year;
				$day = $end_of_month ? (int) $today->setDate( $guess_year, $month, 1 )->format( 't' ) : 1;
			}
		} elseif ( ! $year && checkdate( $month, $day, $guess_year ) && $today->setDate( $guess_year, $month, $day ) < $today ) {
			++$guess_year;
		}

		if ( ! checkdate( $month, $day, $guess_year ) ) {
			return null;
		}
		return $today->setDate( $guess_year, $month, $day );
	}

	/**
	 * Parse a clock time into minutes after midnight.
	 *
	 * @param string   $time     Time phrase.
	 * @param int|null $end_time End of the range, used to infer am or pm.
	 * @return int|null
	 */
	private static function parse_time( string $time, ?int $end_time ): ?int {
		$time = strtolower( trim( $time ) );
		if ( 'noon' === $time || 'midday' === $time ) {
			return 720;
		}
		if ( 'midnight' === $time ) {
			return 0;
		}
		if ( ! preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*([ap])?/', $time, $m ) ) {
			return null;
		}
		$hour     = (int) $m[1];
		$minute   = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
		$meridiem = $m[3] ?? '';

		if ( $hour > 23 || $minute > 59 ) {
			return null;
		}
		if ( 'p' === $meridiem && $hour < 12 ) {
			$hour += 12;
		} elseif ( 'a' === $meridiem && 12 === $hour ) {
			$hour = 0;
		} elseif ( '' === $meridiem && null !== $end_time && $hour <= 12 ) {
			// "6-7pm": borrow pm from the end, unless that would put the start after it ("11-1pm").
			$as_pm = ( $hour % 12 + 12 ) * 60 + $minute;
			$hour  = $as_pm < $end_time ? $hour % 12 + 12 : $hour;
		} elseif ( '' === $meridiem && $hour >= 1 && $hour <= 7 && false === strpos( $time, ':' ) ) {
			// "at 6" with nothing else almost always means the evening.
			$hour += 12;
		}

		return $hour * 60 + $minute;
	}

	/**
	 * Two-letter RRULE code for a weekday word.
	 *
	 * @param string $word Weekday word.
	 * @return string
	 */
	private static function day_code( string $word ): string {
		return strtoupper( substr( trim( $word ), 0, 2 ) );
	}

	/**
	 * Frequency for a unit word.
	 *
	 * @param string $unit Unit word.
	 * @return string
	 */
	private static function unit_freq( string $unit ): string {
		$unit = strtolower( $unit );
		if ( 0 === strpos( $unit, 'day' ) ) {
			return 'DAILY';
		}
		if ( 0 === strpos( $unit, 'week' ) ) {
			return 'WEEKLY';
		}
		if ( 0 === strpos( $unit, 'month' ) ) {
			return 'MONTHLY';
		}
		return 'YEARLY';
	}

	/**
	 * The next date falling on a weekday.
	 *
	 * @param DateTimeImmutable $today     Today.
	 * @param string            $code      RRULE weekday code.
	 * @param bool              $skip_week Whether "next" was said.
	 * @return DateTimeImmutable
	 */
	private static function next_weekday( DateTimeImmutable $today, string $code, bool $skip_week ): DateTimeImmutable {
		$target = array_search( $code, Recurrence::DAYS, true ) + 1;
		$diff   = ( $target - (int) $today->format( 'N' ) + 7 ) % 7;
		if ( $skip_week && $diff < 1 ) {
			$diff = 7;
		}
		return $today->modify( "+{$diff} days" );
	}

	/**
	 * Tidy a title fragment.
	 *
	 * @param string $fragment Fragment.
	 * @return string
	 */
	private static function clean_title( string $fragment ): string {
		$fragment = trim( (string) preg_replace( '/\s+/', ' ', $fragment ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$fragment = trim( (string) preg_replace( '/^(?:on|at|from|to|and|the|every|each|starting|in|,|-|–|:|\.)+\s+|\s+(?:on|at|from|to|and|the|every|each|starting|in)$|^[\s,.:;–-]+|[\s,.:;–-]+$/iu', '', $fragment . ' ' ) );
		}
		if ( preg_match( '/^(?:on|at|from|to|and|the|every|each|starting|in)?$/i', $fragment ) ) {
			return '';
		}
		return $fragment;
	}
}
