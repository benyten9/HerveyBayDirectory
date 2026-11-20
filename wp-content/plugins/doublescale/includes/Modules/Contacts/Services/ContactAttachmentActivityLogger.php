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
		add_action( 'doublescale_contact_file_attached', array( $this, 'on_file_attached' ), 10, 3 );
		add_action( 'doublescale_contact_file_removed', array( $this, 'on_file_removed' ), 10, 3 );
	}

	/**
	 * @param ContactModel         $contact    Parent contact.
	 * @param AttachmentModel      $attachment Attached file.
	 * @param array<string, mixed> $context    `actor` => 'contact' when the contact did it (client portal).
	 */
	public function on_file_attached( $contact, $attachment, $context = array() ): void {
		if ( ! $contact instanceof ContactModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_contact_file_event( $contact, $attachment, ActivityTypes::FILE_ATTACHED, is_array( $context ) ? $context : array() );
	}

	/**
	 * @param ContactModel         $contact    Parent contact.
	 * @param AttachmentModel      $attachment Removed file.
	 * @param array<string, mixed> $context    `actor` => 'contact' when the contact did it (client portal).
	 */
	public function on_file_removed( $contact, $attachment, $context = array() ): void {
		if ( ! $contact instanceof ContactModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_contact_file_event( $contact, $attachment, ActivityTypes::FILE_REMOVED, is_array( $context ) ? $context : array() );
	}

	/**
	 * Write a file attachment activity associated with the given contact.
	 *
	 * A contact acting from the client portal is not a team member, so the
	 * row carries no `user_id`; `actor`/`actor_name` let the timeline name them.
	 *
	 * @param ContactModel         $contact    Parent contact.
	 * @param AttachmentModel      $attachment Attachment row.
	 * @param string               $event_type Activity type constant.
	 * @param array<string, mixed> $context    `actor` => 'contact' for client-portal actions.
	 */
	private function log_contact_file_event( ContactModel $contact, AttachmentModel $attachment, string $event_type, array $context = array() ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		$service  = new AttachmentService();
		$shaped   = $service->shape_for_api( $attachment );
		$by_owner = 'contact' === ( $context['actor'] ?? '' );

		$data = array(
			'contact_id'    => (int) $contact->id,
			'file_name'     => (string) $attachment->file_name,
			'attachment_id' => (int) $attachment->id,
			'file_size'     => (int) $attachment->file_size,
			'file_type'     => (string) $attachment->file_type,
			'url'           => (string) ( $shaped['url'] ?? '' ),
		);

		if ( $by_owner ) {
			$data['actor']      = 'contact';
			$data['actor_name'] = $this->contact_name( $contact );
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $contact->id,
				'activity_type' => $event_type,
				'data'          => $data,
				'user_id'       => $by_owner ? null : get_current_user_id(),
			)
		);
	}

	/**
	 * Display name for the contact: full name, else email.
	 *
	 * @param ContactModel $contact Contact.
	 * @return string
	 */
	private function contact_name( ContactModel $contact ): string {
		$name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
		return '' !== $name ? $name : (string) $contact->email;
	}
}
