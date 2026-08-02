<?php

/**
 * Class Order Code
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
 * Order Code Merge Tag
 */
class OrderCode extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Code';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'order_code';

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
		$order_code = $contact->get_data( 'order_code' );

		return $order_code ? (string) $order_code : '';
	}
}

MergeTagsManager::instance()->register( new OrderCode() );
