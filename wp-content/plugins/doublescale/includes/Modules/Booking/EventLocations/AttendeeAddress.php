<?php
/**
 * Class AttendeeAddress
 *
 * This class is responsible for handling the attendee address
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Attendee Address class
 */
class AttendeeAddress extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Attendee Address';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'attendee_address';

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'address' => array(
				'label'       => __( 'Your Address', 'doublescale' ),
				'type'        => 'text',
				'required'    => true,
				'group'       => 'system',
				'placeholder' => __( 'Enter your address', 'doublescale' ),
				'order'       => 4,
			),
		);
	}
}
