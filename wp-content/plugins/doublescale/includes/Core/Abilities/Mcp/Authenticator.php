<?php
/**
 * Resolves the WordPress user behind an MCP request.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities\Mcp;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;

/**
 * Accepts a DoubleScale MCP API key or a WordPress application password.
 *
 * Both paths end at a WordPress user id and nothing else — this class never
 * grants access on its own. Whatever that user may do is then decided by the
 * ability permission callbacks, so there is no second authorisation model to
 * keep in sync.
 */
final class Authenticator {

	/**
	 * Resolve the calling user.
	 *
	 * Order: an already-authenticated session (cookie, for same-origin admin
	 * testing) → our API key → application password.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int User id, or 0 when unauthenticated.
	 */
	public static function resolve( WP_REST_Request $request ): int {
		$current = get_current_user_id();
		if ( $current > 0 ) {
			return $current;
		}

		$header = self::authorization_header( $request );

		$api_key = self::extract_api_key( $request, $header );
		if ( '' !== $api_key ) {
			$user_id = ApiKeyStore::resolve_user( $api_key );
			if ( $user_id > 0 ) {
				return $user_id;
			}
		}

		return self::resolve_basic_auth( $header );
	}

	/**
	 * Read the Authorization header, tolerating servers that hide it.
	 *
	 * Some Apache/CGI setups drop Authorization before PHP sees it, which is
	 * why the redirected variant is checked too.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function authorization_header( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );

		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		return trim( $header );
	}

	/**
	 * Pull an API key from the request.
	 *
	 * Accepts `Authorization: Bearer <key>` and the `X-DoubleScale-Key` header,
	 * because not every MCP client lets you set an arbitrary Authorization
	 * value.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $header  Authorization header value.
	 * @return string
	 */
	private static function extract_api_key( WP_REST_Request $request, string $header ): string {
		$explicit = trim( (string) $request->get_header( 'x_doublescale_key' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		if ( 0 === stripos( $header, 'bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		return '';
	}

	/**
	 * Validate HTTP Basic credentials as a WordPress application password.
	 *
	 * @since 1.0.0
	 *
	 * @param string $header Authorization header value.
	 * @return int User id, or 0.
	 */
	private static function resolve_basic_auth( string $header ): int {
		if ( 0 !== stripos( $header, 'basic ' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- required: HTTP Basic credentials are base64 by RFC 7617, not obfuscation. Strict mode rejects malformed input.
		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( ! is_string( $decoded ) || false === strpos( $decoded, ':' ) ) {
			return 0;
		}

		list( $username, $password ) = explode( ':', $decoded, 2 );

		$username = trim( $username );
		// Application passwords are displayed in groups of four; clients and
		// humans both paste them with the spaces intact.
		$password = str_replace( ' ', '', $password );

		if ( '' === $username || '' === $password ) {
			return 0;
		}

		if ( ! function_exists( 'wp_authenticate_application_password' ) ) {
			return 0;
		}

		$user = wp_authenticate_application_password( null, $username, $password );

		if ( is_wp_error( $user ) || ! $user instanceof \WP_User ) {
			return 0;
		}

		return (int) $user->ID;
	}
}
