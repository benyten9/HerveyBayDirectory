<?php
/**
 * Merge tag: Project Owner Email.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectOwnerEmail merge tag.
 */
class ProjectOwnerEmail extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Owner Email';

	/**
	 * @var string
	 */
	public $slug = 'project_owner_email';

	/**
	 * @var string
	 */
	public $description = 'Project owner email';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		if ( ! $project->owner_id ) { return ''; } $owner = $project->owner; return $owner ? ( $owner->user_email ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new ProjectOwnerEmail() );