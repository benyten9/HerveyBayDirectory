<?php
/**
 * Email Subject Merge Tag
 *
 * Returns the subject of the received email.
 *
 * Usage: {{messaging:email_subject}}
 *
 * Data Source:
 * - $automation_contact->get_data('email_data')['subject']
 *
 * @since 1.1.0
 * @package DoubleScale\Pro\Pro
 * @see \DoubleScale\Pro\Modules\Automations\Triggers\EmailReceived
 */

namespace DoubleScale\Pro\Modules\Inbox\MergeTags\Messaging;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Email Subject Merge Tag
 */
class EmailSubject extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Email Subject';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'email_subject';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'The subject line of the received email';

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
	public $required_triggers = array( 'email_received' );

	/**
	 * Get Merge Tag Value
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel|\DoubleScale\Modules\Contacts\Models\ContactModel $contact   Contact Model.
	 * @param string                                                                    $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$email_data = $contact->get_data( 'email_data' );
		if ( $email_data && isset( $email_data['subject'] ) ) {
			return $email_data['subject'];
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new EmailSubject() );
