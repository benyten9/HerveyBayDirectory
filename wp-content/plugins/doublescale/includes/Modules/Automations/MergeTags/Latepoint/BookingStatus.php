<?php
/**
 * LatePoint booking merge tag: Booking Status.
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
 * BookingStatus class
 */
class BookingStatus extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Status';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'booking_status';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Booking Status';

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

		$statuses = LatepointBookings::statuses();

		return (string) ( $statuses[ $booking['status'] ] ?? ucwords( str_replace( '_', ' ', (string) $booking['status'] ) ) );
	}
}

MergeTagsManager::instance()->register( new BookingStatus() );
