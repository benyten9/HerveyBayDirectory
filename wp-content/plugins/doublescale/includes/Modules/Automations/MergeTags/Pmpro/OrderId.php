<?php

/**
 * Class Order ID
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
	public $slug = 'order_id';

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
		$order_id = $contact->get_data( 'order_id' );

		return $order_id ? (string) $order_id : '';
	}
}

MergeTagsManager::instance()->register( new OrderId() );
