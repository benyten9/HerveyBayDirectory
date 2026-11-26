<?php
/**
 * Hidden field.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A value visitors don't see (UTM, prefill, a fixed default). Logic never hides it.
 */
class HiddenField extends FieldType {

	/** {@inheritDoc} */
	public function type(): string {
		return 'hidden';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Hidden field', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function default_when_empty(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function hidden_by_logic(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function saved_in_partial(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function in_answers_list(): bool {
		return false;
	}
}
