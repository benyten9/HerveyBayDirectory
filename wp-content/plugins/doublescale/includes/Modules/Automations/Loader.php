<?php

/**
 * Class Automations Loader
 * This class is responsible for loading the Automations
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Core\PluginKernel;
use Exception;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\ProcessAutomation;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Automations Loader class
 */
final class Loader {


	/**
	 * @var Loader|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * The DI container is registered to call this method. Do not resolve the
	 * same FQCN from within here or the container will recurse until the
	 * process runs out of memory.
	 *
	 * @since 1.0.0
	 *
	 * @return Loader
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * constructor
	 */
	private function __construct() {
		add_action( 'doublescale_ready', array( $this, 'load_hooks' ) );
	}



	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		PluginKernel::instance()->automations_tasks->register_callback( 'process_automation_step', array( $this, 'process_automation_step' ) );
		PluginKernel::instance()->automations_tasks->register_callback( 'process_automations', array( $this, 'process_automations' ) );
		PluginKernel::instance()->automations_tasks->register_callback( 'process_automation_goal', array( $this, 'process_automation_goal' ) );
	}

	/**
	 * Process Automations
	 *
	 * Handles both sync calls (direct arguments) and async calls (meta_id).
	 * When called async via Action Scheduler, receives meta_id as first arg and null for second.
	 * When called sync, receives AutomationModel and args directly.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel|array|int $automation Either the automation model (sync), array with meta_id, or meta_id int (async).
	 * @param array|null                $args       The trigger arguments (only for sync calls).
	 *
	 * @return void
	 */
	public function process_automations( $automation, $args = null ) {
		try {
			// Check if this is an async call from Action Scheduler
			// When async via do_action_ref_array, ['meta_id' => X] gets unpacked to just X as first arg
			// So we check: numeric value + null args = meta_id from async call
			if ( is_numeric( $automation ) && null === $args ) {
				$meta_id   = $automation;
				$meta_args = doublescale_get_meta_args( $meta_id );
				if ( ! $meta_args || count( $meta_args ) < 2 ) {
					throw new Exception( 'Failed to retrieve automation arguments from meta_id: ' . $meta_id );
				}
				list( $automation, $args ) = $meta_args;
			}
			// Direct invocation form: caller passes ['meta_id' => X].
			elseif ( is_array( $automation ) && isset( $automation['meta_id'] ) ) {
				$meta_id   = $automation['meta_id'];
				$meta_args = doublescale_get_meta_args( $meta_id );
				if ( ! $meta_args || count( $meta_args ) < 2 ) {
					throw new Exception( 'Failed to retrieve automation arguments from meta_id: ' . $meta_id );
				}
				list( $automation, $args ) = $meta_args;
			}

			// Ensure we have an AutomationModel
			if ( ! $automation instanceof AutomationModel ) {
				if ( is_numeric( $automation ) ) {
					$automation = AutomationModel::find( $automation );
				} elseif ( is_array( $automation ) && isset( $automation['id'] ) ) {
					$automation = AutomationModel::find( $automation['id'] );
				}

				if ( ! $automation ) {
					throw new Exception( 'Automation not found' );
				}
			}

			$process_automation = new ProcessAutomation( $automation, $args );
			$process_automation->start();
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Process Automations Error', 'doublescale' ),
				array(
					'code'  => 'process_automations_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}
	}

	/**
	 * Process automation step
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel|int $automation The automation model or meta_id integer.
	 * @param int                 $step_id      The step.
	 * @param int                 $automation_contact_id      The automation contact.
	 * @return void
	 */
	public function process_automation_step( $automation, $step_id = null, $automation_contact_id = null ) {
		try {
			$parent_step_id = null;

			// if this below condition is true this meaning i get args from meta_id from database and automation is integer from that meta_id
			if ( is_numeric( $automation ) && null === $step_id && null === $automation_contact_id ) {
				$args = doublescale_get_meta_args( $automation );
				if ( $args && count( $args ) >= 4 ) {
					list( $automation, $parent_step_id, $step_id, $automation_contact_id ) = $args;
				} else {
					throw new Exception( 'Failed to retrieve arguments from meta_id: ' . $automation );
				}
			}

			// after this i will check that i can get AutomationModel from $automation
			if ( ! $automation instanceof AutomationModel ) {
				$automation = AutomationModel::findOrFail( $automation );
				if ( ! $automation ) {
					throw new Exception( 'Automation not found' );
				}
			}

			if ( $parent_step_id && is_numeric( $parent_step_id ) && $parent_step_id > 0 ) {
				// Find and update the process record for the delay step, not the step itself
				$delay_process = $automation->processes()
					->where( 'automation_contact_id', $automation_contact_id )
					->where( 'step_id', $parent_step_id )
					->where( 'status', 'pending' )
					->first();

				if ( $delay_process ) {
					$delay_process->status = 'completed';
					$delay_process->save();
				}
			}
			$step               = AutomationStepModel::findOrFail( $step_id );
			$automation_process = new ProcessAutomation( $automation );
			$automation_process->process_step( $step, $automation_contact_id );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Process Automation Step Error: ', 'doublescale' ),
				array(
					'code'  => 'process_automation_step',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}
	}

	/**
	 * Process automation goal
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationStepModel $step The automation model.
	 * @param int                 $automation_contact_id The automation contact ID.
	 *
	 * @return void
	 */
	public function process_automation_goal( $step, $automation_contact_id ) {
		try {
			$skip               = $step->get_setting( 'skip', false );
			$automation         = $step->automation;
			$automation_contact = AutomationContactModel::find( $automation_contact_id );

			if ( ! $automation || ! $automation_contact ) {
				return;
			}

			if ( ! $skip ) {
				// Update the goal process record to completed
				$goal_process = $automation_contact->processes()->where( 'step_id', $step->id )->where( 'status', 'pending' )->first();
				if ( $goal_process ) {
					$goal_process->status = 'completed';
					$goal_process->save();
				}

				// Move to next step if available
				if ( 0 !== $automation_contact->next_step ) {
					$automation_process = new ProcessAutomation( $step->automation );
					$automation_process->enqueue_step( $automation_contact->next_step, $automation_contact->id );
				}
			} else {
				// If skipped, just mark the process as completed
				$automation_process = $automation_contact->processes()->where( 'step_id', $step->id )->where( 'status', 'pending' )->first();
				if ( $automation_process ) {
					$automation_process->status = 'completed';
					$automation_process->save();
				}
			}
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Process Automation Goal Error: ', 'doublescale' ),
				array(
					'code'  => 'process_automation_goal',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}
	}
}
