<?php
/**
 * Merge tag: Project URL.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectUrl merge tag.
 */
class ProjectUrl extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project URL';

	/**
	 * @var string
	 */
	public $slug = 'project_url';

	/**
	 * @var string
	 */
	public $description = 'Public project page URL';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return \DoubleScale\Pro\Modules\Projects\Services\ProjectUrl::get_public_url( $project );
	}
}

MergeTagsManager::instance()->register( new ProjectUrl() );