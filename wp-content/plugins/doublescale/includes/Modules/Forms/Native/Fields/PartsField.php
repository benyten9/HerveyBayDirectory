<?php
/**
 * Base of the answers made of named parts (name, address).
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * An answer stored as { part: text }, only the parts that were filled in.
 */
abstract class PartsField extends FieldType {

	/**
	 * The parts, in display order.
	 *
	 * @return string[]
	 */
	abstract public function parts(): array;

	/**
	 * Text between parts when the answer is shown on one line.
	 *
	 * @return string
	 */
	protected function glue(): string {
		return ', ';
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		$parts = array();
		foreach ( $this->parts() as $part ) {
			$item = is_array( $value ) && isset( $value[ $part ] ) && is_scalar( $value[ $part ] ) ? trim( sanitize_text_field( (string) $value[ $part ] ) ) : '';
			if ( '' !== $item ) {
				$parts[ $part ] = self::cut( $item, 190 );
			}
		}
		return empty( $parts ) ? null : $parts;
	}

	/** {@inheritDoc} */
	public function readable( array $field, $value ): string {
		$parts = array();
		foreach ( $this->parts() as $part ) {
			if ( ! empty( $value[ $part ] ) ) {
				$parts[] = $value[ $part ];
			}
		}
		return implode( $this->glue(), $parts );
	}
}
