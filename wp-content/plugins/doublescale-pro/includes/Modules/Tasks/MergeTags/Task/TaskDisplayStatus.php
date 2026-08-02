<?php
/**
 * Merge tag: Task Display Status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskDisplayStatus merge tag.
 */
class TaskDisplayStatus extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Display Status';

	/**
	 * @var string
	 */
	public $slug = 'task_display_status';

	/**
	 * @var string
	 */
	public $description = 'Task display status (overdue/due_today/upcoming/completed)';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return $task->display_status ?? '';
	}
}

MergeTagsManager::instance()->register( new TaskDisplayStatus() );
