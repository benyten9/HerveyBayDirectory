<?php
/**
 * SMS campaign processing (Pro).
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Sms
 */

namespace DoubleScale\Pro\Modules\Campaigns\Sms;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Abstracts\AbstractCampaignProcessing;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Validators\PhoneValidator;

defined( 'ABSPATH' ) || exit;

/**
 * SMS campaign processing class.
 */
class SmsProcessing extends AbstractCampaignProcessing {

	/**
	 * Communication channel
	 *
	 * @var string
	 */
	protected $channel = CampaignChannel::STR_SMS;

	/**
	 * Constructor — schedule SMS recurring task when Pro owns processing.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'init', array( $this, 'maybe_schedule_sms_campaign_cron' ), 25 );
	}

	/**
	 * Ensure Action Scheduler has the SMS campaigns recurring hook registered.
	 *
	 * @return void
	 */
	public function maybe_schedule_sms_campaign_cron(): void {
		if ( get_transient( 'doublescale_register_tasks_lock_campaigns_sms_pro' ) ) {
			return;
		}
		set_transient( 'doublescale_register_tasks_lock_campaigns_sms_pro', 1, MINUTE_IN_SECONDS );
		$tasks = new \DoubleScale\Core\Tasks( 'doublescale_campaigns' );
		if ( $tasks->get_next_timestamp( 'doublescale_sms_campaigns' ) === false ) {
			$tasks->schedule_recurring( time(), 60, 'doublescale_sms_campaigns' );
		}
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	public function add_hooks() {
		$this->register_campaign_processing_hooks();
	}

	/**
	 * Get campaign message mode
	 *
	 * @return int
	 */
	public function get_message_mode() {
		return CommunicationTrackingModel::MODE_SMS;
	}

	/**
	 * Get channel context for merge tags
	 *
	 * @return string
	 */
	public function get_channel_context() {
		return 'sms';
	}

	/**
	 * Prepare message content - Override to set channel context for merge tags
	 *
	 * @param \DoubleScale\Modules\Campaigns\Models\TemplateModel                         $template Template model
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model
	 * @param CommunicationTrackingModel                                                   $campaign_message Campaign tracking record
	 * @return array Message data array with subject, body, recipient, hash_key
	 */
	protected function prepare_message_content( TemplateModel $template, $contact_or_automation_contact, CommunicationTrackingModel $campaign_message ) {
		add_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

		$message_data = parent::prepare_message_content( $template, $contact_or_automation_contact, $campaign_message );

		remove_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

		return $message_data;
	}

	/**
	 * Get recipient field from contact
	 *
	 * @param ContactModel $contact Contact.
	 * @return string|null
	 */
	protected function get_recipient( ContactModel $contact ) {
		$phone = $contact->phone;

		if ( empty( $phone ) ) {
			doublescale_get_logger()->info(
				'Contact skipped - no phone number for Sms campaign',
				array(
					'code'       => 'missing_phone',
					'contact_id' => $contact->id,
				)
			);
			return null;
		}

		$sanitized = PhoneValidator::sanitize( $phone );
		if ( empty( $sanitized ) ) {
			doublescale_get_logger()->info(
				'Contact skipped - invalid phone number format for Sms campaign',
				array(
					'code'       => 'invalid_phone_format',
					'contact_id' => $contact->id,
					'phone'      => $phone,
				)
			);
			return null;
		}

		return $sanitized;
	}

	/**
	 * Send message
	 *
	 * @param array                      $message_data      Prepared message data.
	 * @param ContactModel               $contact           Contact model.
	 * @param CommunicationTrackingModel $campaign_message Campaign tracking record.
	 * @return array Result array with 'success' boolean and optional data
	 */
	protected function send_message( $message_data, ContactModel $contact, CommunicationTrackingModel $campaign_message ) {
		return $this->send_via_provider( $message_data, $contact, $campaign_message );
	}

	/**
	 * Get tracking class
	 *
	 * @return string
	 */
	protected function get_tracking_class() {
		return SmsTracking::class;
	}

	/**
	 * Get default campaign content
	 *
	 * @return string
	 */
	protected function get_default_campaign_content() {
		return sprintf( __( 'Hi {{contact:first_name}}, thank you for subscribing! Reply STOP to unsubscribe.', 'doublescale' ) );
	}
}
