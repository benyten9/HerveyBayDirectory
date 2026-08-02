<?php
/**
 * Merge tag: Task Status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskStatus merge tag.
 */
class TaskStatus extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Status';

	/**
	 * @var string
	 */
	public $slug = 'task_status';

	/**
	 * @var string
	 */
	public $description = 'Task DB status (pending/completed)';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return $task->status ?? '';
	}
}

MergeTagsManager::instance()->register( new TaskStatus() );
