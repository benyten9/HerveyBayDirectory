<?php
/**
 * Message Body Merge Tag
 *
 * Returns the body/content of the received Sms or WhatsApp message.
 *
 * This merge tag is part of the "messaging" group and is available when using
 * the "Whatsapp Received" or "Sms Received" automation triggers.
 *
 * Usage: {{messaging:message_body}}
 *
 * Data Source:
 * - For WhatsApp: $automation_contact->get_data('whatsapp_data')['message_body']
 * - For Sms: $automation_contact->get_data('sms_data')['message_body']
 *
 * The data structure for both channels:
 * ```
 * array(
 *     'from_number'  => '+1234567890',     // Sender's phone number
 *     'to_number'    => '+0987654321',     // Your business number
 *     'message_body' => 'Hello world',     // Message content
 *     'message_id'   => 'wamid.xxx...',    // Provider message ID
 *     'media_urls'   => array(),           // Array of media attachment URLs
 * )
 * ```
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 * @see \DoubleScale\Pro\Modules\Automations\Triggers\WhatsappReceived
 * @see \DoubleScale\Pro\Modules\Automations\Triggers\SmsReceived
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Message Body Merge Tag
 */
class MessageBody extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Message Body';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'message_body';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'The content of the received message';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'messaging';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array( 'whatsapp_received', 'sms_received', 'email_received' );

	/**
	 * Get Merge Tag Value
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel|\DoubleScale\Modules\Contacts\Models\ContactModel $contact Contact Model.
	 * @param string                                                                    $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		// Try WhatsApp data first
		$whatsapp_data = $contact->get_data( 'whatsapp_data' );
		if ( $whatsapp_data && isset( $whatsapp_data['message_body'] ) ) {
			return $whatsapp_data['message_body'];
		}

		// Fall back to Sms data
		$sms_data = $contact->get_data( 'sms_data' );
		if ( $sms_data && isset( $sms_data['message_body'] ) ) {
			return $sms_data['message_body'];
		}

		// Fall back to email data
		$email_data = $contact->get_data( 'email_data' );
		if ( $email_data && isset( $email_data['body'] ) ) {
			return $email_data['body'];
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new MessageBody() );
