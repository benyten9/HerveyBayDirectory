<?php

/**
 * Class Goal
 *
 * This class is responsible for handling the goal
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Engine\GoalJump;

/**
 * Goal class
 */
abstract class Goal {

	/**
	 * Goal Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name;

	/**
	 * Goal Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug;

	/**
	 * Goal Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description;

	/**
	 * Source
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $source;

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group;

	/**
	 * Is PRO only
	 *
	 * @var bool
	 */
	public $is_pro = false;

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract public function load_hooks();

	/**
	 * Process automations
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact Model.
	 * @param array        $data Data.
	 *
	 * @return void
	 */
	public function process( $contact, $data ) {
		if ( $this->is_pro ) {
			return;
		}
		if ( ! $contact instanceof ContactModel || empty( $contact->id ) ) {
			return;
		}
		try {
			// Contacts waiting on this goal are released; contacts still on the
			// way to it skip ahead (see GoalJump).
			GoalJump::handle( $this, $contact, is_array( $data ) ? $data : array() );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error processing goal', 'doublescale' ),
				array(
					'code'  => 'goal_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
		}
	}

	/**
	 * Check if the goal is completed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 * @param array                  $data Data.
	 *
	 * @return bool
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		return true;
	}

	/**
	 * Whether the contact has already achieved this goal when it reaches the
	 * goal step (for goals that describe a state, such as having a tag).
	 *
	 * Goals that only exist as events (a cart recovered, a coupon used) return
	 * false: the contact waits for the event, or follows the goal's setting.
	 *
	 * @param AutomationContactModel $automation_contact Run, pointed at the goal step.
	 * @param ContactModel           $contact            The contact.
	 * @return bool
	 */
	public function is_met_now( AutomationContactModel $automation_contact, ContactModel $contact ) {
		return false;
	}

	/**
	 * The goal step a run is pointed at, for reading the goal's settings.
	 *
	 * @param AutomationContactModel $automation_contact Run.
	 * @return AutomationStepModel|null
	 */
	protected function goal_step( AutomationContactModel $automation_contact ) {
		return AutomationStepModel::find( $automation_contact->current_step );
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array();
	}

	/**
	 * Enqueue goal
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 * @param AutomationStepModel    $step Automation Step.
	 *
	 * @return void
	 */
	public function enqueue_goal( $automation_contact, $step ) {
		PluginKernel::instance()->automations_tasks->enqueue_sync( 'process_automation_goal', $step, $automation_contact->id );
	}

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
