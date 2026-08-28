<?php
/**
 * Merge tag: Project Progress.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectProgress merge tag.
 */
class ProjectProgress extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Progress';

	/**
	 * @var string
	 */
	public $slug = 'project_progress';

	/**
	 * @var string
	 */
	public $description = 'Project progress percentage';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return (string) $project->resolveProgress();
	}
}

MergeTagsManager::instance()->register( new ProjectProgress() );