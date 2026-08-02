<?php
/**
 * Resolves a ContactModel from a TaskModel via the parent entity chain.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Support;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that maps a task to a contact for automation enrollment.
 */
final class TaskContactResolver {

	/**
	 * Resolve the contact associated with a task.
	 *
	 * CONTACT → ContactModel::find( entity_id )
	 * DEAL    → DealModel::find( entity_id )->contact (when deals storage ready)
	 * PROJECT → ProjectModel::find( entity_id )->contact (when projects storage ready)
	 *
	 * @param TaskModel $task Task.
	 * @return ContactModel|null
	 */
	public static function resolve( TaskModel $task ): ?ContactModel {
		if ( ! self::tasks_storage_ready() ) {
			return null;
		}

		$entity_type = (int) $task->entity_type;
		$entity_id   = (int) $task->entity_id;

		if ( $entity_id <= 0 ) {
			return null;
		}

		if ( TaskEntityType::CONTACT === $entity_type ) {
			$contact = ContactModel::find( $entity_id );
			return $contact instanceof ContactModel ? $contact : null;
		}

		if ( TaskEntityType::DEAL === $entity_type ) {
			return self::resolve_from_deal( $entity_id );
		}

		if ( TaskEntityType::PROJECT === $entity_type ) {
			return self::resolve_from_project( $entity_id );
		}

		return null;
	}

	/**
	 * Look up a task by ID when tasks storage is ready.
	 *
	 * @param int $task_id Task ID.
	 * @return TaskModel|null
	 */
	public static function find_task( int $task_id ): ?TaskModel {
		if ( $task_id <= 0 || ! self::tasks_storage_ready() ) {
			return null;
		}

		$task = TaskModel::find( $task_id );
		return $task instanceof TaskModel ? $task : null;
	}

	/**
	 * Resolve a task from an automation contact's enrollment data.
	 *
	 * @param object $automation_contact Automation contact with `data['task_id']`.
	 * @return TaskModel|null
	 */
	public static function resolve_from_automation_contact( $automation_contact ): ?TaskModel {
		if ( ! is_object( $automation_contact ) || ! isset( $automation_contact->data ) ) {
			return null;
		}

		$data    = is_array( $automation_contact->data ) ? $automation_contact->data : array();
		$task_id = isset( $data['task_id'] ) ? (int) $data['task_id'] : 0;

		return self::find_task( $task_id );
	}

	/**
	 * @param int $deal_id Deal ID.
	 * @return ContactModel|null
	 */
	private static function resolve_from_deal( int $deal_id ): ?ContactModel {
		if ( ! class_exists( \DoubleScale\Pro\Modules\Deals\Models\DealModel::class ) ) {
			return null;
		}

		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'deals', \DoubleScale\Pro\Modules\Deals\Models\DealModel::class ) ) {
			return null;
		}

		$deal = \DoubleScale\Pro\Modules\Deals\Models\DealModel::find( $deal_id );
		if ( ! $deal ) {
			return null;
		}

		$contact = $deal->contact;
		return $contact instanceof ContactModel ? $contact : null;
	}

	/**
	 * @param int $project_id Project ID.
	 * @return ContactModel|null
	 */
	private static function resolve_from_project( int $project_id ): ?ContactModel {
		if ( ! class_exists( \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class ) ) {
			return null;
		}

		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'projects', \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class ) ) {
			return null;
		}

		$project = \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::find( $project_id );
		if ( ! $project ) {
			return null;
		}

		$contact = $project->contact;
		return $contact instanceof ContactModel ? $contact : null;
	}

	/**
	 * Whether tasks storage is safe to query.
	 */
	private static function tasks_storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'tasks', TaskModel::class );
	}
}
