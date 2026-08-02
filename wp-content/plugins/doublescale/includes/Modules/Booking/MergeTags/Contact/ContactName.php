<?php
/**
 * Contact name merge tag.
 *
 * Registered as `{{guest:name}}` for stable template compatibility.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;

class ContactName extends MergeTag {

	public $name = 'Guest Name';

	public $slug = 'name';

	public $group = 'guest';

	public function get_value( $booking, $options = array() ) {
		if ( method_exists( $booking, 'getContactDisplayName' ) ) {
			return $booking->getContactDisplayName();
		}
		if ( ! isset( $booking->contact ) ) {
			return '';
		}
		$name = trim( ( $booking->contact->first_name ?? '' ) . ' ' . ( $booking->contact->last_name ?? '' ) );
		return '' !== $name ? $name : (string) ( $booking->contact->email ?? '' );
	}
}
