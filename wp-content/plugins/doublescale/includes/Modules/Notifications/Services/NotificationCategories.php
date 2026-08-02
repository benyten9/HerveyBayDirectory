<?php
/**
 * Notification Categories Registry
 * Defines available notification categories for user preferences
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Services;

/**
 * NotificationCategories class
 *
 * Registry of notification categories that users can configure
 * preferences for (bell/email toggles per category).
 *
 * @since 1.2.0
 */
class NotificationCategories {

	/**
	 * Campaign-related notifications
	 *
	 * @var string
	 */
	const CAMPAIGNS = 'campaigns';

	/**
	 * Automation-related notifications
	 *
	 * @var string
	 */
	const AUTOMATIONS = 'automations';

	/**
	 * Contact-related notifications (imports, exports)
	 *
	 * @var string
	 */
	const CONTACTS = 'contacts';

	/**
	 * Pipeline-related notifications (deals, stages)
	 *
	 * @var string
	 */
	const PIPELINE = 'pipeline';

	/**
	 * Task-related notifications (reminders, assignments)
	 *
	 * @var string
	 */
	const TASKS = 'tasks';

	/**
	 * System notifications (license, daily summary)
	 *
	 * @var string
	 */
	const SYSTEM = 'system';

	/**
	 * Email tracking notifications (opens, clicks, bounces)
	 *
	 * @var string
	 */
	const EMAIL_TRACKING = 'email_tracking';

	/**
	 * Form-related notifications (submissions, conversions)
	 *
	 * @var string
	 */
	const FORMS = 'forms';

	/**
	 * Integration notifications (connections, errors)
	 *
	 * @var string
	 */
	const INTEGRATIONS = 'integrations';

	/**
	 * Booking-related notifications (new bookings, cancellations, reschedules)
	 *
	 * @var string
	 */
	const BOOKING = 'booking';

	/**
	 * Support-related notifications (tickets) — agent-facing.
	 *
	 * Distinct from the Free Support module's outbound customer emails: this
	 * category drives the in-app/email/push notifications that alert CRM
	 * agents about ticket activity (see SupportNotifications). The whole
	 * Notifications module is Pro, so this section only exists when Pro runs.
	 *
	 * @var string
	 */
	const SUPPORT = 'support';

	/**
	 * Sales-related notifications (proposals, invoices) — rep-facing.
	 *
	 * @var string
	 */
	const SALES = 'sales';

	/**
	 * Project-related notifications (lifecycle, assignment, comments).
	 *
	 * @var string
	 */
	const PROJECTS = 'projects';

	/**
	 * Pipeline subcategory: Deal won or lost
	 *
	 * @var string
	 */
	const PIPELINE_DEAL_WON_LOST = 'deal_won_lost';

	/**
	 * Pipeline subcategory: Deal stage changed
	 *
	 * @var string
	 */
	const PIPELINE_DEAL_STAGE_CHANGED = 'deal_stage_changed';

	/**
	 * Pipeline subcategory: Deal assigned
	 *
	 * @var string
	 */
	const PIPELINE_DEAL_ASSIGNED = 'deal_assigned';

	/**
	 * Pipeline subcategory: Deal unassigned
	 *
	 * @var string
	 */
	const PIPELINE_DEAL_UNASSIGNED = 'deal_unassigned';

	/**
	 * Pipeline subcategory: Deal overdue
	 *
	 * @var string
	 */
	const PIPELINE_DEAL_OVERDUE = 'deal_overdue';

	/**
	 * Tasks subcategory: Task reminder
	 *
	 * @var string
	 */
	const TASKS_REMINDER = 'task_reminder';

	/**
	 * Tasks subcategory: Task assigned to you
	 *
	 * @var string
	 */
	const TASKS_ASSIGNED = 'task_assigned';

	/**
	 * Tasks subcategory: New comment on a task you are assigned to
	 *
	 * @var string
	 */
	const TASKS_COMMENT = 'task_comment';

	/**
	 * Tasks subcategory: You were @mentioned in a task comment
	 *
	 * @var string
	 */
	const TASKS_COMMENT_MENTION = 'task_comment_mention';

	/**
	 * Campaigns subcategory: Email campaigns
	 *
	 * @var string
	 */
	const CAMPAIGNS_EMAIL = 'email_campaigns';

	/**
	 * Campaigns subcategory: Sms campaigns
	 *
	 * @var string
	 */
	const CAMPAIGNS_SMS = 'sms_campaigns';

	/**
	 * Automations subcategory: Automation errors
	 *
	 * @var string
	 */
	const AUTOMATIONS_ERRORS = 'automation_errors';

	/**
	 * Contacts subcategory: Contact import
	 *
	 * @var string
	 */
	const CONTACTS_IMPORT = 'contact_import';

	/**
	 * System subcategory: System alerts
	 *
	 * @var string
	 */
	const SYSTEM_GENERAL = 'system_general';

	// =========================================================================
	// New Subcategory Constants (Notification Expansion)
	// =========================================================================

	/**
	 * Campaigns subcategory: Email campaign scheduled
	 *
	 * @var string
	 */
	const CAMPAIGNS_EMAIL_SCHEDULED = 'email_campaign_scheduled';

	/**
	 * Automations subcategory: Contact entered automation
	 *
	 * @var string
	 */
	const AUTOMATIONS_STARTED = 'automation_started';

	/**
	 * Automations subcategory: Contact completed automation
	 *
	 * @var string
	 */
	const AUTOMATIONS_COMPLETED = 'automation_completed';

	/**
	 * Automations subcategory: Automation paused
	 *
	 * @var string
	 */
	const AUTOMATIONS_PAUSED = 'automation_paused';

	/**
	 * Contacts subcategory: Export completed
	 *
	 * @var string
	 */
	const CONTACTS_EXPORT = 'contact_export';

	/**
	 * Contacts subcategory: Contact subscribed
	 *
	 * @var string
	 */
	const CONTACTS_SUBSCRIBED = 'contact_subscribed';

	/**
	 * Contacts subcategory: Contact unsubscribed
	 *
	 * @var string
	 */
	const CONTACTS_UNSUBSCRIBED = 'contact_unsubscribed';

	/**
	 * Contacts subcategory: Email bounced
	 *
	 * @var string
	 */
	const CONTACTS_BOUNCED = 'contact_bounced';

	/**
	 * Pipeline subcategory: Deal value changed
	 *
	 * @var string
	 */
	const PIPELINE_DEAL_VALUE_CHANGED = 'deal_value_changed';

	/**
	 * Pipeline subcategory: Note added to deal
	 *
	 * @var string
	 */
	const PIPELINE_DEAL_NOTE_ADDED = 'deal_note_added';

	/**
	 * Tasks subcategory: Task completed
	 *
	 * @var string
	 */
	const TASKS_COMPLETED = 'task_completed';

	/**
	 * Tasks subcategory: Task overdue
	 *
	 * @var string
	 */
	const TASKS_OVERDUE = 'task_overdue';

	/**
	 * Email tracking subcategory: Email opened
	 *
	 * @var string
	 */
	const EMAIL_TRACKING_OPENED = 'email_opened';

	/**
	 * Email tracking subcategory: Link clicked
	 *
	 * @var string
	 */
	const EMAIL_TRACKING_CLICKED = 'email_clicked';

	/**
	 * Forms subcategory: Form submitted
	 *
	 * @var string
	 */
	const FORMS_SUBMISSION = 'form_submission';

	/**
	 * Integrations subcategory: Integration connected
	 *
	 * @var string
	 */
	const INTEGRATIONS_CONNECTED = 'integration_connected';

	/**
	 * Integrations subcategory: Integration disconnected
	 *
	 * @var string
	 */
	const INTEGRATIONS_DISCONNECTED = 'integration_disconnected';

	/**
	 * Integrations subcategory: Sync error
	 *
	 * @var string
	 */
	const INTEGRATIONS_SYNC_ERROR = 'integration_sync_error';

	/**
	 * System subcategory: License expiring
	 *
	 * @var string
	 */
	const SYSTEM_LICENSE_EXPIRING = 'license_expiring';

	/**
	 * System subcategory: Security alert
	 *
	 * @var string
	 */
	const SYSTEM_SECURITY_ALERT = 'security_alert';

	/**
	 * System subcategory: Daily summary
	 *
	 * @var string
	 */
	const SYSTEM_DAILY_SUMMARY = 'daily_summary';

	/**
	 * Booking subcategory: New booking created
	 *
	 * @var string
	 */
	const BOOKING_CREATED = 'booking_created';

	/**
	 * Booking subcategory: Booking cancelled
	 *
	 * @var string
	 */
	const BOOKING_CANCELLED = 'booking_cancelled';

	/**
	 * Booking subcategory: Booking rescheduled
	 *
	 * @var string
	 */
	const BOOKING_RESCHEDULED = 'booking_rescheduled';

	/**
	 * Support subcategory: New ticket created.
	 *
	 * @var string
	 */
	const SUPPORT_TICKET_CREATED = 'support_ticket_created';

	/**
	 * Support subcategory: Customer replied to a ticket.
	 *
	 * @var string
	 */
	const SUPPORT_CUSTOMER_REPLY = 'support_customer_reply';

	/**
	 * Support subcategory: Ticket assigned to the agent.
	 *
	 * @var string
	 */
	const SUPPORT_TICKET_ASSIGNED = 'support_ticket_assigned';

	/**
	 * Support subcategory: Ticket resolved or closed.
	 *
	 * @var string
	 */
	const SUPPORT_TICKET_RESOLVED = 'support_ticket_resolved';

	/**
	 * Support subcategory: Ticket reopened (status back to open from resolved/closed).
	 *
	 * @var string
	 */
	const SUPPORT_TICKET_REOPENED = 'support_ticket_reopened';

	/**
	 * Sales subcategory: Proposal sent to customer.
	 *
	 * @var string
	 */
	const SALES_PROPOSAL_SENT = 'sales_proposal_sent';

	/**
	 * Sales subcategory: Proposal accepted by customer.
	 *
	 * @var string
	 */
	const SALES_PROPOSAL_ACCEPTED = 'sales_proposal_accepted';

	/**
	 * Sales subcategory: Proposal declined by customer.
	 *
	 * @var string
	 */
	const SALES_PROPOSAL_DECLINED = 'sales_proposal_declined';

	/**
	 * Sales subcategory: Invoice paid in full.
	 *
	 * @var string
	 */
	const SALES_INVOICE_PAID = 'sales_invoice_paid';

	/**
	 * Sales subcategory: Contract sent to customer.
	 *
	 * @var string
	 */
	const SALES_CONTRACT_SENT = 'sales_contract_sent';

	/**
	 * Sales subcategory: Contract signed by customer.
	 *
	 * @var string
	 */
	const SALES_CONTRACT_SIGNED = 'sales_contract_signed';

	/**
	 * Sales subcategory: Document submitted for internal approval.
	 *
	 * @var string
	 */
	const SALES_APPROVAL_REQUESTED = 'sales_approval_requested';

	/**
	 * Sales subcategory: Document approved by a reviewer.
	 *
	 * @var string
	 */
	const SALES_APPROVAL_APPROVED = 'sales_approval_approved';

	/**
	 * Sales subcategory: Document rejected by a reviewer.
	 *
	 * @var string
	 */
	const SALES_APPROVAL_REJECTED = 'sales_approval_rejected';

	/**
	 * Sales subcategory: Prior approval voided because the document was edited.
	 *
	 * @var string
	 */
	const SALES_APPROVAL_INVALIDATED = 'sales_approval_invalidated';

	/**
	 * Sales subcategory: Pending approval withdrawn by the requester.
	 *
	 * @var string
	 */
	const SALES_APPROVAL_WITHDRAWN = 'sales_approval_withdrawn';

	/**
	 * Sales subcategory: Pending approval reset because a manager edited the document.
	 *
	 * @var string
	 */
	const SALES_APPROVAL_PENDING_RESET = 'sales_approval_pending_reset';

	/**
	 * Projects subcategory: New project created.
	 *
	 * @var string
	 */
	const PROJECTS_CREATED = 'project_created';

	/**
	 * Projects subcategory: Project assigned to you (owner changed).
	 *
	 * @var string
	 */
	const PROJECTS_ASSIGNED = 'project_assigned';

	/**
	 * Projects subcategory: Project status changed.
	 *
	 * @var string
	 */
	const PROJECTS_STATUS_CHANGED = 'project_status_changed';

	/**
	 * Projects subcategory: New comment or reply on a project.
	 *
	 * @var string
	 */
	const PROJECTS_COMMENT = 'project_comment';

	/**
	 * Projects subcategory: Project due date changed.
	 *
	 * @var string
	 */
	const PROJECTS_DUE_DATE = 'project_due_date';

	/**
	 * Get all available notification categories
	 *
	 * Returns basic category info (label, description). For UI display with
	 * subcategory metadata, use get_all_with_metadata() instead.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of categories with labels and descriptions
	 */
	public static function get_all() {
		$categories = array(
			self::CAMPAIGNS      => array(
				'label'       => __( 'Campaigns', 'doublescale' ),
				'description' => __( 'Email and Sms campaign notifications', 'doublescale' ),
			),
			self::AUTOMATIONS    => array(
				'label'       => __( 'Automations', 'doublescale' ),
				'description' => __( 'Automation lifecycle and error notifications', 'doublescale' ),
			),
			self::CONTACTS       => array(
				'label'       => __( 'Contacts', 'doublescale' ),
				'description' => __( 'Contact import, export, and subscription notifications', 'doublescale' ),
			),
			self::PIPELINE       => array(
				'label'       => __( 'Pipeline', 'doublescale' ),
				'description' => __( 'Deal and pipeline notifications', 'doublescale' ),
			),
			self::TASKS          => array(
				'label'       => __( 'Tasks', 'doublescale' ),
				'description' => __( 'Task reminders, assignments, and status', 'doublescale' ),
			),
			self::EMAIL_TRACKING => array(
				'label'       => __( 'Email Tracking', 'doublescale' ),
				'description' => __( 'Email opens, clicks, and engagement', 'doublescale' ),
			),
			self::FORMS          => array(
				'label'       => __( 'Forms', 'doublescale' ),
				'description' => __( 'Form submissions and conversions', 'doublescale' ),
			),
			self::INTEGRATIONS   => array(
				'label'       => __( 'Integrations', 'doublescale' ),
				'description' => __( 'Integration connections and sync errors', 'doublescale' ),
			),
			self::BOOKING        => array(
				'label'       => __( 'Booking', 'doublescale' ),
				'description' => __( 'Booking lifecycle notifications (created, cancelled, rescheduled).', 'doublescale' ),
			),
			self::SUPPORT        => array(
				'label'       => __( 'Helpdesk', 'doublescale' ),
				'description' => __( 'Ticket lifecycle notifications for agents (new ticket, customer reply, assignment, resolved, reopened).', 'doublescale' ),
			),
			self::SALES          => array(
				'label'       => __( 'Sales', 'doublescale' ),
				'description' => __( 'Proposal, contract, and invoice notifications for assigned sales reps.', 'doublescale' ),
			),
			self::PROJECTS       => array(
				'label'       => __( 'Projects', 'doublescale' ),
				'description' => __( 'Project lifecycle notifications (created, assigned, status, comments, due dates).', 'doublescale' ),
			),
				// self::SYSTEM         => array(
				// 'label'       => __( 'System', 'doublescale'),
				// 'description' => __( 'License, security, and system notifications', 'doublescale'),
				// ),
		);

		// Hide categories whose underlying module is disabled so the prefs UI
		// only shows toggles the user can actually act on. Categories with no
		// module mapping (Contacts, System) are always retained.
		$categories = array_filter(
			$categories,
			static function ( $unused_data, $category_key ) {
				return self::is_module_active( $category_key );
			},
			ARRAY_FILTER_USE_BOTH
		);

		/**
		 * Filter the available notification categories
		 *
		 * @since 1.2.0
		 *
		 * @param array $categories Array of categories
		 */
		return apply_filters( 'doublescale_pro_notification_categories', $categories );
	}

	/**
	 * Get all categories with computed subcategory metadata
	 *
	 * Use this method when you need has_subcategories and subcategories_count
	 * computed dynamically from the actual subcategory definitions.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of categories with labels, descriptions, and subcategory metadata
	 */
	public static function get_all_with_metadata() {
		$categories            = self::get_all();
		$push_supported_cats   = self::get_push_supported_categories();
		$push_excluded_subcats = self::get_push_excluded_subcategories();

		foreach ( $categories as $key => &$category ) {
			$subcategories                   = self::get_subcategories( $key );
			$category['has_subcategories']   = ! empty( $subcategories );
			$category['subcategories_count'] = count( $subcategories );
			$category['push_supported']      = in_array( $key, $push_supported_cats, true );

			// For push-supported categories, also mark individual subcategory exclusions.
			if ( $category['push_supported'] && ! empty( $push_excluded_subcats ) ) {
				$excluded = array_intersect( array_keys( $subcategories ), $push_excluded_subcats );
				if ( ! empty( $excluded ) ) {
					$category['push_excluded_subcategories'] = array_values( $excluded );
				}
			}
		}

		return $categories;
	}

	/**
	 * Get all category keys
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of category keys
	 */
	public static function get_keys() {
		return array_keys( self::get_all() );
	}

	/**
	 * Check if a category is valid
	 *
	 * @since 1.2.0
	 *
	 * @param string $category Category key to check.
	 * @return bool True if valid, false otherwise
	 */
	public static function is_valid( $category ) {
		return in_array( $category, self::get_keys(), true );
	}

	/**
	 * Get a single category
	 *
	 * @since 1.2.0
	 *
	 * @param string $category Category key.
	 * @return array|null Category data or null if not found
	 */
	public static function get( $category ) {
		$categories = self::get_all();
		return $categories[ $category ] ?? null;
	}

	/**
	 * Get subcategories for a category
	 *
	 * Returns subcategory definitions for all categories. Every category has
	 * at least one subcategory for uniform preference structure.
	 *
	 * @since 1.2.0
	 *
	 * @param string $category Category key.
	 * @return array Array of subcategories with labels and descriptions
	 */
	public static function get_subcategories( $category ) {
		$subcategories = array(
			self::CAMPAIGNS      => array(
				self::CAMPAIGNS_EMAIL           => array(
					'label'       => __( 'Email Campaigns', 'doublescale' ),
					'description' => __( 'Notifications when email campaigns complete or fail.', 'doublescale' ),
				),
				self::CAMPAIGNS_SMS             => array(
					'label'       => __( 'Sms Campaigns', 'doublescale' ),
					'description' => __( 'Notifications when Sms campaigns complete or fail.', 'doublescale' ),
				),
				self::CAMPAIGNS_EMAIL_SCHEDULED => array(
					'label'       => __( 'Campaign Scheduled', 'doublescale' ),
					'description' => __( 'Notifications when a campaign is scheduled for sending.', 'doublescale' ),
				),
			),
			self::AUTOMATIONS    => array(
				self::AUTOMATIONS_ERRORS    => array(
					'label'       => __( 'Automation Errors', 'doublescale' ),
					'description' => __( 'Notifications when automations encounter errors.', 'doublescale' ),
				),
				self::AUTOMATIONS_STARTED   => array(
					'label'       => __( 'Automation Started', 'doublescale' ),
					'description' => __( 'Notifications when a contact enters an automation.', 'doublescale' ),
				),
				self::AUTOMATIONS_COMPLETED => array(
					'label'       => __( 'Automation Completed', 'doublescale' ),
					'description' => __( 'Notifications when a contact completes an automation.', 'doublescale' ),
				),
				self::AUTOMATIONS_PAUSED    => array(
					'label'       => __( 'Automation Paused', 'doublescale' ),
					'description' => __( 'Notifications when an automation is paused or stopped.', 'doublescale' ),
				),
			),
			self::CONTACTS       => array(
				self::CONTACTS_IMPORT       => array(
					'label'       => __( 'Contact Import', 'doublescale' ),
					'description' => __( 'Notifications when contact imports complete.', 'doublescale' ),
				),
				self::CONTACTS_EXPORT       => array(
					'label'       => __( 'Contact Export', 'doublescale' ),
					'description' => __( 'Notifications when contact exports complete.', 'doublescale' ),
				),
				self::CONTACTS_SUBSCRIBED   => array(
					'label'       => __( 'Contact Subscribed', 'doublescale' ),
					'description' => __( 'Notifications when a contact subscribes.', 'doublescale' ),
				),
				self::CONTACTS_UNSUBSCRIBED => array(
					'label'       => __( 'Contact Unsubscribed', 'doublescale' ),
					'description' => __( 'Notifications when a contact unsubscribes.', 'doublescale' ),
				),
				self::CONTACTS_BOUNCED      => array(
					'label'       => __( 'Contact Bounced', 'doublescale' ),
					'description' => __( 'Notifications when email to a contact bounces.', 'doublescale' ),
				),
			),
			self::PIPELINE       => array(
				self::PIPELINE_DEAL_WON_LOST      => array(
					'label'       => __( 'Deal Won/Lost', 'doublescale' ),
					'description' => __( 'Notifications when a deal is won or lost.', 'doublescale' ),
				),
				self::PIPELINE_DEAL_STAGE_CHANGED => array(
					'label'       => __( 'Deal Stage Changed', 'doublescale' ),
					'description' => __( 'Notifications when a deal moves to a different stage.', 'doublescale' ),
				),
				self::PIPELINE_DEAL_ASSIGNED      => array(
					'label'       => __( 'Deal Assigned', 'doublescale' ),
					'description' => __( 'Notifications when a deal is assigned to you.', 'doublescale' ),
				),
				self::PIPELINE_DEAL_UNASSIGNED    => array(
					'label'       => __( 'Deal Unassigned', 'doublescale' ),
					'description' => __( 'Notifications when you are removed from a deal.', 'doublescale' ),
				),
				self::PIPELINE_DEAL_OVERDUE       => array(
					'label'       => __( 'Deal Overdue', 'doublescale' ),
					'description' => __( 'Notifications when a deal passes its expected close date.', 'doublescale' ),
				),
				self::PIPELINE_DEAL_VALUE_CHANGED => array(
					'label'       => __( 'Deal Value Changed', 'doublescale' ),
					'description' => __( 'Notifications when a deal value is updated.', 'doublescale' ),
				),
				self::PIPELINE_DEAL_NOTE_ADDED    => array(
					'label'       => __( 'Deal Note Added', 'doublescale' ),
					'description' => __( 'Notifications when a note is added to a deal.', 'doublescale' ),
				),
			),
			self::TASKS          => array(
				self::TASKS_REMINDER  => array(
					'label'       => __( 'Task Reminders', 'doublescale' ),
					'description' => __( 'Notifications for upcoming task deadlines.', 'doublescale' ),
				),
				self::TASKS_ASSIGNED  => array(
					'label'       => __( 'Task Assigned', 'doublescale' ),
					'description' => __( 'Notifications when a task is assigned to you.', 'doublescale' ),
				),
				self::TASKS_COMMENT => array(
					'label'       => __( 'Task Comment', 'doublescale' ),
					'description' => __( 'Notifications when someone comments on a task assigned to you.', 'doublescale' ),
				),
				self::TASKS_COMMENT_MENTION => array(
					'label'       => __( 'Task Mention', 'doublescale' ),
					'description' => __( 'Notifications when you are mentioned in a task comment.', 'doublescale' ),
				),
				self::TASKS_COMPLETED => array(
					'label'       => __( 'Task Completed', 'doublescale' ),
					'description' => __( 'Notifications when a task is marked as complete.', 'doublescale' ),
				),
				self::TASKS_OVERDUE   => array(
					'label'       => __( 'Task Overdue', 'doublescale' ),
					'description' => __( 'Notifications when a task is past its due date.', 'doublescale' ),
				),
			),
			self::EMAIL_TRACKING => array(
				self::EMAIL_TRACKING_OPENED  => array(
					'label'       => __( 'Email Opened', 'doublescale' ),
					'description' => __( 'Notifications when a contact opens your email.', 'doublescale' ),
				),
				self::EMAIL_TRACKING_CLICKED => array(
					'label'       => __( 'Link Clicked', 'doublescale' ),
					'description' => __( 'Notifications when a contact clicks a link in your email.', 'doublescale' ),
				),
			),
			self::FORMS          => array(
				self::FORMS_SUBMISSION => array(
					'label'       => __( 'Form Submission', 'doublescale' ),
					'description' => __( 'Notifications when a form is submitted.', 'doublescale' ),
				),
			),
			self::INTEGRATIONS   => array(
				self::INTEGRATIONS_CONNECTED    => array(
					'label'       => __( 'Integration Connected', 'doublescale' ),
					'description' => __( 'Notifications when an integration is connected.', 'doublescale' ),
				),
				self::INTEGRATIONS_DISCONNECTED => array(
					'label'       => __( 'Integration Disconnected', 'doublescale' ),
					'description' => __( 'Notifications when an integration is disconnected.', 'doublescale' ),
				),
				self::INTEGRATIONS_SYNC_ERROR   => array(
					'label'       => __( 'Sync Error', 'doublescale' ),
					'description' => __( 'Notifications when an integration sync fails.', 'doublescale' ),
				),
			),
			self::BOOKING        => array(
				self::BOOKING_CREATED     => array(
					'label'       => __( 'New Booking', 'doublescale' ),
					'description' => __( 'A new booking was made on one of your calendars.', 'doublescale' ),
				),
				self::BOOKING_CANCELLED   => array(
					'label'       => __( 'Booking Cancelled', 'doublescale' ),
					'description' => __( 'A booking on one of your calendars was cancelled.', 'doublescale' ),
				),
				self::BOOKING_RESCHEDULED => array(
					'label'       => __( 'Booking Rescheduled', 'doublescale' ),
					'description' => __( 'A booking on one of your calendars was rescheduled.', 'doublescale' ),
				),
			),
			self::SUPPORT        => array(
				self::SUPPORT_TICKET_CREATED  => array(
					'label'       => __( 'New Ticket', 'doublescale' ),
					'description' => __( 'A new support ticket was opened.', 'doublescale' ),
				),
				self::SUPPORT_CUSTOMER_REPLY  => array(
					'label'       => __( 'New Customer Reply', 'doublescale' ),
					'description' => __( 'A customer replied to a ticket.', 'doublescale' ),
				),
				self::SUPPORT_TICKET_ASSIGNED => array(
					'label'       => __( 'Ticket Assigned to Me', 'doublescale' ),
					'description' => __( 'A ticket was assigned to you.', 'doublescale' ),
				),
				self::SUPPORT_TICKET_RESOLVED => array(
					'label'       => __( 'Ticket Resolved/Closed', 'doublescale' ),
					'description' => __( 'A ticket was marked resolved or closed.', 'doublescale' ),
				),
				self::SUPPORT_TICKET_REOPENED => array(
					'label'       => __( 'Ticket Reopened', 'doublescale' ),
					'description' => __( 'A resolved or closed ticket was reopened.', 'doublescale' ),
				),
			),
			self::SALES          => array(
				self::SALES_PROPOSAL_SENT     => array(
					'label'       => __( 'Proposal Sent', 'doublescale' ),
					'description' => __( 'Notifications when a proposal is sent to a customer.', 'doublescale' ),
				),
				self::SALES_PROPOSAL_ACCEPTED => array(
					'label'       => __( 'Proposal Accepted', 'doublescale' ),
					'description' => __( 'Notifications when a customer accepts a proposal.', 'doublescale' ),
				),
				self::SALES_PROPOSAL_DECLINED => array(
					'label'       => __( 'Proposal Declined', 'doublescale' ),
					'description' => __( 'Notifications when a customer declines a proposal.', 'doublescale' ),
				),
				self::SALES_INVOICE_PAID      => array(
					'label'       => __( 'Invoice Paid', 'doublescale' ),
					'description' => __( 'Notifications when an invoice is paid in full.', 'doublescale' ),
				),
				self::SALES_CONTRACT_SENT     => array(
					'label'       => __( 'Contract Sent', 'doublescale' ),
					'description' => __( 'Notifications when a contract is sent to a customer.', 'doublescale' ),
				),
				self::SALES_CONTRACT_SIGNED   => array(
					'label'       => __( 'Contract Signed', 'doublescale' ),
					'description' => __( 'Notifications when a customer signs a contract.', 'doublescale' ),
				),
				self::SALES_APPROVAL_REQUESTED => array(
					'label'       => __( 'Approval Requested', 'doublescale' ),
					'description' => __( 'Notifications when a sales document is submitted for approval.', 'doublescale' ),
				),
				self::SALES_APPROVAL_APPROVED  => array(
					'label'       => __( 'Document Approved', 'doublescale' ),
					'description' => __( 'Notifications when a submitted sales document is approved.', 'doublescale' ),
				),
				self::SALES_APPROVAL_REJECTED  => array(
					'label'       => __( 'Document Rejected', 'doublescale' ),
					'description' => __( 'Notifications when a submitted sales document is rejected.', 'doublescale' ),
				),
				self::SALES_APPROVAL_INVALIDATED => array(
					'label'       => __( 'Approval Invalidated', 'doublescale' ),
					'description' => __( 'Notifications when an approved document is edited and must be reviewed again.', 'doublescale' ),
				),
				self::SALES_APPROVAL_WITHDRAWN => array(
					'label'       => __( 'Approval Withdrawn', 'doublescale' ),
					'description' => __( 'Notifications when a sales rep withdraws a pending approval request.', 'doublescale' ),
				),
				self::SALES_APPROVAL_PENDING_RESET => array(
					'label'       => __( 'Approval Request Reset', 'doublescale' ),
					'description' => __( 'Notifications when a pending request is cleared because a manager edited the document.', 'doublescale' ),
				),
			),
			self::PROJECTS       => array(
				self::PROJECTS_CREATED        => array(
					'label'       => __( 'New Project', 'doublescale' ),
					'description' => __( 'Notifications when a new project is created.', 'doublescale' ),
				),
				self::PROJECTS_ASSIGNED       => array(
					'label'       => __( 'Project Assigned to Me', 'doublescale' ),
					'description' => __( 'Notifications when a project is assigned to you.', 'doublescale' ),
				),
				self::PROJECTS_STATUS_CHANGED => array(
					'label'       => __( 'Status Changed', 'doublescale' ),
					'description' => __( 'Notifications when a project status changes.', 'doublescale' ),
				),
				self::PROJECTS_COMMENT        => array(
					'label'       => __( 'New Comment', 'doublescale' ),
					'description' => __( 'Notifications when someone comments on a project.', 'doublescale' ),
				),
				self::PROJECTS_DUE_DATE       => array(
					'label'       => __( 'Due Date Changed', 'doublescale' ),
					'description' => __( 'Notifications when a project due date is updated.', 'doublescale' ),
				),
			),
			self::SYSTEM         => array(
				self::SYSTEM_GENERAL          => array(
					'label'       => __( 'System Alerts', 'doublescale' ),
					'description' => __( 'General system notifications.', 'doublescale' ),
				),
				self::SYSTEM_LICENSE_EXPIRING => array(
					'label'       => __( 'License Expiring', 'doublescale' ),
					'description' => __( 'Notifications when your license is about to expire.', 'doublescale' ),
				),
				self::SYSTEM_SECURITY_ALERT   => array(
					'label'       => __( 'Security Alert', 'doublescale' ),
					'description' => __( 'Notifications for security-related events.', 'doublescale' ),
				),
				self::SYSTEM_DAILY_SUMMARY    => array(
					'label'       => __( 'Daily Summary', 'doublescale' ),
					'description' => __( 'Daily digest of CRM activity.', 'doublescale' ),
				),
			),
		);

		return $subcategories[ $category ] ?? array();
	}

	/**
	 * Check if a category has subcategories
	 *
	 * @since 1.2.0
	 *
	 * @param string $category Category key.
	 * @return bool True if category has subcategories, false otherwise
	 */
	public static function has_subcategories( $category ) {
		return ! empty( self::get_subcategories( $category ) );
	}

	/**
	 * Get category for a subcategory
	 *
	 * Derives the parent category from a subcategory key.
	 * Used to avoid storing redundant category in database.
	 *
	 * @since 1.2.0
	 *
	 * @param string $subcategory Subcategory key.
	 * @return string|null Parent category key or null if not found.
	 */
	public static function get_category_for_subcategory( $subcategory ) {
		static $map = null;

		// Build reverse lookup map once (cached in static var).
		// Include system category explicitly since it's excluded from get_all()
		// (hidden from preferences UI) but still needs valid reverse lookups
		// for NotificationService admin-only checks.
		if ( null === $map ) {
			$map            = array();
			$all_categories = array_merge( self::get_keys(), array( self::SYSTEM ) );
			foreach ( $all_categories as $category ) {
				foreach ( array_keys( self::get_subcategories( $category ) ) as $subcat_key ) {
					$map[ $subcat_key ] = $category;
				}
			}
		}

		return $map[ $subcategory ] ?? null;
	}

	/**
	 * Check if a subcategory is valid
	 *
	 * @since 1.2.0
	 *
	 * @param string $subcategory Subcategory key to check.
	 * @return bool True if valid, false otherwise
	 */
	public static function is_valid_subcategory( $subcategory ) {
		return null !== self::get_category_for_subcategory( $subcategory );
	}

	/**
	 * Get categories that support mobile push notifications.
	 *
	 * Only actionable, personal CRM events belong on mobile. Bulk operations
	 * (campaigns, automations, integrations, import/export) are desktop-only.
	 *
	 * @since 2.0.0
	 *
	 * @return array Category keys that support push.
	 */
	public static function get_push_supported_categories() {
		return array(
			self::PIPELINE,
			self::TASKS,
			self::CONTACTS,
			self::EMAIL_TRACKING,
			self::FORMS,
			self::BOOKING,
			self::SUPPORT,
			self::SALES,
			self::PROJECTS,
		);
	}

	/**
	 * Get subcategories that are NOT supported for push within an otherwise push-supported category.
	 *
	 * For example, Contacts category supports push for subscriptions but not for bulk import/export.
	 *
	 * @since 2.0.0
	 *
	 * @return array Subcategory keys to exclude from push.
	 */
	public static function get_push_excluded_subcategories() {
		return array(
			self::CONTACTS_IMPORT,
			self::CONTACTS_EXPORT,
		);
	}

	/**
	 * Check if a subcategory supports mobile push notifications.
	 *
	 * @since 2.0.0
	 *
	 * @param string $subcategory Subcategory key.
	 * @return bool True if push is supported.
	 */
	public static function is_push_supported( $subcategory ) {
		$category = self::get_category_for_subcategory( $subcategory );
		if ( ! $category || ! in_array( $category, self::get_push_supported_categories(), true ) ) {
			return false;
		}

		return ! in_array( $subcategory, self::get_push_excluded_subcategories(), true );
	}

	/**
	 * Get the module slug that owns a notification category.
	 *
	 * Used to hide categories from prefs UI / REST when the underlying module
	 * is disabled. Returns null for categories that aren't gated by a
	 * toggleable module (always visible).
	 *
	 * @since 2.0.0
	 *
	 * @param string $category Category key.
	 * @return string|null Module slug or null if category isn't module-gated.
	 */
	public static function get_module_slug( $category ) {
		$map = array(
			self::CAMPAIGNS      => 'campaigns',
			self::AUTOMATIONS    => 'automations',
			self::FORMS          => 'forms',
			self::BOOKING        => 'booking',
			self::SUPPORT        => 'support',
			self::SALES          => 'sales',
			self::PROJECTS       => 'projects',
			self::TASKS          => 'tasks',
			self::PIPELINE       => 'deals',
			self::EMAIL_TRACKING => 'tracking',
			self::INTEGRATIONS   => 'integrations',
			// CONTACTS, SYSTEM are non-toggleable / foundational.
		);

		return $map[ $category ] ?? null;
	}

	/**
	 * Check whether the module that owns this category is present AND enabled.
	 *
	 * A category is visible only when its owning module is actually loaded in
	 * the live kernel registry and enabled. Presence matters because
	 * {@see doublescale_is_module_active()} answers "true" for unknown slugs
	 * (so third-party groups aren't stripped) and for phantom Pro toggles — both
	 * would leak Pro-owned categories (Deals, Tasks, …) into a free-only install
	 * where those modules don't exist. Requiring registry presence keeps free
	 * showing only its real categories while letting Pro's categories appear the
	 * moment Pro registers its modules.
	 *
	 * Categories with no module mapping (Contacts, System) are always visible.
	 *
	 * @since 2.0.0
	 *
	 * @param string $category Category key.
	 * @return bool True if the category should be visible.
	 */
	public static function is_module_active( $category ) {
		$slug = self::get_module_slug( $category );
		if ( null === $slug ) {
			return true;
		}

		// The owning module must actually be registered (loaded) — not merely
		// "active by default" for an absent/phantom slug.
		if ( function_exists( 'doublescale_kernel_registry_modules' ) ) {
			$registry = doublescale_kernel_registry_modules();
			if ( ! isset( $registry[ $slug ] ) ) {
				return false;
			}
		}

		return doublescale_is_module_active( $slug );
	}

	/**
	 * Get required capability for a category's page access
	 *
	 * Returns the WordPress capability required to access the page
	 * associated with this notification category. Used to filter
	 * notifications for users who lack access to certain features.
	 *
	 * Note: Uses 'doublescale_manage' capability which is granted to both
	 * administrators and CRM managers, but NOT to sales reps.
	 *
	 * @since 1.2.0
	 *
	 * @param string $category Category key.
	 * @return string|null Required capability, or null if accessible by all CRM users.
	 */
	public static function get_required_capability( $category ) {
		$map = array(
			self::CAMPAIGNS    => 'doublescale_manage',
			self::AUTOMATIONS  => 'doublescale_manage',
			self::FORMS        => 'doublescale_manage',
			self::INTEGRATIONS => 'doublescale_manage',
			self::PROJECTS     => 'doublescale_project_read_all_projects',
			self::SYSTEM       => 'manage_options',
			// CONTACTS, PIPELINE, TASKS, EMAIL_TRACKING - accessible by all CRM users.
		);

		return $map[ $category ] ?? null;
	}
}
