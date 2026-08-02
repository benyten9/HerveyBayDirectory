<?php
/**
 * Waiting List Claim URL Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Booking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;

/**
 * Waiting List Claim URL Merge Tag
 */
class WaitingListClaimUrl extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Waiting List Claim URL';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'waiting_list_claim_url';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'booking';

	/**
	 * Get Value
	 *
	 * @param BookingModel $booking Booking model.
	 * @param array        $options Options.
	 *
	 * @return string
	 */
	public function get_value( $booking, $options = array() ) {
		if ( ! ( $booking instanceof BookingModel ) || ! method_exists( $booking, 'getWaitingListClaimUrl' ) ) {
			return '';
		}
		return $booking->getWaitingListClaimUrl();
	}
}
