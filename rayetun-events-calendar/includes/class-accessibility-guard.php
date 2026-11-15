<?php
/**
 * Accessibility Guard: checks an event for common accessibility gaps.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event-level accessibility checks.
 *
 * Every rule is a small method that adds nothing, or one issue. A rule says
 * three things: what is wrong, how serious it is, and what to do about it.
 * Nothing here blocks publishing; the Guard advises and scores.
 */
class Accessibility_Guard {

	/**
	 * What each level costs the score.
	 *
	 * An error fails a WCAG success criterion outright. A warning is a likely
	 * barrier. A note is worth a look but may well be fine as it is.
	 *
	 * @var array<string, int>
	 */
	private const WEIGHTS = array(
		'error'   => 20,
		'warning' => 7,
		'note'    => 2,
	);

	/**
	 * The rules, in the order they are reported.
	 *
	 * @var array<int, string>
	 */
	private const RULES = array(
		'featured_image_alt',
		'content_image_alt',
		'alt_is_a_filename',
		'alt_repeats_the_title',
		'alt_is_very_long',
		'vague_link_text',
		'link_text_is_a_url',
		'same_text_other_place',
		'unannounced_new_window',
		'heading_level_one',
		'heading_level_skipped',
		'table_without_headers',
		'no_description',
		'detail_only_in_the_picture',
		'missing_venue',
		'venue_without_access_details',
		'online_without_a_link',
		'no_end_time',
		'category_colour_contrast',
		'shouting_title',
		'emoji_pile_in_title',
	);

	/**
	 * Check one event.
	 *
	 * @param array<string, mixed> $event Event data from Event::get().
	 * @return array{score: int, issues: array<int, array<string, string>>}
	 */
	public static function check( array $event ): array {
		/**
		 * Filters which Guard rules run, by method name.
		 *
		 * @param array<int, string>   $rules Rule method names.
		 * @param array<string, mixed> $event Event data.
		 */
		$rules = (array) apply_filters( 'rayec_guard_rules', self::RULES, $event );

		$issues = array();
		foreach ( $rules as $rule ) {
			if ( ! is_string( $rule ) || ! method_exists( self::class, $rule ) ) {
				continue;
			}
			$found = self::$rule( $event );
			if ( $found ) {
				$issues[] = $found;
			}
		}

		/**
		 * Filters the Accessibility Guard issues for an event.
		 *
		 * @param array<int, array<string, string>> $issues Issues: code, level, message, fix, where.
		 * @param array<string, mixed>              $event  Event data.
		 */
		$issues = (array) apply_filters( 'rayec_guard_issues', $issues, $event );

		$seen  = array();
		$score = 100;
		foreach ( $issues as $issue ) {
			$code = isset( $issue['code'] ) ? (string) $issue['code'] : '';
			if ( isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;
			$level         = isset( $issue['level'] ) ? (string) $issue['level'] : 'warning';
			$score        -= self::WEIGHTS[ $level ] ?? self::WEIGHTS['warning'];
		}

		return array(
			'score'  => max( 0, $score ),
			'issues' => array_values( $issues ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pictures
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The featured image needs alternative text.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function featured_image_alt( array $event ): ?array {
		if ( ! $event['thumbnail_id'] || '' !== self::alt( $event ) ) {
			return null;
		}
		return self::issue(
			'image_alt',
			'error',
			__( 'The featured image has no alternative text.', 'rayetun-events-calendar' ),
			__( 'Describe what the picture shows, in a sentence. If it is only decoration, mark it as decorative in the media library.', 'rayetun-events-calendar' ),
			'image'
		);
	}

	/**
	 * Images in the description need an alt attribute.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function content_image_alt( array $event ): ?array {
		if ( ! preg_match_all( '/<img\b[^>]*>/i', (string) $event['description'], $images ) ) {
			return null;
		}
		foreach ( $images[0] as $image ) {
			if ( ! preg_match( '/\balt\s*=/i', $image ) ) {
				return self::issue(
					'content_image_alt',
					'error',
					__( 'An image in the description has no alt attribute.', 'rayetun-events-calendar' ),
					__( 'Select the image in the editor and fill in "Alternative text".', 'rayetun-events-calendar' ),
					'description'
				);
			}
		}
		return null;
	}

	/**
	 * Alternative text that is really a file name.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function alt_is_a_filename( array $event ): ?array {
		$alt = self::alt( $event );
		if ( '' === $alt || ! preg_match( '/^\S+\.(jpe?g|png|gif|webp|avif|heic|svg)$/i', $alt ) ) {
			return null;
		}
		return self::issue(
			'alt_is_filename',
			'warning',
			/* translators: %s: the alternative text found. */
			sprintf( __( 'The picture&#8217;s alternative text is a file name: "%s".', 'rayetun-events-calendar' ), $alt ),
			__( 'Replace it with what someone would see in the picture.', 'rayetun-events-calendar' ),
			'image'
		);
	}

	/**
	 * Alternative text that only repeats the event name.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function alt_repeats_the_title( array $event ): ?array {
		$alt = self::alt( $event );
		if ( '' === $alt || self::flatten( $alt ) !== self::flatten( (string) $event['title'] ) ) {
			return null;
		}
		return self::issue(
			'alt_repeats_title',
			'note',
			__( 'The picture&#8217;s alternative text is the same as the event name.', 'rayetun-events-calendar' ),
			__( 'The name is read out already, so say what the picture adds instead.', 'rayetun-events-calendar' ),
			'image'
		);
	}

	/**
	 * Alternative text long enough to be a description.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function alt_is_very_long( array $event ): ?array {
		$alt    = self::alt( $event );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $alt ) : strlen( $alt );
		if ( $length <= 150 ) {
			return null;
		}
		return self::issue(
			'alt_too_long',
			'note',
			/* translators: %d: number of characters. */
			sprintf( __( 'The picture&#8217;s alternative text is %d characters long.', 'rayetun-events-calendar' ), $length ),
			__( 'A screen reader reads it in one breath. Keep it to a sentence and put the rest in the description.', 'rayetun-events-calendar' ),
			'image'
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Links
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Link text that does not say where it goes.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function vague_link_text( array $event ): ?array {
		$vague = array( 'click here', 'here', 'read more', 'more', 'learn more', 'link', 'this', 'this link', 'more info', 'find out more', 'see more', 'details', 'go' );

		/**
		 * Filters the link texts the Guard treats as saying nothing.
		 *
		 * @param array<int, string> $vague Lower-case link texts.
		 */
		$vague = (array) apply_filters( 'rayec_guard_vague_links', $vague );

		foreach ( self::links( $event ) as $link ) {
			if ( in_array( self::flatten( $link['text'] ), $vague, true ) ) {
				return self::issue(
					'vague_link',
					'error',
					/* translators: %s: link text. */
					sprintf( __( 'The link text "%s" does not say where it goes.', 'rayetun-events-calendar' ), $link['text'] ),
					__( 'Write the destination into the link itself, such as "Book a place at the Riverside Studio".', 'rayetun-events-calendar' ),
					'description'
				);
			}
		}
		return null;
	}

	/**
	 * A bare web address used as link text.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function link_text_is_a_url( array $event ): ?array {
		foreach ( self::links( $event ) as $link ) {
			if ( preg_match( '#^(https?://|www\.)\S{12,}$#i', trim( $link['text'] ) ) ) {
				return self::issue(
					'raw_url_link',
					'warning',
					__( 'A link shows its web address rather than words.', 'rayetun-events-calendar' ),
					__( 'Some screen readers read an address out character by character. Use words that name the page.', 'rayetun-events-calendar' ),
					'description'
				);
			}
		}
		return null;
	}

	/**
	 * The same link text pointing at two different places.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function same_text_other_place( array $event ): ?array {
		$seen = array();
		foreach ( self::links( $event ) as $link ) {
			$text = self::flatten( $link['text'] );
			if ( '' === $text || '' === $link['href'] ) {
				continue;
			}
			if ( isset( $seen[ $text ] ) && $seen[ $text ] !== $link['href'] ) {
				return self::issue(
					'same_text_other_place',
					'warning',
					/* translators: %s: link text. */
					sprintf( __( 'Two links both read "%s" but go to different places.', 'rayetun-events-calendar' ), $link['text'] ),
					__( 'Someone listing the links on the page would see the same words twice. Give each one its own.', 'rayetun-events-calendar' ),
					'description'
				);
			}
			$seen[ $text ] = $link['href'];
		}
		return null;
	}

	/**
	 * A link that opens a new tab without saying so.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function unannounced_new_window( array $event ): ?array {
		$phrases = array(
			'new tab',
			'new window',
			strtolower( __( 'new tab', 'rayetun-events-calendar' ) ),
			strtolower( __( 'new window', 'rayetun-events-calendar' ) ),
		);

		foreach ( self::links( $event ) as $link ) {
			if ( ! $link['new_window'] ) {
				continue;
			}
			$said   = self::flatten( $link['text'] . ' ' . $link['title'] );
			$warned = false;
			foreach ( $phrases as $phrase ) {
				if ( '' !== $phrase && false !== strpos( $said, $phrase ) ) {
					$warned = true;
					break;
				}
			}
			if ( ! $warned ) {
				return self::issue(
					'new_window',
					'note',
					__( 'A link opens a new tab without warning.', 'rayetun-events-calendar' ),
					__( 'Add "(opens in a new tab)" to the link text, so nobody loses their place unexpectedly.', 'rayetun-events-calendar' ),
					'description'
				);
			}
		}
		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * The shape of the description
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A second level-one heading inside the description.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function heading_level_one( array $event ): ?array {
		if ( ! preg_match( '/<h1\b/i', (string) $event['description'] ) ) {
			return null;
		}
		return self::issue(
			'heading_h1',
			'error',
			__( 'The description contains a level-one heading.', 'rayetun-events-calendar' ),
			__( 'The event name is already the page&#8217;s only level-one heading. Make this one a level two.', 'rayetun-events-calendar' ),
			'description'
		);
	}

	/**
	 * Heading levels that jump a step.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function heading_level_skipped( array $event ): ?array {
		if ( ! preg_match_all( '/<h([1-6])\b/i', (string) $event['description'], $found ) ) {
			return null;
		}
		$previous = 1; // The event name is the level-one heading above all this.
		foreach ( $found[1] as $level ) {
			$level = (int) $level;
			if ( $level - $previous > 1 ) {
				return self::issue(
					'heading_skip',
					'warning',
					sprintf(
						/* translators: 1: heading level found, 2: heading level above it. */
						__( 'A level-%1$d heading follows a level-%2$d one, skipping a step.', 'rayetun-events-calendar' ),
						$level,
						$previous
					),
					__( 'People who move around by heading rely on the order. Step down one level at a time.', 'rayetun-events-calendar' ),
					'description'
				);
			}
			$previous = $level;
		}
		return null;
	}

	/**
	 * A table with no header cells.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function table_without_headers( array $event ): ?array {
		$content = (string) $event['description'];
		if ( ! preg_match( '/<table\b/i', $content ) || preg_match( '/<th\b/i', $content ) ) {
			return null;
		}
		return self::issue(
			'table_no_header',
			'warning',
			__( 'A table in the description has no header row.', 'rayetun-events-calendar' ),
			__( 'Switch the table&#8217;s header row on, so each cell is read with the column it belongs to.', 'rayetun-events-calendar' ),
			'description'
		);
	}

	/**
	 * No description at all.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function no_description( array $event ): ?array {
		if ( '' !== trim( wp_strip_all_tags( (string) $event['description'] ) ) ) {
			return null;
		}
		return self::issue(
			'no_description',
			'warning',
			__( 'Add a description so people know what to expect.', 'rayetun-events-calendar' ),
			__( 'What happens, who it is for, and anything someone would want to know before deciding to come.', 'rayetun-events-calendar' ),
			'description'
		);
	}

	/**
	 * A poster carrying the detail that the words should carry.
	 *
	 * This is the closest honest test for text trapped in an image: a picture,
	 * and almost nothing written down beside it.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function detail_only_in_the_picture( array $event ): ?array {
		$text  = trim( wp_strip_all_tags( (string) $event['description'] ) );
		$words = '' === $text ? 0 : preg_match_all( '/\S+/u', $text );
		$shown = $event['thumbnail_id'] || preg_match( '/<img\b/i', (string) $event['description'] );
		if ( ! $shown || 0 === $words || $words >= 25 ) {
			return null;
		}
		return self::issue(
			'detail_in_picture',
			'warning',
			__( 'There is a picture but barely any text, so the detail may only be in the picture.', 'rayetun-events-calendar' ),
			__( 'Anything a poster says &#8212; times, prices, who to ask &#8212; has to be written out as well. Nobody can read a picture with their ears, and a search engine cannot read one either.', 'rayetun-events-calendar' ),
			'description'
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Where, when and how
	 * ---------------------------------------------------------------------
	 */

	/**
	 * An in-person event with nowhere to go.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function missing_venue( array $event ): ?array {
		if ( ! self::in_person( $event ) || ! empty( $event['venue'] ) ) {
			return null;
		}
		return self::issue(
			'no_venue',
			'warning',
			__( 'No venue is set, so visitors cannot check how to get in.', 'rayetun-events-calendar' ),
			__( 'Choose a venue, or add one with its address and access details.', 'rayetun-events-calendar' ),
			'venue'
		);
	}

	/**
	 * A venue nobody can check before setting out.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function venue_without_access_details( array $event ): ?array {
		if ( ! self::in_person( $event ) || empty( $event['venue'] ) ) {
			return null;
		}
		if ( ! empty( $event['venue']['access'] ) || '' !== trim( (string) $event['venue']['access_notes'] ) ) {
			return null;
		}
		return self::issue(
			'venue_access',
			'warning',
			__( 'The venue has no access details yet.', 'rayetun-events-calendar' ),
			__( 'Step-free entry, an accessible toilet, a hearing loop, parking: a disabled visitor decides whether to come on this, and silence reads as "no".', 'rayetun-events-calendar' ),
			'venue'
		);
	}

	/**
	 * An online event with no way in.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function online_without_a_link( array $event ): ?array {
		if ( ! self::online( $event ) || '' !== (string) $event['online_url'] ) {
			return null;
		}
		return self::issue(
			'no_online_url',
			'warning',
			__( 'This event is online but has no joining link.', 'rayetun-events-calendar' ),
			__( 'Add the joining address, or say in the description how the link will reach people.', 'rayetun-events-calendar' ),
			'online'
		);
	}

	/**
	 * A timed event that never says when it finishes.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function no_end_time( array $event ): ?array {
		if ( $event['all_day'] || '' === (string) $event['start'] || '' !== (string) $event['end'] ) {
			return null;
		}
		return self::issue(
			'no_end_time',
			'note',
			__( 'The event has no finishing time.', 'rayetun-events-calendar' ),
			__( 'Anyone booking transport, a carer or an interpreter needs to know how long to book for.', 'rayetun-events-calendar' ),
			'when'
		);
	}

	/**
	 * A category colour too pale to see.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function category_colour_contrast( array $event ): ?array {
		foreach ( (array) $event['categories'] as $category ) {
			if ( $category['color'] && self::contrast( $category['color'], '#ffffff' ) < 3 ) {
				return self::issue(
					'category_contrast',
					'error',
					/* translators: %s: category name. */
					sprintf( __( 'The colour of the "%s" category is too pale to see on white.', 'rayetun-events-calendar' ), $category['name'] ),
					__( 'Darken it on the Categories screen until the contrast check there passes.', 'rayetun-events-calendar' ),
					'categories'
				);
			}
		}
		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * The name
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A name written in capitals throughout.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function shouting_title( array $event ): ?array {
		$title = (string) $event['title'];
		$upper = preg_match_all( '/\p{Lu}/u', $title );
		$lower = preg_match_all( '/\p{Ll}/u', $title );
		if ( $upper < 8 || $lower > 0 ) {
			return null;
		}
		return self::issue(
			'title_shouting',
			'note',
			__( 'The event name is written in capitals throughout.', 'rayetun-events-calendar' ),
			__( 'Some screen readers spell capitals out letter by letter, and capitals are harder going for people with dyslexia. Sentence case carries the same emphasis.', 'rayetun-events-calendar' ),
			'title'
		);
	}

	/**
	 * A run of emoji in the name.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, string>|null
	 */
	private static function emoji_pile_in_title( array $event ): ?array {
		$count = preg_match_all( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]/u', (string) $event['title'] );
		if ( $count < 3 ) {
			return null;
		}
		return self::issue(
			'title_emoji',
			'note',
			/* translators: %d: number of emoji found. */
			sprintf( __( 'The event name has %d emoji in it.', 'rayetun-events-calendar' ), $count ),
			__( 'Each one is read out by name, so a row of them becomes a mouthful before the event is even named. One is usually plenty.', 'rayetun-events-calendar' ),
			'title'
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Build an issue.
	 *
	 * @param string $code    Issue code.
	 * @param string $level   "error", "warning" or "note".
	 * @param string $message What is wrong.
	 * @param string $fix     What to do about it.
	 * @param string $where   Which part of the event it is about.
	 * @return array<string, string>
	 */
	private static function issue( string $code, string $level, string $message, string $fix = '', string $where = '' ): array {
		return array(
			'code'    => $code,
			'level'   => $level,
			'message' => $message,
			'fix'     => $fix,
			'where'   => $where,
		);
	}

	/**
	 * The featured image's alternative text.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string
	 */
	private static function alt( array $event ): string {
		if ( ! $event['thumbnail_id'] ) {
			return '';
		}
		return trim( (string) get_post_meta( (int) $event['thumbnail_id'], '_wp_attachment_image_alt', true ) );
	}

	/**
	 * The links in the description.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<int, array{text: string, href: string, title: string, new_window: bool}>
	 */
	private static function links( array $event ): array {
		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', (string) $event['description'], $found, PREG_SET_ORDER ) ) {
			return array();
		}
		$links = array();
		foreach ( $found as $match ) {
			$links[] = array(
				'text'       => trim( wp_strip_all_tags( $match[2] ) ),
				'href'       => self::attribute( $match[1], 'href' ),
				'title'      => self::attribute( $match[1], 'title' ),
				'new_window' => '_blank' === strtolower( self::attribute( $match[1], 'target' ) ),
			);
		}
		return $links;
	}

	/**
	 * One attribute out of a tag's attribute string.
	 *
	 * @param string $attributes The text between the tag name and the closing bracket.
	 * @param string $name       Attribute name.
	 * @return string
	 */
	private static function attribute( string $attributes, string $name ): string {
		$pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i';
		if ( ! preg_match( $pattern, $attributes, $found ) ) {
			return '';
		}
		$value = $found[2] . ( $found[3] ?? '' ) . ( $found[4] ?? '' );
		return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Lower case, with runs of space collapsed, for comparing two bits of text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function flatten( string $text ): string {
		$text = strtolower( trim( wp_strip_all_tags( $text ) ) );
		return (string) preg_replace( '/\s+/u', ' ', $text );
	}

	/**
	 * Whether anyone is expected to turn up in a room.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return bool
	 */
	private static function in_person( array $event ): bool {
		return in_array( $event['format'], array( 'in_person', 'hybrid' ), true ) && 'moved_online' !== $event['event_status'];
	}

	/**
	 * Whether anyone is expected to join from elsewhere.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return bool
	 */
	private static function online( array $event ): bool {
		return in_array( $event['format'], array( 'online', 'hybrid' ), true ) || 'moved_online' === $event['event_status'];
	}

	/**
	 * WCAG contrast ratio between two hex colours.
	 *
	 * @param string $a Hex colour.
	 * @param string $b Hex colour.
	 * @return float
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Relative luminance of a hex colour.
	 *
	 * @param string $hex Hex colour.
	 * @return float
	 */
	private static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return 0.0;
		}
		$channels = array();
		foreach ( str_split( $hex, 2 ) as $pair ) {
			$value      = hexdec( $pair ) / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
}
