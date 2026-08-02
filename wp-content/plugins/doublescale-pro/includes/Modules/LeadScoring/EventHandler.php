<?php

/**
 * Class EventHandler
 *
 * Hook into common events to recalculate lead score
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\LeadScoring\LeadScoringManager;

/**
 * EventHandler class
 */
class EventHandler {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		 $this->add_common_event_actions();
	}

	/**
	 * Add common event actions that should trigger lead score recalculation
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_common_event_actions() {
		// Email tracking events
		add_action( 'doublescale_mail_open', array( $this, 'handle_contact' ), 99, 1 );
		add_action( 'doublescale_mail_click', array( $this, 'handle_contact' ), 99, 1 );
		add_action( 'doublescale_form_submitted', array( $this, 'handle_contact' ), 99, 1 );
		add_action( 'doublescale_page_visited', array( $this, 'handle_contact' ), 99, 1 );
		add_action( 'doublescale_contact_update', array( $this, 'handle_contact' ), 99, 1 );
	}

	/**
	 * Remove common event actions
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function remove_common_event_actions() {
		 // Email tracking events
		remove_action( 'doublescale_mail_open', array( $this, 'handle_contact' ), 99 );
		remove_action( 'doublescale_mail_click', array( $this, 'handle_contact' ), 99 );
		remove_action( 'doublescale_form_submitted', array( $this, 'handle_contact' ), 99 );
		remove_action( 'doublescale_page_visited', array( $this, 'handle_contact' ), 99 );
		remove_action( 'doublescale_contact_update', array( $this, 'handle_contact' ), 99 );
	}

	/**
	 * Handle contact - recalculate lead score
	 *
	 * @since 1.0.0
	 *
	 * @param int|ContactModel $contact Contact ID or Contact Model
	 *
	 * @return void
	 */
	public function handle_contact( $contact ) {
		// Get contact model if ID is provided
		if ( is_numeric( $contact ) ) {
			$contact = ContactModel::find( $contact );
		}

		// Validate contact
		if ( ! $contact || ! $contact instanceof ContactModel || ! $contact->exists ) {
			return;
		}

		// Recalculate lead score
		LeadScoringManager::get_lead_score( $contact );
	}

	/**
	 * Disable event handler temporarily (for bulk operations)
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function disable() {
		 $this->remove_common_event_actions();
	}

	/**
	 * Re-enable event handler
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enable() {
		$this->add_common_event_actions();
	}
}
