<?php
/**
 * FluentCart order merge tag: Order ID.
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
 * OrderId class
 */
class OrderId extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order ID';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'order_id';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Order ID';

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

		return (string) $order['id'];
	}
}

MergeTagsManager::instance()->register( new OrderId() );
