<?php
/**
 * Email.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * An email address, stored in lower case.
 */
class EmailField extends FieldType {

	/** {@inheritDoc} */
	public function type(): string {
		return 'email';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Email', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function contact_fallback(): string {
		return 'email';
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$value = is_scalar( $value ) ? strtolower( trim( sanitize_text_field( (string) $value ) ) ) : '';
		return '' === $value ? null : self::cut( $value, 190 );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		return is_email( $value ) ? '' : $this->say( $texts, 'invalidEmail' );
	}
}
