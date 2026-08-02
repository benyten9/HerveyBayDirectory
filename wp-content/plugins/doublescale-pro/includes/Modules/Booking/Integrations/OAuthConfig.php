<?php
/**
 * Centralized OAuth proxy configuration for booking integrations.
 *
 * All OAuth proxy URLs are defined here so that switching infrastructure
 * (e.g. from app.doublescale.com to a self-hosted endpoint) requires
 * editing only this file — or hooking the filter.
 *
 * Filter: `doublescale_booking_oauth_proxy_host` — return a base URL with no
 * trailing slash (e.g. `https://your-mirror.example`) when outbound access
 * to the default host is blocked. PHPUnit: {@see \DoubleScale\Pro\Tests\BookingOAuthConfigTest}.
 *
 * Filter: `doublescale_booking_oauth_proxy_remote_post_args` — adjust
 * {@see \wp_remote_post()} args for proxy calls (e.g. `sslverify` => false only on local dev
 * when OpenSSL reports cURL error 35 / TLS alert internal error).
 *
 * Constant: `DOUBLESCALE_BOOKING_OAUTH_PROXY_SSLVERIFY` — define as `false` together with
 * `WP_DEBUG` true to disable certificate verification for proxy requests only (development).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations;

defined( 'ABSPATH' ) || exit;

final class OAuthConfig {

	private const DEFAULT_PROXY_HOST = 'https://app.doublescale.io';

	/**
	 * @return string Base URL for the OAuth proxy (no trailing slash).
	 */
	public static function proxy_host(): string {
		$host = (string) apply_filters(
			'doublescale_booking_oauth_proxy_host',
			self::DEFAULT_PROXY_HOST
		);
		return rtrim( $host, '/' );
	}

	/**
	 * Encode {@see \admin_url()} for embedding in OAuth proxy `state` (no raw `://` or slashes).
	 *
	 * Proxy callbacks often validate state as a narrow token alphabet; URL-safe base64 avoids that.
	 */
	public static function encode_booking_oauth_return_url( string $url ): string {
		return rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
	}

	public static function google_auth_proxy_url(): string {
		return self::proxy_host() . '/GoogleAuthProxy.php';
	}

	public static function outlook_auth_proxy_url(): string {
		return self::proxy_host() . '/OutlookAuthProxy.php';
	}

	public static function callback_url(): string {
		return self::proxy_host() . '/Callback.php';
	}

	/**
	 * Build final {@see \wp_remote_post()} arguments for the booking OAuth proxy.
	 *
	 * Applies default `timeout` (30s), optional dev-only SSL override via
	 * `DOUBLESCALE_BOOKING_OAUTH_PROXY_SSLVERIFY` + `WP_DEBUG`, then the filter
	 * `doublescale_booking_oauth_proxy_remote_post_args`.
	 *
	 * @param array<string, mixed> $request_args Headers, body, and other args.
	 * @param string               $proxy_url    Full proxy URL being called.
	 * @return array<string, mixed>
	 */
	public static function prepare_proxy_remote_post_args( array $request_args, string $proxy_url ): array {
		if ( ! isset( $request_args['timeout'] ) ) {
			$request_args['timeout'] = 30;
		}

		if ( ! isset( $request_args['sslverify'] ) && self::is_local_dev_site_host() ) {
			$request_args['sslverify'] = false;
		}

		if (
			defined( 'DOUBLESCALE_BOOKING_OAUTH_PROXY_SSLVERIFY' )
			&& false === \constant( 'DOUBLESCALE_BOOKING_OAUTH_PROXY_SSLVERIFY' )
			&& defined( 'WP_DEBUG' )
			&& WP_DEBUG
		) {
			$request_args['sslverify'] = false;
		}

		return (array) apply_filters(
			'doublescale_booking_oauth_proxy_remote_post_args',
			$request_args,
			$proxy_url
		);
	}

	/**
	 * Whether {@see home_url()} points at a typical local dev hostname (TLS to public SaaS often fails there).
	 */
	private static function is_local_dev_site_host(): bool {
		$host = null;
		if ( function_exists( 'home_url' ) ) {
			$parsed = \parse_url( (string) \home_url(), PHP_URL_HOST );
			$host   = \is_string( $parsed ) ? $parsed : null;
		}
		if ( ! self::host_looks_local( $host ) && isset( $_SERVER['HTTP_HOST'] ) ) {
			$raw  = \sanitize_text_field( \wp_unslash( (string) $_SERVER['HTTP_HOST'] ) );
			$host = \explode( ':', $raw, 2 )[0];
		}
		if ( self::host_looks_local( $host ) ) {
			return true;
		}
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === \wp_get_environment_type() ) {
			return true;
		}
		return false;
	}

	/**
	 * @param mixed $host Hostname or null.
	 */
	private static function host_looks_local( $host ): bool {
		if ( ! \is_string( $host ) || '' === $host ) {
			return false;
		}
		$h = \strtolower( $host );
		if ( \in_array( $h, array( 'localhost', '127.0.0.1' ), true ) ) {
			return true;
		}
		if ( \str_ends_with( $h, '.local' ) || \str_starts_with( $h, '127.' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Sanitize text embedded in user-visible proxy error strings.
	 *
	 * @param string $text Raw fragment (e.g. response body, transport message).
	 */
	private static function sanitize_proxy_detail( string $text ): string {
		$text = \strip_tags( $text );
		if ( \strlen( $text ) > 220 ) {
			return \substr( $text, 0, 220 ) . '…';
		}
		return $text;
	}

	/**
	 * WP_Error when wp_remote_* fails before HTTP (DNS, SSL, timeout, blocked outbound).
	 *
	 * @param string    $proxy_url Full proxy URL that was requested.
	 * @param \WP_Error $transport_error Error from {@see \wp_remote_post()}.
	 */
	public static function new_proxy_transport_wp_error( string $proxy_url, \WP_Error $transport_error ): \WP_Error {
		$detail = self::sanitize_proxy_detail( $transport_error->get_error_message() );
		$msg    = \sprintf(
			/* translators: 1: proxy URL, 2: technical error (e.g. cURL message). */
			\__( 'Cannot reach the OAuth proxy (%1$s): %2$s. Use custom Google or Microsoft app credentials in integration settings to skip the proxy, or set the doublescale_booking_oauth_proxy_host filter to a reachable base URL.', 'doublescale' ),
			$proxy_url,
			$detail
		);

		return new \WP_Error(
			'proxy_error',
			$msg,
			array( 'status' => 502 )
		);
	}

	/**
	 * WP_Error when the proxy responds but HTTP status is not success.
	 *
	 * @param string $proxy_url Full proxy URL.
	 * @param int    $http_code Response code from {@see \wp_remote_retrieve_response_code()}.
	 * @param string $body      Raw response body (truncated internally).
	 */
	public static function new_proxy_http_wp_error( string $proxy_url, int $http_code, string $body = '' ): \WP_Error {
		$snippet = '' !== $body ? self::sanitize_proxy_detail( $body ) : \__( '(empty body)', 'doublescale' );
		$msg     = \sprintf(
			/* translators: 1: HTTP status code, 2: proxy URL, 3: response snippet. */
			\__( 'OAuth proxy returned HTTP %1$d for %2$s. %3$s', 'doublescale' ),
			$http_code,
			$proxy_url,
			$snippet
		);

		return new \WP_Error(
			'proxy_error',
			$msg,
			array( 'status' => 502 )
		);
	}
}
