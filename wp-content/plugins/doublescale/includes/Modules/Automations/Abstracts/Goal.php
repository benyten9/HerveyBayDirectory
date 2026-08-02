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
			$steps = AutomationStepModel::where( 'type', 'goal' )
				->where( 'action', $this->slug )
				->get();
			if ( $steps->isEmpty() ) {
				return;
			}

			foreach ( $steps as $step ) {
				$automation_id = (int) ( $step->automation_id ?? 0 );
				if ( $automation_id <= 0 ) {
					continue;
				}

				// Query by automation_id so orphan goal steps (deleted automation) cannot fatal.
				$automation_contacts = AutomationContactModel::query()
					->where( 'automation_id', $automation_id )
					->where( 'contact_id', (int) $contact->id )
					->where( 'current_step', (int) $step->id )
					->where( 'status', 'pending' )
					->get();

				// Enqueue goal for each automation contact
				foreach ( $automation_contacts as $automation_contact ) {
					if ( $this->is_completed( $automation_contact, $data ) ) {
						$this->enqueue_goal( $automation_contact, $step );
					}
				}
			}
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
