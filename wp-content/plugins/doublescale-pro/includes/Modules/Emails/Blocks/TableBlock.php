<?php
/**
 * Table Block
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Emails\Blocks;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Abstracts\EmailBlock;

/**
 * Table block for emails
 */
class TableBlock extends EmailBlock {
	/**
	 * Get block type
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'table';
	}

	/**
	 * Get block name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Table', 'doublescale');
	}

	/**
	 * Get default properties
	 *
	 * @return array
	 */
	public function get_default_props(): array {
		return array(
			'tableData'       => array(
				array( 'Header 1', 'Header 2', 'Header 3' ),
				array( 'Row 1 Col 1', 'Row 1 Col 2', 'Row 1 Col 3' ),
				array( 'Row 2 Col 1', 'Row 2 Col 2', 'Row 2 Col 3' ),
			),
			'headerBgColor'   => '#f8f9fa',
			'headerTextColor' => '#333333',
			'rowBgColor'      => '#ffffff',
			'rowTextColor'    => '#333333',
			'borderColor'     => '#dee2e6',
			'borderWidth'     => 1,
			'fontSize'        => 14,
			'fontFamily'      => 'Arial, sans-serif',
			'padding'         => array(
				'top'    => 8,
				'right'  => 12,
				'bottom' => 8,
				'left'   => 12,
			),
			'align'           => 'center',
		);
	}

	/**
	 * Render block
	 *
	 * @param array                                       $props Block properties
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	public function render( array $props, $contact = null ): string {
		// Merge with default props
		$props = wp_parse_args( $props, $this->get_default_props() );

		// Container styles (matching frontend)
		$container_style = $this->build_style_string(
			array(
				'text-align' => $props['align'],
				'width'      => '100%',
			)
		);

		// Table styles (matching frontend)
		$table_style = $this->build_style_string(
			array(
				'border-collapse' => 'collapse',
				'width'           => '100%',
				'max-width'       => '100%',
				'font-family'     => $props['fontFamily'],
				'font-size'       => $props['fontSize'] . 'px',
				'border'          => $props['borderWidth'] . 'px solid ' . $props['borderColor'],
			)
		);

		// Header cell styles (matching frontend)
		$header_cell_style = $this->build_style_string(
			array(
				'background-color' => $props['headerBgColor'],
				'color'            => $props['headerTextColor'],
				'padding'          => $this->format_padding( $props['padding'] ),
				'border'           => $props['borderWidth'] . 'px solid ' . $props['borderColor'],
				'font-weight'      => 'bold',
				'text-align'       => 'left',
			)
		);

		// Row cell styles (matching frontend)
		$row_cell_style = $this->build_style_string(
			array(
				'background-color' => $props['rowBgColor'],
				'color'            => $props['rowTextColor'],
				'padding'          => $this->format_padding( $props['padding'] ),
				'border'           => $props['borderWidth'] . 'px solid ' . $props['borderColor'],
				'text-align'       => 'left',
			)
		);

		$table_data = $props['tableData'] ?? array();
		if ( empty( $table_data ) ) {
			// Return placeholder (matching frontend)
			return "<div style=\"{$container_style};text-align:center;padding:20px;\">
				<span style=\"font-size: 32px; font-weight: 600; color: #1E3A8A;\">" .
					esc_html__( 'Add table data', 'doublescale') . '</span>
			</div>';
		}

		$html = "<div style=\"{$container_style}\">
			<table style=\"{$table_style}\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">";

		// Render table rows
		foreach ( $table_data as $row_index => $row ) {
			$html .= '<tr>';

			if ( is_array( $row ) ) {
				foreach ( $row as $cell ) {
					$cell_content = $this->process_merge_tags( $cell, $contact );
					$cell_style   = $row_index === 0 ? $header_cell_style : $row_cell_style;
					$cell_tag     = $row_index === 0 ? 'th' : 'td';

					$html .= "<{$cell_tag} style=\"{$cell_style}\">{$cell_content}</{$cell_tag}>";
				}
			}

			$html .= '</tr>';
		}

		$html .= '</table></div>';

		return $html;
	}
}
