<?php
/**
 * Open tasks in the contact profile summary.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `tasks` to the free plugin's contact summary: the contact's pending
 * tasks the viewer can see, and how many of them are late.
 */
class TaskContactSummary {

	/**
	 * Hook into the summary.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'doublescale_contact_summary', array( $this, 'add' ), 10, 2 );
	}

	/**
	 * Fill the `tasks` part.
	 *
	 * @param array $summary    Summary parts.
	 * @param int   $contact_id Contact ID.
	 * @return array
	 */
	public function add( $summary, $contact_id ) {
		if ( ! Permissions::can_access_tasks_api() ) {
			return $summary;
		}

		try {
			$query = TaskModel::query()
				->forContact( (int) $contact_id )
				->where( 'status', TaskStatus::PENDING );
			if ( TaskAccess::is_own_tasks_only() ) {
				TaskAccess::apply_visibility( $query );
			}
			$open    = (clone $query)->count();
			$overdue = (clone $query)->where( 'due_date', '<', current_time( 'Y-m-d' ) )->count();
		} catch ( \Throwable $e ) {
			return $summary;
		}

		$summary['tasks'] = array(
			'count'   => (int) $open,
			'overdue' => (int) $overdue,
		);

		return $summary;
	}
}
