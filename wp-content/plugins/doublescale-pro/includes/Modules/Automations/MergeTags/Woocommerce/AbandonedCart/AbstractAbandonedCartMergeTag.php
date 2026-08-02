<?php
/**
 * Shared base for abandoned-cart automation merge tags.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\AbandonedCart;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

abstract class AbstractAbandonedCartMergeTag extends MergeTag {

	/**
	 * @var string
	 */
	public $group = 'abandoned_cart';

	/**
	 * @param mixed $contact Automation contact model.
	 * @return AbandonedCartModel|null
	 */
	protected function resolve_abandoned_cart( $contact ): ?AbandonedCartModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}

		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'automations', AbandonedCartModel::class ) ) {
			return null;
		}

		$cart_id = (int) $contact->get_data( 'cart_id', 0 );
		if ( $cart_id <= 0 ) {
			return null;
		}

		$cart = AbandonedCartModel::find( $cart_id );
		return $cart instanceof AbandonedCartModel ? $cart : null;
	}
}
