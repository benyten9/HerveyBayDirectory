<?php
/**
 * Heading (display only).
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Heading.
 */
class HeadingField extends DisplayField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'heading';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Heading', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function props( array $props, array $raw ): array {
		$props['level'] = max( 2, min( 4, (int) ( $props['level'] ?? 2 ) ) );
		return $props;
	}
}
