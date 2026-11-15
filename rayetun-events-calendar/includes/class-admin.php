<?php
/**
 * Admin menu and dashboard app loader.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mounts the single-page dashboard.
 */
class Admin {

	const PAGE_SLUG = 'rayec';

	/**
	 * Menu hook suffix.
	 *
	 * @var string
	 */
	private static string $hook_suffix = '';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RAYEC_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Register the menu. Sub-items are deep links into the app.
	 *
	 * @return void
	 */
	public static function menu(): void {
		self::$hook_suffix = (string) add_menu_page(
			__( 'Events Calendar', 'rayetun-events-calendar' ),
			__( 'Events', 'rayetun-events-calendar' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-calendar-alt',
			26
		);

		$screens = array(
			''          => __( 'Home', 'rayetun-events-calendar' ),
			'#/planner' => __( 'Planner', 'rayetun-events-calendar' ),
			'#/events'  => __( 'All events', 'rayetun-events-calendar' ),
			'#/venues'  => __( 'Venues', 'rayetun-events-calendar' ),
		);
		foreach ( $screens as $hash => $label ) {
			add_submenu_page( self::PAGE_SLUG, $label, $label, 'edit_posts', self::PAGE_SLUG . $hash, array( __CLASS__, 'render' ) );
		}
		add_submenu_page( self::PAGE_SLUG, __( 'Settings', 'rayetun-events-calendar' ), __( 'Settings', 'rayetun-events-calendar' ), 'manage_options', self::PAGE_SLUG . '#/settings', array( __CLASS__, 'render' ) );
	}

	/**
	 * Mark the screen so the app can take the full canvas.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->id === self::$hook_suffix ) {
			$classes .= ' rayec-app-screen';
		}
		return $classes;
	}

	/**
	 * Mount point.
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<div id="rayec-app" class="rayec-admin"><noscript><p>' . esc_html__( 'The Events dashboard needs JavaScript. Please enable it and reload.', 'rayetun-events-calendar' ) . '</p></noscript></div>';
	}

	/**
	 * Load the app on its own screen only.
	 *
	 * @param string $hook_suffix Current screen hook.
	 * @return void
	 */
	public static function assets( $hook_suffix ): void {
		if ( $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		$asset_file = RAYEC_DIR . 'build/admin.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		// Media library, for choosing an event image in the drawer.
		wp_enqueue_media();
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'rayec-admin', RAYEC_URL . 'build/admin.css', array( 'wp-components' ), $asset['version'] );
		wp_enqueue_script( 'rayec-admin', RAYEC_URL . 'build/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'rayec-admin', 'rayetun-events-calendar' );

		wp_add_inline_script( 'rayec-admin', 'window.rayecAdmin = ' . wp_json_encode( self::config() ) . ';', 'before' );

		/**
		 * Fires after the dashboard app is enqueued. Add-ons enqueue their scripts
		 * here with 'rayec-admin' as a dependency.
		 */
		do_action( 'rayec_admin_enqueue' );
	}

	/**
	 * Data handed to the dashboard app and the editor panel.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$user   = wp_get_current_user();
		$config = array(
			'version'        => RAYEC_VERSION,
			'timezone'       => wp_timezone_string(),
			'timezones'      => timezone_identifiers_list(),
			'weekStartsOn'   => (int) Plugin::setting( 'week_starts_on' ),
			'locale'         => str_replace( '_', '-', get_user_locale() ),
			'canManage'      => current_user_can( 'manage_options' ),
			'userName'       => $user->display_name,
			'siteName'       => get_bloginfo( 'name' ),
			'accessFeatures' => Venue::access_features(),
			'categoryIcons'  => Frontend::category_icons(),
			'dashboardUrl'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
			'supportUrl'     => 'https://wordpress.org/support/plugin/rayetun-events-calendar/',
			'addons'         => Extensions::addons(),
		);

		/**
		 * Filters the data handed to the dashboard app.
		 *
		 * @param array<string, mixed> $config Config.
		 */
		return apply_filters( 'rayec_admin_config', $config );
	}

	/**
	 * Plugins screen link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Dashboard', 'rayetun-events-calendar' ) . '</a>' );
		return $links;
	}
}
