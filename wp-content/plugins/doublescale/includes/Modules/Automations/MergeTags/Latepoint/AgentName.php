<?php
/**
 * LatePoint booking merge tag: Agent.
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
 * AgentName class
 */
class AgentName extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Agent';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'booking_agent';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Agent';

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

		return (string) $booking['agent'];
	}
}

MergeTagsManager::instance()->register( new AgentName() );
