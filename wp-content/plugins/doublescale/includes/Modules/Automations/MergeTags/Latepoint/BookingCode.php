<?php
/**
 * LatePoint booking merge tag: Booking Code.
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
 * BookingCode class
 */
class BookingCode extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Code';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'booking_code';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Booking Code';

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

		return (string) $booking['code'];
	}
}

MergeTagsManager::instance()->register( new BookingCode() );
