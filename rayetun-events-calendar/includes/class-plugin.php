<?php
/**
 * Plugin bootstrap.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every component together.
 */
class Plugin {

	const SETTINGS_OPTION = 'rayec_settings';

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public static function init(): void {
		Installer::maybe_upgrade();
		Post_Types::init();
		Event::init();
		Occurrences::init();
		Registrations::init();
		Rest::init();
		Frontend::init();
		Blocks::init();
		Templates::init();
		Map::init();
		Patterns::init();
		Seo::init();
		Migrate::init();
		Subscriptions::init();
		Ics::init();

		Editor::init();

		if ( is_admin() ) {
			Admin::init();
		}

		/**
		 * Fires once the plugin is ready. Add-ons (such as the Pro plugin) boot here.
		 */
		do_action( 'rayec_loaded' );
	}

	/**
	 * Dates the organisation is closed (public holidays and the like).
	 *
	 * @return array<string, string> "Y-m-d" => label.
	 */
	public static function closed_dates(): array {
		$out = array();
		foreach ( (array) self::setting( 'closed_dates' ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['date'] ) ) {
				$out[ (string) $row['date'] ] = (string) ( $row['label'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'default_view'      => 'month',
			'week_starts_on'    => (int) get_option( 'start_of_week', 1 ),
			'time_format'       => '',
			'events_slug'       => 'events',
			'show_past'         => false,
			'theme'             => 'inherit',
			'map_mode'          => 'ask',
			'list_per_page'     => 10,
			'delete_data'       => false,
			'closed_dates'      => array(),
			// Email the organiser about each registration; an empty address means the site's.
			'rsvp_notify'       => true,
			'rsvp_notify_email' => '',
		);
	}

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		return array_merge( self::default_settings(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function setting( string $key ) {
		$settings = self::settings();
		return $settings[ $key ] ?? null;
	}

	/**
	 * Sanitize and save settings.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, mixed> Saved settings.
	 */
	public static function save_settings( array $input ): array {
		$current = self::settings();
		$views   = array( 'month', 'week', 'day', 'list' );

		if ( isset( $input['default_view'] ) && in_array( $input['default_view'], $views, true ) ) {
			$current['default_view'] = $input['default_view'];
		}
		if ( isset( $input['week_starts_on'] ) ) {
			$current['week_starts_on'] = max( 0, min( 6, (int) $input['week_starts_on'] ) );
		}
		if ( isset( $input['time_format'] ) ) {
			$current['time_format'] = sanitize_text_field( (string) $input['time_format'] );
		}
		if ( isset( $input['events_slug'] ) ) {
			$slug = sanitize_title( (string) $input['events_slug'] );
			if ( '' !== $slug && $slug !== $current['events_slug'] ) {
				$current['events_slug'] = $slug;
				update_option( 'rayec_flush_rewrite', 1, false );
			}
		}
		if ( isset( $input['show_past'] ) ) {
			$current['show_past'] = (bool) $input['show_past'];
		}
		if ( isset( $input['theme'] ) && in_array( $input['theme'], array( 'inherit', 'light', 'dark', 'system', 'contrast' ), true ) ) {
			$current['theme'] = $input['theme'];
		}
		if ( isset( $input['map_mode'] ) && in_array( $input['map_mode'], array( 'ask', 'static', 'text' ), true ) ) {
			$current['map_mode'] = $input['map_mode'];
		}
		if ( isset( $input['list_per_page'] ) ) {
			$current['list_per_page'] = max( 1, min( 100, (int) $input['list_per_page'] ) );
		}
		if ( isset( $input['delete_data'] ) ) {
			$current['delete_data'] = (bool) $input['delete_data'];
		}

		if ( isset( $input['rsvp_notify'] ) ) {
			$current['rsvp_notify'] = (bool) $input['rsvp_notify'];
		}
		if ( isset( $input['rsvp_notify_email'] ) ) {
			$current['rsvp_notify_email'] = sanitize_email( (string) $input['rsvp_notify_email'] );
		}

		if ( isset( $input['closed_dates'] ) && is_array( $input['closed_dates'] ) ) {
			$closed = array();
			foreach ( $input['closed_dates'] as $row ) {
				$date = is_array( $row ) ? (string) ( $row['date'] ?? '' ) : '';
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$closed[ $date ] = array(
						'date'  => $date,
						'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					);
				}
			}
			ksort( $closed );
			$changed                 = wp_json_encode( array_values( $closed ) ) !== wp_json_encode( $current['closed_dates'] );
			$current['closed_dates'] = array_values( $closed );
		}

		update_option( self::SETTINGS_OPTION, $current, false );

		if ( ! empty( $changed ) ) {
			// Series that skip closed dates need their occurrences rebuilt.
			Occurrences::extend_all();
		}
		return $current;
	}

	/**
	 * Time format to display with.
	 *
	 * @return string
	 */
	public static function time_format(): string {
		$format = (string) self::setting( 'time_format' );
		return '' !== $format ? $format : (string) get_option( 'time_format', 'g:i a' );
	}
}
