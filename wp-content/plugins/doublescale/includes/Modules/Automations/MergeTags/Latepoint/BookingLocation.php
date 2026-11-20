<?php
/**
 * LatePoint booking merge tag: Location.
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
 * BookingLocation class
 */
class BookingLocation extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Location';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'booking_location';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Location';

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

		return (string) $booking['location'];
	}
}

MergeTagsManager::instance()->register( new BookingLocation() );
