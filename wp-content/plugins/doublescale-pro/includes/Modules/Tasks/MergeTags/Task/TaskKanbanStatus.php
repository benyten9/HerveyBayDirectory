<?php
/**
 * Merge tag: Task Kanban Status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskKanbanStatus merge tag.
 */
class TaskKanbanStatus extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Kanban Status';

	/**
	 * @var string
	 */
	public $slug = 'task_kanban_status';

	/**
	 * @var string
	 */
	public $description = 'Task kanban column name';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		$status = $task->kanbanStatus;
		return $status ? ( $status->name ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new TaskKanbanStatus() );
