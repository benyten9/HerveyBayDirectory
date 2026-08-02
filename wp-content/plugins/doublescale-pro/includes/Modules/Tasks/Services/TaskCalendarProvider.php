<?php
/**
 * Tasks ⇄ admin calendar bridge.
 *
 * Contributes tasks (placed on `due_date`, all-day) to the cross-module admin/staff
 * calendar feed via Free's `doublescale_admin_calendar_events` filter. This is part
 * of the first Pro→calendar seam: with Pro absent the filter simply returns fewer
 * kinds, and Free keeps working.
 *
 * Role scoping mirrors {@see \DoubleScale\Pro\Modules\Tasks\Rest\Controllers\RestTaskController}
 * exactly: a sales rep sees only tasks assigned to them; everyone else (managers)
 * sees all, optionally scoped to one staffer via `$view_user`. No task capability
 * exists, so the rule is role-based — never a guessed cap.
 *
 * Registered in {@see \DoubleScale\Pro\Modules\Tasks\Module::boot()} so it only
 * attaches while the Tasks module is enabled.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Utils\CalendarSupport;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * TaskCalendarProvider.
 */
final class TaskCalendarProvider {

	/**
	 * Safety cap on tasks projected for one window.
	 */
	private const MAX_ROWS = 500;

	public function __construct() {
		add_filter( 'doublescale_admin_calendar_events', array( $this, 'add_events' ), 10, 4 );
	}

	/**
	 * Project the viewer's in-window tasks as all-day calendar events.
	 *
	 * @param array<int, array<string, mixed>> $events    Events collected so far.
	 * @param array{0:string,1:string}         $window    [ start (Y-m-d), end_inclusive (Y-m-d H:i:s) ].
	 * @param int                              $viewer_id Current staff user id.
	 * @param int                              $view_user Manager-only "view as assignee" id (0 = all / self).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_events( array $events, array $window, int $viewer_id, int $view_user ): array {
		if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'tasks' ) ) {
			return $events;
		}

		list( $start, $end_inclusive ) = $window;
		$end_date = substr( $end_inclusive, 0, 10 ); // due_date is a DATE column.

		// Mirror RestTaskController: a sales rep sees tasks assigned to them or
		// with a subtask assigned to them; everyone else (managers) sees all, or
		// one staffer when $view_user is set.
		$scope_user = Permissions::is_sales_rep( $viewer_id )
			? $viewer_id
			: ( $view_user > 0 ? $view_user : 0 );

		$query = TaskModel::whereBetween( 'due_date', array( $start, $end_date ) );
		if ( $scope_user > 0 ) {
			$query->visibleToSalesRep( $scope_user );
		}

		$tasks = $query->orderBy( 'due_date' )->limit( self::MAX_ROWS )->get();
		if ( $tasks->isEmpty() ) {
			return $events;
		}

		// Batch-resolve assignee names and the contact names for contact-entity tasks.
		$names       = CalendarSupport::user_names( $tasks->pluck( 'assigned_to' )->all() );
		$contact_ids = array();
		foreach ( $tasks as $task ) {
			if ( TaskEntityType::CONTACT === (int) $task->entity_type ) {
				$contact_ids[] = (int) $task->entity_id;
			}
		}
		$contacts = self::contact_names( $contact_ids );

		foreach ( $tasks as $task ) {
			$events[] = $this->shape( $task, $names, $contacts );
		}

		return $events;
	}

	/**
	 * Build a single all-day task calendar event.
	 *
	 * Tasks open on the Tasks board via deep link (`tasks` + `task` query) so every
	 * chip is clickable — including project tasks and tasks with no parent entity.
	 * Parent deal/contact/project routes are not used here; the detail dialog is the
	 * dedicated task surface.
	 *
	 * @param TaskModel          $task     The task.
	 * @param array<int, string> $names    user_id => display name.
	 * @param array<int, string> $contacts contact_id => display name.
	 * @return array<string, mixed>
	 */
	private function shape( TaskModel $task, array $names, array $contacts ): array {
		$entity_type = (int) $task->entity_type;
		$entity_id   = (int) $task->entity_id;
		$assignee_id = (int) $task->assigned_to;

		$contact = null;
		if ( TaskEntityType::CONTACT === $entity_type && $entity_id > 0 ) {
			$contact = array(
				'id'   => $entity_id,
				'name' => $contacts[ $entity_id ] ?? '',
			);
		}

		return array(
			'id'       => 'task-' . (int) $task->id,
			'kind'     => 'task',
			'title'    => (string) $task->title,
			'start'    => (string) $task->due_date,
			'end'      => null,
			'all_day'  => true,
			'timezone' => null,
			'status'   => (string) $task->display_status,
			'assignee' => $assignee_id > 0 ? array(
				'id'   => $assignee_id,
				'name' => $names[ $assignee_id ] ?? '',
			) : null,
			'contact'  => $contact,
			'route'    => 'tasks',
		);
	}

	/**
	 * Resolve `[ contact_id => display_name ]` for a set of ids in one query.
	 *
	 * @param array<int, int> $contact_ids Candidate contact ids.
	 * @return array<int, string>
	 */
	private static function contact_names( array $contact_ids ): array {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$map = array();
		foreach ( ContactModel::whereIn( 'id', $contact_ids )->get() as $contact ) {
			$name                      = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
			$map[ (int) $contact->id ] = '' !== $name ? $name : (string) ( $contact->email ?? '' );
		}

		return $map;
	}
}
