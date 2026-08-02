<?php
/**
 * Class Order ID
 *
 * This class is responsible for handling the order ID merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Edd\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Order ID Merge Tag
 */
class OrderId extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order ID';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'id';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order ID';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'edd_order';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$payment_id = $contact->get_data( 'payment_id' );

		return edd_get_order_id_from_transaction_id( $payment_id );
	}
}

MergeTagsManager::instance()->register( new OrderId() );
