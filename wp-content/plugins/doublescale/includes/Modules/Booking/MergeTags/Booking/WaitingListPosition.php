<?php
/**
 * Waiting List Position Merge Tag
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
 * Waiting List Position Merge Tag
 */
class WaitingListPosition extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Waiting List Position';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'waiting_list_position';

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
		if ( ! ( $booking instanceof BookingModel ) ) {
			return '';
		}
		$position = $booking->get_meta( 'waiting_list_position', null );
		return null !== $position ? (string) $position : '';
	}
}
