<?php
/**
 * Logs contact file attachment events to the contact activity timeline.
 *
 * @package DoubleScale\Modules\Contacts
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * ContactAttachmentActivityLogger class.
 */
class ContactAttachmentActivityLogger {

	/**
	 * Register WP action listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_contact_file_attached', array( $this, 'on_file_attached' ), 10, 2 );
		add_action( 'doublescale_contact_file_removed', array( $this, 'on_file_removed' ), 10, 2 );
	}

	/**
	 * @param ContactModel    $contact    Parent contact.
	 * @param AttachmentModel $attachment Attached file.
	 */
	public function on_file_attached( $contact, $attachment ): void {
		if ( ! $contact instanceof ContactModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_contact_file_event( $contact, $attachment, ActivityTypes::FILE_ATTACHED );
	}

	/**
	 * @param ContactModel    $contact    Parent contact.
	 * @param AttachmentModel $attachment Removed file.
	 */
	public function on_file_removed( $contact, $attachment ): void {
		if ( ! $contact instanceof ContactModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_contact_file_event( $contact, $attachment, ActivityTypes::FILE_REMOVED );
	}

	/**
	 * Write a file attachment activity associated with the given contact.
	 *
	 * @param ContactModel    $contact    Parent contact.
	 * @param AttachmentModel $attachment Attachment row.
	 * @param string          $event_type Activity type constant.
	 */
	private function log_contact_file_event( ContactModel $contact, AttachmentModel $attachment, string $event_type ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		$shaped = ( new AttachmentService() )->shape_for_api( $attachment );

		ActivityModel::create(
			array(
				'contact_id'    => (int) $contact->id,
				'activity_type' => $event_type,
				'data'          => array(
					'contact_id'    => (int) $contact->id,
					'file_name'     => (string) $attachment->file_name,
					'attachment_id' => (int) $attachment->id,
					'file_size'     => (int) $attachment->file_size,
					'file_type'     => (string) $attachment->file_type,
					'url'           => (string) ( $shaped['url'] ?? '' ),
				),
				'user_id'       => get_current_user_id(),
			)
		);
	}
}
