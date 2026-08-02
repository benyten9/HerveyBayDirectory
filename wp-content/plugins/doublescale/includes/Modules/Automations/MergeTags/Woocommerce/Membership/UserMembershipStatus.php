<?php

/**
 * Class User Membership Status
 *
 * This class is responsible for handling the user membership status merge tag
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
 * User Membership Status Merge Tag
 */
class UserMembershipStatus extends MergeTag {



	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'User Membership Status';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'user_membership_status';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'User Membership Status';

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
		$status = $contact->get_data( 'status' );

		return $status ? $status : '';
	}
}

MergeTagsManager::instance()->register( new UserMembershipStatus() );
