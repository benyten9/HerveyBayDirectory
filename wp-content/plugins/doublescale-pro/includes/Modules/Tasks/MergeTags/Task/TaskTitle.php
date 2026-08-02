<?php
/**
 * Merge tag: Task Title.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskTitle merge tag.
 */
class TaskTitle extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Title';

	/**
	 * @var string
	 */
	public $slug = 'task_title';

	/**
	 * @var string
	 */
	public $description = 'Task title';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return $task->title ?? '';
	}
}

MergeTagsManager::instance()->register( new TaskTitle() );
