<?php
/**
 * Long text.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Several lines of text (up to 10,000 characters), with an optional length.
 */
class TextareaField extends TextField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'textarea';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Long text', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$value = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
		return '' === trim( $value ) ? null : self::cut( $value, 10000 );
	}
}
