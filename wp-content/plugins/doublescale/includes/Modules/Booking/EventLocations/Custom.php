<?php
/**
 * Class Custom
 *
 * This class is responsible for handling the custom
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Custom class
 */
class Custom extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Custom Location';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'custom';

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
				'label'    => __( 'Custom Location', 'doublescale' ),
				'type'     => 'text',
				'required' => true,
			),
			'description'        => array(
				'label'    => __( 'Description', 'doublescale' ),
				'type'     => 'textarea',
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
