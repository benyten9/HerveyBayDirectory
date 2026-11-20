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

		// Full alignment spans the column. The width control applies to the
		// other alignments. An <hr> ignores that width in most mail clients, so
		// the line is a table cell border instead.
		$is_full    = ( 'full' === $props['align'] );
		$line_width = $is_full ? '100%' : max( 0, (int) $props['width'] ) . '%';

		switch ( $props['align'] ) {
			case 'left':
			case 'full':
				$align_attr = 'left';
				break;
			case 'right':
				$align_attr = 'right';
				break;
			default:
				$align_attr = 'center';
		}

		$border_width = $props['height'] . 'px';
		$border_top   = $border_width . ' ' . $props['style'] . ' ' . $props['color'];

		$line_style = $this->build_style_string(
			array(
				'width'           => $line_width,
				'border-collapse' => 'collapse',
				'opacity'         => $props['opacity'],
			)
		);

		$cell_style = $this->build_style_string(
			array(
				'border-top'      => $border_top,
				'border-radius'   => $props['borderRadius'] . 'px',
				'font-size'       => '0',
				'line-height'     => '0',
				'mso-line-height' => 'exactly',
			)
		);

		$line = '<table role="presentation" width="' . esc_attr( $line_width ) . '" align="' . esc_attr( $align_attr ) . '" cellpadding="0" cellspacing="0" border="0" style="' . esc_attr( $line_style ) . '"><tr><td height="' . esc_attr( (string) $props['height'] ) . '" style="' . esc_attr( $cell_style ) . '">&nbsp;</td></tr></table>';

		return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"width:100%;\">
			<tr>
				<td width=\"100%\" style=\"{$container_style}\">
					{$line}
				</td>
			</tr>
		</table>";
	}
}




