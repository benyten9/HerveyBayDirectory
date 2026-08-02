<?php
/**
 * Document template (design) constants.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Document template id helpers (1–8).
 */
class DocumentTemplate {

	const MIN     = 1;
	const MAX     = 8;
	const DEFAULT = 1;

	/**
	 * Clamp a raw value to a valid template id.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function normalize( $value ): int {
		$id = (int) $value;
		if ( $id < self::MIN || $id > self::MAX ) {
			return self::DEFAULT;
		}

		return $id;
	}
}
