<?php

/**
 * Class Transaction Number
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Memberpress;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Transaction Number Merge Tag
 */
class TransactionNumber extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Transaction Number';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'trans_num';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'memberpress';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$trans_num = $contact->get_data( 'trans_num' );

		return $trans_num ? (string) $trans_num : '';
	}
}

MergeTagsManager::instance()->register( new TransactionNumber() );
