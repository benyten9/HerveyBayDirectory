<?php
/**
 * Cascade task deletion when a parent entity is deleted.
 *
 * Tasks reference their parent polymorphically (entity_type + entity_id), so
 * the database cannot enforce the link; this listener keeps the tasks table
 * free of orphans when a contact, deal, or project goes away.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * TaskCleanup class.
 */
class TaskCleanup {

	/**
	 * Hook the parent-entity deletion events.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'doublescale_contact_deleting',
			function ( $contact ) {
				if ( $contact && isset( $contact->id ) ) {
					$this->delete_for_entity( TaskEntityType::CONTACT, (int) $contact->id );
				}
			}
		);

		add_action(
			'doublescale_deal_deleted',
			function ( $deal_id ) {
				$this->delete_for_entity( TaskEntityType::DEAL, (int) $deal_id );
			}
		);

		add_action(
			'doublescale_project_deleted',
			function ( $project_id ) {
				$this->delete_for_entity( TaskEntityType::PROJECT, (int) $project_id );
			}
		);
	}

	/**
	 * Delete every task attached to an entity, one model at a time so the
	 * TaskModel deleting event cascades subtasks, labels, and recurrences.
	 *
	 * @param int $entity_type TaskEntityType constant.
	 * @param int $entity_id   Parent entity ID.
	 * @return void
	 */
	private function delete_for_entity( int $entity_type, int $entity_id ): void {
		if ( $entity_id <= 0 ) {
			return;
		}

		if (
			function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'tasks', TaskModel::class )
		) {
			return;
		}

		try {
			$tasks = TaskModel::where( 'entity_type', $entity_type )
				->where( 'entity_id', $entity_id )
				->get();

			foreach ( $tasks as $task ) {
				$task->delete();
			}
		} catch ( \Throwable $e ) {
			// Table missing — nothing to clean up.
		}
	}
}
