<?php
/**
 * Class Order Currency
 *
 * This class is responsible for handling the order currency merge tag
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
 * Order Currency Merge Tag
 */
class OrderCurrency extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Currency';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'currency';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Currency';

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

		$currency = edd_get_payment_currency_code( $payment_id );

		return $currency;
	}
}

MergeTagsManager::instance()->register( new OrderCurrency() );
