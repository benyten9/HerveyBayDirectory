<?php
/**
 * Text block (display only).
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Text block.
 */
class ParagraphField extends DisplayField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'paragraph';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Text block', 'doublescale' );
	}
}
