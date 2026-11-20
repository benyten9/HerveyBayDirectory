<?php
/**
 * LatePoint booking merge tag: Booking Time.
 *
 * Reads the booking the workflow started from (`latepoint_booking_id` in the
 * automation contact's data).
 *
 * @package DoubleScale\Modules\Automations\MergeTags\Latepoint
 */

namespace DoubleScale\Modules\Automations\MergeTags\Latepoint;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Services\LatepointBookings;

/**
 * BookingTime class
 */
class BookingTime extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Time';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'booking_time';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Booking Time';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'latepoint_booking';

	/**
	 * Get value
	 *
	 * @param mixed  $contact   Automation contact (carries the booking id).
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$booking = LatepointBookings::for_run( $contact );
		if ( ! $booking ) {
			return '';
		}

		return LatepointBookings::local( (string) $booking['start'], (string) get_option( 'time_format', 'g:i a' ) );
	}
}

MergeTagsManager::instance()->register( new BookingTime() );
