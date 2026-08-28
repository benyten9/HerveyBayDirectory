<?php
/**
 * Merge tag: Project Budget.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectBudget merge tag.
 */
class ProjectBudget extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Budget';

	/**
	 * @var string
	 */
	public $slug = 'project_budget';

	/**
	 * @var string
	 */
	public $description = 'Project budget';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return null !== $project->budget ? (string) $project->budget : '';
	}
}

MergeTagsManager::instance()->register( new ProjectBudget() );