<?php

/**
 * Class Membership Plan Name
 *
 * This class is responsible for handling the membership plan name merge tag
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
 * Membership Plan Name Merge Tag
 */
class MembershipPlanName extends MergeTag {



	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Membership Plan Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'membership_plan_name';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Membership Plan Name';

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
		$plan_name = $contact->get_data( 'plan_name' );

		return $plan_name ? $plan_name : '';
	}
}

MergeTagsManager::instance()->register( new MembershipPlanName() );
