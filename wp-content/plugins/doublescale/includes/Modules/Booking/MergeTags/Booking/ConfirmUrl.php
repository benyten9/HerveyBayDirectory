<?php
/**
 * Class Confirmation_URL
 *
 * This class is responsible for handling the confirm URL
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
 * Confirmation URL Merge Tag
 */
class ConfirmUrl extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Confirmation URL';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'confirm_url';

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
		if ( ! ( $booking instanceof BookingModel ) || ! method_exists( $booking, 'getConfirmUrl' ) ) {
			return '';
		}
		return $booking->getConfirmUrl();
	}
}
