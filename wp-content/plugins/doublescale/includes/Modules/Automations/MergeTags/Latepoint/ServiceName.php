<?php
/**
 * LatePoint booking merge tag: Service.
 *
 * Reads the booking the workflow started from (`latepoint_booking_id` in the
 * automation contact's data).
 *
 * @package DoubleScale\Modules\Automations\MergeTags\Latepoint
 */

namespace DoubleScale\Modules\Automations\MergeTags\Latepoint;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Services\LatepointBookings;

/**
 * ServiceName class
 */
class ServiceName extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Service';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'booking_service';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Service';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'latepoint_booking';

	/**
	 * Get value
	 *
	 * @param mixed  $contact   Automation contact (carries the booking id).
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$booking = LatepointBookings::for_run( $contact );
		if ( ! $booking ) {
			return '';
		}

		return (string) $booking['name'];
	}
}

MergeTagsManager::instance()->register( new ServiceName() );
