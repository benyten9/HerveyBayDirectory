<?php
/**
 * Contact email merge tag.
 *
 * Registered as `{{guest:email}}` for stable template compatibility — the
 * group slug stays `guest` even though the data source is now the contact
 * relation. See plans/zany-plotting-creek.md Audit #11.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;

class ContactEmail extends MergeTag {

	public $name = 'Guest Email';

	public $slug = 'email';

	public $group = 'guest';

	public function get_value( $booking, $options = array() ) {
		return isset( $booking->contact->email ) ? $booking->contact->email : '';
	}
}
