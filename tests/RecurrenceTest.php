<?php
/**
 * The recurrence engine.
 *
 * A repeating event is one post and one rule, so the rule has to be right:
 * every date these tests check is one a site owner would otherwise find out
 * about from a visitor who turned up on the wrong day.
 *
 * @package RayEtun\EventsCalendar
 */

declare( strict_types = 1 );

namespace RayEtun\EventsCalendar\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RayEtun\EventsCalendar\Recurrence;

/**
 * Recurrence expansion.
 */
final class RecurrenceTest extends TestCase {

	/**
	 * Expand a rule and return the dates in a readable form.
	 *
	 * @param string $start  Local start, "Y-m-d H:i".
	 * @param string $zone   Time zone name.
	 * @param string $rrule  The rule.
	 * @param string $format Output format.
	 * @return array<int, string>
	 */
	private function dates( string $start, string $zone, string $rrule, string $format = 'Y-m-d' ): array {
		$from = new DateTimeImmutable( $start, new DateTimeZone( $zone ) );
		return array_map(
			static fn( DateTimeImmutable $d ): string => $d->format( $format ),
			Recurrence::expand( $from, $rrule )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * The ordinary rules
	 * ---------------------------------------------------------------------
	 */

	/**
	 * No rule means the one date it started on.
	 *
	 * @return void
	 */
	public function test_an_event_with_no_rule_happens_once(): void {
		$this->assertSame(
			array( '2026-11-17' ),
			$this->dates( '2026-11-17 18:00', 'Europe/London', '' )
		);
	}

	/**
	 * Daily with a count.
	 *
	 * @return void
	 */
	public function test_daily_with_a_count(): void {
		$this->assertSame(
			array( '2026-11-17', '2026-11-18', '2026-11-19' ),
			$this->dates( '2026-11-17 09:00', 'Europe/London', 'FREQ=DAILY;COUNT=3' )
		);
	}

	/**
	 * Every other day.
	 *
	 * @return void
	 */
	public function test_an_interval_skips_days(): void {
		$this->assertSame(
			array( '2026-11-17', '2026-11-19', '2026-11-21', '2026-11-23' ),
			$this->dates( '2026-11-17 09:00', 'Europe/London', 'FREQ=DAILY;INTERVAL=2;COUNT=4' )
		);
	}

	/**
	 * Two weekdays a week.
	 *
	 * @return void
	 */
	public function test_weekly_on_two_days(): void {
		$this->assertSame(
			array( '2026-11-17', '2026-11-19', '2026-11-24', '2026-11-26', '2026-12-01' ),
			$this->dates( '2026-11-17 18:00', 'America/New_York', 'FREQ=WEEKLY;BYDAY=TU,TH;COUNT=5' )
		);
	}

	/**
	 * UNTIL is inclusive of the whole last day.
	 *
	 * @return void
	 */
	public function test_until_includes_its_own_day(): void {
		$this->assertSame(
			array( '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20' ),
			$this->dates( '2026-11-17 09:00', 'Europe/London', 'FREQ=DAILY;UNTIL=20261120' )
		);
	}

	/**
	 * The second Tuesday of the month.
	 *
	 * @return void
	 */
	public function test_the_second_tuesday_of_the_month(): void {
		$this->assertSame(
			array( '2026-01-13', '2026-02-10', '2026-03-10', '2026-04-14' ),
			$this->dates( '2026-01-13 19:00', 'Europe/London', 'FREQ=MONTHLY;BYDAY=2TU;COUNT=4' )
		);
	}

	/**
	 * The last Friday, where "last" is the fourth in some months and the fifth
	 * in others.
	 *
	 * @return void
	 */
	public function test_the_last_friday_of_the_month(): void {
		$this->assertSame(
			array( '2026-01-30', '2026-02-27', '2026-03-27', '2026-04-24', '2026-05-29' ),
			$this->dates( '2026-01-30 19:00', 'Europe/London', 'FREQ=MONTHLY;BYDAY=-1FR;COUNT=5' )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Month ends
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The 31st exists in seven months of the year, and a monthly event on the
	 * 31st happens in those seven. Rolling into the 1st of the next month would
	 * be wrong: nobody means "1 March" when they say "the 31st".
	 *
	 * @return void
	 */
	public function test_the_thirty_first_skips_the_short_months(): void {
		$this->assertSame(
			array( '2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31', '2026-08-31' ),
			$this->dates( '2026-01-31 10:00', 'Europe/London', 'FREQ=MONTHLY;COUNT=5' )
		);
	}

	/**
	 * The 30th skips February alone.
	 *
	 * @return void
	 */
	public function test_the_thirtieth_skips_only_february(): void {
		$this->assertSame(
			array( '2026-01-30', '2026-03-30', '2026-04-30', '2026-05-30' ),
			$this->dates( '2026-01-30 10:00', 'Europe/London', 'FREQ=MONTHLY;COUNT=4' )
		);
	}

	/**
	 * The last day of each month, however long the month is.
	 *
	 * @return void
	 */
	public function test_the_last_day_of_every_month(): void {
		$this->assertSame(
			array( '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30' ),
			$this->dates( '2026-01-31 10:00', 'Europe/London', 'FREQ=MONTHLY;BYMONTHDAY=-1;COUNT=4' )
		);
	}

	/**
	 * Two days of the month at once.
	 *
	 * @return void
	 */
	public function test_two_days_of_the_month(): void {
		$this->assertSame(
			array( '2026-01-01', '2026-01-15', '2026-02-01', '2026-02-15' ),
			$this->dates( '2026-01-01 10:00', 'Europe/London', 'FREQ=MONTHLY;BYMONTHDAY=1,15;COUNT=4' )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Leap years
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A yearly event on 29 February happens in leap years and no others.
	 *
	 * @return void
	 */
	public function test_the_twenty_ninth_of_february_waits_for_a_leap_year(): void {
		$this->assertSame(
			array( '2024-02-29', '2028-02-29', '2032-02-29' ),
			$this->dates( '2024-02-29 12:00', 'Europe/London', 'FREQ=YEARLY;COUNT=3' )
		);
	}

	/**
	 * 2100 is not a leap year, whatever the four-year rule suggests.
	 *
	 * @return void
	 */
	public function test_the_century_is_not_a_leap_year(): void {
		$this->assertSame(
			array( '2096-02-29', '2104-02-29' ),
			$this->dates( '2096-02-29 12:00', 'Europe/London', 'FREQ=YEARLY;COUNT=2' )
		);
	}

	/**
	 * A daily event running through 29 February does not lose a day.
	 *
	 * @return void
	 */
	public function test_daily_runs_through_a_leap_day(): void {
		$this->assertSame(
			array( '2028-02-27', '2028-02-28', '2028-02-29', '2028-03-01' ),
			$this->dates( '2028-02-27 09:00', 'Europe/London', 'FREQ=DAILY;COUNT=4' )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Daylight saving
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A six o'clock class stays at six o'clock when the clocks go forward.
	 * In UTC the same class moves an hour, which is exactly the point of
	 * storing the wall-clock time and the zone rather than a UTC stamp.
	 *
	 * @return void
	 */
	public function test_a_weekly_class_keeps_its_hour_across_the_spring_change(): void {
		$local = $this->dates( '2027-03-09 18:00', 'America/New_York', 'FREQ=WEEKLY;COUNT=3', 'Y-m-d H:i' );
		$this->assertSame(
			array( '2027-03-09 18:00', '2027-03-16 18:00', '2027-03-23 18:00' ),
			$local
		);

		$utc = array_map(
			static fn( DateTimeImmutable $d ): string => $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i' ),
			Recurrence::expand( new DateTimeImmutable( '2027-03-09 18:00', new DateTimeZone( 'America/New_York' ) ), 'FREQ=WEEKLY;COUNT=3' )
		);
		$this->assertSame(
			array( '2027-03-09 23:00', '2027-03-16 22:00', '2027-03-23 22:00' ),
			$utc
		);
	}

	/**
	 * And the same when the clocks go back.
	 *
	 * @return void
	 */
	public function test_a_weekly_class_keeps_its_hour_across_the_autumn_change(): void {
		$this->assertSame(
			array( '2026-10-22 19:00', '2026-10-29 19:00', '2026-11-05 19:00' ),
			$this->dates( '2026-10-22 19:00', 'Europe/London', 'FREQ=WEEKLY;COUNT=3', 'Y-m-d H:i' )
		);
	}

	/**
	 * The southern hemisphere changes the other way round, in October.
	 *
	 * @return void
	 */
	public function test_the_southern_hemisphere_change(): void {
		$this->assertSame(
			array( '2026-09-29 18:30', '2026-10-06 18:30', '2026-10-13 18:30' ),
			$this->dates( '2026-09-29 18:30', 'Australia/Sydney', 'FREQ=WEEKLY;COUNT=3', 'Y-m-d H:i' )
		);
	}

	/**
	 * The hour that never happens.
	 *
	 * On 8 March 2026 New York goes from 01:59 to 03:00, so a weekly 02:30
	 * event has no 02:30 that week. PHP resolves it forwards to 03:30; what
	 * matters is that the series does not stop, lose a week or move the other
	 * dates.
	 *
	 * @return void
	 */
	public function test_a_time_that_does_not_exist_on_the_day_the_clock_jumps(): void {
		$dates = $this->dates( '2026-03-01 02:30', 'America/New_York', 'FREQ=WEEKLY;COUNT=3', 'Y-m-d H:i' );

		$this->assertCount( 3, $dates );
		$this->assertSame( '2026-03-01 02:30', $dates[0] );
		$this->assertSame( '2026-03-15 02:30', $dates[2] );
		$this->assertStringStartsWith( '2026-03-08 0', $dates[1] );
	}

	/**
	 * The hour that happens twice.
	 *
	 * On 1 November 2026 New York runs 01:30 twice. One of them is enough.
	 *
	 * @return void
	 */
	public function test_a_time_that_happens_twice_is_listed_once(): void {
		$dates = $this->dates( '2026-10-25 01:30', 'America/New_York', 'FREQ=WEEKLY;COUNT=3', 'Y-m-d H:i' );

		$this->assertSame(
			array( '2026-10-25 01:30', '2026-11-01 01:30', '2026-11-08 01:30' ),
			$dates
		);
		$this->assertSame( $dates, array_values( array_unique( $dates ) ) );
	}

	/**
	 * A daily event in a zone that does not change at all.
	 *
	 * @return void
	 */
	public function test_a_zone_with_no_daylight_saving(): void {
		$this->assertSame(
			array( '2026-03-27 08:00', '2026-03-28 08:00', '2026-03-29 08:00', '2026-03-30 08:00' ),
			$this->dates( '2026-03-27 08:00', 'Asia/Kolkata', 'FREQ=DAILY;COUNT=4', 'Y-m-d H:i' )
		);
	}

	/**
	 * A monthly series crossing both changes in one year keeps its hour.
	 *
	 * @return void
	 */
	public function test_a_monthly_series_keeps_its_hour_all_year(): void {
		$dates = $this->dates( '2026-01-15 19:00', 'Europe/London', 'FREQ=MONTHLY;COUNT=12', 'Y-m-d H:i' );

		$this->assertCount( 12, $dates );
		foreach ( $dates as $date ) {
			$this->assertStringEndsWith( ' 19:00', $date );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Limits and bad input
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A window end cuts the series off.
	 *
	 * @return void
	 */
	public function test_a_window_end_cuts_the_series_off(): void {
		$zone  = new DateTimeZone( 'Europe/London' );
		$dates = Recurrence::expand(
			new DateTimeImmutable( '2026-11-17 09:00', $zone ),
			'FREQ=DAILY',
			new DateTimeImmutable( '2026-11-20 23:59', $zone )
		);

		$this->assertCount( 4, $dates );
	}

	/**
	 * An endless rule stops at the limit it is given.
	 *
	 * @return void
	 */
	public function test_an_endless_rule_stops_at_the_limit(): void {
		$dates = Recurrence::expand(
			new DateTimeImmutable( '2026-11-17 09:00', new DateTimeZone( 'Europe/London' ) ),
			'FREQ=DAILY',
			null,
			10
		);

		$this->assertCount( 10, $dates );
	}

	/**
	 * Nonsense in, the single date out. A rule nobody can read must never
	 * become a rule that means something else.
	 *
	 * @return void
	 */
	public function test_an_unreadable_rule_leaves_one_date(): void {
		$this->assertSame(
			array( '2026-11-17' ),
			$this->dates( '2026-11-17 09:00', 'Europe/London', 'FREQ=FORTNIGHTLY;COUNT=5' )
		);
		$this->assertFalse( Recurrence::is_valid( 'FREQ=FORTNIGHTLY' ) );
	}

	/**
	 * A valid rule says so.
	 *
	 * @return void
	 */
	public function test_a_readable_rule_is_valid(): void {
		$this->assertTrue( Recurrence::is_valid( 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4' ) );
		$this->assertTrue( Recurrence::is_valid( 'FREQ=MONTHLY;BYDAY=-1FR' ) );
	}

	/**
	 * COUNT=1 is the start and nothing after it.
	 *
	 * @return void
	 */
	public function test_a_count_of_one(): void {
		$this->assertSame(
			array( '2026-11-17' ),
			$this->dates( '2026-11-17 09:00', 'Europe/London', 'FREQ=DAILY;COUNT=1' )
		);
	}

	/**
	 * An UNTIL before the start cannot produce a second date.
	 *
	 * @return void
	 */
	public function test_an_until_in_the_past_leaves_the_start_alone(): void {
		$this->assertSame(
			array( '2026-11-17' ),
			$this->dates( '2026-11-17 09:00', 'Europe/London', 'FREQ=DAILY;UNTIL=20260101' )
		);
	}

	/**
	 * Adding an end date to a rule that already has one replaces it.
	 *
	 * @return void
	 */
	public function test_an_end_date_can_be_put_on_a_rule(): void {
		$this->assertSame(
			'FREQ=DAILY;UNTIL=20261231',
			Recurrence::with_until( 'FREQ=DAILY;COUNT=99', '20261231' )
		);
	}

	/**
	 * Rules are written back in a fixed order, so the same rule is the same
	 * string however it arrived.
	 *
	 * @return void
	 */
	public function test_a_rule_is_written_back_the_same_way_every_time(): void {
		$this->assertSame(
			Recurrence::normalize( 'COUNT=4;BYDAY=WE,MO;FREQ=WEEKLY' ),
			Recurrence::normalize( 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4' )
		);
	}
}
