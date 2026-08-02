<?php

/**
 * Class User Membership ID
 *
 * This class is responsible for handling the user membership ID merge tag
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
 * User Membership ID Merge Tag
 */
class UserMembershipId extends MergeTag {






	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'User Membership ID';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'user_membership_id';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'User Membership ID';

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
		$user_membership_id = $contact->get_data( 'user_membership_id' );

		return $user_membership_id ? $user_membership_id : '';
	}
}

MergeTagsManager::instance()->register( new UserMembershipId() );
