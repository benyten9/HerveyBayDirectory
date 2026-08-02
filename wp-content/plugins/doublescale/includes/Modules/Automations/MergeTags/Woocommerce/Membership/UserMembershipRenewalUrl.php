<?php

/**
 * Class User Membership Renewal URL
 *
 * This class is responsible for handling the user membership renewal URL merge tag
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
 * User Membership Renewal URL Merge Tag
 */
class UserMembershipRenewalUrl extends MergeTag {





	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'User Membership Renewal URL';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'user_membership_renewal_url';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'User Membership Renewal URL';

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
		$membership_renewal_url = $contact->get_data( 'membership_renewal_url' );

		return $membership_renewal_url ? $membership_renewal_url : '';
	}
}
MergeTagsManager::instance()->register( new UserMembershipRenewalUrl() );
