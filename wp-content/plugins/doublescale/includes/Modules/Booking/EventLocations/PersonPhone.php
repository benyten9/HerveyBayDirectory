<?php
/**
 * Class PersonPhone
 *
 * This class is responsible for handling the person phone
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Person Phone class
 */
class PersonPhone extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Person Phone';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'person_phone';

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_admin_fields() {
		return array(
			'phone'              => array(
				'label'    => __( 'Person Phone', 'doublescale' ),
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
