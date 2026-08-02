<?php
/**
 * Google Meet Location class.
 *
 * This class is responsible for handling the Google Meet location.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Google Meet class
 */
class GoogleMeet extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Google Meet';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'google-meet';

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	public $is_integration = true;
}
