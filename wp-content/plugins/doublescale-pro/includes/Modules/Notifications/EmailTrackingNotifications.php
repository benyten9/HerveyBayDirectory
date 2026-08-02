<?php
/**
 * Email Tracking Notifications Handler
 * Listens to email tracking events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * EmailTrackingNotifications class
 *
 * Handles notification creation for email tracking events:
 * - Email opened
 * - Link clicked
 * - Email bounced
 *
 * Note: Email reply detection is not implemented as it requires
 * integration with an email inbox (future feature).
 *
 * @listens doublescale_mail_open Fired from Email tracking class
 * @listens doublescale_mail_click Fired from Email tracking class
 *
 * @since 1.2.0
 */
class EmailTrackingNotifications {

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::EMAIL_TRACKING ) ) {
			return;
		}
		add_action( 'doublescale_mail_open', array( $this, 'on_email_opened' ), 10, 1 );
		add_action( 'doublescale_mail_click', array( $this, 'on_email_clicked' ), 10, 1 );
	}

	/**
	 * Handle email opened event
	 *
	 * Broadcasts to all CRM users since email opens are tracked
	 * in the context of campaigns/automations which don't have owners.
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact The contact who opened the email.
	 */
	public function on_email_opened( $contact ) {
		if ( ! $contact ) {
			return;
		}

		$contact_name = $this->get_contact_name( $contact );

		NotificationService::broadcast(
			/* translators: %s: contact name */
			sprintf( __( 'Email Opened by %s', 'doublescale'), $contact_name ),
			/* translators: %s: contact email */
			sprintf( __( '%s just opened your email.', 'doublescale'), $contact->email ),
			$this->get_contact_link( $contact ),
			NotificationCategories::EMAIL_TRACKING_OPENED
		);
	}

	/**
	 * Handle email link clicked event
	 *
	 * Broadcasts to all CRM users since email clicks are tracked
	 * in the context of campaigns/automations which don't have owners.
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact The contact who clicked.
	 */
	public function on_email_clicked( $contact ) {
		if ( ! $contact ) {
			return;
		}

		$contact_name = $this->get_contact_name( $contact );

		NotificationService::broadcast(
			/* translators: %s: contact name */
			sprintf( __( 'Link Clicked by %s', 'doublescale'), $contact_name ),
			/* translators: %s: contact email */
			sprintf( __( '%s clicked a link in your email.', 'doublescale'), $contact->email ),
			$this->get_contact_link( $contact ),
			NotificationCategories::EMAIL_TRACKING_CLICKED
		);
	}

	/**
	 * Get contact display name
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact The contact.
	 * @return string Contact name or email.
	 */
	private function get_contact_name( $contact ) {
		$name = trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) );
		return $name ?: $contact->email;
	}

	/**
	 * Get link to contact in admin
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact The contact.
	 * @return string Admin URL to contact.
	 */
	private function get_contact_link( $contact ) {
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=contacts&id=' . $contact->id ),
			'mobile' => '/contacts/' . $contact->id,
		);
	}
}
