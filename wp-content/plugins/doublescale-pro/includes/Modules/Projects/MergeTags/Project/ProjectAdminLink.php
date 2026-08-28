<?php
/**
 * Merge tag: Project Admin Link.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectAdminLink merge tag.
 */
class ProjectAdminLink extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Admin Link';

	/**
	 * @var string
	 */
	public $slug = 'project_admin_link';

	/**
	 * @var string
	 */
	public $description = 'Admin project detail URL';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return admin_url( 'admin.php?page=doublescale&path=projects/' . (int) $project->id );
	}
}

MergeTagsManager::instance()->register( new ProjectAdminLink() );