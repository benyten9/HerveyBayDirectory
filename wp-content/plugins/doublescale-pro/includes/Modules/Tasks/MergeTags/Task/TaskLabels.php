<?php
/**
 * Merge tag: Task Labels.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskLabels merge tag.
 */
class TaskLabels extends AbstractTaskMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Task Labels';

	/**
	 * @var string
	 */
	public $slug = 'task_labels';

	/**
	 * @var string
	 */
	public $description = 'Task labels (comma-separated)';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$task = $this->resolve_task( $contact );
		if ( ! $task ) {
			return '';
		}
		$labels = $task->labels;
		if ( ! $labels || $labels->isEmpty() ) {
			return '';
		}
		$names = array();
		foreach ( $labels as $label ) {
			$names[] = $label->title ?: $label->color;
		}
		return implode( ', ', $names );
	}
}

MergeTagsManager::instance()->register( new TaskLabels() );
