<?php
/**
 * Connection point for add-ons such as RayEtun Accessible Events Calendar Pro.
 *
 * The free plugin contains no premium code. A separate add-on plugin connects
 * itself through the hooks documented here.
 *
 * PHP hooks
 * - action `rayec_loaded`            Fires once the free plugin is ready. Add-ons boot here.
 * - filter `rayec_addons`            Register an add-on: array( 'slug' => array( 'name' => '', 'version' => '' ) ).
 * - filter `rayec_admin_config`      Add data to window.rayecAdmin for the dashboard app.
 * - action `rayec_admin_enqueue`     Enqueue add-on scripts on the dashboard screen (after the app's own).
 * - action `rayec_register_rest`     Register extra routes in the rayec/v1 namespace.
 * - action `rayec_event_saved`       After an event is saved and its occurrences rebuilt.
 * - filter `rayec_event_data`        Event data returned by Event::get().
 * - filter `rayec_guard_issues`      Accessibility Guard issues for an event.
 * - filter `rayec_single_before` / `rayec_single_after`  HTML around the single-event description.
 *
 * JavaScript hooks (wp.hooks)
 * - filter `rayec.screens`           Add dashboard screens: { path, label, icon, component }.
 * - filter `rayec.eventPayload`      Change the data sent when an event is saved from the drawer.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add-on registry.
 */
class Extensions {

	/**
	 * Registered add-ons.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function addons(): array {
		/**
		 * Filters the list of connected add-ons.
		 *
		 * @param array<string, array<string, string>> $addons Add-ons keyed by slug.
		 */
		$addons = apply_filters( 'rayec_addons', array() );
		return is_array( $addons ) ? $addons : array();
	}

	/**
	 * Whether a given add-on is connected.
	 *
	 * @param string $slug Add-on slug, for example "pro".
	 * @return bool
	 */
	public static function has( string $slug ): bool {
		return isset( self::addons()[ $slug ] );
	}
}
