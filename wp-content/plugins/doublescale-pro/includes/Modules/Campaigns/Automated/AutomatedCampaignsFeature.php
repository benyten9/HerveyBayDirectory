<?php
/**
 * Automated campaigns feature (Pro).
 *
 * Boots the free-plugin AutomatedCampaignHandler when Pro is active.
 * Free code gates automated campaign REST/UI behind class_exists() of this class.
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Automated
 */

namespace DoubleScale\Pro\Modules\Campaigns\Automated;

defined( 'ABSPATH' ) || exit;

/**
 * Marker + bootstrap class for automated campaigns.
 */
class AutomatedCampaignsFeature {

	/**
	 * @var self|null
	 */
	private static $instance;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		AutomatedCampaignHandler::instance();
	}
}
