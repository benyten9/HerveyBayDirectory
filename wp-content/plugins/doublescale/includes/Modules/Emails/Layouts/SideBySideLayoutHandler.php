<?php
/**
 * Side-by-Side Layout Handler (50% + 50%)
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Side-by-side layout handler
 */
class SideBySideLayoutHandler extends AbstractLayoutHandler {
	/**
	 * Check if this handler can handle the given block
	 *
	 * @param array $block Block data
	 * @return bool
	 */
	public function can_handle( array $block ): bool {
		return isset( $block['props']['sideBySideLayout'] ) && $block['props']['sideBySideLayout'];
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
		$left_blocks  = array();
		$right_blocks = array();

		while ( $i < count( $blocks ) && isset( $blocks[ $i ]['props']['sideBySideLayout'] ) && $blocks[ $i ]['props']['sideBySideLayout'] ) {
			$block = $blocks[ $i ];

			if ( isset( $block['props']['sideBySidePosition'] ) && $block['props']['sideBySidePosition'] === 'left' ) {
				$left_blocks[] = $block;
			} elseif ( isset( $block['props']['sideBySidePosition'] ) && $block['props']['sideBySidePosition'] === 'right' ) {
				$right_blocks[] = $block;
			}

			++$i;
		}

		$gap           = '5px';
		$left_content  = $this->render_blocks_in_column( $left_blocks, $render_block_callback );
		$right_content = $this->render_blocks_in_column( $right_blocks, $render_block_callback );

		$columns = array(
			array(
				'width_pct' => '50%',
				'content'   => $left_content,
				'gap_style' => $this->get_column_padding( 0, 2, $gap ),
			),
			array(
				'width_pct' => '50%',
				'content'   => $right_content,
				'gap_style' => $this->get_column_padding( 1, 2, $gap ),
			),
		);

		return $this->build_responsive_columns( $columns, $gap );
	}

	/**
	 * Get handler name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Side-by-Side (50% + 50%)';
	}

	/**
	 * Get handler priority (higher than grids)
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 30;
	}
}
