<?php
/**
 * Pro's WhatsApp receiver: Meta's delivery/inbound webhook and the click links.
 *
 * Copy of free's Tracking\\Whatsapp, which free keeps, frozen, for sites whose
 * Pro predates this one. All the logic lives in free's AbstractTracking; only
 * one receiver per site registers its handlers — the one ChannelEngineRegistry
 * holds under the WhatsApp tracking key (see WhatsappEngine::register_tracking()).
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Whatsapp
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Abstracts\AbstractTracking;
use DoubleScale\Core\Constants\CampaignChannel;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsappTracking.
 */
class WhatsappTracking extends AbstractTracking {

	protected $channel = CampaignChannel::STR_WHATSAPP;

	public function add_hooks() {
		// Only the receiver that owns WhatsApp answers Meta and the click links.
		$registry = WhatsappEngine::REGISTRY;
		if ( ! class_exists( $registry ) || ! $registry::is_owner( $registry::tracking_key( CampaignChannel::STR_WHATSAPP ), $this ) ) {
			return;
		}

		$this->register_standard_hooks();
	}

	public function handle_tracking() {
		$this->handle_standard_tracking();
	}

	public function handle_webhook() {
		$this->process_provider_webhook();
	}

	public static function get_webhook_url() {
		return admin_url( 'admin-ajax.php?action=doublescale_whatsapp_webhook' );
	}

	protected function get_campaign_model_class() {
		return CommunicationTrackingModel::class;
	}

	protected function get_campaign_mode() {
		return CommunicationTrackingModel::MODE_WHATSAPP;
	}

	protected static function get_tracking_action() {
		return 'whatsapp_click';
	}

	protected static function get_unsubscribe_action() {
		return 'whatsapp_unsubscribe';
	}

	protected static function get_channel_type() {
		return CampaignChannel::STR_WHATSAPP;
	}
}
