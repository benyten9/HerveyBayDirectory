<?php

/**
 * Send Sms Action
 * Auto-generates templates and creates tracking records
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Messaging;

use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractSendMessage;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Campaigns\Campaign\SmsProcessing;
use DoubleScale\Core\Validators\PhoneValidator;

/**
 * Send Sms Action
 */
class SendSms extends AbstractSendMessage
{

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Send Sms';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'send_sms';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'sms';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'message';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will send an Sms to the user with full tracking and analytics.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Get channel type
	 *
	 * @return string
	 */
	protected function get_channel_type()
	{
		return \DoubleScale\Core\Constants\CampaignChannel::STR_SMS;
	}

	/**
	 * Get tracking mode
	 *
	 * @return int
	 */
	protected function get_tracking_mode()
	{
		return CommunicationTrackingModel::MODE_SMS;
	}

	/**
	 * Get recipient from contact
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return string|null
	 */
	protected function get_recipient(ContactModel $contact)
	{
		return $contact->phone;
	}

	/**
	 * Validate recipient
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return array|null
	 */
	protected function validate_recipient(ContactModel $contact)
	{
		if (empty($contact->phone)) {
			return array(
				'status'  => 'skipped',
				'message' => 'Contact has no phone number',
			);
		}

		// Validate phone number format using centralized utility
		if (! PhoneValidator::is_valid($contact->phone)) {
			return array(
				'status'  => 'skipped',
				'message' => 'Invalid phone number format. Use E.164 format: +1234567890',
			);
		}

		return null;
	}

	/**
	 * Get processing instance
	 *
	 * @return SmsProcessing
	 */
	protected function get_processing_instance()
	{
		return SmsProcessing::instance();
	}

	/**
	 * Get channel name for logging
	 *
	 * @return string
	 */
	protected function get_channel_name()
	{
		return 'Sms';
	}

	/**
	 * Get fields for UI
	 * User composes Sms directly (no template selection)
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'body' => array(
				'label'       => __('Message', 'doublescale'),
				'type'        => 'textarea',
				'required'    => true,
				'placeholder' => __('Enter Sms message...', 'doublescale'),
				'description' => __('Maximum 160 characters for standard Sms. Longer messages will be split.', 'doublescale'),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'body' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}

	/**
	 * Prepare channel-specific message data for sending
	 *
	 * For Sms, this simply returns the body text from step settings.
	 * Template auto-generation is handled by AutomationStepModel events.
	 *
	 * @param AutomationStepModel             $step     Automation Step Model.
	 * @param ContactModel                    $contact  Contact Model.
	 * @param CommunicationTrackingModel $tracking Communication Tracking Model.
	 * @return array Prepared message data with body.
	 * @throws \Exception If message body is empty.
	 */
	protected function prepare_message_data( AutomationStepModel $step, ContactModel $contact, CommunicationTrackingModel $tracking )
	{
		$body = $step->get_setting('body');

		if (empty($body)) {
			throw new \Exception(__('Sms message body is empty.', 'doublescale'));
		}

		return array(
			'body' => $body,
		);
	}
}

// Registered via Pro main class
