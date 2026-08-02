<?php
/**
 * Class Deal Expected Close Date
 *
 * Merge tag for deal expected close date
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
 * Deal Expected Close Date Merge Tag
 */
class DealExpectedCloseDate extends AbstractDealMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Deal Expected Close Date';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_expected_close_date';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Deal Expected Close Date';

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
		return $deal->expected_close_date ?? '';
	}
}

MergeTagsManager::instance()->register( new DealExpectedCloseDate() );
