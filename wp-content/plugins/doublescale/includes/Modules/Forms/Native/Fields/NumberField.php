<?php
/**
 * Number.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A number (a comma counts as the decimal point), with an optional range.
 */
class NumberField extends FieldType {

	/** {@inheritDoc} */
	public function type(): string {
		return 'number';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Number', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		if ( is_bool( $value ) || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) ? $value + 0 : self::cut( sanitize_text_field( (string) $value ), 50 );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		if ( ! is_numeric( $value ) ) {
			return $this->say( $texts, 'invalidNumber' );
		}
		$validation = is_array( $field['validation'] ?? null ) ? $field['validation'] : array();
		if ( isset( $validation['min'] ) && '' !== $validation['min'] && $value < (float) $validation['min'] ) {
			return $this->say( $texts, 'minNumber', (string) $validation['min'] );
		}
		if ( isset( $validation['max'] ) && '' !== $validation['max'] && $value > (float) $validation['max'] ) {
			return $this->say( $texts, 'maxNumber', (string) $validation['max'] );
		}
		return '';
	}
}
