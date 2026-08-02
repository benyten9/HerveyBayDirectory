<?php

/**
 * Class Membership Plan ID
 *
 * This class is responsible for handling the membership plan ID merge tag
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
 * Membership Plan ID Merge Tag
 */
class MembershipPlanId extends MergeTag {



	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Membership Plan ID';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'membership_plan_id';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Membership Plan ID';

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
		$plan_id = $contact->get_data( 'plan_id' );

		return $plan_id ? $plan_id : '';
	}
}

MergeTagsManager::instance()->register( new MembershipPlanId() );
