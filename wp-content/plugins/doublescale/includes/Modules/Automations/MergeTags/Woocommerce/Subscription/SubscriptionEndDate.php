<?php

/**
 * Subscription End Date Merge Tag
 *
 * This class is responsible for handling the subscription end date merge tag
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
 * Subscription End Date Merge Tag
 */
class SubscriptionEndDate extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Subscription End Date';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'subscription_end_date';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Subscription End Date';

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

		$end_date = $subscription->get_date( 'end' );
		if ( ! $end_date ) {
			return '';
		}

		return date_i18n( get_option( 'date_format' ), strtotime( $end_date ) );
	}
}

MergeTagsManager::instance()->register( new SubscriptionEndDate() );
