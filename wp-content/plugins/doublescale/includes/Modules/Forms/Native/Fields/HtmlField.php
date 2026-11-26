<?php
/**
 * HTML (display only).
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * HTML.
 */
class HtmlField extends DisplayField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'html';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'HTML', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function props( array $props, array $raw ): array {
		if ( isset( $raw['content'] ) ) {
			// The HTML block keeps what a manager may post, scripts aside.
			$props['content'] = wp_kses_post( (string) $raw['content'] );
		}
		return $props;
	}
}
