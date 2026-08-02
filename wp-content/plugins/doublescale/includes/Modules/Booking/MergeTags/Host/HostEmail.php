<?php

/**
 * Host email
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Host;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;

/**
 * Host email Merge Tag
 */
class HostEmail extends MergeTag {


	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Host Email';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'email';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'host';

	/**
	 * Get value
	 *
	 * @param BookingModel $booking
	 * @param array        $options
	 * @return string
	 */
	public function get_value( $booking, $options = array() ) {
		if ( isset( $booking ) && isset( $booking->calendar ) && isset( $booking->calendar->user ) && isset( $booking->calendar->user->user_email ) ) {
			return $booking->calendar->user->user_email;
		}

		return '';
	}
}
