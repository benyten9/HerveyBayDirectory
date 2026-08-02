<?php
/**
 * Merge tag: Task Priority.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskPriority merge tag.
 */
class TaskPriority extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Priority';

	/**
	 * @var string
	 */
	public $slug = 'task_priority';

	/**
	 * @var string
	 */
	public $description = 'Task priority';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return \DoubleScale\Core\Constants\TaskPriority::get_label( $task->priority );
	}
}

MergeTagsManager::instance()->register( new TaskPriority() );
