<?php
/**
 * Message ID Merge Tag
 *
 * Returns the external provider message ID for the received Sms or WhatsApp message.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Message ID Merge Tag
 */
class MessageId extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Message ID';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'message_id';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'The provider message ID';

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
	public $required_triggers = array( 'whatsapp_received', 'sms_received' );

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
		if ( $whatsapp_data && isset( $whatsapp_data['message_id'] ) ) {
			return $whatsapp_data['message_id'];
		}

		// Fall back to Sms data
		$sms_data = $contact->get_data( 'sms_data' );
		if ( $sms_data && isset( $sms_data['message_id'] ) ) {
			return $sms_data['message_id'];
		}

		// Also check for message_sid (Twilio uses this)
		if ( $sms_data && isset( $sms_data['message_sid'] ) ) {
			return $sms_data['message_sid'];
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new MessageId() );
