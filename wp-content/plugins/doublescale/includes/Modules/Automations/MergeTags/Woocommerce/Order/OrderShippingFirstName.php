<?php
/**
 * Class Order Shipping First Name
 *
 * This class is responsible for handling the order shipping first name merge tag
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
 * Order Shipping First Name Merge Tag
 */
class OrderShippingFirstName extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Shipping First Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'shipping_first_name';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Shipping First Name';

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

		$shipping_first_name = $order->get_shipping_first_name();

		return $shipping_first_name;
	}
}

MergeTagsManager::instance()->register( new OrderShippingFirstName() );
