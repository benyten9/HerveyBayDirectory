<?php

/**
 * Process Automation
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Automations\Engine\ContactEnrollment;
use DoubleScale\Modules\Automations\Engine\StepNavigator;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Modules\Automations\Conditions\Process as Process_Conditions;

/**
 * Process Automation
 */
class ProcessAutomation {

	/**
	 * Automation
	 *
	 * @var AutomationModel
	 */
	public $automation;

	/**
	 * Arguments
	 *
	 * @var array
	 */
	public $args = array();

	/**
	 * Start time of automation processing
	 *
	 * @var float|null
	 */
	private static $start_time = null;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation
	 * @param array           $args
	 */
	public function __construct( AutomationModel $automation, $args = array() ) {
		$this->automation = $automation;
		$this->args       = $args;
	}

	/**
	 * Start
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function start() {
		$automation_contact = $this->add_contact();
		if ( ! $automation_contact ) {
			return;
		}

		$first_step = $this->automation->get_first_step();

		if ( $first_step ) {
			$this->enqueue_step( $first_step->id, $automation_contact->id );
		}
	}

	/**
	 * Add Contact
	 *
	 * @since 1.0.0
	 *
	 * @return AutomationContactModel|false
	 */
	public function add_contact() {
		return ( new ContactEnrollment( $this->automation, $this->args ) )->add_contact();
	}

	/**
	 * Maybe Create Contact
	 *
	 * @since 1.0.0
	 *
	 * @return \DoubleScale\Modules\Contacts\Models\ContactModel
	 */
	public function maybe_create_contact() {
		return ( new ContactEnrollment( $this->automation, $this->args ) )->maybe_create_contact();
	}

	/**
	 * Process Step
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 * @param int    $automation_contact_id Automation Contact ID.
	 *
	 * @return void
	 */
	public function process_step( $step, $automation_contact_id ) {

		// If this step has a parent_id > 0, it's a child of a condition step
		// We need to verify that the parent condition has been processed first
		if ( $step->parent_id > 0 ) {
			$parent_step = $this->automation->steps()->where( 'id', $step->parent_id )->first();

			if ( $parent_step && $parent_step->type === 'condition' ) {
				// Check if the parent condition has been processed
				$parent_process = $this->automation->processes()->where( 'automation_contact_id', $automation_contact_id )
					->where( 'step_id', $parent_step->id )
					->whereIn( 'status', array( 'completed', 'yes', 'no' ) )
					->first();

				if ( ! $parent_process ) {
					// Parent condition hasn't been processed yet, so we shouldn't process this step
					return;
				}
			}
		}

		switch ( $step->type ) {
			case 'action':
			case 'delay':
				$this->process_action( $step, $automation_contact_id );
				break;
			case 'condition':
				$this->process_condition( $step, $automation_contact_id );
				break;
			case 'goal':
				$this->process_goal( $step, $automation_contact_id );
				break;
			case 'end_automation':
				$this->process_end_automation( $step, $automation_contact_id );
				break;
		}
	}

	/**
	 * Process End Automation
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 * @param int    $automation_contact_id Automation Contact ID.
	 *
	 * @return void
	 */
	public function process_end_automation( $step, $automation_contact_id ) {
		try {
			$automation_contact = AutomationContactModel::findOrFail( $automation_contact_id );
			$this->update_automation_contact_status( $automation_contact, 'completed', $step->id, 0 );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				\__( 'Process End Automation Error', 'doublescale' ),
				array(
					'code'  => 'process_end_automation',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
			return;
		}
	}

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 * @param int    $automation_contact_id Automation Contact ID.
	 *
	 * @return void
	 */
	public function process_action( $step, $automation_contact_id ) {
		try {
			$automation_contact = AutomationContactModel::findOrFail( $automation_contact_id );
			$action             = ActionsManager::instance()->get_action( $step->action );
			if ( $action->is_pro ) {
				$result = true;
			} else {
				$result = $action->process_action( $this->automation, $step, $automation_contact );
			}
			$next_step = $this->get_next_step( $step );

			if ( ! $result ) {
				$this->add_automation_contact_process( $step, $automation_contact->contact_id, $automation_contact->id, 'failed' );
				$this->update_automation_contact_status( $automation_contact, 'failed', $step->id, $next_step ? $next_step->id : 0 );
				throw new Exception( \__( 'Action failed', 'doublescale' ) );
			}

			$status = $action->auto_enqueue ? 'completed' : 'pending';
			// Add to the automation_contact_processes table
			$this->add_automation_contact_process( $step, $automation_contact->contact_id, $automation_contact->id, $status );

			if ( $action->auto_enqueue ) {
				if ( $next_step ) {
					$this->enqueue_step( $next_step->id, $automation_contact->id );
				} else {
					$this->update_automation_contact_status( $automation_contact, 'completed', $step->id, 0 );
				}
			}
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				\__( 'Process Action Error', 'doublescale' ),
				array(
					'code'  => 'process_action',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
			return;
		}
	}

	/**
	 * Get Next Step
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 *
	 * @return object|bool
	 */
	public function get_next_step( $step ) {
		return StepNavigator::get_next_step( $this->automation, $step );
	}

	/**
	 * Process Condition
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 * @param int    $automation_contact_id Automation Contact ID.
	 *
	 * @return void
	 */
	public function process_condition( $step, $automation_contact_id ) {
		try {
			$automation_contact = AutomationContactModel::findOrFail( $automation_contact_id );
			$conditions         = $step->settings;
			$result             = new Process_Conditions( $automation_contact, $conditions );
			$check              = $result->check();

			$this->add_automation_contact_process(
				$step,
				$automation_contact->contact_id,
				$automation_contact->id,
				$check ? 'yes' : 'no'
			);
			if ( $check ) {
				$this->process_yes_steps( $step, $automation_contact );
			} else {
				$this->process_no_steps( $step, $automation_contact );
			}
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				\__( 'Process Condition Error', 'doublescale' ),
				array(
					'code'  => 'process_condition',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
			return;
		}
	}

	/**
	 * Process Yes Steps
	 *
	 * @since 1.0.0
	 *
	 * @param object                 $step Automation Step.
	 * @param AutomationContactModel $automation_contact Automation Contact.
	 *
	 * @return void
	 */
	public function process_yes_steps( $step, $automation_contact ) {
		$first_yes_step = $automation_contact->automation->steps()
			->where( 'status', 'active' )
			->where( 'parent_id', $step->id )
			->where( 'condition', 'yes' )
			->orderBy( 'order', 'asc' )
			->first();

		if ( $first_yes_step ) {
			$this->enqueue_step( $first_yes_step->id, $automation_contact->id );
		} else {
			// No YES branch steps, continue to the next step after this condition
			$next_step = $this->get_next_step( $step );
			if ( $next_step ) {
				$this->enqueue_step( $next_step->id, $automation_contact->id );
			} else {
				$this->update_automation_contact_status( $automation_contact, 'completed', $step->id, 0 );
			}
		}
	}

	/**
	 * Process No Steps
	 *
	 * @since 1.0.0
	 *
	 * @param object                 $step Automation Step.
	 * @param AutomationContactModel $automation_contact Automation Contact.
	 *
	 * @return void
	 */
	public function process_no_steps( $step, $automation_contact ) {
		$first_no_step = $automation_contact->automation->steps()
			->where( 'status', 'active' )
			->where( 'parent_id', $step->id )
			->where( 'condition', 'no' )
			->orderBy( 'order', 'asc' )
			->first();

		if ( $first_no_step ) {
			$this->enqueue_step( $first_no_step->id, $automation_contact->id );
		} else {
			// No NO branch steps, continue to the next step after this condition
			$next_step = $this->get_next_step( $step );
			if ( $next_step ) {
				$this->enqueue_step( $next_step->id, $automation_contact->id );
			} else {
				$this->update_automation_contact_status( $automation_contact, 'completed', $step->id, 0 );
			}
		}
	}

	/**
	 * Process Goal
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 * @param int    $automation_contact_id Automation Contact ID.
	 *
	 * @return void
	 */
	public function process_goal( $step, $automation_contact_id ) {
		try {
			$automation_contact = AutomationContactModel::findOrFail( $automation_contact_id );
			$skip               = $step->get_setting( 'skip', false );
			$next_step          = $this->get_next_step( $step );
			if ( $skip ) {
				$this->add_automation_contact_process( $step, $automation_contact->contact_id, $automation_contact->id, 'skipped' );
				if ( $next_step ) {
					$this->enqueue_step( $next_step->id, $automation_contact->id );
				}
				return;
			}

			// Create a process record for the goal step with pending status
			$this->add_automation_contact_process( $step, $automation_contact->contact_id, $automation_contact->id, 'pending' );
			$this->update_automation_contact_status( $automation_contact, 'pending', $step->id, $next_step ? $next_step->id : 0 );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				\__( 'Process Goal Error', 'doublescale' ),
				array(
					'code'  => 'process_goal',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
			return;
		}
	}

	/**
	 * Update Automation Contact Status
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Automation Contact.
	 * @param string                 $status Status.
	 * @param int                    $current_step Current Step.
	 * @param int                    $next_step Next Step.
	 *
	 * @return void
	 */
	public function update_automation_contact_status( $automation_contact, $status, $current_step, $next_step ) {
		$automation_contact->update(
			array(
				'status'       => $status,
				'current_step' => $current_step,
				'next_step'    => $next_step,
			)
		);

		// Fire action when automation step fails.
		if ( 'failed' === $status ) {
			/**
			 * Fires when an automation step fails.
			 *
			 * @since 1.0.0
			 *
			 * @param \DoubleScale\Modules\Automations\Models\AutomationModel         $automation         The automation.
			 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel $automation_contact The automation contact.
			 * @param int                                       $step_id            The failed step ID.
			 */
			do_action( 'doublescale_automation_step_failure', $this->automation, $automation_contact, $current_step );
		}

		// Fire action when a contact completes an automation (next_step = 0 means no more steps).
		if ( 'completed' === $status && 0 === $next_step ) {
			/**
			 * Fires when a contact completes an automation.
			 *
			 * @since 1.0.0
			 *
			 * @param \DoubleScale\Modules\Automations\Models\AutomationModel         $automation         The automation.
			 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel $automation_contact The automation contact.
			 */
			do_action( 'doublescale_automation_contact_complete', $this->automation, $automation_contact );
		}
	}

	/**
	 * Add Automation Contact Process
	 *
	 * @since 1.0.0
	 *
	 * @param object $step Automation Step.
	 * @param int    $automation_contact_id Automation Contact ID.
	 * @param string $status Status.
	 *
	 * @return void
	 */
	public function add_automation_contact_process( $step, $contact_id, $automation_contact_id, $status ) {
		$this->automation->processes()->create(
			array(
				'automation_contact_id' => $automation_contact_id,
				'contact_id'            => $contact_id,
				'step_id'               => $step->id,
				'status'                => $status,
			)
		);
	}

	/**
	 * Enqueue Next Step
	 *
	 * Uses smart hybrid approach:
	 * - Runs synchronously for speed when safe
	 * - Switches to async when approaching timeout or memory limits
	 *
	 * @since 1.0.0
	 *
	 * @param int $step_id Step ID.
	 * @param int $automation_contact_id Contact ID.
	 *
	 * @return void
	 */
	public function enqueue_step( $step_id, $automation_contact_id ) {
		// Initialize start time on first call.
		// if ( null === self::$start_time ) {
		// self::$start_time = microtime( true );
		// }

		// // Check if we should switch to async.
		// if ( $this->should_switch_to_async() ) {
			PluginKernel::instance()->automations_tasks->enqueue_async(
				'process_automation_step',
				$this->automation->id,
				0, // parent_step_id - only used for delay steps
				$step_id,
				$automation_contact_id
			);
			// Reset start time for next batch.
		// self::$start_time = null;
		// return;
		// }

		// // Safe to continue synchronously.
		// PluginKernel::instance()->automations_tasks->enqueue_sync(
		// 'process_automation_step',
		// $this->automation,
		// 0, // parent_step_id - only used for delay steps
		// $step_id,
		// $automation_contact_id
		// );
	}

	/**
	 * Check if we should switch to async execution
	 *
	 * Switches to async when:
	 * - Execution time exceeds 70% of the safe threshold (which is already 75% of max)
	 * - Memory usage approaches limit
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function should_switch_to_async() {
		// Check execution time (70% of the already-safe 75% threshold).
		// get minimum of 10 seconds and the max execution time
		$max_time = min( 10, \DoubleScale\Pro\Utils::get_max_execution_time() );
		$elapsed  = microtime( true ) - self::$start_time;

		if ( $elapsed >= ( $max_time * 0.70 ) ) {
			return true;
		}

		// Check memory limit.
		if ( \DoubleScale\Pro\Utils::is_memory_limit_reached() ) {
			return true;
		}

		return false;
	}
}
