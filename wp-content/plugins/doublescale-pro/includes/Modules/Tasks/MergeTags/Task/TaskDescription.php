<?php
/**
 * Merge tag: Task Description.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskDescription merge tag.
 */
class TaskDescription extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Description';

	/**
	 * @var string
	 */
	public $slug = 'task_description';

	/**
	 * @var string
	 */
	public $description = 'Task description';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		return $task->description ?? '';
	}
}

MergeTagsManager::instance()->register( new TaskDescription() );
