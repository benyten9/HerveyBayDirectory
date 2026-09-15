<?php
/**
 * Public GIF endpoint for the email timer block.
 *
 * Mirrors the open-tracking pixel: `/?doublescale=email_timer&...` so Gmail's
 * image proxy can fetch it without cookies or REST JSON wrapping.
 *
 * @package DoubleScale\Pro\Modules\Emails
 */

namespace DoubleScale\Pro\Modules\Emails\Services;

defined( 'ABSPATH' ) || exit;

/**
 * TimerGifEndpoint class
 */
final class TimerGifEndpoint {

	/**
	 * Serve the countdown GIF and terminate when this request is for it.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public email image; authenticity is the HMAC on the query string.
		if ( ! isset( $_GET['doublescale'] ) || TimerGifUrl::QUERY_FLAG !== $_GET['doublescale'] ) {
			return;
		}

		$query = wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$params = TimerGifUrl::parse_request( is_array( $query ) ? $query : array() );
		if ( null === $params ) {
			self::emit_gif( self::fallback_pixel(), true );
			return;
		}

		$gif = TimerCountdownGif::render(
			$params['end'],
			$params['bg'],
			$params['fg'],
			$params['sg'],
			$params['fs']
		);

		if ( '' === $gif ) {
			$gif = self::fallback_pixel();
		}

		self::emit_gif( $gif, false );
	}

	/**
	 * @param string $gif     GIF binary.
	 * @param bool   $error   Whether this is a rejection/fallback response.
	 * @return void
	 */
	private static function emit_gif( string $gif, bool $error ): void {
		if ( function_exists( 'status_header' ) ) {
			status_header( $error ? 403 : 200 );
		}

		header( 'Content-Type: image/gif' );
		header( 'Content-Length: ' . (string) strlen( $gif ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary GIF; escaping would corrupt the bytes.
		echo $gif;
		exit;
	}

	/**
	 * 1x1 transparent GIF used when the request is invalid or GD is missing.
	 *
	 * @return string
	 */
	private static function fallback_pixel(): string {
		return base64_decode( 'R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 1x1 GIF.
	}
}
