<?php
/**
 * Sms Received Trigger
 * Triggers when an Sms message is received from a contact
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * SmsReceived class
 */
class SmsReceived extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Sms Received';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'sms_received';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Triggers when an Sms is received from a contact';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'messaging';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_sms_received', array( $this, 'handle_sms_received' ), 10, 4 );
	}

	/**
	 * Handle Sms received event
	 *
	 * This method is called when an Sms message is received via webhook.
	 * It structures the data for automation processing and merge tag access.
	 *
	 * @since 1.0.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel                $contact  Contact who sent the Sms.
	 * @param \DoubleScale\Modules\Activities\Models\ActivityModel               $activity Activity record.
	 * @param \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel $tracking Tracking record.
	 * @param array                                         $sms_data {
	 *     Sms message data from the provider.
	 *
	 *     @type string   $from_number  Sender's phone number (e.g., '+1234567890').
	 *     @type string   $to_number    Your Twilio phone number.
	 *     @type string   $message_body The text content of the message.
	 *     @type string   $message_id   Twilio Message SID for tracking.
	 *     @type string[] $media_urls   Array of MMS media attachment URLs.
	 * }
	 *
	 * @return void
	 */
	public function handle_sms_received( $contact, $activity, $tracking, $sms_data ) {
		/*
		 * Data structure for automation processing:
		 *
		 * - 'contact': Used by ProcessAutomation::add_contact() to identify the contact
		 * - 'data': Stored in AutomationContactModel.data column for merge tag access
		 *           and used by is_processable() for condition checking
		 *
		 * Merge tags access data via: $automation_contact->get_data('sms_data')
		 * Available merge tags: {{messaging:message_body}}, {{messaging:from_number}},
		 * {{messaging:to_number}}, {{messaging:message_id}}, {{messaging:media_urls}},
		 * {{messaging:has_media}}, {{messaging:channel}}
		 */
		$data = array(
			'contact' => $contact,
			'data'    => array(
				'sms_data'    => $sms_data,
				'activity_id' => $activity->id,
				'tracking_id' => $tracking->id,
			),
		);

		$this->process( $data );
	}

	/**
	 * Check if trigger should be processed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model.
	 * @param array            $args       Arguments.
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		// Get message_contains filter from automation settings
		$message_contains = $automation->get_setting( 'message_contains', '' );

		// Check message_contains filter if set
		if ( ! empty( $message_contains ) ) {
			$message_body = $args['data']['sms_data']['message_body'] ?? '';

			// Case-insensitive search for the keyword
			if ( stripos( $message_body, $message_contains ) === false ) {
				return false;
			}
		}

		return parent::is_processable( $automation, $args );
	}

	/**
	 * Get fields for trigger configuration
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'message_contains' => array(
				'label'       => __( 'Message Contains', 'doublescale'),
				'type'        => 'text',
				'placeholder' => __( 'Optional keyword to match', 'doublescale'),
				'description' => __( 'Only trigger if the message contains this text (case-insensitive). Leave empty to trigger on any message.', 'doublescale'),
			),
		);
	}
}

