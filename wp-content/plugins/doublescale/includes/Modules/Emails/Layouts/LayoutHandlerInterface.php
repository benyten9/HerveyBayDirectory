<?php
/**
 * Layout Handler Interface
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for layout handlers
 */
interface LayoutHandlerInterface {
	/**
	 * Check if this handler can handle the given block
	 *
	 * @param array $block Block data
	 * @return bool
	 */
	public function can_handle( array $block ): bool;

	/**
	 * Get handler priority (higher = checked first)
	 *
	 * @return int
	 */
	public function get_priority(): int;

	/**
	 * Render the layout
	 *
	 * @param array    $blocks All blocks in column
	 * @param int      &$i Current index (passed by reference, will be updated)
	 * @param callable $render_block_callback Callback to render individual blocks
	 * @return string HTML output
	 */
	public function render( array $blocks, int &$i, callable $render_block_callback ): string;

	/**
	 * Get handler name for debugging
	 *
	 * @return string
	 */
	public function get_name(): string;
}
