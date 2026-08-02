<?php
/**
 * Class Integration Remote Data
 *
 * This class is responsible for handling the Integration Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Integration;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Integration;

/**
 * Integration Remote Data class
 */
abstract class RemoteData {

	/**
	 * Integration
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @param Integration $integration Host-calendar integration (Google, Outlook, Zoom, etc.).
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;
	}
}
