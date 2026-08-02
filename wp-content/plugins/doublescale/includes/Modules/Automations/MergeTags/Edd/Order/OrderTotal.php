<?php
/**
 * Class Order Total Merge Tag
 *
 * This class is responsible for handling the order total merge tag
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
 * Order Total Merge Tag
 */
class OrderTotal extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Total';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'total';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Total';

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
		$payment    = \edd_get_payment( $payment_id );
		if ( ! $payment ) {
			return '';
		}

		$total    = $payment->__get( 'total' );
		$currency = edd_get_payment_currency_code( $payment_id );

		return edd_currency_filter( edd_format_amount( $total ), $currency );
	}
}

MergeTagsManager::instance()->register( new OrderTotal() );
