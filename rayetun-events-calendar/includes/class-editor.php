<?php
/**
 * Block editor integration: the event details panel.
 *
 * Event fields are exposed as REST meta so the panel edits them with the post
 * and they save, autosave and revert together with it.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor panel support.
 */
class Editor {

	const META = array(
		'_rayec_start'        => 'string',
		'_rayec_end'          => 'string',
		'_rayec_timezone'     => 'string',
		'_rayec_all_day'      => 'boolean',
		'_rayec_rrule'        => 'string',
		'_rayec_status'       => 'string',
		'_rayec_format'       => 'string',
		'_rayec_venue_id'     => 'integer',
		'_rayec_room'         => 'string',
		'_rayec_organizer_id' => 'integer',
		'_rayec_online_url'   => 'string',
		'_rayec_cost'         => 'string',
		'_rayec_skip_closed'  => 'boolean',
	);

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 6 );
		add_action( 'rest_after_insert_' . Post_Types::EVENT, array( __CLASS__, 'after_save' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Expose event fields to the editor.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		foreach ( self::META as $key => $type ) {
			register_post_meta(
				Post_Types::EVENT,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => '_rayec_online_url' === $key
						? static fn( $value ) => esc_url_raw( (string) $value )
						: ( 'string' === $type ? static fn( $value ) => sanitize_text_field( (string) $value ) : null ),
					'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
				)
			);
		}

		$auth = static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id );
		register_post_meta(
			Post_Types::EVENT,
			'_rayec_extra_times',
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'sanitize_callback' => static fn( $value ) => Event::clean_times( $value, '' ),
				'auth_callback'     => $auth,
			)
		);
		// One row per speaker.
		register_post_meta(
			Post_Types::EVENT,
			'_rayec_speaker',
			array(
				'type'              => 'integer',
				'single'            => false,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			)
		);
		// Access laid on at the event itself.
		register_post_meta(
			Post_Types::EVENT,
			'_rayec_event_access',
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'sanitize_callback' => array( Event::class, 'clean_access' ),
				'auth_callback'     => $auth,
			)
		);
		register_post_meta(
			Post_Types::EVENT,
			'_rayec_event_access_notes',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ) => sanitize_textarea_field( (string) $value ),
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * After the editor saves an event: tidy its fields and rebuild its dates.
	 *
	 * @param \WP_Post $post Saved post.
	 * @return void
	 */
	public static function after_save( $post ): void {
		Event::sync( (int) $post->ID );
	}

	/**
	 * Load the panel in the event editor.
	 *
	 * @return void
	 */
	public static function assets(): void {
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$asset_file = RAYEC_DIR . 'build/editor.asset.php';
		if ( ! $screen || Post_Types::EVENT !== $screen->post_type || ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_style( 'rayec-editor', RAYEC_URL . 'build/editor.css', array( 'wp-components' ), $asset['version'] );
		wp_enqueue_script( 'rayec-editor', RAYEC_URL . 'build/editor.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'rayec-editor', 'rayetun-events-calendar' );
		wp_add_inline_script( 'rayec-editor', 'window.rayecAdmin = ' . wp_json_encode( Admin::config() ) . ';', 'before' );
	}
}
