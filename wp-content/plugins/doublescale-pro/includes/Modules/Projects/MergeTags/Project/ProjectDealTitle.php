<?php
/**
 * Merge tag: Project Deal Title.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\MergeTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectDealTitle merge tag.
 */
class ProjectDealTitle extends AbstractProjectMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Project Deal Title';

	/**
	 * @var string
	 */
	public $slug = 'project_deal_title';

	/**
	 * @var string
	 */
	public $description = 'Linked deal title';

	/**
	 * {@inheritdoc}
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$project = $this->resolve_project( $contact );
		if ( ! $project ) {
			return '';
		}
		if ( ! $project->deal_id || ! $project->deal() ) { return ''; } $deal = $project->deal; return $deal ? ( $deal->title ?? '' ) : '';
	}
}

MergeTagsManager::instance()->register( new ProjectDealTitle() );