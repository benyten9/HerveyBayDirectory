<?php
/**
 * Merge tag: Project Due Date.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectDueDate merge tag.
 */
class ProjectDueDate extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Due Date';

	/**
	 * @var string
	 */
	public $slug = 'project_due_date';

	/**
	 * @var string
	 */
	public $description = 'Project due date';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		return $project->due_date ?? '';
	}
}

MergeTagsManager::instance()->register( new ProjectDueDate() );