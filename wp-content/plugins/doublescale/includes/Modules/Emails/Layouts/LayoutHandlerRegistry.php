<?php
/**
 * Layout Handler Registry
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Registry for layout handlers
 */
class LayoutHandlerRegistry {
	/**
	 * Singleton instance
	 *
	 * @var LayoutHandlerRegistry
	 */
	private static $instance = null;

	/**
	 * Registered handlers
	 *
	 * @var LayoutHandlerInterface[]
	 */
	private $handlers = array();

	/**
	 * Get singleton instance
	 *
	 * @return LayoutHandlerRegistry
	 */
	public static function instance(): LayoutHandlerRegistry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - register default handlers
	 */
	private function __construct() {
		$this->register_default_handlers();
	}

	/**
	 * Register default layout handlers
	 */
	private function register_default_handlers() {
		// Register side-by-side handler (highest priority)
		$this->register( new SideBySideLayoutHandler() );

		// Register all grid handlers (one handler, multiple instances)
		foreach ( GridLayoutHandler::get_supported_grids() as $grid_id ) {
			$this->register( new GridLayoutHandler( $grid_id ) );
		}

		// Register generic inline handler (lowest priority - fallback)
		$this->register( new GenericInlineLayoutHandler() );
	}

	/**
	 * Register a layout handler
	 *
	 * @param LayoutHandlerInterface $handler Handler to register
	 */
	public function register( LayoutHandlerInterface $handler ) {
		$this->handlers[] = $handler;

		// Sort by priority (highest first)
		usort(
			$this->handlers,
			function ( $a, $b ) {
				return $b->get_priority() - $a->get_priority();
			}
		);
	}

	/**
	 * Find handler for a block
	 *
	 * @param array $block Block data
	 * @return LayoutHandlerInterface|null
	 */
	public function find_handler( array $block ) {
		foreach ( $this->handlers as $handler ) {
			if ( $handler->can_handle( $block ) ) {
				return $handler;
			}
		}
		return null;
	}

	/**
	 * Get all registered handlers
	 *
	 * @return LayoutHandlerInterface[]
	 */
	public function get_handlers(): array {
		return $this->handlers;
	}
}
