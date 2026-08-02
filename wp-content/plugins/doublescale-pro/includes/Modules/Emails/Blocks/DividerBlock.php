<?php
/**
 * Divider Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Divider block for emails
 */
class DividerBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'divider';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Divider', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'height'          => '1',
			'color'           => '#cccccc',
			'backgroundColor' => 'transparent',
			'style'           => 'solid',
			'padding'         => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
			'align'           => 'center',
			'width'           => '100',
			'borderRadius'    => '0',
			'opacity'         => 1,
		);
	}

	/**
	 * Render block
	 *
	 * @param array                                    $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string {
		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		// Container styles
		$container_style = $this->build_style_string(
			array(
				'padding'          => $this->format_padding( $props['padding'] ),
				'background-color' => $props['backgroundColor'],
			)
		);

		// Calculate alignment styles
		$alignment_styles = array();
		switch ( $props['align'] ) {
			case 'left':
				$alignment_styles['margin-left']  = '0';
				$alignment_styles['margin-right'] = 'auto';
				break;
			case 'right':
				$alignment_styles['margin-left']  = 'auto';
				$alignment_styles['margin-right'] = '0';
				break;
			case 'full':
				$alignment_styles['width']  = '100%';
				$alignment_styles['margin'] = '0';
				break;
			default: // center
				$alignment_styles['margin-left']  = 'auto';
				$alignment_styles['margin-right'] = 'auto';
		}

		// Get border style based on the style property
		$border_width = $props['height'] . 'px';
		$border_color = $props['color'];
		$border_style = $props['style'];

		// Build hr styles
		$hr_styles = array_merge(
			$alignment_styles,
			array(
				'height'          => '0',
				'width'           => $props['width'] . '%',
				'backgroundColor' => 'transparent',
				'border'          => 'none',
				'border-top'      => "{$border_width} {$border_style} {$border_color}",
				'border-radius'   => $props['borderRadius'] . 'px',
				'opacity'         => $props['opacity'],
			)
		);

		$hr_style = $this->build_style_string( $hr_styles );

		// Use table structure for better email client compatibility
		return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">
			<tr>
				<td style=\"{$container_style}\">
					<hr style=\"{$hr_style}\" />
				</td>
			</tr>
		</table>";
	}
}




