<?php
/**
 * Automation Notifications Handler
 * Listens to automation events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * AutomationNotifications class
 *
 * Handles notification creation for automation-related events:
 * - Automation step failed (error)
 * - Contact entered automation (started)
 * - Contact completed automation (completed)
 * - Automation paused (paused)
 *
 * @listens doublescale_automation_step_failure Fired when a step fails
 * @listens doublescale_automation_contact_enter Fired when contact enters automation
 * @listens doublescale_automation_contact_complete Fired when contact completes automation
 * @listens doublescale_automation_paused Fired when automation is paused
 *
 * @since 1.2.0
 */
class AutomationNotifications {

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::AUTOMATIONS ) ) {
			return;
		}
		add_action( 'doublescale_automation_step_failure', array( $this, 'on_step_failed' ), 10, 3 );
		add_action( 'doublescale_automation_contact_enter', array( $this, 'on_contact_entered' ), 10, 2 );
		add_action( 'doublescale_automation_contact_complete', array( $this, 'on_contact_completed' ), 10, 2 );
		add_action( 'doublescale_automation_paused', array( $this, 'on_automation_paused' ), 10, 2 );
	}

	/**
	 * Handle automation step failed event
	 *
	 * Broadcasts to all CRM users since automations run in background
	 * and the model doesn't track ownership.
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationModel         $automation         The automation.
	 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel $automation_contact The automation contact.
	 * @param int                                       $step_id            The failed step ID.
	 */
	public function on_step_failed( $automation, $automation_contact, $step_id ) {
		// Get step info for the notification.
		$step = $automation->steps()->where( 'id', $step_id )->first();
		$step_name = $step ? $this->get_step_name( $step ) : __( 'Unknown step', 'doublescale');

		// Broadcast to all CRM users (automations run in background).
		NotificationService::broadcast(
			/* translators: %s: automation name */
			sprintf( __( 'Automation "%s" Step Failed', 'doublescale'), $automation->name ),
			/* translators: 1: step name, 2: contact info */
			sprintf(
				__( 'Step "%1$s" failed for contact #%2$d.', 'doublescale'),
				$step_name,
				$automation_contact->contact_id
			),
			$this->get_automation_link( $automation ),
			NotificationCategories::AUTOMATIONS_ERRORS
		);
	}

	/**
	 * Get link to automation in admin
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationModel $automation Automation model.
	 * @return string Admin URL to automation.
	 */
	private function get_automation_link( $automation ) {
		return array(
			'web'    => admin_url( "admin.php?page=doublescale&path=automations&id={$automation->id}" ),
			'mobile' => null,
		);
	}

	/**
	 * Handle contact entered automation event
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationModel         $automation The automation.
	 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel $automation_contact The automation contact record.
	 */
	public function on_contact_entered( $automation, $automation_contact ) {
		$contact = $automation_contact->contact;
		$contact_name = $contact ? $this->get_contact_name( $contact ) : '#' . $automation_contact->contact_id;

		NotificationService::broadcast(
			/* translators: %s: automation name */
			sprintf( __( 'Contact Entered "%s"', 'doublescale'), $automation->name ),
			/* translators: 1: contact name, 2: automation name */
			sprintf( __( '%1$s has entered the %2$s automation.', 'doublescale'), $contact_name, $automation->name ),
			$this->get_automation_link( $automation ),
			NotificationCategories::AUTOMATIONS_STARTED
		);
	}

	/**
	 * Handle contact completed automation event
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationModel         $automation The automation.
	 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel $automation_contact The automation contact record.
	 */
	public function on_contact_completed( $automation, $automation_contact ) {
		$contact = $automation_contact->contact;
		$contact_name = $contact ? $this->get_contact_name( $contact ) : '#' . $automation_contact->contact_id;

		NotificationService::broadcast(
			/* translators: %s: automation name */
			sprintf( __( 'Automation "%s" Completed', 'doublescale'), $automation->name ),
			/* translators: 1: contact name, 2: automation name */
			sprintf( __( '%1$s has completed the %2$s automation.', 'doublescale'), $contact_name, $automation->name ),
			$this->get_automation_link( $automation ),
			NotificationCategories::AUTOMATIONS_COMPLETED
		);
	}

	/**
	 * Handle automation paused event
	 *
	 * @since 1.2.0
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationModel $automation The automation.
	 * @param string                            $reason     Reason for pausing (e.g., 'limit_reached', 'manual').
	 */
	public function on_automation_paused( $automation, $reason = '' ) {
		$message = __( 'The automation has been paused.', 'doublescale');

		if ( 'limit_reached' === $reason ) {
			$message = __( 'The automation has been paused because it reached its contact limit.', 'doublescale');
		} elseif ( 'manual' === $reason ) {
			$message = __( 'The automation has been manually paused.', 'doublescale');
		} elseif ( ! empty( $reason ) ) {
			/* translators: %s: reason for pausing */
			$message = sprintf( __( 'The automation has been paused: %s', 'doublescale'), $reason );
		}

		NotificationService::broadcast(
			/* translators: %s: automation name */
			sprintf( __( 'Automation "%s" Paused', 'doublescale'), $automation->name ),
			$message,
			$this->get_automation_link( $automation ),
			NotificationCategories::AUTOMATIONS_PAUSED
		);
	}

	/**
	 * Get human-readable step name
	 *
	 * @since 1.2.0
	 *
	 * @param object $step Automation step.
	 * @return string Step name or type.
	 */
	private function get_step_name( $step ) {
		if ( ! empty( $step->name ) ) {
			return $step->name;
		}

		// Fallback to step type.
		$type = $step->type ?? 'action';
		$slug = $step->slug ?? '';

		if ( $slug ) {
			return ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) );
		}

		return ucfirst( $type );
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
}
