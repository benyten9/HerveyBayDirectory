<?php
/**
 * Moves contacts to a goal the moment they achieve it.
 *
 * When a goal's event happens for a contact, every automation with a goal
 * step of that kind is checked for that contact:
 *
 * - a contact waiting on the goal step itself is released, as before;
 * - a contact waiting earlier on the route to the goal (in a delay, or on an
 *   earlier goal) skips the steps in between and continues after the goal.
 *
 * If the event achieves more than one goal on the contact's route, the
 * contact moves to the one furthest along. Contacts already past a goal, on a
 * sibling branch, between two steps (not waiting anywhere), or finished are
 * left alone.
 *
 * @package DoubleScale\Modules\Automations\Engine
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Goal;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactProcessesModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\ProcessAutomation;
use DoubleScale\Modules\Contacts\Models\ContactModel;

final class GoalJump {

	/**
	 * Handle one goal event for one contact.
	 *
	 * @param Goal                 $goal    The goal type whose event happened.
	 * @param ContactModel         $contact The contact it happened to.
	 * @param array<string, mixed> $data    Event data the goal checks against its settings.
	 * @return void
	 */
	public static function handle( Goal $goal, ContactModel $contact, $data ): void {
		$goal_steps = AutomationStepModel::query()
			->where( 'type', 'goal' )
			->where( 'action', $goal->slug )
			->get();
		if ( $goal_steps->isEmpty() ) {
			return;
		}

		foreach ( $goal_steps->groupBy( 'automation_id' ) as $automation_id => $steps_of_goal ) {
			$automation_id = (int) $automation_id;
			if ( $automation_id <= 0 ) {
				continue;
			}

			$runs = AutomationContactModel::query()
				->where( 'automation_id', $automation_id )
				->where( 'contact_id', (int) $contact->id )
				->whereIn( 'status', array( 'active', 'pending' ) )
				->get();
			if ( $runs->isEmpty() ) {
				continue;
			}

			$automation = AutomationModel::find( $automation_id );
			if ( ! $automation ) {
				continue; // orphan goal step of a deleted automation.
			}

			$all_steps = AutomationStepModel::query()
				->where( 'automation_id', $automation_id )
				->get( array( 'id', 'parent_id', 'condition', 'order' ) )
				->toArray();
			$positions = GoalPath::positions( $all_steps );

			// Furthest goal first: a contact who achieves two goals at once lands on the later one.
			$targets = $steps_of_goal->sortByDesc(
				static function ( $step ) use ( $positions ) {
					return $positions[ (int) $step->id ] ?? -1;
				}
			);

			foreach ( $runs as $run ) {
				self::handle_run( $goal, $automation, $run, $targets, $all_steps, $data );
			}
		}
	}

	/**
	 * @param Goal                   $goal      Goal type.
	 * @param AutomationModel        $automation Automation.
	 * @param AutomationContactModel $run       One run of the contact through the automation.
	 * @param iterable               $targets   Goal steps of this type, furthest first.
	 * @param array<int, array>      $all_steps Step rows of the automation.
	 * @param array<string, mixed>   $data      Event data.
	 * @return void
	 */
	private static function handle_run( Goal $goal, AutomationModel $automation, AutomationContactModel $run, $targets, array $all_steps, $data ): void {
		$waiting_id = self::waiting_step_id( $run );
		if ( null === $waiting_id ) {
			return; // between steps right now: it reaches the goal on its own.
		}

		foreach ( $targets as $target ) {
			$target_id = (int) $target->id;
			$is_here   = $target_id === $waiting_id;
			if ( ! $is_here && ! GoalPath::precedes( $all_steps, $waiting_id, $target_id ) ) {
				continue;
			}
			if ( AutomationStepModel::STATUS_ACTIVE !== $target->status && ! $is_here ) {
				continue;
			}
			if ( ! $goal->is_completed( self::probe( $run, $target_id ), $data ) ) {
				continue;
			}

			if ( $is_here ) {
				$goal->enqueue_goal( $run, $target );
			} else {
				self::jump( $automation, $run, $waiting_id, $target );
			}
			return;
		}
	}

	/**
	 * The step a run is waiting on: the goal it is held on, or the delay it
	 * sleeps in. Null while the run is moving between steps.
	 *
	 * @param AutomationContactModel $run Run.
	 * @return int|null
	 */
	public static function waiting_step_id( AutomationContactModel $run ): ?int {
		if ( 'pending' === $run->status && (int) $run->current_step > 0 ) {
			return (int) $run->current_step;
		}

		$pending = AutomationContactProcessesModel::query()
			->where( 'automation_contact_id', (int) $run->id )
			->where( 'status', 'pending' )
			->orderBy( 'id', 'desc' )
			->first();

		return $pending ? (int) $pending->step_id : null;
	}

	/**
	 * The run as seen from a goal step: goals read their settings from the
	 * run's current step, so point a throwaway copy at the target.
	 *
	 * @param AutomationContactModel $run       Run.
	 * @param int                    $target_id Goal step.
	 * @return AutomationContactModel
	 */
	public static function probe( AutomationContactModel $run, int $target_id ): AutomationContactModel {
		$probe               = clone $run;
		$probe->current_step = $target_id;

		return $probe;
	}

	/**
	 * Skip the run ahead to `$target`, closing the step it was waiting on.
	 *
	 * The delay it slept in is marked skipped, so its scheduled wake-up finds
	 * nothing to resume ({@see \DoubleScale\Modules\Automations\Loader::process_automation_step()}).
	 *
	 * @param AutomationModel        $automation Automation.
	 * @param AutomationContactModel $run        Run.
	 * @param int                    $waiting_id Step it waited on.
	 * @param AutomationStepModel    $target     Goal step it achieved.
	 * @return void
	 */
	public static function jump( AutomationModel $automation, AutomationContactModel $run, int $waiting_id, AutomationStepModel $target ): void {
		AutomationContactProcessesModel::query()
			->where( 'automation_contact_id', (int) $run->id )
			->where( 'step_id', $waiting_id )
			->where( 'status', 'pending' )
			->update(
				array(
					'status'  => 'skipped',
					'message' => __( 'Skipped: the contact achieved a goal further on.', 'doublescale' ),
				)
			);

		$process = new ProcessAutomation( $automation );
		$process->add_automation_contact_process(
			$target,
			(int) $run->contact_id,
			(int) $run->id,
			'completed',
			__( 'Goal achieved early: the contact skipped the steps before it.', 'doublescale' )
		);

		$next = $process->get_next_step( $target );
		if ( $next ) {
			$process->update_automation_contact_status( $run, 'active', (int) $target->id, (int) $next->id );
		} else {
			$process->update_automation_contact_status( $run, 'completed', (int) $target->id, 0 );
		}

		/**
		 * Fires when a contact jumps ahead to a goal it achieved early.
		 *
		 * @param AutomationModel        $automation Automation.
		 * @param AutomationContactModel $run        The contact's run.
		 * @param int                    $waiting_id Step the contact left.
		 * @param AutomationStepModel    $target     Goal step it moved to.
		 */
		do_action( 'doublescale_automation_goal_jumped', $automation, $run, $waiting_id, $target );

		if ( $next ) {
			$process->enqueue_step( (int) $next->id, (int) $run->id );
		}
	}
}
