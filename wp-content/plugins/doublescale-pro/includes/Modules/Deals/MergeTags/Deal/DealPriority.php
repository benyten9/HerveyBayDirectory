<?php
/**
 * Class Deal Priority
 *
 * Merge tag for deal priority
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
 * Deal Priority Merge Tag
 */
class DealPriority extends AbstractDealMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Deal Priority';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_priority';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Deal Priority';

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
		return $deal->priority ?? '';
	}
}

MergeTagsManager::instance()->register( new DealPriority() );
