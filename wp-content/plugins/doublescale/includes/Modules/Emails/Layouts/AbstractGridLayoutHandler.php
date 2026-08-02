<?php
/**
 * Abstract Grid Layout Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for grid layout handlers
 */
abstract class AbstractGridLayoutHandler extends AbstractLayoutHandler {
	/**
	 * Get container ID for this grid
	 *
	 * @return string
	 */
	abstract protected function get_container_id(): string;

	/**
	 * Get column configuration
	 * Returns array of column configs: [ ['width' => '25%', 'blocks' => [0, 3]], ... ]
	 *
	 * @return array
	 */
	abstract protected function get_column_config(): array;

	/**
	 * Check if this handler can handle the given block
	 *
	 * @param array $block Block data
	 * @return bool
	 */
	public function can_handle( array $block ): bool {
		return isset( $block['props']['inlineLayout'] ) && $block['props']['inlineLayout'] &&
				isset( $block['props']['containerId'] ) && $block['props']['containerId'] === $this->get_container_id();
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

		$grid_blocks   = $this->collect_blocks_by_container( $blocks, $i, $container_id );
		$gap           = $this->get_gap( $template_layout );
		$column_config = $this->get_column_config();
		$columns       = array();

		foreach ( $column_config as $index => $config ) {
			$column_blocks = array();
			foreach ( $config['blocks'] as $block_index ) {
				if ( isset( $grid_blocks[ $block_index ] ) ) {
					$column_blocks[] = $grid_blocks[ $block_index ];
				}
			}

			if ( empty( $column_blocks ) ) {
				continue;
			}

			$content   = $this->render_blocks_in_column( $column_blocks, $render_block_callback );
			$gap_style = $this->get_column_padding( $index, count( $column_config ), $gap );

			$columns[] = array(
				'width_pct' => $config['width'],
				'content'   => $content,
				'gap_style' => $gap_style,
			);
		}

		return $this->build_responsive_columns( $columns, $gap );
	}

	/**
	 * Get handler priority (grids have higher priority)
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 20;
	}
}
