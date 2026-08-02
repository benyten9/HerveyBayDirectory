<?php
/**
 * Document template accent color helpers.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize optional per-document accent colors (#RRGGBB).
 */
class DocumentTemplateColor {

	/**
	 * Normalize a raw color value; invalid/empty becomes null (use design default).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function normalize( $value ): ?string {
		if ( null === $value || '' === $value || 'none' === $value ) {
			return null;
		}

		$color = trim( (string) $value );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			return null;
		}

		return strtolower( $color );
	}
}
