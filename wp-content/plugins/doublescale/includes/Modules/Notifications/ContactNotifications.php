<?php
/**
 * Contact Notifications Handler
 * Listens to contact events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * ContactNotifications class
 *
 * Handles notification creation for contact-related events:
 * - Contact subscribed
 * - Contact unsubscribed
 * - Contact bounced (hard bounce)
 * - Contact export completed
 *
 * @listens doublescale_contact_subscribe Fired from ContactModel
 * @listens doublescale_contact_unsubscribe Fired from ContactModel
 * @listens doublescale_contact_bounced Fired from BounceHandler
 * @listens doublescale_export_completed Fired from Export class
 *
 * @since 1.2.0
 */
class ContactNotifications {

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		add_action( 'doublescale_contact_subscribe', array( $this, 'on_contact_subscribed' ), 10, 1 );
		add_action( 'doublescale_contact_unsubscribe', array( $this, 'on_contact_unsubscribed' ), 10, 1 );
		add_action( 'doublescale_contact_bounced', array( $this, 'on_contact_bounced' ), 10, 3 );
		add_action( 'doublescale_export_completed', array( $this, 'on_export_completed' ), 10, 3 );
	}

	/**
	 * Handle contact subscribed event
	 *
	 * Broadcasts to all CRM users when a contact subscribes.
	 *
	 * @since 1.2.0
	 *
	 * @param ContactModel $contact The contact who subscribed.
	 */
	public function on_contact_subscribed( $contact ) {
		if ( ! $contact ) {
			return;
		}

		$contact_name = $this->get_contact_name( $contact );

		NotificationService::broadcast(
			__( 'New Subscriber', 'doublescale' ),
			/* translators: %s: contact name or email */
			sprintf( __( '%s has subscribed.', 'doublescale' ), $contact_name ),
			$this->get_contact_link( $contact ),
			NotificationCategories::CONTACTS_SUBSCRIBED
		);
	}

	/**
	 * Handle contact unsubscribed event
	 *
	 * Broadcasts to all CRM users when a contact unsubscribes.
	 * This is an important event to track.
	 *
	 * @since 1.2.0
	 *
	 * @param ContactModel $contact The contact who unsubscribed.
	 */
	public function on_contact_unsubscribed( $contact ) {
		if ( ! $contact ) {
			return;
		}

		$contact_name = $this->get_contact_name( $contact );

		NotificationService::broadcast(
			__( 'Contact Unsubscribed', 'doublescale' ),
			/* translators: %s: contact name or email */
			sprintf( __( '%s has unsubscribed from emails.', 'doublescale' ), $contact_name ),
			$this->get_contact_link( $contact ),
			NotificationCategories::CONTACTS_UNSUBSCRIBED
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
	 * Handle contact bounced event
	 *
	 * Broadcasts to all CRM users when a contact's email hard bounces.
	 * This is important for deliverability awareness.
	 *
	 * @since 1.2.0
	 *
	 * @param ContactModel $contact    The contact that bounced.
	 * @param string       $old_status Previous email status.
	 * @param array        $metadata   Bounce metadata (reason, type, etc.).
	 */
	public function on_contact_bounced( $contact, $old_status, $metadata ) {
		if ( ! $contact ) {
			return;
		}

		$contact_name = $this->get_contact_name( $contact );
		$reason       = $metadata['reason'] ?? __( 'Email bounced', 'doublescale' );

		NotificationService::broadcast(
			/* translators: %s: contact email */
			sprintf( __( 'Email Bounced: %s', 'doublescale' ), $contact->email ),
			/* translators: 1: contact name, 2: bounce reason */
			sprintf( __( '%1$s has been marked as bounced. Reason: %2$s', 'doublescale' ), $contact_name, $reason ),
			$this->get_contact_link( $contact ),
			NotificationCategories::CONTACTS_BOUNCED
		);
	}

	/**
	 * Handle export completed event
	 *
	 * Notifies the user who initiated the export when it completes.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id        User who initiated the export.
	 * @param int $exported_count Number of contacts exported.
	 * @param int $total          Total contacts processed.
	 */
	public function on_export_completed( $user_id, $exported_count, $total ) {
		if ( ! $user_id ) {
			return;
		}

		NotificationService::create(
			$user_id,
			__( 'Export Completed', 'doublescale' ),
			/* translators: %d: number of contacts exported */
			sprintf( __( 'Successfully exported %d contacts.', 'doublescale' ), $exported_count ),
			array(
				'web'    => admin_url( 'admin.php?page=doublescale&path=contacts' ),
				'mobile' => '/contacts',
			),
			NotificationCategories::CONTACTS_EXPORT
		);
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
