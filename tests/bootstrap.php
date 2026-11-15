<?php
/**
 * Test bootstrap.
 *
 * The recurrence engine and the Accessibility Guard are the two pieces worth
 * testing on their own, and neither needs a database. This file loads them
 * with the handful of WordPress functions they call stubbed out, so the suite
 * runs in a second against plain PHP.
 *
 * Run it either way:
 *
 *   phpunit                 (with PHPUnit installed)
 *   php tests/run.php       (without it; see that file)
 *
 * @package RayEtun\EventsCalendar
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

// ---------------------------------------------------------------------------
// The WordPress functions these two classes call, and nothing else.
// ---------------------------------------------------------------------------

if ( ! function_exists( '__' ) ) {
	/**
	 * Pass the string through untranslated.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Return the value unfiltered.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $hook );
		return $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip tags, script and style.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON, for printing a value in a failure message.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function wp_json_encode( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'wp_sprintf' ) ) {
	/**
	 * Enough of wp_sprintf() for the recurrence sentences: the %l list.
	 *
	 * @param string $pattern Pattern.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	function wp_sprintf( $pattern, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( '%l' === $pattern ) {
			$list = (array) ( $args[0] ?? array() );
			if ( count( $list ) < 2 ) {
				return (string) reset( $list );
			}
			$last = array_pop( $list );
			return implode( ', ', $list ) . ' and ' . $last;
		}
		return vsprintf( $pattern, $args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Fixed options for the suite.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	function get_option( $name, $default_value = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$options = array( 'date_format' => 'j F Y' );
		return $options[ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Format a timestamp in a given zone.
	 *
	 * @param string            $format    Date format.
	 * @param int|null          $timestamp Timestamp.
	 * @param DateTimeZone|null $timezone  Time zone.
	 * @return string
	 */
	function wp_date( $format, $timestamp = null, $timezone = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$date = new DateTimeImmutable( '@' . (int) ( $timestamp ?? time() ) );
		return $date->setTimezone( $timezone ?? new DateTimeZone( 'UTC' ) )->format( $format );
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * The site's time zone.
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return new DateTimeZone( 'UTC' );
	}
}

/**
 * Alternative text, keyed by attachment ID, that a test has set up.
 *
 * @var array<int, string>
 */
$GLOBALS['rayec_test_alt'] = array();

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Only the alternative text the Guard reads.
	 *
	 * @param int    $post_id Attachment ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $single );
		if ( '_wp_attachment_image_alt' === $key ) {
			return $GLOBALS['rayec_test_alt'][ $post_id ] ?? '';
		}
		return '';
	}
}

// ---------------------------------------------------------------------------
// The classes under test.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../rayetun-events-calendar/includes/class-recurrence.php';
require_once __DIR__ . '/../rayetun-events-calendar/includes/class-accessibility-guard.php';
