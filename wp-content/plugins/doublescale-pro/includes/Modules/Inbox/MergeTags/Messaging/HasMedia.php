<?php
/**
 * Has Media Merge Tag
 *
 * Returns "Yes" or "No" based on whether the message contains media attachments.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Has Media Merge Tag
 */
class HasMedia extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Has Media';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'has_media';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Whether the message contains media attachments (Yes/No)';

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
		$has_media = false;

		// Try WhatsApp data first
		$whatsapp_data = $contact->get_data( 'whatsapp_data' );
		if ( $whatsapp_data && ! empty( $whatsapp_data['media_urls'] ) ) {
			$has_media = true;
		}

		// Fall back to Sms data
		if ( ! $has_media ) {
			$sms_data = $contact->get_data( 'sms_data' );
			if ( $sms_data && ! empty( $sms_data['media_urls'] ) ) {
				$has_media = true;
			}
		}

		return $has_media ? __( 'Yes', 'doublescale') : __( 'No', 'doublescale');
	}
}

MergeTagsManager::instance()->register( new HasMedia() );
