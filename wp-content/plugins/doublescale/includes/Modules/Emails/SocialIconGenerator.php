<?php
/**
 * Social Icon Generator
 *
 * Generates custom-color social media icon PNGs on-demand using PHP's GD library.
 * Original-color icons ship with the plugin; custom-color variants are generated
 * lazily at render time and cached in the uploads directory.
 *
 * A maximum of MAX_COLORS distinct custom colors are stored. Once the cap is
 * reached, new colors are snapped to the nearest existing color via Euclidean
 * distance in RGB space, so no additional files are created.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * SocialIconGenerator class
 */
class SocialIconGenerator {

	/**
	 * Maximum number of distinct custom colors allowed.
	 *
	 * @var int
	 */
	const MAX_COLORS = 20;

	/**
	 * Option key that stores the list of generated color hex values.
	 *
	 * @var string
	 */
	const COLORS_OPTION = 'doublescale_social_icon_colors';

	/**
	 * Get the uploads directory path for generated icons.
	 *
	 * @return string Absolute directory path (with trailing slash).
	 */
	public static function get_upload_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'doublescale/social-icons/';
	}

	/**
	 * Get the uploads directory URL for generated icons.
	 *
	 * @return string URL (with trailing slash).
	 */
	public static function get_upload_url() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['baseurl'] ) . 'doublescale/social-icons/';
	}

	/**
	 * Get the plugin directory path for original-color icons.
	 *
	 * @return string Absolute directory path (with trailing slash).
	 */
	public static function get_source_dir() {
		$candidates = array();
		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			$candidates[] = DOUBLESCALE_PLUGIN_DIR . 'assets/social-icons/';
		}
		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			$candidates[] = DOUBLESCALE_PRO_PLUGIN_DIR . 'assets/social-icons/';
		}
		foreach ( $candidates as $dir ) {
			if ( is_dir( $dir ) ) {
				return trailingslashit( $dir );
			}
		}

		return isset( $candidates[0] ) ? trailingslashit( $candidates[0] ) : '';
	}

	/**
	 * Get the list of custom colors that have been generated so far.
	 *
	 * @return array Array of uppercase 6-char hex strings (no #).
	 */
	private static function get_registered_colors() {
		$colors = get_option( self::COLORS_OPTION, array() );
		return is_array( $colors ) ? $colors : array();
	}

	/**
	 * Register a new color in the stored list.
	 *
	 * @param string $hex Uppercase 6-char hex (no #).
	 */
	private static function register_color( $hex ) {
		$colors = self::get_registered_colors();
		if ( ! in_array( $hex, $colors, true ) ) {
			$colors[] = $hex;
			update_option( self::COLORS_OPTION, $colors, true );
		}
	}

	/**
	 * Resolve the requested color to an allowed color.
	 *
	 * If the color is already registered or there's room for a new one,
	 * the original color is returned. Otherwise, the nearest registered
	 * color (by Euclidean distance in RGB) is returned.
	 *
	 * @param string $hex Uppercase 6-char hex (no #).
	 * @return string Resolved hex color.
	 */
	private static function resolve_color( $hex ) {
		$colors = self::get_registered_colors();

		if ( in_array( $hex, $colors, true ) ) {
			return $hex;
		}

		if ( count( $colors ) < self::MAX_COLORS ) {
			return $hex;
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		$best          = $colors[0];
		$best_distance = PHP_INT_MAX;

		foreach ( $colors as $existing ) {
			$er = hexdec( substr( $existing, 0, 2 ) );
			$eg = hexdec( substr( $existing, 2, 2 ) );
			$eb = hexdec( substr( $existing, 4, 2 ) );

			$distance = ( $r - $er ) * ( $r - $er )
				+ ( $g - $eg ) * ( $g - $eg )
				+ ( $b - $eb ) * ( $b - $eb );

			if ( $distance < $best_distance ) {
				$best_distance = $distance;
				$best          = $existing;
			}
		}

		return $best;
	}

	/**
	 * Ensure a custom-color icon exists, generating it if needed.
	 *
	 * The color may be snapped to the nearest existing color if the
	 * MAX_COLORS cap has been reached. Returns the public URL to the
	 * icon, generating it via GD if the file doesn't exist yet.
	 *
	 * @param string $platform  Platform name (e.g. 'facebook').
	 * @param int    $size      Icon size in pixels (24, 32, or 40).
	 * @param string $shape     Shape (circle, rounded, square).
	 * @param string $hex_color Hex color with or without # (e.g. '#FF6600').
	 * @return string|false URL to the icon, or false on failure.
	 */
	public static function ensure_icon( $platform, $size, $shape, $hex_color ) {
		$hex_color = strtoupper( ltrim( $hex_color, '#' ) );
		$hex_color = self::resolve_color( $hex_color );

		$filename    = "{$platform}-{$shape}-{$size}-{$hex_color}.png";
		$output_dir  = self::get_upload_dir();
		$output_path = $output_dir . $filename;

		if ( file_exists( $output_path ) ) {
			return self::get_upload_url() . $filename;
		}

		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return false;
		}

		if ( ! file_exists( $output_dir ) ) {
			wp_mkdir_p( $output_dir );
		}

		if ( ! is_writable( $output_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- writing generated PNG icons into the uploads dir; WP_Filesystem does not offer an equivalent writability probe.
			return false;
		}

		$source_path = self::get_source_dir() . "{$platform}-{$shape}-{$size}.png";
		if ( ! file_exists( $source_path ) ) {
			return false;
		}

		$success = self::colorize_icon( $source_path, $output_path, $hex_color );

		if ( $success ) {
			self::register_color( $hex_color );
			return self::get_upload_url() . $filename;
		}

		return false;
	}

	/**
	 * Colorize a single icon PNG.
	 *
	 * Every non-transparent pixel is classified as either "white glyph"
	 * (kept as-is) or "background" (replaced with the target color).
	 * Pixels near the boundary are blended so edges stay smooth.
	 *
	 * @param string $source_path Absolute path to the original PNG.
	 * @param string $output_path Absolute path for the colorized PNG.
	 * @param string $hex_color   Target color as 6-char hex (no #).
	 * @return bool True on success.
	 */
	private static function colorize_icon( $source_path, $output_path, $hex_color ) {
		$src = imagecreatefrompng( $source_path );
		if ( ! $src ) {
			return false;
		}

		$width  = imagesx( $src );
		$height = imagesy( $src );

		$dst = imagecreatetruecolor( $width, $height );
		imagesavealpha( $dst, true );
		imagealphablending( $dst, false );

		$tr = hexdec( substr( $hex_color, 0, 2 ) );
		$tg = hexdec( substr( $hex_color, 2, 2 ) );
		$tb = hexdec( substr( $hex_color, 4, 2 ) );

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$rgba  = imagecolorat( $src, $x, $y );
				$alpha = ( $rgba >> 24 ) & 0x7F;

				if ( 127 === $alpha ) {
					$color = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
					imagesetpixel( $dst, $x, $y, $color );
					continue;
				}

				$r = ( $rgba >> 16 ) & 0xFF;
				$g = ( $rgba >> 8 ) & 0xFF;
				$b = $rgba & 0xFF;

				$luminance = ( $r * 0.299 + $g * 0.587 + $b * 0.114 ) / 255.0;

				if ( $luminance > 0.95 ) {
					$nr = $r;
					$ng = $g;
					$nb = $b;
				} elseif ( $luminance > 0.75 ) {
					$blend = ( $luminance - 0.75 ) / 0.20;
					$nr    = (int) ( $tr + ( $r - $tr ) * $blend );
					$ng    = (int) ( $tg + ( $g - $tg ) * $blend );
					$nb    = (int) ( $tb + ( $b - $tb ) * $blend );
				} else {
					$nr = $tr;
					$ng = $tg;
					$nb = $tb;
				}

				$color = imagecolorallocatealpha( $dst, $nr, $ng, $nb, $alpha );
				imagesetpixel( $dst, $x, $y, $color );
			}
		}

		$result = imagepng( $dst, $output_path, 9 );

		imagedestroy( $src );
		imagedestroy( $dst );

		return $result;
	}

	/**
	 * Clean up generated icons from the uploads directory.
	 *
	 * Called on plugin deactivation to remove generated files.
	 */
	public static function cleanup() {
		$dir = self::get_upload_dir();

		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '*.png' );
			if ( $files ) {
				array_map( 'wp_delete_file', $files );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- cleanup of generated-icon dir; WP_Filesystem isn't bootstrapped on cache-clear paths and failure is non-fatal.
			@rmdir( $dir );

			$parent    = dirname( $dir );
			$remaining = glob( $parent . '/*' );
			if ( empty( $remaining ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- cleanup of empty parent dir; non-fatal on failure.
				@rmdir( $parent );
			}
		}

		delete_option( self::COLORS_OPTION );
	}
}
