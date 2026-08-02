<?php
/**
 * Merge tag: Task Due Time.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskDueTime merge tag.
 */
class TaskDueTime extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Due Time';

	/**
	 * @var string
	 */
	public $slug = 'task_due_time';

	/**
	 * @var string
	 */
	public $description = 'Task due time';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return $task->due_time ?? '';
	}
}

MergeTagsManager::instance()->register( new TaskDueTime() );
