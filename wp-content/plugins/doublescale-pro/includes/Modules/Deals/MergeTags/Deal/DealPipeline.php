<?php
/**
 * Class Deal Pipeline
 *
 * Merge tag for deal pipeline name
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Deals\MergeTags\Deal;

use DoubleScale\Pro\Modules\Deals\MergeTags\Deal\AbstractDealMergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Deal Pipeline Merge Tag
 */
class DealPipeline extends AbstractDealMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Deal Pipeline';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_pipeline';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Deal Pipeline Name';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                   $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$deal = $this->resolve_deal( $contact );
		if ( ! $deal ) {
			return '';
		}
		$pipeline = $deal->pipeline;
		if ( ! $pipeline ) {
			return '';
		}
		return $pipeline->name ?? '';
	}
}

MergeTagsManager::instance()->register( new DealPipeline() );
