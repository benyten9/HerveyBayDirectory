<?php
/**
 * Safe redirects (WPCS: prefer wp_safe_redirect over wp_redirect).
 *
 * @package DoubleScale
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirect via wp_safe_redirect(). When the URL is not the site host, the current
 * request temporarily allows that host (OAuth providers, admin-configured pages, click targets).
 *
 * @param string $location Absolute URL.
 * @param int    $status   HTTP status code.
 */
function doublescale_safe_redirect( string $location, int $status = 302 ): void {
	$location = esc_url_raw( $location );
	if ( '' === $location ) {
		wp_safe_redirect( home_url(), $status );
		exit;
	}

	$host = wp_parse_url( $location, PHP_URL_HOST );
	$home = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( is_string( $host ) && '' !== $host
		&& ( ! is_string( $home ) || strcasecmp( $host, $home ) !== 0 ) ) {
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts, $redirect_host ) {
				if ( is_string( $redirect_host ) && '' !== $redirect_host ) {
					$hosts[] = $redirect_host;
				}
				return $hosts;
			},
			10,
			2
		);
	}

	wp_safe_redirect( $location, $status );
	exit;
}
