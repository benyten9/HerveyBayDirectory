<?php

/**
 * Class GoalPro
 *
 * Base class for Pro-only goals. This class serves as a placeholder in the free plugin
 * that gets replaced by full implementations when the Pro plugin is active.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * GoalPro class
 *
 * Goals extending this class are Pro-only features. The $is_pro flag indicates
 * whether the feature should be locked in the UI (true = locked, needs Pro).
 */
abstract class GoalPro extends Goal {

	/**
	 * Constructor
	 *
	 * Sets is_pro flag to indicate if this feature is locked.
	 * When Pro is NOT active, is_pro = true (feature is locked).
	 * When Pro IS active, this class is replaced by the Pro implementation.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// is_pro = true when Pro is NOT active (feature is locked/requires Pro)
		// is_pro = false when Pro IS active (feature is available)
		$this->is_pro = ! doublescale_is_pro_addon_active();
	}

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {}
}
