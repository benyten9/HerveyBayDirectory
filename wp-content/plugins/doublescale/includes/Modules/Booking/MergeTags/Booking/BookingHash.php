<?php
/**
 * Booking hash
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
 * Booking hash Merge Tag
 */
class BookingHash extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Unique Hash';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'hash';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'booking';

	/**
	 * Get value
	 *
	 * @param BookingModel $booking
	 * @param array        $options
	 * @return string
	 */
	public function get_value( $booking, $options = array() ) {
		if ( ! isset( $booking->hash_id ) || empty( $booking->hash_id ) ) {
			return '';
		}
		return $booking->hash_id;
	}
}
