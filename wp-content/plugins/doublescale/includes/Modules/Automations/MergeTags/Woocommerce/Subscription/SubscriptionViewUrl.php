<?php

/**
 * Subscription View URL Merge Tag
 *
 * This class is responsible for handling the subscription view URL merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Subscription;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Subscription View URL Merge Tag
 */
class SubscriptionViewUrl extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Subscription View URL';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'subscription_view_url';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Subscription View URL';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'subscription';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$subscription_id = $contact->get_data( 'subscription_id' );
		if ( ! $subscription_id ) {
			return '';
		}

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		return $subscription->get_view_order_url();
	}
}

MergeTagsManager::instance()->register( new SubscriptionViewUrl() );
