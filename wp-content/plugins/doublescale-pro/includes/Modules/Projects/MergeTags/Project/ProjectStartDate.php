<?php
/**
 * Merge tag: Project Start Date.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectStartDate merge tag.
 */
class ProjectStartDate extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Start Date';

	/**
	 * @var string
	 */
	public $slug = 'project_start_date';

	/**
	 * @var string
	 */
	public $description = 'Project start date';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return $project->start_date ?? '';
	}
}

MergeTagsManager::instance()->register( new ProjectStartDate() );