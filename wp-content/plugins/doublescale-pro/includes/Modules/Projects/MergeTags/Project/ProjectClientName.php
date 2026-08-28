<?php
/**
 * Merge tag: Project Client Name.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectClientName merge tag.
 */
class ProjectClientName extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Client Name';

	/**
	 * @var string
	 */
	public $slug = 'project_client_name';

	/**
	 * @var string
	 */
	public $description = 'Project client / contact name';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		$contact = $project->contact; if ( ! $contact ) { return ''; } return trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) );
	}
}

MergeTagsManager::instance()->register( new ProjectClientName() );