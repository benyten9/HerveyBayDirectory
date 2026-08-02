<?php
/**
 * Merge tag: Task Assignee.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskAssignee merge tag.
 */
class TaskAssignee extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Assignee';

	/**
	 * @var string
	 */
	public $slug = 'task_assignee';

	/**
	 * @var string
	 */
	public $description = 'Task assignee display name';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		$user = $task->assignedUser;
		return $user ? ( $user->display_name ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new TaskAssignee() );
