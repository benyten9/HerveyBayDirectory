<?php
/**
 * Recipient Number Merge Tag
 *
 * Returns the phone number that received the Sms or WhatsApp message (your business number).
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Recipient Number Merge Tag
 */
class RecipientNumber extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Recipient Number';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'to_number';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'The phone number that received the message (your business number)';

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
		if ( $whatsapp_data && isset( $whatsapp_data['to_number'] ) ) {
			return $whatsapp_data['to_number'];
		}

		// Fall back to Sms data
		$sms_data = $contact->get_data( 'sms_data' );
		if ( $sms_data && isset( $sms_data['to_number'] ) ) {
			return $sms_data['to_number'];
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new RecipientNumber() );
