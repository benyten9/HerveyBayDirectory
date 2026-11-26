<?php
/**
 * Base of the choice types (dropdown, single choice, multiple choice).
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * One option out of the field's list ("Other" lets visitors type their own).
 */
abstract class ChoiceField extends FieldType {

	/** {@inheritDoc} */
	public function has_options(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
		return '' === $value ? null : self::cut( $value, 500 );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		return self::option_allowed( $field, (string) $value ) ? '' : $this->say( $texts, 'invalidChoice' );
	}

	/** {@inheritDoc} */
	public function readable( array $field, $value ): string {
		return self::option_label( $field, (string) $value );
	}

	/** {@inheritDoc} */
	public function contact_text( array $field, $value ): string {
		return $this->readable( $field, $value );
	}

	/**
	 * Whether a choice is one of the field's options (or "Other" is allowed).
	 *
	 * @param array  $field Field.
	 * @param string $value Choice.
	 * @return bool
	 */
	protected static function option_allowed( array $field, string $value ): bool {
		if ( ! empty( $field['props']['allow_other'] ) ) {
			return true;
		}
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			if ( (string) ( $option['value'] ?? '' ) === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Label of a choice (a typed "Other" answer stays as typed).
	 *
	 * @param array  $field Field.
	 * @param string $value Choice.
	 * @return string
	 */
	protected static function option_label( array $field, string $value ): string {
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			if ( (string) ( $option['value'] ?? '' ) === $value ) {
				return (string) ( $option['label'] ?? $value );
			}
		}
		return $value;
	}
}
