<?php
/**
 * Connection point for add-ons such as RayEtun Events Calendar Pro.
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
 * Registration (extension API 2)
 * - constant `RAYEC_EXTENSION_API`   The version of these hooks. Check it rather than the plugin version.
 * - filter `rayec_registration_fields`     Extra HTML inside the registration form, before the button.
 * - filter `rayec_registration_validate`   Errors for a submitted registration, keyed by field.
 * - filter `rayec_registration_data`       Extra data stored with a registration (JSON).
 * - filter `rayec_registration_status`     The status a new registration starts with: "confirmed" or "pending".
 * - filter `rayec_registration_when_full`  Return "waitlisted" to accept a registration on a full date.
 * - filter `rayec_registration_redirect`   Where someone goes after registering (a checkout, say).
 * - filter `rayec_registration_email`      A registration email before it is sent.
 * - filter `rayec_registration_admin_statuses`  Statuses an organiser can set from the dashboard.
 * - action `rayec_registration_created`    After a registration is written: ( $id, $event, $token ).
 * - action `rayec_registration_status_changed`  ( $id, $status, $old, $by ).
 * - action `rayec_event_status_changed`   An event was cancelled, postponed, moved online or reinstated: ( $id, $new, $old ). Fires however the status was changed.
 *
 * JavaScript hooks (wp.hooks)
 * - filter `rayec.screens`           Add dashboard screens: { path, label, icon, component }.
 * - filter `rayec.eventPayload`      Change the data sent when an event is saved from the drawer.
 * - filter `rayec.attendeeColumns`   Add columns to the attendee list: { key, label, value( registration ) }.
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
