<?php
/**
 * The picture a link to an event shows when it is shared.
 *
 * An event with a picture of its own uses that. For one without, this draws a
 * plain card: the date, the title and the site's name, at the size the social
 * networks ask for, kept in the uploads folder and built once.
 *
 * Drawing text needs a font file, and WordPress ships none that can be used
 * for words. So the card is drawn only when the site has a font to draw with —
 * one installed through the Font Library, one in the theme, or one named by a
 * filter. Without one, rather than drawing something ugly, the event falls
 * back to the still map of its venue, then to the site icon, then to nothing.
 *
 * @package RayEtun\EventsCalendar
 */

namespace RayEtun\EventsCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generated sharing pictures.
 */
class Share_Image {

	/**
	 * The size every social network asks for.
	 *
	 * @var int
	 */
	const WIDTH  = 1200;
	const HEIGHT = 630;

	/**
	 * The best picture for an event, as a URL.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param array<int, mixed>    $times The occurrence being shown, if any.
	 * @return array{url: string, width: int, height: int, alt: string}|null
	 */
	public static function for_event( array $event, ?array $times = null ): ?array {
		if ( ! empty( $event['image'] ) ) {
			return array(
				'url'    => (string) $event['image']['url'],
				'width'  => (int) $event['image']['width'],
				'height' => (int) $event['image']['height'],
				'alt'    => '' !== $event['image']['alt'] ? (string) $event['image']['alt'] : (string) $event['title'],
			);
		}

		$card = self::card( $event, $times );
		if ( '' !== $card ) {
			return array(
				'url'    => $card,
				'width'  => self::WIDTH,
				'height' => self::HEIGHT,
				'alt'    => (string) $event['title'],
			);
		}

		// A map of where it is says something; a site icon at least says who.
		if ( ! empty( $event['venue'] ) && '' !== (string) $event['venue']['lat'] ) {
			$map = Map::url( (string) $event['venue']['lat'], (string) $event['venue']['lng'] );
			if ( '' !== $map ) {
				return array(
					'url'    => $map,
					'width'  => Map::WIDTH,
					'height' => Map::HEIGHT,
					/* translators: %s: venue name. */
					'alt'    => sprintf( __( 'Map showing %s', 'rayetun-events-calendar' ), $event['venue']['name'] ),
				);
			}
		}

		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			return array(
				'url'    => $icon,
				'width'  => 512,
				'height' => 512,
				'alt'    => get_bloginfo( 'name' ),
			);
		}

		return null;
	}

	/**
	 * A font file this site can draw with.
	 *
	 * @return string Path, or an empty string.
	 */
	public static function font(): string {
		/**
		 * Filters the font the sharing card is drawn with.
		 *
		 * @param string $path Absolute path to a .ttf or .otf file.
		 */
		$named = (string) apply_filters( 'rayec_share_image_font', '' );
		if ( '' !== $named && is_readable( $named ) ) {
			return $named;
		}

		$uploads = wp_upload_dir();
		$places  = array();
		if ( empty( $uploads['error'] ) ) {
			// Fonts installed through the Font Library live here.
			$places[] = trailingslashit( $uploads['basedir'] ) . 'fonts';
		}
		$places[] = get_stylesheet_directory() . '/assets/fonts';
		$places[] = get_template_directory() . '/assets/fonts';

		foreach ( $places as $place ) {
			if ( ! is_dir( $place ) ) {
				continue;
			}
			foreach ( (array) glob( $place . '/*.{ttf,otf,TTF,OTF}', GLOB_BRACE ) as $file ) {
				if ( is_readable( (string) $file ) ) {
					return (string) $file;
				}
			}
		}

		return '';
	}

	/**
	 * Draw, or find, the card for one event.
	 *
	 * @param array<string, mixed> $event Event.
	 * @param array<int, mixed>    $times The occurrence being shown, if any.
	 * @return string URL, or an empty string when there is no card.
	 */
	private static function card( array $event, ?array $times ): string {
		if ( ! function_exists( 'imagettftext' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$font = self::font();
		if ( '' === $font ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$when = $times ? Occurrences::format_range( $times[0], $times[1], (bool) $event['all_day'] ) : '';
		$site = (string) get_bloginfo( 'name' );
		// The name is in the file, so a renamed event gets a new card by itself.
		$name = 'share-' . (int) $event['id'] . '-' . substr( md5( $event['title'] . '|' . $when . '|' . $site . '|' . $font ), 0, 10 ) . '.png';
		$dir  = trailingslashit( $uploads['basedir'] ) . 'rayec-share';
		$url  = trailingslashit( $uploads['baseurl'] ) . 'rayec-share/' . $name;

		if ( file_exists( $dir . '/' . $name ) ) {
			return $url;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$image = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( ! $image ) {
			return '';
		}

		$ink   = imagecolorallocate( $image, 17, 27, 26 );
		$paper = imagecolorallocate( $image, 255, 255, 255 );
		$brand = imagecolorallocate( $image, 15, 118, 110 );
		$muted = imagecolorallocate( $image, 90, 110, 107 );

		imagefilledrectangle( $image, 0, 0, self::WIDTH, self::HEIGHT, $paper );
		imagefilledrectangle( $image, 0, 0, 18, self::HEIGHT, $brand );

		$left = 96;
		$top  = 150;

		if ( '' !== $when ) {
			imagettftext( $image, 30, 0, $left, $top, $brand, $font, self::fit( $when, $font, 30, self::WIDTH - $left - 96 ) );
			$top += 80;
		}

		// The title, as big as it can be while still fitting in three lines.
		$size  = 64;
		$lines = self::wrap( (string) $event['title'], $font, $size, self::WIDTH - $left - 96 );
		$count = count( $lines );
		while ( $count > 3 && $size > 34 ) {
			$size -= 6;
			$lines = self::wrap( (string) $event['title'], $font, $size, self::WIDTH - $left - 96 );
			$count = count( $lines );
		}
		$lines = array_slice( $lines, 0, 3 );
		foreach ( $lines as $line ) {
			$top += (int) round( $size * 1.3 );
			imagettftext( $image, $size, 0, $left, $top, $ink, $font, $line );
		}

		if ( '' !== $site ) {
			imagettftext( $image, 24, 0, $left, self::HEIGHT - 72, $muted, $font, self::fit( $site, $font, 24, self::WIDTH - $left - 96 ) );
		}

		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		imagedestroy( $image );

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $dir . '/' . $name, $bytes, FS_CHMOD_FILE ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * How wide a piece of text is, drawn at this size.
	 *
	 * @param string $text Text.
	 * @param string $font Font path.
	 * @param int    $size Point size.
	 * @return int
	 */
	private static function width( string $text, string $font, int $size ): int {
		$box = imagettfbbox( $size, 0, $font, $text );
		return is_array( $box ) ? (int) abs( $box[2] - $box[0] ) : 0;
	}

	/**
	 * Break text into lines that fit.
	 *
	 * @param string $text  Text.
	 * @param string $font  Font path.
	 * @param int    $size  Point size.
	 * @param int    $limit Width in pixels.
	 * @return array<int, string>
	 */
	private static function wrap( string $text, string $font, int $size, int $limit ): array {
		$words = (array) preg_split( '/\s+/', trim( $text ) );
		$lines = array();
		$line  = '';

		foreach ( $words as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( self::width( $try, $font, $size ) <= $limit || '' === $line ) {
				$line = $try;
				continue;
			}
			$lines[] = $line;
			$line    = $word;
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines ? $lines : array( '' );
	}

	/**
	 * Shorten one line until it fits, with an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param string $font  Font path.
	 * @param int    $size  Point size.
	 * @param int    $limit Width in pixels.
	 * @return string
	 */
	private static function fit( string $text, string $font, int $size, int $limit ): string {
		if ( self::width( $text, $font, $size ) <= $limit ) {
			return $text;
		}
		while ( mb_strlen( $text ) > 1 && self::width( $text . '…', $font, $size ) > $limit ) {
			$text = mb_substr( $text, 0, mb_strlen( $text ) - 1 );
		}
		return $text . '…';
	}
}
