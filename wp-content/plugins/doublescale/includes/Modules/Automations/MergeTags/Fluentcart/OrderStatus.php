<?php
/**
 * FluentCart order merge tag: Order Status.
 *
 * Reads the order the workflow started from (`order_id` in the automation
 * contact's data).
 *
 * @package DoubleScale\Modules\Automations\MergeTags\Fluentcart
 */

namespace DoubleScale\Modules\Automations\MergeTags\Fluentcart;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Services\FluentCartOrders;

/**
 * OrderStatus class
 */
class OrderStatus extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order Status';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'order_status';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Order Status';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'fluentcart_order';

	/**
	 * Get value
	 *
	 * @param mixed  $contact   Automation contact (carries the order id).
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( ! is_object( $contact ) || ! method_exists( $contact, 'get_data' ) ) {
			return '';
		}

		$order = FluentCartOrders::find_order( (int) $contact->get_data( 'order_id', 0 ) );
		if ( ! $order ) {
			return '';
		}

		$statuses = FluentCartOrders::order_statuses();

		return $statuses[ $order['status'] ] ?? $order['status'];
	}
}

MergeTagsManager::instance()->register( new OrderStatus() );
