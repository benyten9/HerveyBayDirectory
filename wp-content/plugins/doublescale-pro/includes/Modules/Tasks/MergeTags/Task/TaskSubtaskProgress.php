<?php
/**
 * Merge tag: Task Subtask Progress.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskSubtaskProgress merge tag.
 */
class TaskSubtaskProgress extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Subtask Progress';

	/**
	 * @var string
	 */
	public $slug = 'task_subtask_progress';

	/**
	 * @var string
	 */
	public $description = 'Task subtask progress (completed/total)';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		$progress = $task->subtask_progress;
		$total    = (int) ( $progress['total'] ?? 0 );
		$done     = (int) ( $progress['completed'] ?? 0 );
		return $done . '/' . $total;
	}
}

MergeTagsManager::instance()->register( new TaskSubtaskProgress() );
