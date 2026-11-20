<?php
/**
 * Amelia booking merge tag: Booking Status.
 *
 * Reads the booking the workflow started from (`amelia_booking_id` in the
 * automation contact's data).
 *
 * @package DoubleScale\Modules\Automations\MergeTags\Amelia
 */

namespace DoubleScale\Modules\Automations\MergeTags\Amelia;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Services\AmeliaBookings;

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
	public $group = 'amelia_booking';

	/**
	 * Get value
	 *
	 * @param mixed  $contact   Automation contact (carries the booking id).
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( ! is_object( $contact ) || ! method_exists( $contact, 'get_data' ) ) {
			return '';
		}

		$booking = AmeliaBookings::find_booking( (int) $contact->get_data( 'amelia_booking_id', 0 ) );
		if ( ! $booking ) {
			return '';
		}

		$statuses = AmeliaBookings::statuses();

		return $statuses[ $booking['status'] ] ?? ucfirst( (string) $booking['status'] );
	}
}

MergeTagsManager::instance()->register( new BookingStatus() );
