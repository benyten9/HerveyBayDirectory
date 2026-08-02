<?php
/**
 * Class MergeTag
 *
 * Abstract class for merge tags
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Managers\MergeTagsManager;

/**
 * Class MergeTag
 */
abstract class MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group;

	/**
	 * Subclasses instances.
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	private static $instances = array();

	/**
	 * Location Instances.
	 *
	 * Instantiates or reuses an instances of Location.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return static - Single instance
	 */
	public static function instance() {
		if ( ! isset( self::$instances[ static::class ] ) ) {
			$instances = new static();
			$instances->register();
			self::$instances[ static::class ] = $instances;
		}
		return self::$instances[ static::class ];
	}

	/**
	 * Constructor
	 */
	protected function __construct() {}

	/**
	 * Register
	 *
	 * @return bool
	 */
	private function register() {
		try {
			MergeTagsManager::instance()->register_merge_tag( $this );
		} catch ( \Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Get value
	 *
	 * @param BookingModel $booking Booking model.
	 * @param array        $options Options.
	 *
	 * @return string
	 */
	abstract public function get_value( BookingModel $booking, $options = array() );

	/**
	 * Get options
	 *
	 * @return array
	 */
	public function get_options() {
		return array();
	}
}
