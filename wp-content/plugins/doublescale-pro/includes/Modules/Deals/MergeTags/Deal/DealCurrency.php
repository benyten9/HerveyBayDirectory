<?php
/**
 * Class Deal Currency
 *
 * Merge tag for deal currency
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
 * Deal Currency Merge Tag
 */
class DealCurrency extends AbstractDealMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Deal Currency';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_currency';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Deal Currency';

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
		return $deal->currency ?? '';
	}
}

MergeTagsManager::instance()->register( new DealCurrency() );
