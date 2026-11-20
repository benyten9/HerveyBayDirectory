<?php
/**
 * FluentCart order merge tag: Order Total.
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
 * OrderTotal class
 */
class OrderTotal extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order Total';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'order_total';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Order Total';

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

		return number_format( (float) $order['total'], 2, '.', '' ) . ' ' . $order['currency'];
	}
}

MergeTagsManager::instance()->register( new OrderTotal() );
