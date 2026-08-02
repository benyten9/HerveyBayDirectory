<?php
/**
 * Class PersonAddress
 *
 * This class is responsible for handling the person address
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Person Address class
 */
class PersonAddress extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Person Address';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'person_address';

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_admin_fields() {
		return array(
			'location'           => array(
				'label'    => __( 'Person Address', 'doublescale' ),
				'type'     => 'text',
				'required' => true,
			),
			'display_on_booking' => array(
				'label'    => __( 'Display on Booking', 'doublescale' ),
				'desc'     => __( 'Display on booking page', 'doublescale' ),
				'type'     => 'checkbox',
				'required' => false,
			),
		);
	}
}
