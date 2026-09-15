<?php
/**
 * Render an animated countdown GIF for email clients that cannot run JS.
 *
 * Remaining time is computed at image-request time (Gmail's proxy fetch),
 * then the GIF animates for up to 60 seconds. Clients play it once and hold
 * the last frame; they cannot keep counting after that without JavaScript.
 *
 * @package DoubleScale\Pro\Modules\Emails
 */

namespace DoubleScale\Pro\Modules\Emails\Services;

defined( 'ABSPATH' ) || exit;

/**
 * TimerCountdownGif class
 */
final class TimerCountdownGif {

	const FRAME_COUNT = 60;

	const DELAY_CS = 100;

	/**
	 * Whether GD can produce GIF frames.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagegif' )
			&& function_exists( 'imagedestroy' );
	}

	/**
	 * Pixel size of the GIF for a given font size.
	 *
	 * @param int $font_size Digit size in pixels.
	 * @return array{0:int,1:int} Width and height.
	 */
	public static function dimensions( int $font_size ): array {
		$font_size = max( 12, min( 64, $font_size ) );
		$font      = self::font_path();
		if ( $font && function_exists( 'imagettfbbox' ) ) {
			$box    = imagettfbbox( $font_size, 0, $font, '00:00:00:00' );
			$width  = abs( (int) $box[2] - (int) $box[0] ) + 24;
			$height = abs( (int) $box[1] - (int) $box[7] ) + 16;
			return array( max( 80, $width ), max( 24, $height ) );
		}

		return array(
			max( 80, (int) round( $font_size * 9.2 ) ),
			max( 24, (int) round( $font_size * 1.7 ) ),
		);
	}

	/**
	 * Build an animated (or single-frame) countdown GIF.
	 *
	 * @param int    $end_timestamp Deadline unix timestamp.
	 * @param string $bg_hex        Background, 6-char hex no #.
	 * @param string $fg_hex        Digit color.
	 * @param string $sg_hex        Separator color.
	 * @param int    $font_size     Digit size.
	 * @return string GIF binary, or empty string on failure.
	 */
	public static function render( int $end_timestamp, string $bg_hex, string $fg_hex, string $sg_hex, int $font_size ): string {
		if ( ! self::is_available() ) {
			return '';
		}

		$remaining = $end_timestamp - time();
		$frames    = max( 1, min( self::FRAME_COUNT, $remaining + 1 ) );
		if ( $remaining <= 0 ) {
			$remaining = 0;
			$frames    = 1;
		}

		$gif_frames = array();
		for ( $i = 0; $i < $frames; $i++ ) {
			$seconds_left = max( 0, $remaining - $i );
			$frame        = self::render_frame( $seconds_left, $bg_hex, $fg_hex, $sg_hex, $font_size );
			if ( '' === $frame ) {
				return '';
			}
			$gif_frames[] = $frame;
		}

		$gif = AnimatedGifEncoder::from_gif_frames( $gif_frames, self::DELAY_CS );

		return is_string( $gif ) ? $gif : '';
	}

	/**
	 * Format remaining seconds as DD:HH:MM:SS (days may exceed two digits).
	 *
	 * @param int $remaining Remaining seconds.
	 * @return string
	 */
	public static function format_label( int $remaining ): string {
		$parts = self::parts( max( 0, $remaining ) );

		return $parts['days'] . ':' . $parts['hours'] . ':' . $parts['minutes'] . ':' . $parts['seconds'];
	}

	/**
	 * @param int $remaining Remaining seconds.
	 * @return array{days:string,hours:string,minutes:string,seconds:string}
	 */
	public static function parts( int $remaining ): array {
		$remaining = max( 0, $remaining );
		$day     = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$hour    = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		$minute  = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		$days    = (int) floor( $remaining / $day );
		$hours   = (int) floor( ( $remaining % $day ) / $hour );
		$minutes = (int) floor( ( $remaining % $hour ) / $minute );
		$seconds = (int) ( $remaining % $minute );

		return array(
			'days'    => str_pad( (string) $days, 2, '0', STR_PAD_LEFT ),
			'hours'   => str_pad( (string) $hours, 2, '0', STR_PAD_LEFT ),
			'minutes' => str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT ),
			'seconds' => str_pad( (string) $seconds, 2, '0', STR_PAD_LEFT ),
		);
	}

	/**
	 * @param int    $remaining Remaining seconds for this frame.
	 * @param string $bg_hex    Background hex.
	 * @param string $fg_hex    Digit hex.
	 * @param string $sg_hex    Separator hex.
	 * @param int    $font_size Font size.
	 * @return string Single-frame GIF binary.
	 */
	private static function render_frame( int $remaining, string $bg_hex, string $fg_hex, string $sg_hex, int $font_size ): string {
		list( $width, $height ) = self::dimensions( $font_size );

		$im = imagecreatetruecolor( $width, $height );
		if ( false === $im ) {
			return '';
		}

		$bg = self::allocate_hex( $im, $bg_hex, array( 255, 255, 255 ) );
		$fg = self::allocate_hex( $im, $fg_hex, array( 51, 51, 51 ) );
		$sg = self::allocate_hex( $im, $sg_hex, array( 51, 51, 51 ) );
		imagefilledrectangle( $im, 0, 0, $width, $height, $bg );

		$parts = self::parts( $remaining );
		$font  = self::font_path();

		if ( $font && function_exists( 'imagettftext' ) ) {
			self::draw_ttf( $im, $font, $font_size, $fg, $sg, $parts, $width, $height );
		} else {
			self::draw_bitmap( $im, $fg, $parts, $width, $height );
		}

		if ( function_exists( 'imagetruecolortopalette' ) ) {
			imagetruecolortopalette( $im, false, 32 );
		}

		ob_start();
		imagegif( $im );
		$binary = (string) ob_get_clean();
		imagedestroy( $im );

		return $binary;
	}

	/**
	 * @param \GdImage|resource $im        Image.
	 * @param string            $font      TTF path.
	 * @param int               $font_size Size.
	 * @param int               $fg        Digit color.
	 * @param int               $sg        Separator color.
	 * @param array             $parts     Time parts.
	 * @param int               $width     Canvas width.
	 * @param int               $height    Canvas height.
	 * @return void
	 */
	private static function draw_ttf( $im, string $font, int $font_size, int $fg, int $sg, array $parts, int $width, int $height ): void {
		$sample = imagettfbbox( $font_size, 0, $font, '00' );
		$colon  = imagettfbbox( $font_size, 0, $font, ':' );
		$digit_w = abs( (int) $sample[2] - (int) $sample[0] );
		$colon_w = abs( (int) $colon[2] - (int) $colon[0] );
		$text_h  = abs( (int) $sample[1] - (int) $sample[7] );
		$gap     = max( 4, (int) round( $font_size * 0.18 ) );

		$total = ( 4 * $digit_w ) + ( 3 * $colon_w ) + ( 6 * $gap );
		$x     = (int) max( 4, ( $width - $total ) / 2 );
		$y     = (int) round( ( $height + $text_h ) / 2 ) - 2;

		$units = array( $parts['days'], $parts['hours'], $parts['minutes'], $parts['seconds'] );
		foreach ( $units as $i => $unit ) {
			imagettftext( $im, $font_size, 0, $x, $y, $fg, $font, $unit );
			$x += $digit_w + $gap;
			if ( $i < 3 ) {
				imagettftext( $im, $font_size, 0, $x, $y, $sg, $font, ':' );
				$x += $colon_w + $gap;
			}
		}
	}

	/**
	 * Built-in bitmap font fallback when FreeType is missing.
	 *
	 * @param \GdImage|resource $im     Image.
	 * @param int               $fg     Color.
	 * @param array             $parts  Time parts.
	 * @param int               $width  Canvas width.
	 * @param int               $height Canvas height.
	 * @return void
	 */
	private static function draw_bitmap( $im, int $fg, array $parts, int $width, int $height ): void {
		$label  = $parts['days'] . ':' . $parts['hours'] . ':' . $parts['minutes'] . ':' . $parts['seconds'];
		$font   = 5;
		$text_w = imagefontwidth( $font ) * strlen( $label );
		$text_h = imagefontheight( $font );
		$x      = (int) max( 2, ( $width - $text_w ) / 2 );
		$y      = (int) max( 2, ( $height - $text_h ) / 2 );
		imagestring( $im, $font, $x, $y, $label, $fg );
	}

	/**
	 * @param \GdImage|resource $im        Image.
	 * @param string            $hex       6-char hex.
	 * @param array{0:int,1:int,2:int} $fallback RGB fallback.
	 * @return int
	 */
	private static function allocate_hex( $im, string $hex, array $fallback ): int {
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			$color = imagecolorallocate( $im, $fallback[0], $fallback[1], $fallback[2] );
			return false === $color ? 0 : $color;
		}

		$color = imagecolorallocate(
			$im,
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) )
		);

		return false === $color ? 0 : $color;
	}

	/**
	 * Bold TTF shipped with the free plugin's bundled Dompdf fonts.
	 *
	 * @return string Empty when missing.
	 */
	private static function font_path(): string {
		$candidates = array();
		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			$candidates[] = DOUBLESCALE_PLUGIN_DIR . 'dependencies/build/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
			$candidates[] = DOUBLESCALE_PLUGIN_DIR . 'dependencies/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
		}
		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			$candidates[] = DOUBLESCALE_PRO_PLUGIN_DIR . 'dependencies/build/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
		}

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}
}
