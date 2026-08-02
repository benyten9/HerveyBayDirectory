<?php
/**
 * Order Payment URL Merge Tag
 *
 * This class is responsible for handling the order payment URL merge tag
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
 * Order Payment URL Merge Tag
 */
class OrderPaymentUrl extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Payment URL';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'payment_url';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Payment URL';

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

		$payment_url = $order->get_checkout_payment_url();

		return $payment_url;
	}
}

MergeTagsManager::instance()->register( new OrderPaymentUrl() );
