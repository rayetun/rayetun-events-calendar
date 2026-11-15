<?php
/**
 * The Accessibility Guard.
 *
 * Each test sets up one event and names the one thing it expects the Guard to
 * say about it. A rule that fires on an event that is fine is worse than a
 * rule that does not exist, so most of these check the quiet case too.
 *
 * @package RayEtun\EventsCalendar
 */

declare( strict_types = 1 );

namespace RayEtun\EventsCalendar\Tests;

use PHPUnit\Framework\TestCase;
use RayEtun\EventsCalendar\Accessibility_Guard;

/**
 * Guard rules.
 */
final class AccessibilityGuardTest extends TestCase {

	/**
	 * An event with nothing wrong with it.
	 *
	 * @param array<string, mixed> $changes Fields to override.
	 * @return array<string, mixed>
	 */
	private function event( array $changes = array() ): array {
		$base = array(
			'id'           => 1,
			'title'        => 'Winter talk, with tea',
			'description'  => '<p>An hour on winter gardening, with tea afterwards. Everyone welcome, and no booking needed. There is room for twenty of us in the hall downstairs, which is warm, and we will have the kettle on from half past five.</p>',
			'thumbnail_id' => 0,
			'start'        => '2026-11-17T18:00',
			'end'          => '2026-11-17T19:30',
			'all_day'      => false,
			'format'       => 'in_person',
			'event_status' => 'scheduled',
			'online_url'   => '',
			'categories'   => array(),
			'venue'        => array(
				'id'           => 9,
				'name'         => 'The Old Library',
				'access'       => array( 'step_free' ),
				'access_notes' => '',
			),
		);
		return array_merge( $base, $changes );
	}

	/**
	 * The codes the Guard reported.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<int, string>
	 */
	private function codes( array $event ): array {
		$result = Accessibility_Guard::check( $event );
		return array_column( $result['issues'], 'code' );
	}

	/**
	 * Set up alternative text for an attachment ID.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $alt Alternative text.
	 * @return void
	 */
	private function alt( int $id, string $alt ): void {
		$GLOBALS['rayec_test_alt'][ $id ] = $alt;
	}

	/**
	 * Clear the alternative text between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['rayec_test_alt'] = array();
	}

	/*
	 * ---------------------------------------------------------------------
	 * The quiet case
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A good event scores full marks and is told nothing.
	 *
	 * @return void
	 */
	public function test_a_good_event_is_left_alone(): void {
		$result = Accessibility_Guard::check( $this->event() );

		$this->assertSame( array(), $result['issues'] );
		$this->assertSame( 100, $result['score'] );
	}

	/**
	 * An online event with a link is fine, and is not asked for a venue.
	 *
	 * @return void
	 */
	public function test_an_online_event_with_a_link_is_left_alone(): void {
		$codes = $this->codes(
			$this->event(
				array(
					'format'     => 'online',
					'venue'      => null,
					'online_url' => 'https://example.org/join',
				)
			)
		);

		$this->assertSame( array(), $codes );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pictures
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A featured image with no alternative text.
	 *
	 * @return void
	 */
	public function test_a_picture_with_no_alternative_text(): void {
		$this->assertContains( 'image_alt', $this->codes( $this->event( array( 'thumbnail_id' => 42 ) ) ) );
	}

	/**
	 * Alternative text that is a file name.
	 *
	 * @return void
	 */
	public function test_alternative_text_that_is_a_file_name(): void {
		$this->alt( 42, 'IMG_2043.JPG' );

		$codes = $this->codes( $this->event( array( 'thumbnail_id' => 42 ) ) );
		$this->assertContains( 'alt_is_filename', $codes );
		$this->assertNotContains( 'image_alt', $codes );
	}

	/**
	 * Alternative text that only repeats the event name.
	 *
	 * @return void
	 */
	public function test_alternative_text_that_repeats_the_name(): void {
		$this->alt( 42, 'Winter talk,  with tea ' );

		$this->assertContains( 'alt_repeats_title', $this->codes( $this->event( array( 'thumbnail_id' => 42 ) ) ) );
	}

	/**
	 * Alternative text long enough to be a paragraph.
	 *
	 * @return void
	 */
	public function test_alternative_text_that_runs_on(): void {
		$this->alt( 42, str_repeat( 'a tray of seedlings on a windowsill, ', 6 ) );

		$this->assertContains( 'alt_too_long', $this->codes( $this->event( array( 'thumbnail_id' => 42 ) ) ) );
	}

	/**
	 * Proper alternative text passes all four picture rules.
	 *
	 * @return void
	 */
	public function test_good_alternative_text_says_nothing(): void {
		$this->alt( 42, 'A tray of seedlings on a sunny windowsill.' );

		$this->assertSame( array(), $this->codes( $this->event( array( 'thumbnail_id' => 42 ) ) ) );
	}

	/**
	 * An image in the description with no alt attribute.
	 *
	 * @return void
	 */
	public function test_an_image_in_the_description_with_no_alt(): void {
		$content = '<p>Some words about the talk, which run on for long enough that nothing else fires, with plenty to say about the evening and the tea.</p><img src="poster.png">';

		$this->assertContains( 'content_image_alt', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * An empty alt attribute is a decision, not an omission.
	 *
	 * @return void
	 */
	public function test_an_empty_alt_attribute_is_allowed(): void {
		$content = '<p>Some words about the talk, which run on for long enough that nothing else fires, with plenty to say about the evening and the tea.</p><img src="rule.png" alt="">';

		$this->assertNotContains( 'content_image_alt', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * A poster and almost no words: the detail is trapped in the picture.
	 *
	 * @return void
	 */
	public function test_a_poster_with_no_words_beside_it(): void {
		$this->alt( 42, 'A poster for the winter talk.' );

		$codes = $this->codes(
			$this->event(
				array(
					'thumbnail_id' => 42,
					'description'  => '<p>See the poster.</p>',
				)
			)
		);

		$this->assertContains( 'detail_in_picture', $codes );
	}

	/**
	 * No picture and no words is the other rule, not this one.
	 *
	 * @return void
	 */
	public function test_an_empty_description_is_its_own_problem(): void {
		$codes = $this->codes( $this->event( array( 'description' => '' ) ) );

		$this->assertContains( 'no_description', $codes );
		$this->assertNotContains( 'detail_in_picture', $codes );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Links
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Link text that says nothing.
	 *
	 * @return void
	 */
	public function test_a_link_that_says_click_here(): void {
		$content = '<p>Booking is open for the evening and the tea afterwards. <a href="/book">Click here</a> to take a place at the table.</p>';

		$this->assertContains( 'vague_link', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * Link text that names its destination.
	 *
	 * @return void
	 */
	public function test_a_link_that_names_where_it_goes(): void {
		$content = '<p>Booking is open for the evening and the tea afterwards. <a href="/book">Book a place at the winter talk</a>.</p>';

		$this->assertSame( array(), $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * A bare address used as link text.
	 *
	 * @return void
	 */
	public function test_a_link_showing_its_own_address(): void {
		$content = '<p>Booking is open for the evening and the tea afterwards, over at <a href="https://example.org/booking/winter">https://example.org/booking/winter</a>.</p>';

		$this->assertContains( 'raw_url_link', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * The same words going to two different places.
	 *
	 * @return void
	 */
	public function test_two_links_with_the_same_words(): void {
		$content = '<p>Read about <a href="/a">the programme</a> for the winter, and about <a href="/b">the programme</a> for the spring, before you book.</p>';

		$this->assertContains( 'same_text_other_place', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * The same words going to the same place is a repeat, not a trap.
	 *
	 * @return void
	 */
	public function test_two_links_to_the_same_place_are_fine(): void {
		$content = '<p>Read about <a href="/a">the programme</a> for the winter, and about <a href="/a">the programme</a> again further down, before you book.</p>';

		$this->assertNotContains( 'same_text_other_place', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * A new tab opened without warning.
	 *
	 * @return void
	 */
	public function test_a_link_that_opens_a_new_tab_quietly(): void {
		$content = '<p>Booking is open for the evening and the tea afterwards. <a href="/book" target="_blank">Book a place at the winter talk</a>.</p>';

		$this->assertContains( 'new_window', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * A new tab that says so.
	 *
	 * @return void
	 */
	public function test_a_link_that_warns_about_the_new_tab(): void {
		$content = '<p>Booking is open for the evening and the tea afterwards. <a href="/book" target="_blank">Book a place (opens in a new tab)</a>.</p>';

		$this->assertNotContains( 'new_window', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The shape of the description
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A second level-one heading.
	 *
	 * @return void
	 */
	public function test_a_level_one_heading_in_the_description(): void {
		$content = '<h1>Winter talk</h1><p>An hour on winter gardening, with tea afterwards and room for twenty of us in the hall downstairs.</p>';

		$this->assertContains( 'heading_h1', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * A heading level skipped.
	 *
	 * @return void
	 */
	public function test_a_skipped_heading_level(): void {
		$content = '<h2>What happens</h2><p>An hour on winter gardening, with tea.</p><h4>Getting in</h4><p>Step-free through the side door, which is open from six.</p>';

		$this->assertContains( 'heading_skip', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * Headings in order.
	 *
	 * @return void
	 */
	public function test_headings_in_order_say_nothing(): void {
		$content = '<h2>What happens</h2><p>An hour on winter gardening, with tea.</p><h3>Getting in</h3><p>Step-free through the side door, which is open from six.</p>';

		$this->assertSame( array(), $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * A table with no header row.
	 *
	 * @return void
	 */
	public function test_a_table_with_no_header_row(): void {
		$content = '<p>The evening runs to this timetable, and there is tea in the middle of it.</p><table><tr><td>6pm</td><td>Doors</td></tr></table>';

		$this->assertContains( 'table_no_header', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/**
	 * A table with one.
	 *
	 * @return void
	 */
	public function test_a_table_with_a_header_row(): void {
		$content = '<p>The evening runs to this timetable, and there is tea in the middle of it.</p><table><tr><th>Time</th><th>What</th></tr><tr><td>6pm</td><td>Doors</td></tr></table>';

		$this->assertNotContains( 'table_no_header', $this->codes( $this->event( array( 'description' => $content ) ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Where, when and how
	 * ---------------------------------------------------------------------
	 */

	/**
	 * An in-person event with no venue.
	 *
	 * @return void
	 */
	public function test_an_in_person_event_with_nowhere_to_go(): void {
		$this->assertContains( 'no_venue', $this->codes( $this->event( array( 'venue' => null ) ) ) );
	}

	/**
	 * A venue nobody can check.
	 *
	 * @return void
	 */
	public function test_a_venue_with_no_access_details(): void {
		$venue = array(
			'id'           => 9,
			'name'         => 'The Old Library',
			'access'       => array(),
			'access_notes' => '',
		);

		$this->assertContains( 'venue_access', $this->codes( $this->event( array( 'venue' => $venue ) ) ) );
	}

	/**
	 * Written access notes count, even with no ticks.
	 *
	 * @return void
	 */
	public function test_written_access_notes_are_enough(): void {
		$venue = array(
			'id'           => 9,
			'name'         => 'The Old Library',
			'access'       => array(),
			'access_notes' => 'Three steps at the front; the side door is level and we will open it on request.',
		);

		$this->assertNotContains( 'venue_access', $this->codes( $this->event( array( 'venue' => $venue ) ) ) );
	}

	/**
	 * An online event with no way in.
	 *
	 * @return void
	 */
	public function test_an_online_event_with_no_link(): void {
		$codes = $this->codes(
			$this->event(
				array(
					'format' => 'online',
					'venue'  => null,
				)
			)
		);

		$this->assertContains( 'no_online_url', $codes );
		$this->assertNotContains( 'no_venue', $codes );
	}

	/**
	 * An event moved online is no longer asked about its room.
	 *
	 * @return void
	 */
	public function test_an_event_moved_online_is_not_asked_about_its_room(): void {
		$codes = $this->codes(
			$this->event(
				array(
					'event_status' => 'moved_online',
					'venue'        => null,
					'online_url'   => 'https://example.org/join',
				)
			)
		);

		$this->assertSame( array(), $codes );
	}

	/**
	 * A hybrid event is asked about both.
	 *
	 * @return void
	 */
	public function test_a_hybrid_event_is_asked_about_both(): void {
		$codes = $this->codes(
			$this->event(
				array(
					'format' => 'hybrid',
					'venue'  => null,
				)
			)
		);

		$this->assertContains( 'no_venue', $codes );
		$this->assertContains( 'no_online_url', $codes );
	}

	/**
	 * A timed event with no finishing time.
	 *
	 * @return void
	 */
	public function test_an_event_that_never_finishes(): void {
		$this->assertContains( 'no_end_time', $this->codes( $this->event( array( 'end' => '' ) ) ) );
	}

	/**
	 * An all-day event is not asked for one.
	 *
	 * @return void
	 */
	public function test_an_all_day_event_needs_no_finishing_time(): void {
		$codes = $this->codes(
			$this->event(
				array(
					'end'     => '',
					'all_day' => true,
				)
			)
		);

		$this->assertNotContains( 'no_end_time', $codes );
	}

	/**
	 * A category colour too pale to see on white.
	 *
	 * @return void
	 */
	public function test_a_category_colour_too_pale_to_see(): void {
		$categories = array(
			array(
				'id'    => 3,
				'name'  => 'Wellbeing',
				'color' => '#f3f9ee',
			),
		);

		$this->assertContains( 'category_contrast', $this->codes( $this->event( array( 'categories' => $categories ) ) ) );
	}

	/**
	 * A colour dark enough to read.
	 *
	 * @return void
	 */
	public function test_a_category_colour_dark_enough(): void {
		$categories = array(
			array(
				'id'    => 3,
				'name'  => 'Wellbeing',
				'color' => '#1f5f3f',
			),
		);

		$this->assertSame( array(), $this->codes( $this->event( array( 'categories' => $categories ) ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The name
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A name in capitals throughout.
	 *
	 * @return void
	 */
	public function test_a_name_written_in_capitals(): void {
		$this->assertContains( 'title_shouting', $this->codes( $this->event( array( 'title' => 'WINTER TALK WITH TEA' ) ) ) );
	}

	/**
	 * A short acronym is not shouting.
	 *
	 * @return void
	 */
	public function test_a_short_acronym_is_not_shouting(): void {
		$this->assertNotContains( 'title_shouting', $this->codes( $this->event( array( 'title' => 'AGM' ) ) ) );
	}

	/**
	 * A row of emoji in the name.
	 *
	 * @return void
	 */
	public function test_a_row_of_emoji_in_the_name(): void {
		$this->assertContains( 'title_emoji', $this->codes( $this->event( array( 'title' => 'Winter talk 🌱🌱🌿☕' ) ) ) );
	}

	/**
	 * One emoji is nobody's problem.
	 *
	 * @return void
	 */
	public function test_one_emoji_is_fine(): void {
		$this->assertNotContains( 'title_emoji', $this->codes( $this->event( array( 'title' => 'Winter talk ☕' ) ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scoring and extension
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Levels cost what they are meant to cost.
	 *
	 * @return void
	 */
	public function test_an_error_costs_more_than_a_note(): void {
		$with_error = Accessibility_Guard::check( $this->event( array( 'thumbnail_id' => 42 ) ) );
		$with_note  = Accessibility_Guard::check( $this->event( array( 'end' => '' ) ) );

		$this->assertSame( 80, $with_error['score'] );
		$this->assertSame( 98, $with_note['score'] );
	}

	/**
	 * The score never goes below zero, however much is wrong.
	 *
	 * @return void
	 */
	public function test_the_score_stops_at_zero(): void {
		$content = str_repeat( '<h1>Shouting</h1><img src="a.png"><a href="/a">Click here</a><a href="/b">Click here</a>', 3 );
		$result  = Accessibility_Guard::check(
			$this->event(
				array(
					'title'        => 'WINTER TALK WITH TEA 🌱🌱🌿',
					'description'  => $content,
					'thumbnail_id' => 42,
					'venue'        => null,
					'end'          => '',
					'format'       => 'hybrid',
				)
			)
		);

		$this->assertSame( 0, $result['score'] );
	}

	/**
	 * Every issue carries what to do about it and which part it is about.
	 *
	 * @return void
	 */
	public function test_every_issue_says_what_to_do_about_it(): void {
		$result = Accessibility_Guard::check(
			$this->event(
				array(
					'description'  => '<p>See the poster.</p>',
					'thumbnail_id' => 42,
					'venue'        => null,
					'end'          => '',
				)
			)
		);

		$this->assertNotEmpty( $result['issues'] );
		foreach ( $result['issues'] as $issue ) {
			$this->assertNotSame( '', $issue['message'] );
			$this->assertNotSame( '', $issue['fix'] );
			$this->assertNotSame( '', $issue['where'] );
			$this->assertContains( $issue['level'], array( 'error', 'warning', 'note' ) );
		}
	}

	/**
	 * Contrast is the WCAG ratio, to the published figures.
	 *
	 * @return void
	 */
	public function test_the_contrast_figures(): void {
		$this->assertSame( 21.0, round( Accessibility_Guard::contrast( '#000000', '#ffffff' ), 2 ) );
		$this->assertSame( 1.0, round( Accessibility_Guard::contrast( '#ffffff', '#ffffff' ), 2 ) );
		$this->assertSame( 4.54, round( Accessibility_Guard::contrast( '#767676', '#ffffff' ), 2 ) );
	}

	/**
	 * Short hex and a missing hash are read the same way.
	 *
	 * @return void
	 */
	public function test_short_colours_are_read_the_same_way(): void {
		$this->assertSame(
			round( Accessibility_Guard::contrast( '#ffffff', '#000000' ), 4 ),
			round( Accessibility_Guard::contrast( 'fff', '000' ), 4 )
		);
	}
}
