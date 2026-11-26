<?php
/**
 * Multiple choice.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Several options, with an optional fewest and most.
 */
class CheckboxField extends ChoiceField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'checkbox';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Multiple choice', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$clean = array();
		foreach ( self::as_list( $value ) as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$clean[] = self::cut( sanitize_text_field( (string) $item ), 500 );
			}
		}
		return empty( $clean ) ? null : array_values( array_unique( $clean ) );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		$chosen = (array) $value;
		foreach ( $chosen as $item ) {
			if ( ! self::option_allowed( $field, (string) $item ) ) {
				return $this->say( $texts, 'invalidChoices' );
			}
		}
		$validation = is_array( $field['validation'] ?? null ) ? $field['validation'] : array();
		$min        = (int) ( $validation['min'] ?? 0 );
		$max        = (int) ( $validation['max'] ?? 0 );
		if ( $min > 0 && count( $chosen ) < $min ) {
			return $this->say( $texts, 1 === $min ? 'minChoicesOne' : 'minChoices', $min );
		}
		if ( $max > 0 && count( $chosen ) > $max ) {
			return $this->say( $texts, 1 === $max ? 'maxChoicesOne' : 'maxChoices', $max );
		}
		return '';
	}

	/** {@inheritDoc} */
	public function readable( array $field, $value ): string {
		$labels = array();
		foreach ( (array) $value as $item ) {
			$labels[] = self::option_label( $field, (string) $item );
		}
		return implode( ', ', $labels );
	}
}
