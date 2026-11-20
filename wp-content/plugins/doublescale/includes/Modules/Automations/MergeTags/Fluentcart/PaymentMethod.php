<?php
/**
 * FluentCart order merge tag: Payment Method.
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
 * PaymentMethod class
 */
class PaymentMethod extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Payment Method';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'payment_method';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Payment Method';

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

		return $order['payment_method_title'];
	}
}

MergeTagsManager::instance()->register( new PaymentMethod() );
