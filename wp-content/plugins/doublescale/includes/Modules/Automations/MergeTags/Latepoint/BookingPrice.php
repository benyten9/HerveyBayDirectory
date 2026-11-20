<?php
/**
 * LatePoint booking merge tag: Booking Price.
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
 * BookingPrice class
 */
class BookingPrice extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Price';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'booking_price';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Booking Price';

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

		return null === $booking['total'] ? '' : LatepointBookings::price( (float) $booking['total'] );
	}
}

MergeTagsManager::instance()->register( new BookingPrice() );
