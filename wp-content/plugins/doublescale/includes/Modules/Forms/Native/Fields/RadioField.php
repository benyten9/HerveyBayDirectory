<?php
/**
 * Single choice.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * One option from a list of buttons.
 */
class RadioField extends ChoiceField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'radio';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Single choice', 'doublescale' );
	}
}
