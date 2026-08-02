<?php
/**
 * Merge tag: Task Entity Name.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskEntityName merge tag.
 */
class TaskEntityName extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Entity Name';

	/**
	 * @var string
	 */
	public $slug = 'task_entity_name';

	/**
	 * @var string
	 */
	public $description = 'Task parent entity name';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		$entity = $task->entity;
		if ( ! $entity ) {
			return '';
		}
		if ( isset( $entity->title ) ) {
			return (string) $entity->title;
		}
		if ( isset( $entity->name ) ) {
			return (string) $entity->name;
		}
		$first = $entity->first_name ?? '';
		$last  = $entity->last_name ?? '';
		$name  = trim( $first . ' ' . $last );
		return '' !== $name ? $name : (string) ( $entity->email ?? '' );
	}
}

MergeTagsManager::instance()->register( new TaskEntityName() );
