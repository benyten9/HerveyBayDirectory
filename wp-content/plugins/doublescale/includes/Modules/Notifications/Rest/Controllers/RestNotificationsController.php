<?php
/**
 * RestNotificationsController class.
 *
 * @since 1.2.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Rest\Controllers;

use DoubleScale\Modules\Notifications\Models\NotificationModel;
use DoubleScale\Modules\Notifications\Services\NotificationPreferences;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestNotificationsController class.
 *
 * @since 1.2.0
 */
class RestNotificationsController extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1';

	/**
	 * REST Base
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	protected $rest_base = 'notifications';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /notifications - Get paginated notifications
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_notifications' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 20,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET /notifications/count - Get unread count (lightweight for heartbeat)
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/count",
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_unread_count' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// POST /notifications/read - Mark notifications as read
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/read",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_as_read' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// DELETE /notifications/{id} - Delete a notification
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/(?P<id>[\d]+)",
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_notification' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission check for notifications endpoints
	 *
	 * @since 1.2.0
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'doublescale_access' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access notifications.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Get paginated notifications for current user
	 *
	 * Only returns notifications for subcategories where bell is enabled,
	 * matching the unread count filtering. System category notifications
	 * are excluded for non-administrators.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_notifications( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$page     = $request->get_param( 'page' );
		$per_page = max( 1, min( $request->get_param( 'per_page' ), 50 ) ); // Min 1, Max 50

		// Get bell-enabled subcategories to filter the list consistently with unread count.
		$bell_enabled_subcats = NotificationPreferences::get_bell_enabled_subcategories( $user_id );

		// If no subcategories have bell enabled, return empty.
		if ( empty( $bell_enabled_subcats ) ) {
			return new WP_REST_Response(
				array(
					'notifications' => array(),
					'total'         => 0,
					'page'          => $page,
					'per_page'      => $per_page,
					'total_pages'   => 1,
				),
				200
			);
		}

		// Base query for counting (filtered by bell preferences)
		$count_query = NotificationModel::query()
			->forUser( $user_id )
			->whereIn( 'subcategory', $bell_enabled_subcats )
			->excludeSystemForNonAdmin();

		// Get total count first
		$total = $count_query->count();

		// Build fresh query for fetching (avoid query builder state issues)
		$fetch_query = NotificationModel::query()
			->forUser( $user_id )
			->whereIn( 'subcategory', $bell_enabled_subcats )
			->excludeSystemForNonAdmin()
			->orderBy( 'created_at', 'desc' );

		$notifications = $fetch_query
			->skip( ( $page - 1 ) * $per_page )
			->take( $per_page )
			->get();

		return new WP_REST_Response(
			array(
				'notifications' => $notifications->toArray(),
				'total'         => $total,
				'page'          => $page,
				'per_page'      => $per_page,
				'total_pages'   => $total > 0 ? (int) ceil( $total / $per_page ) : 1,
			),
			200
		);
	}

	/**
	 * Get unread count for current user
	 *
	 * Only counts notifications for subcategories where bell is enabled.
	 * System category notifications are excluded for non-administrators.
	 *
	 * @since 1.2.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_unread_count() {
		$user_id = get_current_user_id();

		// Get bell-enabled subcategories for this user (filter at display time).
		$bell_enabled_subcats = NotificationPreferences::get_bell_enabled_subcategories( $user_id );

		// If no subcategories have bell enabled, count is 0.
		if ( empty( $bell_enabled_subcats ) ) {
			return new WP_REST_Response(
				array(
					'unread_count' => 0,
				),
				200
			);
		}

		// Use cached count method (handles preference filtering and admin check).
		$is_admin = current_user_can( 'manage_options' );
		$count    = NotificationModel::getUnreadCount( $user_id, $bell_enabled_subcats, $is_admin );

		return new WP_REST_Response(
			array(
				'unread_count' => $count,
			),
			200
		);
	}

	/**
	 * Mark notification(s) as read
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_as_read( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = $request->get_param( 'id' );

		if ( $id ) {
			// Mark single notification as read.
			$notification = NotificationModel::query()->forUser( $user_id )->find( $id );

			if ( ! $notification ) {
				return new WP_Error(
					'not_found',
					__( 'Notification not found.', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			$notification->markAsRead();

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Notification marked as read.', 'doublescale' ),
				),
				200
			);
		}

		// Mark all as read
		$count = NotificationModel::markAllAsRead( $user_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of notifications */
					__( '%d notifications marked as read.', 'doublescale' ),
					$count
				),
				'count'   => $count,
			),
			200
		);
	}

	/**
	 * Delete a notification
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_notification( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = $request->get_param( 'id' );

		$notification = NotificationModel::query()->forUser( $user_id )->find( $id );

		if ( ! $notification ) {
			return new WP_Error(
				'not_found',
				__( 'Notification not found.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$notification->delete();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Notification deleted.', 'doublescale' ),
			),
			200
		);
	}
}
