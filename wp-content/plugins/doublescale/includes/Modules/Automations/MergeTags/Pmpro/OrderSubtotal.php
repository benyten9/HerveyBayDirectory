<?php

/**
 * Class Order Subtotal
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Pmpro;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
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
	public $slug = 'order_subtotal';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'pmpro';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$subtotal = $contact->get_data( 'subtotal' );

		return $subtotal ? (string) $subtotal : '0.00';
	}
}

MergeTagsManager::instance()->register( new OrderSubtotal() );
