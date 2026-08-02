<?php
/**
 * Class Deal Owner
 *
 * Merge tag for deal owner name
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
 * Deal Owner Merge Tag
 */
class DealOwner extends AbstractDealMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Deal Owner';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_owner';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Deal Owner Name';

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
		if ( ! $deal->owner_id ) {
			return '';
		}
		$owner = $deal->owner;
		if ( ! $owner ) {
			return '';
		}
		return $owner->display_name ?? '';
	}
}

MergeTagsManager::instance()->register( new DealOwner() );
