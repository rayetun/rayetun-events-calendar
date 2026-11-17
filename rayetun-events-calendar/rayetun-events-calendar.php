<?php
/**
 * Plugin Name:       RayEtun Events Calendar – Accessible Recurring Events & Event Management
 * Plugin URI:        https://rayetun.com/events-calendar/
 * Description:       Accessible event calendar with recurring events, month, week, day and list views, venues, iCal import and export, blocks and shortcodes.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            RayEtun
 * Author URI:        https://rayetun.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rayetun-events-calendar
 * Domain Path:       /languages
 *
 * @package RayEtun\EventsCalendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RAYEC_VERSION', '1.0.0' );
define( 'RAYEC_DB_VERSION', '2' );

/*
 * The version of the hooks add-ons connect through. It goes up whenever a
 * hook an add-on relies on is added, so the Pro plugin can check for what
 * it needs without depending on a release number.
 */
define( 'RAYEC_EXTENSION_API', 2 );
define( 'RAYEC_FILE', __FILE__ );
define( 'RAYEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAYEC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload plugin classes: RayEtun\EventsCalendar\Foo_Bar => includes/class-foo-bar.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'RayEtun\\EventsCalendar\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = RAYEC_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'RayEtun\\EventsCalendar\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RayEtun\\EventsCalendar\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'RayEtun\\EventsCalendar\\Plugin', 'init' ) );
