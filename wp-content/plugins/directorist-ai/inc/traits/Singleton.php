<?php
/**
 * Singleton utility adapted from zamandevs/base-plugin.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Traits;

trait Singleton {

	/**
	 * Prevent direct construction.
	 */
	protected function __construct() {}

	/**
	 * Return the class instance.
	 *
	 * @return static
	 */
	final public static function get_instance() {
		static $instances = array();
		$class            = static::class;
		if ( ! isset( $instances[ $class ] ) ) {
			$instances[ $class ] = new static();
		}
		return $instances[ $class ];
	}

	/**
	 * Prevent cloning.
	 */
	final protected function __clone() {}
}
