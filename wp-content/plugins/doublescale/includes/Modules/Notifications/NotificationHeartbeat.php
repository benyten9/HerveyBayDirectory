<?php
/**
 * Notification Heartbeat Handler
 *
 * Integrates with WordPress Heartbeat Api to provide real-time
 * notification updates without custom polling.
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications;

use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Notifications\Models\NotificationModel;
use DoubleScale\Modules\Notifications\Services\NotificationPreferences;

/**
 * NotificationHeartbeat class
 */
class NotificationHeartbeat {

	/**
	 * Class instance
	 *
	 * @var NotificationHeartbeat
	 */
	private static $instance;

	/**
	 * Get singleton instance
	 *
	 * @since 1.2.0
	 *
	 * @return NotificationHeartbeat
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.2.0
	 */
	private function __construct() {
		// Hook into WordPress Heartbeat Api only on admin pages
		if ( is_admin() ) {
			add_filter( 'heartbeat_received', array( $this, 'check_notifications' ), 10, 2 );
		}
		// Note: heartbeat_nopriv_received removed - non-authenticated users don't need notifications
	}

	/**
	 * Check notifications on heartbeat tick (authenticated users)
	 *
	 * @since 1.2.0
	 *
	 * @param array $response Heartbeat response data.
	 * @param array $data     Heartbeat request data.
	 *
	 * @return array Modified response.
	 */
	public function check_notifications( $response, $data ) {
		// Only process if client requested notification check.
		if ( empty( $data['doublescale_notifications'] ) ) {
			return $response;
		}

		$user_id = get_current_user_id();

		// User must have CRM access.
		if ( ! $user_id || ! current_user_can( 'doublescale_access' ) ) {
			return $response;
		}

		// Get user preferences for bell and browser channels.
		$bell_enabled_subcats    = NotificationPreferences::get_bell_enabled_subcategories( $user_id );
		$browser_enabled_subcats = NotificationPreferences::get_browser_enabled_subcategories( $user_id );
		$is_admin                = current_user_can( 'manage_options' );

		// Calculate unread count using cached method (filters by bell-enabled subcategories).
		// This count is for the bell dropdown display.
		$unread_count = 0;
		if ( ! empty( $bell_enabled_subcats ) ) {
			$unread_count = NotificationModel::getUnreadCount( $user_id, $bell_enabled_subcats, $is_admin );
		}

		// Get the 3 most recent unread notifications for browser desktop notifications.
		// IMPORTANT: Browser notifications are INDEPENDENT from bell notifications.
		// User may disable bell but enable browser for critical alerts.
		// Filter by browser-enabled subcategories (may differ from bell-enabled).
		// Returns array to allow showing multiple browser notifications per heartbeat interval.
		$latest = array();
		if ( ! empty( $browser_enabled_subcats ) ) {
			$latest_notifications = NotificationModel::query()
				->where( 'user_id', $user_id )
				->where( 'is_read', false )
				->whereIn( 'subcategory', $browser_enabled_subcats )
				->excludeSystemForNonAdmin( $is_admin )
				->orderBy( 'created_at', 'desc' )
				->take( 3 )
				->get();

			$latest = $latest_notifications->map(
				function ( $notification ) {
					return array(
						'id'          => $notification->id,
						'user_id'     => $notification->user_id,
						'category'    => $notification->category,
						'subcategory' => $notification->subcategory,
						'title'       => $notification->title,       // From accessor.
						'message'     => $notification->message,     // From accessor.
						'link'        => $notification->link,        // Web link from accessor.
						'mobile_link' => $notification->mobile_link, // Mobile link from accessor.
						'is_read'     => (bool) $notification->is_read,
						'created_at'  => $notification->created_at,
					);
				}
			)->toArray();
		}

		// Inbox unread count (inbound messages: email, Sms, WhatsApp).
		$inbox_last_read = get_user_meta( $user_id, '_doublescale_inbox_last_read_at', true );
		$inbox_query     = ActivityModel::query()
			->byType( array( 'email_received', 'sms_received', 'whatsapp_received' ) )
			->where(
				function ( $q ) use ( $user_id ) {
					$q->whereNull( 'user_id' )
						->orWhere( 'user_id', $user_id );
				}
			);
		if ( $inbox_last_read ) {
			$inbox_query->where( 'created_at', '>', $inbox_last_read );
		}

		$response['doublescale_notifications'] = array(
			'unread_count'       => $unread_count,
			'latest'             => $latest,
			'inbox_unread_count' => $inbox_query->count(),
		);

		return $response;
	}
}
