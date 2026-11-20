<?php
/**
 * X (Twitter) handle of the site's social account.
 *
 * @package SEOPressPro
 */

namespace SEOPressPro\Helpers;

defined( 'ABSPATH' ) || exit;

class XHandle {

	/**
	 * Extract the bare handle from an X account value.
	 *
	 * The X account setting is free text saved with a leading "@", so it holds
	 * "@handle", or "@https://twitter.com/handle" when a URL was typed there.
	 * The free plugin parses it with `SEOPress\Helpers\XHandle::extract()`;
	 * on an older free plugin without it the same parsing is done here, so
	 * both plugins read one handle whatever their versions.
	 *
	 * @param string $value Stored value.
	 *
	 * @return string Handle without "@", empty when none can be read.
	 */
	public static function extract( $value ) {
		if ( class_exists( '\SEOPress\Helpers\XHandle' ) ) {
			return (string) \SEOPress\Helpers\XHandle::extract( $value );
		}

		$value = ltrim( trim( (string) $value ), '@' );

		if ( preg_match( '#^(?:https?://)?(?:www\.|mobile\.)?(?:twitter|x)\.com/+@?([^/?\#\s]+)#i', $value, $match ) ) {
			$value = $match[1];
		}

		return ltrim( $value, '@' );
	}

	/**
	 * X profile URL of an X account value.
	 *
	 * @param string $value Stored value.
	 *
	 * @return string "https://x.com/handle", empty when no handle can be read.
	 */
	public static function profile_url( $value ) {
		$handle = self::extract( $value );

		return '' !== $handle ? sprintf( 'https://x.com/%s', $handle ) : '';
	}
}
