<?php
/**
 * Merge tag: Project Title.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectTitle merge tag.
 */
class ProjectTitle extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Title';

	/**
	 * @var string
	 */
	public $slug = 'project_title';

	/**
	 * @var string
	 */
	public $description = 'Project title';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return $project->title ?? '';
	}
}

MergeTagsManager::instance()->register( new ProjectTitle() );