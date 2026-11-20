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

		$contact      = $automation_contact->contact;
		$contact_name = $contact ? $this->get_contact_name( $contact ) : '#' . $automation_contact->contact_id;

		// Broadcast to all CRM users (automations run in background).
		NotificationService::broadcast(
			/* translators: %s: automation name */
			sprintf( __( 'Automation "%s" Step Failed', 'doublescale'), $automation->name ),
			/* translators: 1: step name, 2: contact name */
			sprintf(
				__( 'Step "%1$s" failed for %2$s.', 'doublescale'),
				$step_name,
				$contact_name
			),
			$this->get_automation_link( $automation ),
			NotificationCategories::AUTOMATIONS_ERRORS,
			array(),
			$this->users_outside( $automation )
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
			NotificationCategories::AUTOMATIONS_STARTED,
			array(),
			$this->users_outside( $automation )
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
			NotificationCategories::AUTOMATIONS_COMPLETED,
			array(),
			$this->users_outside( $automation )
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
			NotificationCategories::AUTOMATIONS_PAUSED,
			array(),
			$this->users_outside( $automation )
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
		// The name the step shows on the canvas: a custom name, else its action's label ("Send SMS").
		$settings = is_array( $step->settings ?? null ) ? $step->settings : array();
		foreach ( array( 'custom_label', '_action_label', '_goal_label' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				return trim( $settings[ $key ] );
			}
		}

		$action = $step->action ?? '';
		if ( $action ) {
			try {
				return \DoubleScale\Modules\Automations\Services\ActionsManager::instance()->get_action( $action )->name;
			} catch ( \Throwable $e ) {
				return ucfirst( str_replace( array( '_', '-' ), ' ', $action ) );
			}
		}

		return ucfirst( $step->type ?? 'action' );
	}

	/**
	 * CRM users who should not hear about this workflow: everyone except the
	 * people who manage every automation (administrators, CRM Managers,
	 * Automation Managers) and the workflow's own creator.
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationModel $automation The automation.
	 * @return array<int, int> User IDs to leave out.
	 */
	private function users_outside( $automation ) {
		$owner = (int) ( $automation->created_by ?? 0 );
		$out   = array();
		foreach ( get_users( array( 'capability__in' => array( 'doublescale_access' ), 'fields' => 'ID' ) ) as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id === $owner || \DoubleScale\Core\UserRoles\Permissions::can_manage_all_automations( $user_id ) ) {
				continue;
			}
			$out[] = $user_id;
		}
		return $out;
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
