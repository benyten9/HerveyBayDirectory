<?php
/**
 * Merge tag: Project Public URL.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectPublicUrl merge tag.
 */
class ProjectPublicUrl extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Public URL';

	/**
	 * @var string
	 */
	public $slug = 'project_public_url';

	/**
	 * @var string
	 */
	public $description = 'Public project page URL (alias)';

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

MergeTagsManager::instance()->register( new ProjectPublicUrl() );