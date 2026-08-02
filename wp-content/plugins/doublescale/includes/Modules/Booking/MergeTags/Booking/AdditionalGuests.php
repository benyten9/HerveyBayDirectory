<?php
/**
 * Additional Guests Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Booking;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;

/**
 * Additional Guests Merge Tag
 */
class AdditionalGuests extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Additional Guests';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'additional_guests';

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
		if ( ! isset( $booking->fields ) || ! is_array( $booking->fields ) ) {
			return '';
		}

		$additional_guests = Arr::get( $booking->fields, 'additional_guests', array() );

		if ( ! is_array( $additional_guests ) || empty( $additional_guests ) ) {
			return '';
		}

		// Cast each value to string just in case
		$guest_names = array_map( 'strval', $additional_guests );

		return implode( ', ', $guest_names );
	}
}
