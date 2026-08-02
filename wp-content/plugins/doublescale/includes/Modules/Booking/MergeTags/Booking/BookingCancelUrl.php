<?php
/**
 * Booking Cancel URL Merge Tag
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
 * Booking Cancel URL Merge Tag
 */
class BookingCancelUrl extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Cancel URL';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'cancel_url';

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
		if ( ! ( $booking instanceof BookingModel ) || ! method_exists( $booking, 'getCancelUrl' ) ) {
			return '';
		}
		return $booking->getCancelUrl();
	}
}
