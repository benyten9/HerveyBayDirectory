<?php
/**
 * Email Received Trigger
 * Triggers when an email message is received from a contact
 *
 * @since 1.1.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * EmailReceived class
 */
class EmailReceived extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Email Received';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'email_received';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Triggers when an email is received from a contact';

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
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_email_received', array( $this, 'handle_email_received' ), 10, 4 );
	}

	/**
	 * Handle email received event
	 *
	 * This method is called when an email message is received via IMAP or webhook.
	 * It structures the data for automation processing and merge tag access.
	 *
	 * @since 1.1.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel                $contact  Contact who sent the email.
	 * @param \DoubleScale\Modules\Activities\Models\ActivityModel               $activity Activity record.
	 * @param \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel $tracking Tracking record.
	 * @param array                                         $email_data {
	 *     Email message data.
	 *
	 *     @type string $from_email  Sender's email address.
	 *     @type string $from_name   Sender's display name.
	 *     @type string $to_email    Recipient email address.
	 *     @type string $subject     Email subject.
	 *     @type string $body        Email body (sanitized HTML).
	 *     @type string $message_id  Email Message-ID header.
	 *     @type string $in_reply_to Email In-Reply-To header.
	 * }
	 *
	 * @return void
	 */
	public function handle_email_received( $contact, $activity, $tracking, $email_data ) {
		/*
		 * Data structure for automation processing:
		 *
		 * - 'contact': Used by ProcessAutomation::add_contact() to identify the contact
		 * - 'data': Stored in AutomationContactModel.data column for merge tag access
		 *           and used by is_processable() for condition checking
		 *
		 * Merge tags access data via: $automation_contact->get_data('email_data')
		 * Available merge tags: {{messaging:email_subject}}, {{messaging:message_body}}
		 */
		$data = array(
			'contact' => $contact,
			'data'    => array(
				'email_data'  => $email_data,
				'activity_id' => $activity->id,
				'tracking_id' => $tracking->id,
			),
		);

		$this->process( $data );
	}

	/**
	 * Check if trigger should be processed
	 *
	 * Supports subject_contains and message_contains filters.
	 *
	 * @since 1.1.0
	 *
	 * @param AutomationModel $automation Automation Model.
	 * @param array            $args       Arguments.
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$email_data = $args['data']['email_data'] ?? array();

		// Check subject_contains filter if set.
		$subject_contains = $automation->get_setting( 'subject_contains', '' );
		if ( ! empty( $subject_contains ) ) {
			$subject = $email_data['subject'] ?? '';
			if ( stripos( $subject, $subject_contains ) === false ) {
				return false;
			}
		}

		// Check message_contains filter if set.
		$message_contains = $automation->get_setting( 'message_contains', '' );
		if ( ! empty( $message_contains ) ) {
			$body = $email_data['body'] ?? '';
			if ( stripos( $body, $message_contains ) === false ) {
				return false;
			}
		}

		return parent::is_processable( $automation, $args );
	}

	/**
	 * Get fields for trigger configuration
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'subject_contains' => array(
				'label'       => __( 'Subject Contains', 'doublescale'),
				'type'        => 'text',
				'placeholder' => __( 'Optional keyword to match in subject', 'doublescale'),
				'description' => __( 'Only trigger if the email subject contains this text (case-insensitive). Leave empty to trigger on any email.', 'doublescale'),
			),
			'message_contains' => array(
				'label'       => __( 'Message Contains', 'doublescale'),
				'type'        => 'text',
				'placeholder' => __( 'Optional keyword to match in body', 'doublescale'),
				'description' => __( 'Only trigger if the email body contains this text (case-insensitive). Leave empty to trigger on any email.', 'doublescale'),
			),
		);
	}
}
