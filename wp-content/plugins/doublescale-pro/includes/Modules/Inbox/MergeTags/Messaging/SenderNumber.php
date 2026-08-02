<?php
/**
 * Sender Number Merge Tag
 *
 * Returns the phone number that sent the Sms or WhatsApp message.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Sender Number Merge Tag
 */
class SenderNumber extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Sender Number';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'from_number';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'The phone number that sent the message';

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
		if ( $whatsapp_data && isset( $whatsapp_data['from_number'] ) ) {
			return $whatsapp_data['from_number'];
		}

		// Fall back to Sms data
		$sms_data = $contact->get_data( 'sms_data' );
		if ( $sms_data && isset( $sms_data['from_number'] ) ) {
			return $sms_data['from_number'];
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new SenderNumber() );
