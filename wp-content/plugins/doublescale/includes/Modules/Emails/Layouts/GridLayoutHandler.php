<?php
/**
 * Configurable Grid Layout Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails\Layouts;

defined( 'ABSPATH' ) || exit;

/**
 * Configurable grid layout handler - handles all grid patterns
 */
class GridLayoutHandler extends AbstractGridLayoutHandler {
	/**
	 * Grid configurations
	 *
	 * @var array
	 */
	private static $grid_configs = array(
		'grid2-container' => array(
			'name'    => 'Grid 2 (50% + 25% + 25%)',
			'columns' => array(
				array(
					'width'  => '50%',
					'blocks' => array( 0 ),
				),
				array(
					'width'  => '25%',
					'blocks' => array( 1, 3 ),
				),
				array(
					'width'  => '25%',
					'blocks' => array( 2, 4 ),
				),
			),
		),
		'grid3-container' => array(
			'name'    => 'Grid 3 (25% + 50% + 25%)',
			'columns' => array(
				array(
					'width'  => '25%',
					'blocks' => array( 0, 3 ),
				),
				array(
					'width'  => '50%',
					'blocks' => array( 1 ),
				),
				array(
					'width'  => '25%',
					'blocks' => array( 2, 4 ),
				),
			),
		),
		'grid4-container' => array(
			'name'    => 'Grid 4 (25% + 25% + 50%)',
			'columns' => array(
				array(
					'width'  => '25%',
					'blocks' => array( 0, 3 ),
				),
				array(
					'width'  => '25%',
					'blocks' => array( 1, 4 ),
				),
				array(
					'width'  => '50%',
					'blocks' => array( 2 ),
				),
			),
		),
		'grid5-container' => array(
			'name'    => 'Grid 5 (33.33% x 3)',
			'columns' => array(
				array(
					'width'  => '33.33%',
					'blocks' => array( 0, 3 ),
				),
				array(
					'width'  => '33.33%',
					'blocks' => array( 1, 4 ),
				),
				array(
					'width'  => '33.33%',
					'blocks' => array( 2, 5 ),
				),
			),
		),
		'grid6-container' => array(
			'name'    => 'Grid 6 (25% x 4)',
			'columns' => array(
				array(
					'width'  => '25%',
					'blocks' => array( 0, 4 ),
				),
				array(
					'width'  => '25%',
					'blocks' => array( 1, 5 ),
				),
				array(
					'width'  => '25%',
					'blocks' => array( 2, 6 ),
				),
				array(
					'width'  => '25%',
					'blocks' => array( 3, 7 ),
				),
			),
		),
	);

	/**
	 * Container ID for this instance
	 *
	 * @var string
	 */
	private $container_id;

	/**
	 * Constructor
	 *
	 * @param string $container_id Container ID to handle
	 */
	public function __construct( string $container_id ) {
		$this->container_id = $container_id;
	}

	/**
	 * Check if this handler can handle the given block
	 *
	 * @param array $block Block data
	 * @return bool
	 */
	public function can_handle( array $block ): bool {
		return isset( $block['props']['inlineLayout'] ) && $block['props']['inlineLayout'] &&
				isset( $block['props']['containerId'] ) && $block['props']['containerId'] === $this->container_id;
	}

	/**
	 * Get container ID
	 *
	 * @return string
	 */
	protected function get_container_id(): string {
		return $this->container_id;
	}

	/**
	 * Get column configuration
	 *
	 * @return array
	 */
	protected function get_column_config(): array {
		if ( isset( self::$grid_configs[ $this->container_id ] ) ) {
			return self::$grid_configs[ $this->container_id ]['columns'];
		}
		return array();
	}

	/**
	 * Get handler name
	 *
	 * @return string
	 */
	public function get_name(): string {
		if ( isset( self::$grid_configs[ $this->container_id ] ) ) {
			return self::$grid_configs[ $this->container_id ]['name'];
		}
		return 'Grid Layout';
	}

	/**
	 * Get all supported grid container IDs
	 *
	 * @return array
	 */
	public static function get_supported_grids(): array {
		return array_keys( self::$grid_configs );
	}
}
