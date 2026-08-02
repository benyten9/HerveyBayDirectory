<?php
/**
 * Class User Billing Phone
 *
 * Resolves the WordPress user's `billing_phone` user-meta for the enrolled
 * contact (matched by email). Useful on registration-style triggers where no
 * WooCommerce order exists yet but the billing phone is already stored as user
 * meta.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Wordpress\User;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * User Billing Phone Merge Tag
 */
class UserBillingPhone extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'User Billing Phone';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'billing_phone';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'WordPress user billing phone (from user meta).';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'wordpress_user';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Automation Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( ! $contact instanceof AutomationContactModel || ! $contact->contact ) {
			return '';
		}

		$email = $contact->contact->email;
		if ( empty( $email ) ) {
			return '';
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return '';
		}

		$value = get_user_meta( $user->ID, 'billing_phone', true );

		return is_string( $value ) ? $value : '';
	}
}

MergeTagsManager::instance()->register( new UserBillingPhone() );
