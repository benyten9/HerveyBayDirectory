<?php
/**
 * Image (display only).
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Image.
 */
class ImageField extends DisplayField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'image';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Image', 'doublescale' );
	}
}
