<?php
/**
 * Assemble a single-play animated GIF from GD `imagegif()` frames.
 *
 * Email clients do not run JavaScript, so the timer block ships as a GIF
 * whose remaining time is computed when the image is fetched.
 *
 * @package DoubleScale\Pro\Modules\Emails
 */

namespace DoubleScale\Pro\Modules\Emails\Services;

defined( 'ABSPATH' ) || exit;

/**
 * AnimatedGifEncoder class
 */
final class AnimatedGifEncoder {

	/**
	 * Build a GIF89a animation that plays once and holds the last frame.
	 *
	 * @param string[] $gif_frames Complete single-frame GIF binaries.
	 * @param int      $delay_cs   Frame delay in centiseconds (100 = 1s).
	 * @return string Animated GIF binary, or empty string on failure.
	 */
	public static function from_gif_frames( array $gif_frames, int $delay_cs ): string {
		$parsed = array();
		foreach ( $gif_frames as $gif ) {
			if ( ! is_string( $gif ) || '' === $gif ) {
				continue;
			}
			$frame = self::parse_frame( $gif );
			if ( null === $frame ) {
				continue;
			}
			$parsed[] = $frame;
		}

		if ( empty( $parsed ) ) {
			return '';
		}

		$delay_cs = max( 1, min( 1000, $delay_cs ) );

		$output  = 'GIF89a' . substr( $parsed[0]['header'], 6 );
		foreach ( $parsed as $frame ) {
			$output .= self::graphic_control_extension( $delay_cs );
			$output .= self::image_with_local_table( $frame['image'], $frame['gct'] );
		}
		$output .= "\x3B";

		return $output;
	}

	/**
	 * Graphic Control Extension: disposal 1 (keep), no transparency.
	 *
	 * @param int $delay_cs Delay in centiseconds.
	 * @return string
	 */
	private static function graphic_control_extension( int $delay_cs ): string {
		return pack(
			'C4vC2',
			0x21,
			0xF9,
			0x04,
			0x04,
			$delay_cs,
			0x00,
			0x00
		);
	}

	/**
	 * Attach this frame's global color table as a local table so palettes can differ.
	 *
	 * @param string $image Image descriptor + data from a single-frame GIF.
	 * @param string $gct   Global color table from that frame.
	 * @return string
	 */
	private static function image_with_local_table( string $image, string $gct ): string {
		if ( '' === $image || "\x2C" !== $image[0] ) {
			return $image;
		}

		if ( strlen( $image ) < 10 ) {
			return $image;
		}

		$packed = ord( $image[9] );
		if ( $packed & 0x80 ) {
			return $image;
		}

		$gct_bytes = strlen( $gct );
		if ( $gct_bytes < 6 || 0 !== $gct_bytes % 3 ) {
			return $image;
		}

		$color_count = (int) ( $gct_bytes / 3 );
		$size_bits   = (int) round( log( $color_count, 2 ) ) - 1;
		if ( $size_bits < 0 || $size_bits > 7 ) {
			return $image;
		}

		$image[9] = chr( $packed | 0x80 | $size_bits );

		return substr( $image, 0, 10 ) . $gct . substr( $image, 10 );
	}

	/**
	 * Split a single-frame GIF into screen header, color table, and image data.
	 *
	 * @param string $gif GIF binary.
	 * @return array{header:string,gct:string,image:string}|null
	 */
	private static function parse_frame( string $gif ): ?array {
		$length = strlen( $gif );
		if ( $length < 14 || 'GIF' !== substr( $gif, 0, 3 ) ) {
			return null;
		}

		$packed   = ord( $gif[10] );
		$gct_flag = (bool) ( $packed & 0x80 );
		$gct_size = $gct_flag ? 3 * ( 2 << ( $packed & 0x07 ) ) : 0;
		$header   = substr( $gif, 0, 13 + $gct_size );
		$gct      = $gct_flag ? substr( $gif, 13, $gct_size ) : '';

		$offset = 13 + $gct_size;
		$image  = '';

		while ( $offset < $length ) {
			$block = ord( $gif[ $offset ] );

			if ( 0x3B === $block ) {
				break;
			}

			if ( 0x21 === $block ) {
				$offset = self::skip_extension( $gif, $offset, $length );
				if ( null === $offset ) {
					return null;
				}
				continue;
			}

			if ( 0x2C === $block ) {
				$start  = $offset;
				$offset = self::skip_image_descriptor( $gif, $offset, $length );
				if ( null === $offset ) {
					return null;
				}
				$image = substr( $gif, $start, $offset - $start );
				break;
			}

			++$offset;
		}

		if ( '' === $image ) {
			return null;
		}

		return array(
			'header' => $header,
			'gct'    => $gct,
			'image'  => $image,
		);
	}

	/**
	 * Advance past a GIF extension block.
	 *
	 * @param string $gif    GIF binary.
	 * @param int    $offset Offset of the 0x21 introducer.
	 * @param int    $length Total length.
	 * @return int|null Next offset, or null if truncated.
	 */
	private static function skip_extension( string $gif, int $offset, int $length ): ?int {
		$offset += 2;
		if ( $offset >= $length ) {
			return null;
		}

		while ( $offset < $length ) {
			$size = ord( $gif[ $offset ] );
			++$offset;
			if ( 0 === $size ) {
				return $offset;
			}
			$offset += $size;
		}

		return null;
	}

	/**
	 * Advance past an image descriptor and its LZW data.
	 *
	 * @param string $gif    GIF binary.
	 * @param int    $offset Offset of the 0x2C introducer.
	 * @param int    $length Total length.
	 * @return int|null Next offset, or null if truncated.
	 */
	private static function skip_image_descriptor( string $gif, int $offset, int $length ): ?int {
		if ( $offset + 10 > $length ) {
			return null;
		}

		$packed  = ord( $gif[ $offset + 9 ] );
		$offset += 10;

		if ( $packed & 0x80 ) {
			$lct     = 3 * ( 2 << ( $packed & 0x07 ) );
			$offset += $lct;
		}

		if ( $offset >= $length ) {
			return null;
		}

		++$offset;

		while ( $offset < $length ) {
			$size = ord( $gif[ $offset ] );
			++$offset;
			if ( 0 === $size ) {
				return $offset;
			}
			$offset += $size;
		}

		return null;
	}
}
