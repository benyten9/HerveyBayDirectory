<?php

/**
 * Process Conditions
 *
 * This class is responsible for handling the process conditions
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Conditions;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Conditions\Condition;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Process Conditions class
 */
class Process {





	/**
	 * Conditions
	 *
	 * @var array
	 */
	protected $conditions = array();

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
	 * @param array                  $conditions Conditions
	 *
	 * @return void
	 */
	public function __construct( $automation_contact, $conditions = array() ) {
		$this->conditions         = $conditions;
		$this->automation_contact = $automation_contact;
	}

	/**
	 * Check OR Conditions
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function Check() {
		foreach ( $this->conditions ?? array() as $group ) {
			$group_result = $this->check_group( $group );

			if ( $group_result ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check AND Conditions
	 *
	 * @since 1.0.0
	 *
	 * @param array $group Group
	 *
	 * @return bool
	 */
	protected function check_group( $group ) {
		$condition        = new Condition( $this->automation_contact, $group );
		$condition_result = $condition->is_condition_fulfilled();

		if ( ! $condition_result ) {
			return false;
		}

		return true;
	}
}
