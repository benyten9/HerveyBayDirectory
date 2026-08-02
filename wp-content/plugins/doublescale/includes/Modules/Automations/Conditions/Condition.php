<?php

/**
 * Class Condition
 *
 * This class is responsible for handling the condition
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Conditions;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Condition class
 */
class Condition {


	/**
	 * Rules
	 *
	 * @var array
	 */
	protected $rules = array();

	/**
	 * Automation contact
	 *
	 * @var AutomationContactModel
	 */
	protected $automation_contact;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Automation contact
	 * @param array                  $rules Rules
	 *
	 * @return void
	 */
	public function __construct( $automation_contact, $rules = array() ) {
		$this->rules              = $rules;
		$this->automation_contact = $automation_contact;
	}

	/**
	 * Check
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_condition_fulfilled() {
		$result = true;
		if ( ! defined( 'DOUBLESCALE_PLUGIN_FILE' ) ) {
			doublescale_get_logger()->error( 'DoubleScale Pro is not installed or not loaded yet' );
			throw new \Exception( 'DoubleScale Pro is not installed or not loaded yet' );
		}

		foreach ( $this->rules as $rule ) {
			$rule_manager = RulesManager::instance()->get_rule( $rule['rule'] );
			if ( ! $rule_manager ) {
				continue;
			}

			$rule_result = $rule_manager->is_met( $this->automation_contact, $rule );
			if ( ! $rule_result ) {
				$result = false;
				break;
			}
		}
		return $result;
	}
}
