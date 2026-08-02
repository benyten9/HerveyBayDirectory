<?php
/**
 * Class Online
 *
 * This class is responsible for handling the online event location
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Online class
 */
class Online extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Online';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'online';

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_admin_fields() {
		return array(
			'meeting_url'        => array(
				'label'    => __( 'Meeting URL', 'doublescale' ),
				'type'     => 'url',
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
