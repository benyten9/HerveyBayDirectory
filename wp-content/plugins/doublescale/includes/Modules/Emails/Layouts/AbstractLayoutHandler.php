<?php
/**
 * Abstract Layout Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for layout handlers
 */
abstract class AbstractLayoutHandler implements LayoutHandlerInterface {
	/**
	 * Collect blocks with same containerId
	 *
	 * @param array  $blocks All blocks
	 * @param int    &$i Current index
	 * @param string $container_id Container ID to match
	 * @return array Collected blocks
	 */
	protected function collect_blocks_by_container( array $blocks, int &$i, string $container_id ): array {
		$collected = array();

		while ( $i < count( $blocks ) &&
				isset( $blocks[ $i ]['props']['inlineLayout'] ) && $blocks[ $i ]['props']['inlineLayout'] &&
				isset( $blocks[ $i ]['props']['containerId'] ) && $blocks[ $i ]['props']['containerId'] === $container_id ) {
			$collected[] = $blocks[ $i ];
			++$i;
		}

		return $collected;
	}

	/**
	 * Get gap from template layout
	 *
	 * @param array $template_layout Template layout settings
	 * @return string Gap value
	 */
	protected function get_gap( array $template_layout ): string {
		return isset( $template_layout['gap'] ) ? $template_layout['gap'] : '4px';
	}

	/**
	 * Render blocks in a table column
	 *
	 * @param array    $blocks Blocks to render
	 * @param callable $render_callback Render callback
	 * @return string HTML output
	 */
	protected function render_blocks_in_column( array $blocks, callable $render_callback ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= $render_callback( $block );
		}
		return $html;
	}

	/**
	 * Get content width from the renderer.
	 *
	 * @return int
	 */
	protected function get_content_width(): int {
		global $doublescale_email_renderer;
		if ( isset( $doublescale_email_renderer ) && isset( $doublescale_email_renderer->content_width ) ) {
			return (int) $doublescale_email_renderer->content_width;
		}
		return 600;
	}

	/**
	 * Build a responsive table-based layout with Outlook ghost tables
	 * and mobile-stacking classes.
	 *
	 * Structure mirrors render_section() column output:
	 *   <td class="mobile-container">      — width constraint
	 *     <table>                           — responsive wrapper
	 *       <td class="mobile-table">       — gap padding only; blocks provide their own styles
	 *
	 * Each entry in $columns should be:
	 *   [ 'width_pct' => '50%', 'content' => '<rendered html>', 'gap_style' => 'padding-right:4px;' ]
	 *
	 * @param array  $columns    Column definitions.
	 * @param string $gap        Gap CSS value between columns.
	 * @return string HTML output
	 */
	protected function build_responsive_columns( array $columns, string $gap = '4px' ): string {
		if ( empty( $columns ) ) {
			return '';
		}

		$content_width = $this->get_content_width();
		$html          = '';

		$html .= '<tr><td style="padding: 0;">';

		// Wrapper div: font-size:0 eliminates whitespace between inline-block columns
		$html .= '<div style="font-size:0; text-align:left;">';

		// Outlook: open ghost table row
		$html .= '<!--[if mso]>';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
		$html .= '<![endif]-->';

		foreach ( $columns as $col ) {
			$pct         = (float) str_replace( '%', '', $col['width_pct'] );
			$pixel_width = round( ( $pct / 100 ) * $content_width );
			$gap_css     = $col['gap_style'] ?? '';

			// Outlook: open ghost cell
			$html .= '<!--[if mso]>';
			$html .= '<td width="' . round( $pct ) . '%" valign="top" style="' . esc_attr( $gap_css ) . '">';
			$html .= '<![endif]-->';

			// Column wrapper: visible to ALL clients
			$html .= '<div class="mobile-container" style="width:' . $pixel_width . 'px;vertical-align:top;display:inline-block;">';
			$html .= '<table border="0" cellspacing="0" cellpadding="0" width="100%" style="overflow-wrap:anywhere;word-wrap:anywhere;font-size:initial"><tbody><tr>';
			$html .= '<td style="' . esc_attr( $gap_css ) . '" class="mobile-table">';
			$html .= $col['content'];
			$html .= '</td></tr></tbody></table>';
			$html .= '</div>';

			// Outlook: close ghost cell
			$html .= '<!--[if mso]>';
			$html .= '</td>';
			$html .= '<![endif]-->';
		}

		// Outlook: close ghost table row
		$html .= '<!--[if mso]>';
		$html .= '</tr></table>';
		$html .= '<![endif]-->';

		$html .= '</div>';
		$html .= '</td></tr>';

		return $html;
	}

	/**
	 * Calculate gap padding for a column by position.
	 *
	 * @param int    $index Column index (0-based).
	 * @param int    $total Total column count.
	 * @param string $gap   Gap CSS value.
	 * @return string Inline padding style.
	 */
	protected function get_column_padding( int $index, int $total, string $gap ): string {
		if ( $total <= 1 ) {
			return '';
		}
		if ( $index === 0 ) {
			return "padding-right: {$gap};";
		} elseif ( $index === $total - 1 ) {
			return "padding-left: {$gap};";
		}
		return "padding-left: {$gap}; padding-right: {$gap};";
	}

	/**
	 * Get default priority
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 10;
	}
}
