<?php
/**
 * Class Order Tax Merge Tag
 *
 * This class is responsible for handling the order tax merge tag
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
 * Order Tax Merge Tag
 */
class OrderTax extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Tax';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'tax';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Tax';

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

		$tax = $payment->get_tax();

		return $tax;
	}
}

MergeTagsManager::instance()->register( new OrderTax() );
