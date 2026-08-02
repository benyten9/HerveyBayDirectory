<?php
/**
 * Zoom Location class.
 *
 * This class is responsible for handling the Zoom location.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Zoom class
 */
class Zoom extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Zoom';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'zoom';

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	public $is_integration = true;
}
