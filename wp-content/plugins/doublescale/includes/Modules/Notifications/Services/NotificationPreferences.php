<?php
/**
 * Notification Preferences Service
 * Manages user notification preferences stored in user meta
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Services;

use DoubleScale\Modules\Notifications\Models\NotificationModel;

/**
 * NotificationPreferences class
 *
 * Handles storing and retrieving user notification preferences.
 * Preferences are stored as JSON in user meta.
 *
 * STORAGE OPTIMIZATION: Only differences from defaults are stored.
 * - If user uses all defaults: nothing stored (~0 bytes)
 * - If user changes 2 settings: only those 2 stored (~50 bytes)
 * - Reading always merges stored diff with defaults
 *
 * Structure is flat for simplicity:
 * - channels: Global on/off for each channel (bell, email, browser)
 * - subcategories: Per-subcategory settings (flat, not nested under categories)
 *
 * Categories are derived from NotificationCategories for UI grouping only.
 *
 * @since 1.2.0
 */
class NotificationPreferences {

	/**
	 * User meta key for notification preferences
	 *
	 * @var string
	 */
	const META_KEY = '_doublescale_notification_preferences';

	/**
	 * Get default preferences
	 *
	 * Email is the free channel and is ON by default. Bell, browser, and push
	 * are Pro channels; their keys are only present when Pro makes them
	 * available via {@see NotificationChannels::allowed()}. On a free-only
	 * install the returned structure therefore contains email keys only.
	 *
	 * Browser notifications require both:
	 * 1. User preference enabled (stored here)
	 * 2. Browser permission granted (managed by browser Notification Api)
	 *
	 * @since 1.2.0
	 *
	 * @return array Default preferences structure
	 */
	public static function get_defaults() {
		$defaults = array(
			'channels'      => array(
				'bell'    => true,
				'email'   => true,  // Email on by default (the free channel).
				'browser' => true,  // Browser on by default (like bell).
				'push'    => true,  // Mobile push on by default.
			),
			'subcategories' => array(
				// Campaigns — desktop only, push not supported.
				NotificationCategories::CAMPAIGNS_EMAIL    => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				NotificationCategories::CAMPAIGNS_SMS      => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				NotificationCategories::CAMPAIGNS_EMAIL_SCHEDULED => array(
					'bell'    => true,
					'email'   => false,
					'browser' => false,
					'push'    => false,
				),
				// Automations — desktop only, push not supported.
				NotificationCategories::AUTOMATIONS_ERRORS => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				NotificationCategories::AUTOMATIONS_STARTED => array(
					'bell'    => false,
					'email'   => false,
					'browser' => false,
					'push'    => false,
				),
				NotificationCategories::AUTOMATIONS_COMPLETED => array(
					'bell'    => false,
					'email'   => false,
					'browser' => false,
					'push'    => false,
				),
				NotificationCategories::AUTOMATIONS_PAUSED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				// Contacts — push supported except import/export (bulk ops).
				NotificationCategories::CONTACTS_IMPORT    => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				NotificationCategories::CONTACTS_EXPORT    => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				NotificationCategories::CONTACTS_SUBSCRIBED => array(
					'bell'    => false,
					'email'   => false, // Can be noisy with high volume.
					'browser' => false,
					'push'    => false,
				),
				NotificationCategories::CONTACTS_UNSUBSCRIBED => array(
					'bell'    => true,
					'email'   => true, // Important to track unsubscribes.
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::CONTACTS_BOUNCED   => array(
					'bell'    => true,
					'email'   => true, // Important for deliverability.
					'browser' => true,
					'push'    => true,
				),
				// Pipeline.
				NotificationCategories::PIPELINE_DEAL_WON_LOST => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::PIPELINE_DEAL_STAGE_CHANGED => array(
					'bell'    => true,
					'email'   => false, // Stage changes can be noisy.
					'browser' => false,
					'push'    => true,
				),
				NotificationCategories::PIPELINE_DEAL_ASSIGNED => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::PIPELINE_DEAL_UNASSIGNED => array(
					'bell'    => true,
					'email'   => false, // Less critical than assignment.
					'browser' => false,
					'push'    => true,
				),
				NotificationCategories::PIPELINE_DEAL_OVERDUE => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::PIPELINE_DEAL_VALUE_CHANGED => array(
					'bell'    => true,
					'email'   => false, // Informational.
					'browser' => false,
					'push'    => false,
				),
				NotificationCategories::PIPELINE_DEAL_NOTE_ADDED => array(
					'bell'    => true,
					'email'   => false, // Can be noisy.
					'browser' => false,
					'push'    => false,
				),
				// Tasks.
				NotificationCategories::TASKS_REMINDER     => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::TASKS_ASSIGNED     => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::TASKS_COMMENT      => array(
					'bell'    => true,
					'email'   => false, // Can be noisy on active tasks.
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::TASKS_COMMENT_MENTION => array(
					'bell'    => true,
					'email'   => true, // Direct mention — worth an email.
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::TASKS_COMPLETED    => array(
					'bell'    => true,
					'email'   => false, // Informational.
					'browser' => false,
					'push'    => false,
				),
				NotificationCategories::TASKS_OVERDUE      => array(
					'bell'    => true,
					'email'   => true, // Important deadline.
					'browser' => true,
					'push'    => true,
				),
				// Email Tracking.
				NotificationCategories::EMAIL_TRACKING_OPENED => array(
					'bell'    => false,
					'email'   => false, // Very noisy.
					'browser' => false,
					'push'    => false,
				),
				NotificationCategories::EMAIL_TRACKING_CLICKED => array(
					'bell'    => false,
					'email'   => false, // Can be noisy.
					'browser' => false,
					'push'    => false,
				),
				// Forms.
				NotificationCategories::FORMS_SUBMISSION   => array(
					'bell'    => true,
					'email'   => false, // Can be noisy.
					'browser' => false,
					'push'    => true,
				),
				// Integrations — desktop only, push not supported.
				NotificationCategories::INTEGRATIONS_CONNECTED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				NotificationCategories::INTEGRATIONS_DISCONNECTED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				NotificationCategories::INTEGRATIONS_SYNC_ERROR => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => false,
				),
				// Booking — personal, actionable; push supported.
				// Email defaults to false because Free's EmailNotifications already emails
				// the host via getOrganizerRecipientEmails() for these events. Enabling
				// email here would double-send.
				NotificationCategories::BOOKING_CREATED    => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::BOOKING_CANCELLED  => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::BOOKING_RESCHEDULED => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				// Support — personal, actionable; push supported.
				// Email defaults to TRUE here (unlike Booking). The Free Support
				// module's EmailNotifications emails the *customer*; these Pro
				// notifications go to the *agent*. Different audience, different
				// inbox — no double-send — so an agent legitimately gets an email
				// when a ticket lands or a customer replies.
				NotificationCategories::SUPPORT_TICKET_CREATED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SUPPORT_CUSTOMER_REPLY => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SUPPORT_TICKET_ASSIGNED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SUPPORT_TICKET_RESOLVED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SUPPORT_TICKET_REOPENED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				// Sales — personal, actionable; push supported.
				NotificationCategories::SALES_PROPOSAL_SENT => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_PROPOSAL_ACCEPTED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_PROPOSAL_DECLINED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_INVOICE_PAID => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_CONTRACT_SENT => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_CONTRACT_SIGNED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_APPROVAL_REQUESTED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_APPROVAL_APPROVED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_APPROVAL_REJECTED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_APPROVAL_INVALIDATED => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_APPROVAL_WITHDRAWN => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::SALES_APPROVAL_PENDING_RESET => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				// Projects — personal, actionable; push supported.
				NotificationCategories::PROJECTS_CREATED   => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::PROJECTS_ASSIGNED  => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::PROJECTS_STATUS_CHANGED => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::PROJECTS_COMMENT   => array(
					'bell'    => true,
					'email'   => false,
					'browser' => true,
					'push'    => true,
				),
				NotificationCategories::PROJECTS_DUE_DATE  => array(
					'bell'    => true,
					'email'   => true,
					'browser' => true,
					'push'    => true,
				),
				// System.
				// NotificationCategories::SYSTEM_GENERAL               => array(
				// 'bell'    => true,
				// 'email'   => false, // System notifications don't email by default.
				// 'browser' => true,
				// ),
				// NotificationCategories::SYSTEM_LICENSE_EXPIRING      => array(
				// 'bell'    => true,
				// 'email'   => true, // Critical reminder.
				// 'browser' => true,
				// ),
				// NotificationCategories::SYSTEM_SECURITY_ALERT        => array(
				// 'bell'    => true,
				// 'email'   => true, // Security is critical.
				// 'browser' => true,
				// ),
				// NotificationCategories::SYSTEM_DAILY_SUMMARY         => array(
				// 'bell'    => false, // Daily digest is email-focused.
				// 'email'   => true,
				// 'browser' => false,
				// ),
			),
		);

		// Restrict to channels available on this install (free = email only).
		$defaults = self::restrict_to_allowed_channels( $defaults );

		/**
		 * Filter the default notification preferences
		 *
		 * @since 1.2.0
		 *
		 * @param array $defaults Default preferences structure
		 */
		return apply_filters( 'doublescale_pro_notification_preferences_defaults', $defaults );
	}

	/**
	 * Strip channel keys that are not available on this install.
	 *
	 * Applies to both the global `channels` map and every subcategory's channel
	 * map so the entire preferences structure only ever references available
	 * channels. On free this collapses every row to the email key only.
	 *
	 * @since 1.0.0
	 *
	 * @param array $defaults Full defaults structure.
	 * @return array Filtered defaults.
	 */
	private static function restrict_to_allowed_channels( array $defaults ) {
		$allowed = NotificationChannels::allowed();

		if ( isset( $defaults['channels'] ) && is_array( $defaults['channels'] ) ) {
			$defaults['channels'] = array_intersect_key(
				$defaults['channels'],
				array_flip( $allowed )
			);
		}

		if ( isset( $defaults['subcategories'] ) && is_array( $defaults['subcategories'] ) ) {
			foreach ( $defaults['subcategories'] as $subcat_key => $subcat_prefs ) {
				$defaults['subcategories'][ $subcat_key ] = array_intersect_key(
					(array) $subcat_prefs,
					array_flip( $allowed )
				);
			}
		}

		return $defaults;
	}

	/**
	 * Get user preferences
	 *
	 * Returns stored preferences merged with defaults to ensure
	 * all expected keys exist.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array User preferences
	 */
	public static function get( $user_id ) {
		$stored = get_user_meta( $user_id, self::META_KEY, true );

		if ( empty( $stored ) || ! is_array( $stored ) ) {
			return self::get_defaults();
		}

		// Deep merge with defaults to ensure all keys exist.
		return self::merge_with_defaults( $stored );
	}

	/**
	 * Update user preferences
	 *
	 * Only stores differences from defaults to minimize storage.
	 * If all preferences match defaults, the meta key is deleted.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $user_id     WordPress user ID.
	 * @param array $preferences Preferences to save.
	 * @return bool True on success, false on failure
	 */
	public static function update( $user_id, $preferences ) {
		// Validate and sanitize preferences.
		$sanitized = self::sanitize_preferences( $preferences );

		// Compute diff from defaults - only store what's different.
		$diff = self::compute_diff( $sanitized );

		// If no differences, delete the meta key entirely.
		if ( empty( $diff ) ) {
			$result = delete_user_meta( $user_id, self::META_KEY );
		} else {
			$result = update_user_meta( $user_id, self::META_KEY, $diff );
		}

		// Invalidate cached unread count since preferences affect which notifications are counted.
		NotificationModel::invalidateCountCache( $user_id );

		return $result;
	}

	/**
	 * Check if a channel is enabled for a specific subcategory
	 *
	 * Checks BOTH the global channel setting AND the subcategory-specific setting.
	 * Both must be enabled for the notification to be sent via that channel.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $subcategory Subcategory key (e.g., 'deal_won_lost', 'task_reminder').
	 * @param string $channel     Channel type ('bell', 'email', or 'browser').
	 * @return bool True if enabled, false if disabled
	 */
	public static function is_enabled( $user_id, $subcategory, $channel ) {
		// Channel must be available on this install (free = email only).
		if ( ! NotificationChannels::is_allowed( $channel ) ) {
			return false;
		}

		$prefs = self::get( $user_id );

		// First check global channel setting.
		if ( empty( $prefs['channels'][ $channel ] ) ) {
			return false;
		}

		// A subcategory with no defaults row would otherwise read as disabled on
		// every channel, silently dropping the notification. Fall back to the
		// global channel setting so a registered-but-undefaulted subcategory
		// still delivers instead of vanishing.
		if ( ! isset( $prefs['subcategories'][ $subcategory ] ) ) {
			return NotificationCategories::is_valid_subcategory( $subcategory );
		}

		// Check subcategory-specific setting.
		return ! empty( $prefs['subcategories'][ $subcategory ][ $channel ] );
	}

	/**
	 * Check if bell notifications are enabled for a subcategory
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $subcategory Subcategory key.
	 * @return bool True if enabled
	 */
	public static function is_bell_enabled( $user_id, $subcategory ) {
		return self::is_enabled( $user_id, $subcategory, 'bell' );
	}

	/**
	 * Check if email notifications are enabled for a subcategory
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $subcategory Subcategory key.
	 * @return bool True if enabled
	 */
	public static function is_email_enabled( $user_id, $subcategory ) {
		return self::is_enabled( $user_id, $subcategory, 'email' );
	}

	/**
	 * Check if browser notifications are enabled for a subcategory
	 *
	 * Note: This only checks user preferences. Browser permission
	 * must also be granted via the Notification Api.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $subcategory Subcategory key.
	 * @return bool True if enabled
	 */
	public static function is_browser_enabled( $user_id, $subcategory ) {
		return self::is_enabled( $user_id, $subcategory, 'browser' );
	}

	/**
	 * Check if mobile push notifications are enabled for a subcategory
	 *
	 * @since 2.0.0
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $subcategory Subcategory key.
	 * @return bool True if enabled
	 */
	public static function is_push_enabled( $user_id, $subcategory ) {
		return self::is_enabled( $user_id, $subcategory, 'push' );
	}

	/**
	 * Get all subcategories where bell is enabled for a user
	 *
	 * Used by REST Api to filter notifications at display time.
	 * This allows browser-only notifications to exist without showing in dropdown.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Array of subcategory keys where bell is enabled.
	 */
	public static function get_bell_enabled_subcategories( $user_id ) {
		$prefs = self::get( $user_id );

		// If global bell channel is off, no subcategories are enabled.
		if ( empty( $prefs['channels']['bell'] ) ) {
			return array();
		}

		// Collect subcategories where bell is enabled.
		$enabled = array();
		foreach ( $prefs['subcategories'] as $subcat_key => $subcat_prefs ) {
			if ( ! empty( $subcat_prefs['bell'] ) ) {
				$enabled[] = $subcat_key;
			}
		}

		return $enabled;
	}

	/**
	 * Get all subcategories where browser is enabled for a user
	 *
	 * Used by Heartbeat to filter latest notification for browser desktop notifications.
	 * This allows a user to have browser notifications for different categories than bell.
	 *
	 * Note: This only checks user preferences. Browser permission must also be
	 * granted via the browser's Notification Api.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Array of subcategory keys where browser is enabled.
	 */
	public static function get_browser_enabled_subcategories( $user_id ) {
		$prefs = self::get( $user_id );

		// If global browser channel is off, no subcategories are enabled.
		if ( empty( $prefs['channels']['browser'] ) ) {
			return array();
		}

		// Collect subcategories where browser is enabled.
		$enabled = array();
		foreach ( $prefs['subcategories'] as $subcat_key => $subcat_prefs ) {
			if ( ! empty( $subcat_prefs['browser'] ) ) {
				$enabled[] = $subcat_key;
			}
		}

		return $enabled;
	}

	/**
	 * Deep merge stored preferences with defaults
	 *
	 * Flat subcategory structure - ensures all default keys exist.
	 *
	 * @since 1.2.0
	 *
	 * @param array $stored Stored preferences.
	 * @return array Merged preferences
	 */
	private static function merge_with_defaults( $stored ) {
		$defaults = self::get_defaults();
		$merged   = array();

		// Merge channels.
		$merged['channels'] = array_merge(
			$defaults['channels'],
			$stored['channels'] ?? array()
		);

		// Merge subcategories (flat structure).
		$merged['subcategories'] = array();
		foreach ( $defaults['subcategories'] as $subcat_key => $subcat_defaults ) {
			$merged['subcategories'][ $subcat_key ] = array_merge(
				$subcat_defaults,
				$stored['subcategories'][ $subcat_key ] ?? array()
			);
		}

		return $merged;
	}

	/**
	 * Sanitize preferences input
	 *
	 * Flat subcategory structure for simplicity.
	 *
	 * @since 1.2.0
	 *
	 * @param array $preferences Raw preferences input.
	 * @return array Sanitized preferences
	 */
	private static function sanitize_preferences( $preferences ) {
		$defaults  = self::get_defaults();
		$sanitized = array(
			'channels'      => array(),
			'subcategories' => array(),
		);

		// Sanitize channels (must be boolean).
		foreach ( array_keys( $defaults['channels'] ) as $channel ) {
			$sanitized['channels'][ $channel ] = ! empty( $preferences['channels'][ $channel ] );
		}

		// Sanitize subcategories (flat structure).
		foreach ( array_keys( $defaults['subcategories'] ) as $subcat_key ) {
			$sanitized['subcategories'][ $subcat_key ] = array();

			foreach ( array_keys( $defaults['channels'] ) as $channel ) {
				$sanitized['subcategories'][ $subcat_key ][ $channel ] =
					! empty( $preferences['subcategories'][ $subcat_key ][ $channel ] );
			}
		}

		return $sanitized;
	}

	/**
	 * Compute diff from defaults
	 *
	 * Returns only values that differ from defaults.
	 * This minimizes storage - most users use defaults.
	 *
	 * @since 1.2.0
	 *
	 * @param array $preferences Full preferences array.
	 * @return array Only values that differ from defaults (may be empty).
	 */
	private static function compute_diff( $preferences ) {
		$defaults = self::get_defaults();
		$diff     = array();

		// Check channels - only store if different from default.
		foreach ( $defaults['channels'] as $channel => $default_value ) {
			$user_value = $preferences['channels'][ $channel ] ?? $default_value;
			if ( $user_value !== $default_value ) {
				if ( ! isset( $diff['channels'] ) ) {
					$diff['channels'] = array();
				}
				$diff['channels'][ $channel ] = $user_value;
			}
		}

		// Check subcategories - only store changed channel values.
		foreach ( $defaults['subcategories'] as $subcat => $subcat_defaults ) {
			foreach ( $subcat_defaults as $channel => $default_value ) {
				$user_value = $preferences['subcategories'][ $subcat ][ $channel ] ?? $default_value;
				if ( $user_value !== $default_value ) {
					if ( ! isset( $diff['subcategories'] ) ) {
						$diff['subcategories'] = array();
					}
					if ( ! isset( $diff['subcategories'][ $subcat ] ) ) {
						$diff['subcategories'][ $subcat ] = array();
					}
					$diff['subcategories'][ $subcat ][ $channel ] = $user_value;
				}
			}
		}

		return $diff;
	}

	/**
	 * Delete user preferences
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True on success, false on failure
	 */
	public static function delete( $user_id ) {
		return delete_user_meta( $user_id, self::META_KEY );
	}
}
