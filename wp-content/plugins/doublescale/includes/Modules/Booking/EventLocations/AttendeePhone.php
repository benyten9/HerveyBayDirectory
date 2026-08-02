<?php

/**
 * Class AttendeePhone
 *
 * This class is responsible for handling the attendee phone
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Attendee Phone class
 */
class AttendeePhone extends Location {


	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Attendee Phone';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'attendee_phone';

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'phone' => array(
				'label'       => __( 'Your Phone', 'doublescale' ),
				'type'        => 'phone',
				'required'    => true,
				'group'       => 'system',
				'placeholder' => __( 'Enter your phone', 'doublescale' ),
				'order'       => 4,
			),
		);
	}
}
