<?php
/**
 * Campaign Notifications Handler
 * Listens to campaign events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * CampaignNotifications class
 *
 * Handles notification creation for campaign-related events.
 *
 * @listens doublescale_campaign_complete Fired when campaign completes
 * @listens doublescale_campaign_failure Fired when campaign fails
 * @listens doublescale_campaign_schedule Fired when campaign is scheduled
 *
 * @since 1.2.0
 */
class CampaignNotifications {

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::CAMPAIGNS ) ) {
			return;
		}
		add_action( 'doublescale_campaign_complete', array( $this, 'on_campaign_completed' ), 10, 3 );
		add_action( 'doublescale_campaign_failure', array( $this, 'on_campaign_failed' ), 10, 3 );
		add_action( 'doublescale_campaign_schedule', array( $this, 'on_campaign_scheduled' ), 10, 2 );
	}

	/**
	 * Handle campaign completed event
	 *
	 * Broadcasts to all CRM users since campaigns run in cron context
	 * and the model doesn't track ownership.
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Campaigns\Models\CampaignModel $campaign         The completed campaign.
	 * @param int                             $recipients_count Number of recipients.
	 * @param string                          $channel          Campaign channel.
	 */
	public function on_campaign_completed( $campaign, $recipients_count, $channel ) {
		$channel_label = $this->get_channel_label( $channel );
		$subcategory   = $this->get_subcategory_for_channel( $channel );

		// Broadcast to all CRM users (campaigns run in background/cron).
		NotificationService::broadcast(
			/* translators: %s: campaign name */
			sprintf( __( 'Campaign "%s" Completed', 'doublescale'), $campaign->name ),
			/* translators: 1: channel type, 2: number of recipients */
			sprintf( __( '%1$s campaign successfully sent to %2$d recipients.', 'doublescale'), $channel_label, $recipients_count ),
			$this->get_campaign_link( $campaign ),
			$subcategory
		);
	}

	/**
	 * Handle campaign failed event
	 *
	 * Broadcasts to all CRM users since campaigns run in cron context
	 * and failures need immediate attention.
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Campaigns\Models\CampaignModel $campaign      The failed campaign.
	 * @param string                          $error_message Error description.
	 * @param string                          $channel       Campaign channel.
	 */
	public function on_campaign_failed( $campaign, $error_message, $channel ) {
		$channel_label = $this->get_channel_label( $channel );
		$subcategory   = $this->get_subcategory_for_channel( $channel );

		// Broadcast error to all CRM users (campaigns run in background/cron).
		NotificationService::broadcast(
			/* translators: %s: campaign name */
			sprintf( __( 'Campaign "%s" Failed', 'doublescale'), $campaign->name ),
			/* translators: 1: channel type, 2: error message */
			sprintf( __( '%1$s campaign failed: %2$s', 'doublescale'), $channel_label, $error_message ),
			$this->get_campaign_link( $campaign ),
			$subcategory
		);
	}

	/**
	 * Get link to campaign in admin
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Campaigns\Models\CampaignModel $campaign Campaign model.
	 * @return string Admin URL to campaign.
	 */
	private function get_campaign_link( $campaign ) {
		return array(
			'web'    => admin_url( "admin.php?page=doublescale&path=campaigns&id={$campaign->id}" ),
			'mobile' => null,
		);
	}

	/**
	 * Get human-readable channel label
	 *
	 * @since 1.2.0
	 *
	 * @param string $channel Channel type.
	 * @return string Translated label.
	 */
	private function get_channel_label( $channel ) {
		$labels = array(
			'email'    => __( 'Email', 'doublescale'),
			'sms'      => __( 'Sms', 'doublescale'),
			'whatsapp' => __( 'WhatsApp', 'doublescale'),
		);

		return $labels[ $channel ] ?? ucfirst( $channel );
	}

	/**
	 * Handle campaign scheduled event
	 *
	 * Broadcasts to all CRM users when a campaign is scheduled.
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Campaigns\Models\CampaignModel $campaign   The scheduled campaign.
	 * @param string                          $execute_at Scheduled execution time.
	 */
	public function on_campaign_scheduled( $campaign, $execute_at ) {
		$channel_label = $this->get_channel_label( $campaign->type );

		// Format the scheduled time.
		$scheduled_time = $execute_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $execute_at ) ) : __( 'immediately', 'doublescale');

		NotificationService::broadcast(
			/* translators: %s: campaign name */
			sprintf( __( 'Campaign "%s" Scheduled', 'doublescale'), $campaign->name ),
			/* translators: 1: channel type, 2: scheduled time */
			sprintf( __( '%1$s campaign scheduled to send %2$s.', 'doublescale'), $channel_label, $scheduled_time ),
			$this->get_campaign_link( $campaign ),
			NotificationCategories::CAMPAIGNS_EMAIL_SCHEDULED
		);
	}

	/**
	 * Get notification subcategory for a campaign channel
	 *
	 * Maps campaign channels to notification subcategories for user preferences.
	 * WhatsApp campaigns are disabled, so it falls back to email subcategory.
	 *
	 * @since 1.2.0
	 *
	 * @param string $channel Campaign channel (email, sms, whatsapp).
	 * @return string Notification subcategory constant.
	 */
	private function get_subcategory_for_channel( $channel ) {
		$map = array(
			'email' => NotificationCategories::CAMPAIGNS_EMAIL,
			'sms'   => NotificationCategories::CAMPAIGNS_SMS,
		);

		// Default to email subcategory for unknown channels (whatsapp campaigns are disabled).
		return $map[ $channel ] ?? NotificationCategories::CAMPAIGNS_EMAIL;
	}
}
