<?php
/**
 * Merge tag: Project Task Progress.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectTaskProgress merge tag.
 */
class ProjectTaskProgress extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Task Progress';

	/**
	 * @var string
	 */
	public $slug = 'project_task_progress';

	/**
	 * @var string
	 */
	public $description = 'Task completion percent for the project';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		$progress = $project->task_progress; return is_array( $progress ) ? (string) ( $progress['percent'] ?? 0 ) : '0';
	}
}

MergeTagsManager::instance()->register( new ProjectTaskProgress() );