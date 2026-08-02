<?php

/**
 * Abstract Action Pro
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Marks actions that require the Pro add-on. Concrete classes implement {@see Action::process_action};
 * catalog-only stubs may extend {@see ProAutomationStubAction}.
 */
abstract class ActionPro extends Action {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->is_pro = ! doublescale_is_pro_addon_active();
		parent::__construct();
	}
}
