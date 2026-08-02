<?php
/**
 * Class Deal Title
 *
 * Merge tag for deal title
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
 * Deal Title Merge Tag
 */
class DealTitle extends AbstractDealMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Deal Title';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_title';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Deal Title';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $deal Deal Model.
	 * @param string                   $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$deal = $this->resolve_deal( $contact );
		if ( ! $deal ) {
			return '';
		}
		return $deal->title ?? '';
	}
}

MergeTagsManager::instance()->register( new DealTitle() );
