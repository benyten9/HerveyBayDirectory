<?php
/**
 * Merge tag: Task Type.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskType merge tag.
 */
class TaskType extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Type';

	/**
	 * @var string
	 */
	public $slug = 'task_type';

	/**
	 * @var string
	 */
	public $description = 'Task type';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return \DoubleScale\Core\Constants\TaskType::get_label( $task->task_type );
	}
}

MergeTagsManager::instance()->register( new TaskType() );
