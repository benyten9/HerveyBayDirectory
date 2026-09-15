<?php
/**
 * HMAC-signed public URL for the emailed countdown GIF.
 *
 * Query params that affect the image are signed. `hk` is an unsigned
 * cache-buster so Gmail's image proxy fetches per recipient; it is appended
 * as a literal `{{tracking:hash_key}}` merge tag (must not be urlencoded).
 *
 * @package DoubleScale\Pro\Modules\Emails
 */

namespace DoubleScale\Pro\Modules\Emails\Services;

defined( 'ABSPATH' ) || exit;

/**
 * TimerGifUrl class
 */
final class TimerGifUrl {

	const QUERY_FLAG = 'email_timer';

	/**
	 * Build a public image URL for the given deadline and style.
	 *
	 * @param array<string, mixed> $args {
	 *     @type int    $end Deadline unix timestamp.
	 *     @type string $bg  Background hex (with or without #).
	 *     @type string $fg  Digit color hex.
	 *     @type string $sg  Separator color hex.
	 *     @type int    $fs  Font size in pixels.
	 * }
	 * @return string
	 */
	public static function build( array $args ): string {
		$params = self::normalize( $args );
		$params['sig'] = self::signature( $params );

		$base = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
		$url  = add_query_arg(
			array(
				'doublescale' => self::QUERY_FLAG,
				'end'         => $params['end'],
				'bg'          => $params['bg'],
				'fg'          => $params['fg'],
				'sg'          => $params['sg'],
				'fs'          => $params['fs'],
				'sig'         => $params['sig'],
			),
			$base
		);

		// Keep the merge tag literal so bulk mailers can substitute per recipient.
		return $url . '&hk={{tracking:hash_key}}';
	}

	/**
	 * Verify a request and return normalized image params, or null on failure.
	 *
	 * @param array<string, mixed> $query Typically $_GET.
	 * @return array{end:int,bg:string,fg:string,sg:string,fs:int}|null
	 */
	public static function parse_request( array $query ): ?array {
		$flag = isset( $query['doublescale'] ) ? (string) $query['doublescale'] : '';
		if ( self::QUERY_FLAG !== $flag ) {
			return null;
		}

		$sig = isset( $query['sig'] ) ? (string) $query['sig'] : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $sig ) ) {
			return null;
		}

		$params = self::normalize( $query );
		if ( ! hash_equals( self::signature( $params ), $sig ) ) {
			return null;
		}

		return $params;
	}

	/**
	 * @param array<string, mixed> $args Raw args.
	 * @return array{end:int,bg:string,fg:string,sg:string,fs:int}
	 */
	public static function normalize( array $args ): array {
		$end = isset( $args['end'] ) ? (int) $args['end'] : 0;
		$bg  = self::hex_color( isset( $args['bg'] ) ? (string) $args['bg'] : '', 'ffffff' );
		$fg  = self::hex_color( isset( $args['fg'] ) ? (string) $args['fg'] : '', '333333' );
		$sg = self::hex_color( isset( $args['sg'] ) ? (string) $args['sg'] : $fg, $fg );
		$fs = isset( $args['fs'] ) ? (int) $args['fs'] : 24;
		$fs = max( 12, min( 64, $fs ) );

		return array(
			'end' => $end,
			'bg'  => $bg,
			'fg'  => $fg,
			'sg'  => $sg,
			'fs'  => $fs,
		);
	}

	/**
	 * @param array{end:int,bg:string,fg:string,sg:string,fs:int} $params Normalized params.
	 * @return string 32-char hex HMAC.
	 */
	public static function signature( array $params ): string {
		$payload = $params['end'] . '|' . $params['bg'] . '|' . $params['fg'] . '|' . $params['sg'] . '|' . $params['fs'];

		return substr( hash_hmac( 'sha256', $payload, self::signing_key() ), 0, 32 );
	}

	/**
	 * @return string
	 */
	private static function signing_key(): string {
		if ( function_exists( 'wp_salt' ) ) {
			return (string) wp_salt( 'auth' );
		}
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return (string) AUTH_KEY;
		}

		return 'doublescale-email-timer';
	}

	/**
	 * @param string $color    Raw color.
	 * @param string $fallback 6-char hex fallback.
	 * @return string Lowercase 6-char hex without #.
	 */
	public static function hex_color( string $color, string $fallback ): string {
		$color = ltrim( trim( $color ), '#' );
		if ( preg_match( '/^[0-9a-fA-F]{6}$/', $color ) ) {
			return strtolower( $color );
		}
		if ( preg_match( '/^[0-9a-fA-F]{3}$/', $color ) ) {
			return strtolower( $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2] );
		}

		return $fallback;
	}
}
