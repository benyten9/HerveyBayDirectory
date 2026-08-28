<?php
/**
 * Merge tag: Project Status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectStatus merge tag.
 */
class ProjectStatus extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Status';

	/**
	 * @var string
	 */
	public $slug = 'project_status';

	/**
	 * @var string
	 */
	public $description = 'Project status name';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		$status = $project->status; return $status ? ( $status->name ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new ProjectStatus() );