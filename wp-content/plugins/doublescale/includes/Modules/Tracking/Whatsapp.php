<?php
/**
 * WhatsApp Tracking
 * This class is responsible for handling WhatsApp tracking functionality
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Tracking;

use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Abstracts\AbstractTracking;
use DoubleScale\Core\Constants\CampaignChannel;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp Tracking Class
 */
class Whatsapp extends AbstractTracking {

	/**
	 * Communication channel
	 *
	 * @var string
	 */
	protected $channel = CampaignChannel::STR_WHATSAPP;

	/**
	 * Add hooks - implementation of abstract method
	 *
	 * @since 1.0.0
	 */
	public function add_hooks() {
		$this->register_standard_hooks();
	}

	/**
	 * Handle tracking requests - implementation of abstract method
	 *
	 * @since 1.0.0
	 */
	public function handle_tracking() {
		$this->handle_standard_tracking();
	}

	/**
	 * Handle webhook - implementation of abstract method
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_webhook() {
		$this->process_provider_webhook();
	}

	/**
	 * Get webhook URL - implementation of abstract method
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_webhook_url() {
		return admin_url( 'admin-ajax.php?action=doublescale_whatsapp_webhook' );
	}

	/**
	 * Get campaign model class - implementation of abstract method
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function get_campaign_model_class() {
		return CommunicationTrackingModel::class;
	}

	/**
	 * Get campaign mode - implementation of abstract method
	 *
	 * @since 1.0.0
	 * @return int
	 */
	protected function get_campaign_mode() {
		return CommunicationTrackingModel::MODE_WHATSAPP;
	}

	/**
	 * Get tracking action - implementation of abstract method
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected static function get_tracking_action() {
		return 'whatsapp_click';
	}

	/**
	 * Get unsubscribe action - implementation of abstract method
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected static function get_unsubscribe_action() {
		return 'whatsapp_unsubscribe';
	}

	/**
	 * Get channel type - implementation of abstract method
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected static function get_channel_type() {
		return CampaignChannel::STR_WHATSAPP;
	}
}

// Initialized by Pro main class
