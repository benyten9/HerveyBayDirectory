<?php
/**
 * Shared base for deal automation merge tags.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Deals\MergeTags\Deal;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;

defined( 'ABSPATH' ) || exit;

abstract class AbstractDealMergeTag extends MergeTag {

	/**
	 * @var string
	 */
	public $group = 'deal';

	/**
	 * @var bool
	 */
	public $is_automation = true;

	/**
	 * @param mixed $contact Automation contact model.
	 * @return DealModel|null
	 */
	protected function resolve_deal( $contact ): ?DealModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}

		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'deals', DealModel::class ) ) {
			return null;
		}

		$deal_id = $contact->data['deal_id'] ?? null;
		if ( ! $deal_id ) {
			return null;
		}

		$deal = DealModel::find( $deal_id );
		return $deal instanceof DealModel ? $deal : null;
	}
}
