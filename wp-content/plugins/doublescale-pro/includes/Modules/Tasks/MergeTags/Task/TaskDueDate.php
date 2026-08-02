<?php
/**
 * Merge tag: Task Due Date.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskDueDate merge tag.
 */
class TaskDueDate extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Due Date';

	/**
	 * @var string
	 */
	public $slug = 'task_due_date';

	/**
	 * @var string
	 */
	public $description = 'Task due date';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return $task->due_date ?? '';
	}
}

MergeTagsManager::instance()->register( new TaskDueDate() );
