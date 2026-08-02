<?php
/**
 * Notification Service
 *
 * Provides methods to create notifications for users.
 * Supports multiple channels (bell, email, browser) with user preferences.
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Services;

use DoubleScale\Modules\Notifications\Models\NotificationModel;

/**
 * NotificationService class
 *
 * All methods accept a subcategory parameter. The category is derived
 * automatically from the subcategory using NotificationCategories::get_category_for_subcategory().
 */
class NotificationService {

	/**
	 * Whether notifications are currently suppressed.
	 *
	 * When true, create() and broadcast() return immediately without
	 * querying users or checking preferences. Used by batch operations
	 * (import, export, bulk updates) to avoid per-item notification overhead.
	 *
	 * @since 1.2.0
	 *
	 * @var bool
	 */
	private static $suppressed = false;

	/**
	 * Suppress all notifications.
	 *
	 * Call before batch operations to prevent per-item notification overhead.
	 * Always pair with resume() in a try/finally block.
	 *
	 * @since 1.2.0
	 */
	public static function suppress() {
		self::$suppressed = true;
	}

	/**
	 * Resume notifications after suppression.
	 *
	 * @since 1.2.0
	 */
	public static function resume() {
		self::$suppressed = false;
	}

	/**
	 * Check if notifications are currently suppressed.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public static function is_suppressed() {
		return self::$suppressed;
	}

	/**
	 * Create a notification for a specific user
	 *
	 * Checks user preferences and sends to enabled channels:
	 * - Bell: Creates database record (shows in UI dropdown)
	 * - Browser: Creates database record (triggers desktop notification via Heartbeat)
	 * - Email: Sends notification email to user
	 *
	 * All channels are independent - user can enable any combination.
	 *
	 * IMPORTANT: System category notifications are restricted to administrators only.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id     User ID.
	 * @param string $title       Notification title.
	 * @param string $message     Notification message.
	 * @param mixed  $links       Link(s) to related item. Can be:
	 *                            - string: Web link (converted to ['web' => $link])
	 *                            - array: ['web' => '...', 'mobile' => '...']
	 * @param string $subcategory Subcategory key (e.g., 'deal_won_lost'). Category is derived from this.
	 * @param array  $metadata    Optional metadata for the notification.
	 *
	 * @return array {
	 *     Result array indicating what happened.
	 *
	 *     @type NotificationModel|null $notification      Created notification, or null if no bell/browser notification was created.
	 *     @type bool                    $email_sent        Whether email was sent successfully.
	 *     @type bool                    $channels_enabled  Whether ANY channel was enabled for this subcategory.
	 * }
	 */
	public static function create( $user_id, $title, $message, $links = array(), $subcategory = 'system_general', $metadata = array() ) {
		if ( ! $user_id || ! $title || ! $message || self::$suppressed ) {
			return array(
				'notification'     => null,
				'email_sent'       => false,
				'channels_enabled' => false,
			);
		}

		// Validate subcategory.
		if ( ! NotificationCategories::is_valid_subcategory( $subcategory ) ) {
			$subcategory = NotificationCategories::SYSTEM_GENERAL;
		}

		// Derive category from subcategory.
		$category = NotificationCategories::get_category_for_subcategory( $subcategory );

		// Restrict system notifications to administrators only.
		if ( NotificationCategories::SYSTEM === $category ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				// User is not an administrator - skip system notification.
				return array(
					'notification'     => null,
					'email_sent'       => false,
					'channels_enabled' => false,
				);
			}
		}

		// Normalize links - convert string to array format.
		if ( is_string( $links ) && ! empty( $links ) ) {
			$links = array( 'web' => $links );
		} elseif ( ! is_array( $links ) ) {
			$links = array();
		}

		// Build data JSON structure.
		$data = array(
			'title'    => $title,
			'message'  => $message,
			'links'    => $links,
			'metadata' => $metadata,
		);

		$notification = null;
		$email_sent   = false;

		// Check what channels the user has enabled for this subcategory.
		$bell_enabled    = NotificationPreferences::is_bell_enabled( $user_id, $subcategory );
		$browser_enabled = NotificationPreferences::is_browser_enabled( $user_id, $subcategory );
		$email_enabled   = NotificationPreferences::is_email_enabled( $user_id, $subcategory );
		$push_enabled    = NotificationPreferences::is_push_enabled( $user_id, $subcategory );

		// Track if ANY channel is enabled.
		$channels_enabled = $bell_enabled || $browser_enabled || $email_enabled || $push_enabled;

		// Bell or Browser channel - create record if either is enabled.
		// Bell shows in dropdown, browser triggers desktop notification via Heartbeat.
		// Both channels share the same database record.
		if ( $bell_enabled || $browser_enabled ) {
			$notification = NotificationModel::create(
				array(
					'user_id'     => $user_id,
					'subcategory' => $subcategory,
					'data'        => $data,
					'is_read'     => 0,
				)
			);
		}

		// Email channel (independent - can send even if bell is off).
		if ( $email_enabled ) {
			$web_link   = $links['web'] ?? null;
			$email_sent = NotificationEmailSender::send_direct( $user_id, $title, $message, $web_link, $category );
		}

		// Push channel (independent — can send even if bell/browser are off).
		// Skip scheduling entirely when the admin has disabled push site-wide
		// to avoid queueing Action Scheduler jobs that would just bail in send().
		// When a DB record exists, pass its ID so the push service can load full data.
		// When no record exists (bell+browser off, push on), pass the data inline.
		if ( $push_enabled && get_option( 'doublescale_push_enabled', false ) && class_exists( 'ActionScheduler' ) ) {
			if ( $notification ) {
				as_enqueue_async_action(
					'doublescale_send_push_notification',
					array( $user_id, $notification->id ),
					'doublescale-push'
				);
			} else {
				as_enqueue_async_action(
					'doublescale_send_push_notification',
					array(
						$user_id,
						0,
						array(
							'title'       => $title,
							'message'     => $message,
							'mobile_link' => $links['mobile'] ?? '',
							'subcategory' => $subcategory,
						),
					),
					'doublescale-push'
				);
			}
		}

		// Return structured result array.
		return array(
			'notification'     => $notification,
			'email_sent'       => $email_sent,
			'channels_enabled' => $channels_enabled,
		);
	}

	/**
	 * Notify all users with CRM access
	 *
	 * IMPORTANT: For system category, only administrators are notified.
	 * For categories with page access restrictions (campaigns, automations, forms, integrations),
	 * only users with the required capability are notified.
	 * For other categories, all users with doublescale_access are notified.
	 *
	 * @since 1.2.0
	 *
	 * @param string $title            Notification title.
	 * @param string $message          Notification message.
	 * @param mixed  $links            Link(s) - string or array with 'web'/'mobile' keys.
	 * @param string $subcategory      Subcategory key. Category is derived from this.
	 * @param array  $metadata         Optional metadata.
	 * @param array  $exclude_user_ids Optional array of user IDs to exclude (e.g., the user who triggered the event).
	 *
	 * @return int Number of users successfully notified.
	 */
	public static function broadcast( $title, $message, $links = array(), $subcategory = 'system_general', $metadata = array(), $exclude_user_ids = array() ) {
		// Skip entirely during batch operations (import, export, bulk updates).
		if ( self::$suppressed ) {
			return 0;
		}

		// Derive category from subcategory.
		$category = NotificationCategories::get_category_for_subcategory( $subcategory );

		// For system category, only notify administrators.
		if ( NotificationCategories::SYSTEM === $category ) {
			$users = get_users(
				array(
					'role' => 'administrator',
				)
			);
		} else {
			// For other categories, notify all CRM users.
			$users = get_users(
				array(
					'capability__in' => array( 'doublescale_access' ),
				)
			);
		}

		// Get required capability for this category's page access.
		// Users without this capability can't access the linked page, so skip them.
		$required_cap = NotificationCategories::get_required_capability( $category );

		$count = 0;
		foreach ( $users as $user ) {
			// Skip excluded users (e.g., the user who triggered the event).
			if ( ! empty( $exclude_user_ids ) && in_array( $user->ID, $exclude_user_ids, true ) ) {
				continue;
			}

			// Skip users who lack capability for this category's page.
			// This prevents sending notifications for features the user can't access.
			if ( $required_cap && ! user_can( $user->ID, $required_cap ) ) {
				continue;
			}

			// create() handles preference checking for each channel and admin check.
			$result = self::create( $user->ID, $title, $message, $links, $subcategory, $metadata );

			// Count if notification was created or email was sent.
			if ( $result['notification'] || $result['email_sent'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Delete old notifications (cleanup)
	 *
	 * @since 1.2.0
	 *
	 * @param int $days Number of days to keep.
	 *
	 * @return int Number of deleted records.
	 */
	public static function cleanup( $days = 30 ) {
		return NotificationModel::deleteOlderThan( $days );
	}
}
