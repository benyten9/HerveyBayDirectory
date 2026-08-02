<?php
/**
 * Message Channel Merge Tag
 *
 * Returns the channel type (Whatsapp or Sms) of the received message.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Message Channel Merge Tag
 */
class MessageChannel extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Message Channel';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'channel';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'The channel the message was received on (Whatsapp/Sms)';

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
		// Check which data is present to determine channel
		$whatsapp_data = $contact->get_data( 'whatsapp_data' );
		if ( $whatsapp_data && ! empty( $whatsapp_data ) ) {
			return __( 'WhatsApp', 'doublescale');
		}

		$sms_data = $contact->get_data( 'sms_data' );
		if ( $sms_data && ! empty( $sms_data ) ) {
			return __( 'Sms', 'doublescale');
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new MessageChannel() );
