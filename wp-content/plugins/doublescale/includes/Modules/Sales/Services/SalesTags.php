<?php
/**
 * Tag ID helpers for sales records.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

/**
 * SalesTags class.
 */
class SalesTags {

	/**
	 * @param mixed $tag_ids Raw request value.
	 * @return int[]|null
	 */
	public static function normalize_tag_ids( $tag_ids ): ?array {
		if ( null === $tag_ids ) {
			return null;
		}
		if ( ! is_array( $tag_ids ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );
	}
}
