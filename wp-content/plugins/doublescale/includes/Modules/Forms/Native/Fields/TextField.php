<?php
/**
 * Short text.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * One line of text, with an optional length.
 */
class TextField extends FieldType {

	/** {@inheritDoc} */
	public function type(): string {
		return 'text';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Short text', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$validation = is_array( $field['validation'] ?? null ) ? $field['validation'] : array();
		$length     = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		$min        = (int) ( $validation['min_length'] ?? 0 );
		$max        = (int) ( $validation['max_length'] ?? 0 );
		if ( $min > 0 && $length < $min ) {
			return $this->say( $texts, 'minLength', $min );
		}
		if ( $max > 0 && $length > $max ) {
			return $this->say( $texts, 'maxLength', $max );
		}
		return '';
	}
}
