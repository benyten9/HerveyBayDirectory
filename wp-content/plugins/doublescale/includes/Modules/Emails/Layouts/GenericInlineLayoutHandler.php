<?php
/**
 * Generic Inline Layout Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Generic inline layout handler - uses flexBasis from blocks
 */
class GenericInlineLayoutHandler extends AbstractLayoutHandler {
	/**
	 * Check if this handler can handle the given block
	 *
	 * @param array $block Block data
	 * @return bool
	 */
	public function can_handle( array $block ): bool {
		return isset( $block['props']['inlineLayout'] ) && $block['props']['inlineLayout'] &&
				isset( $block['props']['containerId'] );
	}

	/**
	 * Render the layout using responsive table columns
	 *
	 * @param array    $blocks All blocks in column
	 * @param int      &$i Current index
	 * @param callable $render_block_callback Callback to render individual blocks
	 * @return string HTML output
	 */
	public function render( array $blocks, int &$i, callable $render_block_callback ): string {
		$container_id    = $blocks[ $i ]['props']['containerId'];
		$first_block     = $blocks[ $i ];
		$template_layout = isset( $first_block['props']['templateLayout'] ) ? $first_block['props']['templateLayout'] : array();

		$inline_blocks = $this->collect_blocks_by_container( $blocks, $i, $container_id );
		$gap           = $this->get_gap( $template_layout );
		$total         = count( $inline_blocks );
		$columns       = array();

		foreach ( $inline_blocks as $index => $block ) {
			$flex_basis = isset( $block['props']['flexBasis'] ) ? $block['props']['flexBasis'] : 'auto';

			if ( strpos( $flex_basis, '%' ) !== false ) {
				$width = $flex_basis;
			} else {
				$width = round( 100 / $total, 2 ) . '%';
			}

			$gap_style = $this->get_column_padding( $index, $total, $gap );
			$content   = $render_block_callback( $block );

			$columns[] = array(
				'width_pct' => $width,
				'content'   => $content,
				'gap_style' => $gap_style,
			);
		}

		return $this->build_responsive_columns( $columns, $gap );
	}

	/**
	 * Get handler name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Generic Inline Layout';
	}

	/**
	 * Get handler priority (lowest - fallback)
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 5;
	}
}
