<?php
/**
 * Contact-supplied note (booking form `message` field).
 *
 * Registered as `{{guest:note}}` for stable template compatibility. The data
 * source is the booking's own custom-fields meta — independent of contact data.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;
use Illuminate\Support\Arr;

class ContactNote extends MergeTag {

	public $name = 'Guest Note';

	public $slug = 'note';

	public $group = 'guest';

	public function get_value( $booking, $options = array() ) {
		$message = Arr::get( $booking->fields, 'message', '' );
		if ( empty( $message ) ) {
			return '';
		}
		return $message;
	}
}
