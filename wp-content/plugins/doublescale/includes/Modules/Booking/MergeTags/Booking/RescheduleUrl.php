<?php
/**
 * Reschedule URL Merge Tag
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
 * Reschedule URL Merge Tag
 */
class RescheduleUrl extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Reschedule URL';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'reschedule_url';

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
		if ( ! ( $booking instanceof BookingModel ) || ! method_exists( $booking, 'getRescheduleUrl' ) ) {
			return '';
		}
		return $booking->getRescheduleUrl();
	}
}
