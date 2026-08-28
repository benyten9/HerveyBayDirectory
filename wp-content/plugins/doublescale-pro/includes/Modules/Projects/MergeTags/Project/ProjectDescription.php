<?php
/**
 * Merge tag: Project Description.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectDescription merge tag.
 */
class ProjectDescription extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Description';

	/**
	 * @var string
	 */
	public $slug = 'project_description';

	/**
	 * @var string
	 */
	public $description = 'Project description';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return $project->description ?? '';
	}
}

MergeTagsManager::instance()->register( new ProjectDescription() );