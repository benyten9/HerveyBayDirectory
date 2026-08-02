<?php
/**
 * Order Payment Method Merge Tag
 *
 * This class is responsible for handling the order payment method merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Order Payment Method Merge Tag
 */
class OrderPaymentMethod extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Payment Method';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'payment_method';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Payment Method';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$order_id = $contact->get_data( 'order_id' );
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		return $order->get_payment_method_title();
	}
}

MergeTagsManager::instance()->register( new OrderPaymentMethod() );
