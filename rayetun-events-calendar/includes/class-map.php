<?php
/**
 * Still map pictures, built on the server.
 *
 * A map embed is third-party content: it tells OpenStreetMap who is reading
 * the page. The embed therefore only ever loads when the visitor asks for it.
 * This class offers the other way round — the server fetches the tiles once,
 * stitches them into one picture and keeps it in the uploads folder, so the
 * visitor sees a map without their browser ever speaking to another site.
 *
 * Tiles come from OpenStreetMap's own servers, so this is deliberately not the
 * default, it happens once per place rather than once per view, and the picture
 * carries the attribution OpenStreetMap asks for.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and caches a still map for a set of coordinates.
 */
class Map {

	/**
	 * Zoom level: close enough to name the street.
	 *
	 * @var int
	 */
	const ZOOM = 16;

	/**
	 * Picture size in pixels.
	 *
	 * @var int
	 */
	const WIDTH  = 768;
	const HEIGHT = 420;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rayec_build_map', array( __CLASS__, 'build' ), 10, 2 );
	}

	/**
	 * Whether a still picture can be built at all.
	 *
	 * @return bool
	 */
	public static function possible(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecreatefromstring' );
	}

	/**
	 * The file name a place's picture is kept under.
	 *
	 * @param string $lat Latitude.
	 * @param string $lng Longitude.
	 * @return string
	 */
	private static function name( string $lat, string $lng ): string {
		return 'map-' . md5( $lat . ',' . $lng . ',' . self::ZOOM . ',' . self::WIDTH . 'x' . self::HEIGHT ) . '.png';
	}

	/**
	 * Where the pictures live.
	 *
	 * @return array{dir: string, url: string}|null
	 */
	private static function folder(): ?array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . 'rayec-maps',
			'url' => trailingslashit( $uploads['baseurl'] ) . 'rayec-maps',
		);
	}

	/**
	 * The picture for a place, if it has already been built.
	 *
	 * Asks for it to be built in the background when it has not, so the page
	 * being looked at now is never held up by six requests to another server.
	 *
	 * @param string $lat Latitude.
	 * @param string $lng Longitude.
	 * @return string URL, or an empty string while there is nothing to show.
	 */
	public static function url( string $lat, string $lng ): string {
		$folder = self::folder();
		if ( ! $folder || ! self::possible() || '' === $lat || '' === $lng ) {
			return '';
		}

		$name = self::name( $lat, $lng );
		if ( file_exists( $folder['dir'] . '/' . $name ) ) {
			return $folder['url'] . '/' . $name;
		}

		// One failure is enough; do not ask again on every view.
		if ( get_transient( 'rayec_map_' . $name ) ) {
			return '';
		}
		if ( ! wp_next_scheduled( 'rayec_build_map', array( $lat, $lng ) ) ) {
			wp_schedule_single_event( time(), 'rayec_build_map', array( $lat, $lng ) );
		}
		return '';
	}

	/**
	 * Fetch the tiles and stitch them together.
	 *
	 * @param string $lat Latitude.
	 * @param string $lng Longitude.
	 * @return string URL of the finished picture, or an empty string.
	 */
	public static function build( string $lat, string $lng ): string {
		$folder = self::folder();
		if ( ! $folder || ! self::possible() ) {
			return '';
		}

		$name = self::name( $lat, $lng );
		$path = $folder['dir'] . '/' . $name;
		if ( file_exists( $path ) ) {
			return $folder['url'] . '/' . $name;
		}

		$scale  = 2 ** self::ZOOM;
		$rad    = deg2rad( (float) $lat );
		$centre = array(
			256 * $scale * ( ( (float) $lng + 180 ) / 360 ),
			256 * $scale * ( ( 1 - log( tan( $rad ) + 1 / cos( $rad ) ) / M_PI ) / 2 ),
		);

		$left = $centre[0] - self::WIDTH / 2;
		$top  = $centre[1] - self::HEIGHT / 2;
		$x0   = (int) floor( $left / 256 );
		$y0   = (int) floor( $top / 256 );
		$x1   = (int) floor( ( $left + self::WIDTH - 1 ) / 256 );
		$y1   = (int) floor( ( $top + self::HEIGHT - 1 ) / 256 );

		$canvas = imagecreatetruecolor( ( $x1 - $x0 + 1 ) * 256, ( $y1 - $y0 + 1 ) * 256 );
		if ( ! $canvas ) {
			return '';
		}

		for ( $x = $x0; $x <= $x1; $x++ ) {
			for ( $y = $y0; $y <= $y1; $y++ ) {
				$tile = self::tile( $x, $y );
				if ( ! $tile ) {
					imagedestroy( $canvas );
					// Try again, but not for a while.
					set_transient( 'rayec_map_' . $name, 1, 6 * HOUR_IN_SECONDS );
					return '';
				}
				imagecopy( $canvas, $tile, ( $x - $x0 ) * 256, ( $y - $y0 ) * 256, 0, 0, 256, 256 );
				imagedestroy( $tile );
			}
		}

		$picture = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		imagecopy( $picture, $canvas, 0, 0, (int) round( $left - $x0 * 256 ), (int) round( $top - $y0 * 256 ), self::WIDTH, self::HEIGHT );
		imagedestroy( $canvas );

		self::pin( $picture );

		if ( ! wp_mkdir_p( $folder['dir'] ) ) {
			imagedestroy( $picture );
			return '';
		}

		ob_start();
		imagepng( $picture );
		$bytes = (string) ob_get_clean();
		imagedestroy( $picture );

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $path, $bytes, FS_CHMOD_FILE ) ) {
			return '';
		}

		delete_transient( 'rayec_map_' . $name );
		return $folder['url'] . '/' . $name;
	}

	/**
	 * One map tile as an image resource.
	 *
	 * @param int $x Tile column.
	 * @param int $y Tile row.
	 * @return \GdImage|false
	 */
	private static function tile( int $x, int $y ) {
		$response = wp_remote_get(
			'https://tile.openstreetmap.org/' . self::ZOOM . '/' . $x . '/' . $y . '.png',
			array(
				'timeout' => 10,
				// OpenStreetMap asks every program that fetches tiles to name itself.
				'headers' => array( 'User-Agent' => 'RayEtunEventsCalendar/' . RAYEC_VERSION . ' (WordPress plugin)' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		return @imagecreatefromstring( wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a damaged tile is handled by the false return.
	}

	/**
	 * Draw the marker in the middle of the picture.
	 *
	 * @param \GdImage $picture Picture.
	 * @return void
	 */
	private static function pin( $picture ): void {
		$x    = (int) ( self::WIDTH / 2 );
		$y    = (int) ( self::HEIGHT / 2 );
		$ring = imagecolorallocate( $picture, 255, 255, 255 );
		$fill = imagecolorallocate( $picture, 15, 118, 110 );
		$edge = imagecolorallocate( $picture, 0, 0, 0 );

		imagefilledpolygon( $picture, array( $x - 7, $y + 2, $x + 7, $y + 2, $x, $y + 20 ), $ring );
		imagefilledellipse( $picture, $x, $y, 30, 30, $ring );
		imagefilledpolygon( $picture, array( $x - 5, $y + 2, $x + 5, $y + 2, $x, $y + 17 ), $fill );
		imagefilledellipse( $picture, $x, $y, 24, 24, $fill );
		imagefilledellipse( $picture, $x, $y, 9, 9, $ring );
		imageellipse( $picture, $x, $y, 25, 25, $edge );
	}
}
