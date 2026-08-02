<?php

/**
 * Class Membership Start Date
 *
 * This class is responsible for handling the membership start date merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Membership;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Membership Start Date Merge Tag
 */
class MembershipStartDate extends MergeTag {



	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Membership Start Date';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'membership_start_date';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Membership Start Date';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'membership';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$start_date = $contact->get_data( 'start_date' );

		return $start_date ? $start_date : '';
	}
}

MergeTagsManager::instance()->register( new MembershipStartDate() );
