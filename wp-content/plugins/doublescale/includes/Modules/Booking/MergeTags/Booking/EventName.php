<?php
/**
 * Event / Service Name Merge Tag
 *
 * Returns the name of the bookable entity — the event name for
 * event-based bookings or the service name for service-based bookings.
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
 * Event / Service Name Merge Tag
 */
class EventName extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Event / Service Name';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'event_name';

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
		return $booking->getBookableName();
	}
}
