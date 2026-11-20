<?php
/**
 * The address a public request is counted against (rate limits, one vote,
 * one view).
 *
 * By default that is the connection's own address (REMOTE_ADDR): a header
 * like X-Forwarded-For can be typed by anyone, so trusting it blindly would
 * let a visitor pick a fresh address for every request.
 *
 * A site behind a proxy (Cloudflare, a load balancer, a host's cache) sees
 * the proxy's address on every request, so every visitor would share one
 * limit. The site owner then chooses the header that proxy writes
 * (Settings → System → Visitor address); the address in it is used when it
 * is a valid IP, the connection's address otherwise.
 *
 * Only ever used for counting, and hashed before it is stored.
 *
 * @since 1.7.1
 * @package DoubleScale\Core\Security
 */

namespace DoubleScale\Core\Security;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * ClientIp class.
 */
final class ClientIp {

	/**
	 * Settings key holding the trusted header choice ('' = none).
	 */
	public const SETTING = 'client_ip_header';

	/**
	 * Choices and the $_SERVER key each one reads.
	 */
	public const HEADERS = array(
		'cloudflare'      => 'HTTP_CF_CONNECTING_IP',
		'x_real_ip'       => 'HTTP_X_REAL_IP',
		'x_forwarded_for' => 'HTTP_X_FORWARDED_FOR',
	);

	/**
	 * The visitor's address.
	 *
	 * @return string An IP, or 'unknown'.
	 */
	public static function get(): string {
		$remote = self::valid( self::server( 'REMOTE_ADDR' ) );
		$choice = self::trusted_header();
		$ip     = '' !== $choice ? self::from_header( self::HEADERS[ $choice ] ) : '';

		if ( '' === $ip ) {
			$ip = '' !== $remote ? $remote : 'unknown';
		}

		/**
		 * Filters the address public limits count against.
		 *
		 * @param string $ip     Chosen address.
		 * @param string $remote The connection's own address.
		 */
		return (string) apply_filters( 'doublescale_client_ip', $ip, $remote );
	}

	/**
	 * The key limits are counted under: the address itself for IPv4 (also
	 * when written as IPv6), its /64 network for IPv6 (one home connection
	 * holds billions of addresses).
	 *
	 * @return string
	 */
	public static function bucket(): string {
		$ip = self::get();
		if ( false === strpos( $ip, ':' ) || ! function_exists( 'inet_pton' ) ) {
			return $ip;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}

		// "::ffff:1.2.3.4" (dual-stack servers) is an IPv4 visitor: count the
		// IPv4 address, not the /64 every such visitor shares.
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}

		return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * The header the site owner chose to trust, '' for none.
	 *
	 * @return string
	 */
	public static function trusted_header(): string {
		$choice = (string) Settings::get( self::SETTING, '' );

		return isset( self::HEADERS[ $choice ] ) ? $choice : '';
	}

	/**
	 * What this request carries, for the settings screen: the connection's
	 * address and the address in each header (empty when absent or invalid).
	 *
	 * @return array{remote: string, headers: array<string, string>}
	 */
	public static function detected(): array {
		$headers = array();
		foreach ( self::HEADERS as $choice => $key ) {
			$headers[ $choice ] = self::from_header( $key );
		}

		return array(
			'remote'  => self::valid( self::server( 'REMOTE_ADDR' ) ),
			'headers' => $headers,
		);
	}

	/**
	 * A valid address from a header. For a list (X-Forwarded-For), the last
	 * one: the entry the nearest proxy added, which the visitor cannot forge.
	 *
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	private static function from_header( string $key ): string {
		$raw = self::server( $key );
		if ( '' === $raw ) {
			return '';
		}

		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' );

		return self::valid( (string) end( $parts ) );
	}

	/**
	 * @param string $ip Candidate.
	 * @return string The address, or '' when it is not one.
	 */
	private static function valid( string $ip ): string {
		$ip = trim( $ip );
		// "[2001:db8::1]" and "1.2.3.4:5678" as some proxies write them.
		if ( '' !== $ip && '[' === $ip[0] ) {
			$ip = (string) preg_replace( '/^\[([^\]]+)\].*$/', '$1', $ip );
		} elseif ( 1 === substr_count( $ip, ':' ) ) {
			$ip = (string) strstr( $ip, ':', true );
		}

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	private static function server( string $key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated as an IP before use.
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : '';
	}
}
