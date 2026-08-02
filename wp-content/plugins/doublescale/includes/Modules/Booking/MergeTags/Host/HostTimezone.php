<?php
/**
 * Host timezone
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
 * Host timezone Merge Tag
 */
class HostTimezone extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Host Timezone';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'timezone';

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
		return isset( $booking ) && isset( $booking->calendar ) && isset( $booking->calendar->timezone )
			? $booking->calendar->timezone
			: '';
	}
}
