<?php
/**
 * Divider (display only).
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Divider.
 */
class DividerField extends DisplayField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'divider';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Divider', 'doublescale' );
	}
}
