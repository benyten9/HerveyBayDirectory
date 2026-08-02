<?php
/**
 * Media URLs Merge Tag
 *
 * Returns comma-separated list of media URLs attached to the received message.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Media URLs Merge Tag
 */
class MediaUrls extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Media URLs';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'media_urls';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Comma-separated list of media URLs attached to the message';

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
		$media_urls = array();

		// Try WhatsApp data first
		$whatsapp_data = $contact->get_data( 'whatsapp_data' );
		if ( $whatsapp_data && ! empty( $whatsapp_data['media_urls'] ) ) {
			$media_urls = $whatsapp_data['media_urls'];
		}

		// Fall back to Sms data
		if ( empty( $media_urls ) ) {
			$sms_data = $contact->get_data( 'sms_data' );
			if ( $sms_data && ! empty( $sms_data['media_urls'] ) ) {
				$media_urls = $sms_data['media_urls'];
			}
		}

		if ( ! empty( $media_urls ) && is_array( $media_urls ) ) {
			return implode( ', ', $media_urls );
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new MediaUrls() );
