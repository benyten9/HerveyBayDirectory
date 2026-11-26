<?php
/**
 * Base of the types that show something and take no answer.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Content only: never required, never mapped, no answer.
 */
abstract class DisplayField extends FieldType {

	/** {@inheritDoc} */
	public function is_display(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function saves_to_contact(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		return null;
	}
}
