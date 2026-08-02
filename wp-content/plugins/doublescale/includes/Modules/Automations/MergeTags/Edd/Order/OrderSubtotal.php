<?php
/**
 * Class Order Subtotal Merge Tag
 *
 * This class is responsible for handling the order subtotal merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Edd\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Order Subtotal Merge Tag
 */
class OrderSubtotal extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Subtotal';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'subtotal';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Subtotal';

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
		$payment    = edd_get_payment( $payment_id );
		if ( ! $payment ) {
			return '';
		}

		$subtotal = $payment->__get( 'subtotal' );
		$currency = edd_get_payment_currency_code( $payment_id );

		return edd_currency_filter( edd_format_amount( $subtotal ), $currency );
	}
}

MergeTagsManager::instance()->register( new OrderSubtotal() );
