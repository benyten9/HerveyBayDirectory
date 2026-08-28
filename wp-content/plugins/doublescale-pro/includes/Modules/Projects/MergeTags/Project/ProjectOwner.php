<?php
/**
 * Merge tag: Project Owner.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectOwner merge tag.
 */
class ProjectOwner extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Owner';

	/**
	 * @var string
	 */
	public $slug = 'project_owner';

	/**
	 * @var string
	 */
	public $description = 'Project owner name';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		if ( ! $project->owner_id ) { return ''; } $owner = $project->owner; return $owner ? ( $owner->display_name ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new ProjectOwner() );