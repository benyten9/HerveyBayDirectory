<?php
/**
 * Dropdown.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * One option from a dropdown.
 */
class SelectField extends ChoiceField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'select';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Dropdown', 'doublescale' );
	}
}
