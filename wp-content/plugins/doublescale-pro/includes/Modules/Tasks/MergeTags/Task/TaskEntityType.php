<?php
/**
 * Merge tag: Task Entity Type.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskEntityType merge tag.
 */
class TaskEntityType extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Entity Type';

	/**
	 * @var string
	 */
	public $slug = 'task_entity_type';

	/**
	 * @var string
	 */
	public $description = 'Task parent entity type';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return \DoubleScale\Core\Constants\TaskEntityType::get_label( (int) $task->entity_type );
	}
}

MergeTagsManager::instance()->register( new TaskEntityType() );
