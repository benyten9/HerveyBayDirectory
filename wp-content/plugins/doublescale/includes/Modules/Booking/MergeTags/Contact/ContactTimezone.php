<?php
/**
 * Contact-side timezone merge tag.
 *
 * Registered as `{{guest:timezone}}`. Reads from booking meta (`$booking->timezone`),
 * which captures what the booker selected at booking time. Independent of contact data.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;

class ContactTimezone extends MergeTag {

	public $name = 'Guest Timezone';

	public $slug = 'timezone';

	public $group = 'guest';

	public function get_value( $booking, $options = array() ) {
		if ( ! isset( $booking->timezone ) ) {
			return '';
		}
		return $booking->timezone;
	}
}
