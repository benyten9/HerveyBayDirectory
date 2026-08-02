<?php
/**
 * Form Notifications Handler
 * Listens to form submission events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * FormNotifications class
 *
 * Handles notification creation for form-related events:
 * - Form submitted (new contact via form)
 *
 * @listens doublescale_form_submitted Fired from Form abstract class
 *
 * @since 1.2.0
 */
class FormNotifications {

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::FORMS ) ) {
			return;
		}
		add_action( 'doublescale_form_submitted', array( $this, 'on_form_submitted' ), 10, 1 );
	}

	/**
	 * Handle form submitted event
	 *
	 * Broadcasts to all CRM users when a form submission creates
	 * or updates a contact.
	 *
	 * @since 1.2.0
	 *
	 * @param int $contact_id The contact ID that was created/updated.
	 */
	public function on_form_submitted( $contact_id ) {
		if ( ! $contact_id ) {
			return;
		}

		$contact = ContactModel::find( $contact_id );
		if ( ! $contact ) {
			return;
		}

		$contact_name = $this->get_contact_name( $contact );

		NotificationService::broadcast(
			__( 'New Form Submission', 'doublescale' ),
			/* translators: %s: contact name or email */
			sprintf( __( '%s submitted a form.', 'doublescale' ), $contact_name ),
			$this->get_contact_link( $contact ),
			NotificationCategories::FORMS_SUBMISSION
		);
	}

	/**
	 * Get contact display name
	 *
	 * @since 1.2.0
	 *
	 * @param ContactModel $contact The contact.
	 * @return string Contact name or email.
	 */
	private function get_contact_name( $contact ) {
		$name = trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) );
		return '' !== $name ? $name : $contact->email;
	}

	/**
	 * Get link to contact in admin
	 *
	 * @since 1.2.0
	 *
	 * @param ContactModel $contact The contact.
	 * @return string Admin URL to contact.
	 */
	private function get_contact_link( $contact ) {
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=contacts&id=' . $contact->id ),
			'mobile' => '/contacts/' . $contact->id,
		);
	}
}
