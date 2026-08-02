<?php
/**
 * Microsoft Teams Location class.
 *
 * This class is responsible for handling the Microsoft Teams location.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Location;

/**
 * Microsoft Teams class
 */
class MsTeams extends Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title = 'Microsoft Teams';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'ms-teams';

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	public $is_integration = true;
}
