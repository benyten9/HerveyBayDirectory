<?php
/**
 * Merge tag: Project Status Color.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectStatusColor merge tag.
 */
class ProjectStatusColor extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Status Color';

	/**
	 * @var string
	 */
	public $slug = 'project_status_color';

	/**
	 * @var string
	 */
	public $description = 'Project status color';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		$status = $project->status; return $status ? ( $status->color ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new ProjectStatusColor() );