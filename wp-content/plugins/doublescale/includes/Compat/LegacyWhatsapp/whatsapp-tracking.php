<?php
/**
 * Stand-in for free's former WhatsApp receiver, served only next to a Pro that
 * predates its own engine. See {@see \DoubleScale\Compat\LegacyWhatsapp}.
 *
 * Links in WhatsApp messages already sent keep redirecting (the shared click
 * handling lives in AbstractTracking). Meta's webhook is acknowledged with a
 * 200 and ignored: WhatsApp is off on the site, and an unanswered webhook would
 * make Meta report the URL as failing.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Modules\Tracking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Services\ChannelEngineRegistry;
use DoubleScale\Modules\Tracking\Abstracts\AbstractTracking;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * Whatsapp.
 */
class Whatsapp extends AbstractTracking {

	/** @var string */
	protected $channel = CampaignChannel::STR_WHATSAPP;

	/**
	 * Hooks, only while this receiver owns WhatsApp.
	 *
	 * @return void
	 */
	public function add_hooks() {
		if ( ! ChannelEngineRegistry::is_owner( ChannelEngineRegistry::tracking_key( CampaignChannel::STR_WHATSAPP ), $this ) ) {
			return;
		}

		$this->register_standard_hooks();
	}

	/**
	 * Click links.
	 *
	 * @return void
	 */
	public function handle_tracking() {
		$this->handle_standard_tracking();
	}

	/**
	 * Meta's webhook: acknowledge, process nothing.
	 *
	 * @return void
	 */
	public function handle_webhook() {
		status_header( 200 );
		exit;
	}

	/**
	 * The webhook URL Meta was given.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return admin_url( 'admin-ajax.php?action=doublescale_whatsapp_webhook' );
	}

	/** @return string */
	protected function get_campaign_model_class() {
		return CommunicationTrackingModel::class;
	}

	/** @return int */
	protected function get_campaign_mode() {
		return CommunicationTrackingModel::MODE_WHATSAPP;
	}

	/** @return string */
	protected static function get_tracking_action() {
		return 'whatsapp_click';
	}

	/** @return string */
	protected static function get_unsubscribe_action() {
		return 'whatsapp_unsubscribe';
	}

	/** @return string */
	protected static function get_channel_type() {
		return CampaignChannel::STR_WHATSAPP;
	}
}
