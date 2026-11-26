<?php
/**
 * Date.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A date (YYYY-MM-DD), any day or only past or future days (site time).
 */
class DateField extends FieldType {

	/** {@inheritDoc} */
	public function type(): string {
		return 'date';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Date', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
		return '' === $value ? null : self::cut( $value, 10 );
	}

	/** {@inheritDoc} */
	public function validate( array $field, $value, array $texts ): string {
		$date = \DateTime::createFromFormat( '!Y-m-d', (string) $value );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return $this->say( $texts, 'invalidDate' );
		}
		$range = (string) ( $field['props']['range'] ?? 'any' );
		$today = wp_date( 'Y-m-d' );
		if ( 'past' === $range && $value > $today ) {
			return $this->say( $texts, 'datePast' );
		}
		if ( 'future' === $range && $value < $today ) {
			return $this->say( $texts, 'dateFuture' );
		}
		return '';
	}
}
