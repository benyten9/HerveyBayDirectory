<?php
/**
 * Rating.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Stars: a whole number from 1 to the field's maximum (2–10).
 */
class RatingField extends NumberField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'rating';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Rating', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		$max = max( 2, min( 10, (int) ( $field['props']['max'] ?? 5 ) ) );
		return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= $max ? '' : $this->say( $texts, 'invalidRating' );
	}
}
