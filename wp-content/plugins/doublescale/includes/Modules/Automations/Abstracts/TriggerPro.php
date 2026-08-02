<?php

/**
 * Abstract Trigger
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Trigger class
 */
abstract class TriggerPro extends Trigger {


	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->is_pro = ! doublescale_is_pro_addon_active();
		parent::__construct();
	}

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array();
	}
}
