<?php
/**
 * Addon Settings class.
 *
 * @since 1.5.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Addon;

/**
 * Stores global addon settings as a WP option.
 *
 * @since 1.5.0
 */
class Settings {

	/**
	 * @var Addon
	 */
	private $addon;

	/**
	 * @var string
	 */
	private $option_name;

	/**
	 * Constructor.
	 *
	 * @param Addon $addon Addon instance.
	 */
	public function __construct( Addon $addon ) {
		$this->addon       = $addon;
		$this->option_name = "doublescale_{$addon->slug}_settings";
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = get_option( $this->option_name, array() );
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function get_all() {
		return get_option( $this->option_name, array() );
	}

	/**
	 * Update settings (merge).
	 *
	 * @param array $data Key-value pairs to merge.
	 * @return bool
	 */
	public function update( $data ) {
		$settings = get_option( $this->option_name, array() );
		$settings = array_merge( $settings, $data );
		return update_option( $this->option_name, $settings );
	}

	/**
	 * Delete a setting key.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function delete( $key ) {
		$settings = get_option( $this->option_name, array() );
		unset( $settings[ $key ] );
		return update_option( $this->option_name, $settings );
	}
}
