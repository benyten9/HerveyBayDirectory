<?php
/**
 * Booking Timezone
 *
 * Booking Timezone Merge Tag
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
 * Booking Timezone Merge Tag
 */
class BookingTimezone extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Timezone';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'timezone';

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
		return ! empty( $booking->timezone ) ? $booking->timezone : '';
	}
}
