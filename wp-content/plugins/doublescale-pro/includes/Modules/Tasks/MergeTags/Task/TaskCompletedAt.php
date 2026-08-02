<?php
/**
 * Merge tag: Task Completed At.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskCompletedAt merge tag.
 */
class TaskCompletedAt extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Completed At';

	/**
	 * @var string
	 */
	public $slug = 'task_completed_at';

	/**
	 * @var string
	 */
	public $description = 'Task completed timestamp';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return $task->completed_at ?? '';
	}
}

MergeTagsManager::instance()->register( new TaskCompletedAt() );
