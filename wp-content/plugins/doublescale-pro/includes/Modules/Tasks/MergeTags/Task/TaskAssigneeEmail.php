<?php
/**
 * Merge tag: Task Assignee Email.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskAssigneeEmail merge tag.
 */
class TaskAssigneeEmail extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Assignee Email';

	/**
	 * @var string
	 */
	public $slug = 'task_assignee_email';

	/**
	 * @var string
	 */
	public $description = 'Task assignee email';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		$user = $task->assignedUser;
		return $user ? ( $user->user_email ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new TaskAssigneeEmail() );
