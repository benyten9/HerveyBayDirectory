<?php
/**
 * Notification abilities.
 *
 * @package DoubleScale\Modules\Notifications
 */

namespace DoubleScale\Modules\Notifications\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Modules\Notifications\Models\NotificationModel;
use DoubleScale\Modules\Notifications\Services\NotificationPreferences;

/**
 * "What have I missed" — the one question an agent could not answer.
 *
 * Notifications need no ownership scoping because they have no cross-user read
 * path at all: every row belongs to exactly one recipient, and the model's
 * `forUser()` scope is applied unconditionally here. There is deliberately no
 * user_id input — an agent must not be able to read someone else's bell.
 *
 * Two filters from the REST controller are reproduced rather than skipped:
 * `excludeSystemForNonAdmin()`, because system rows leak site-level detail to
 * non-administrators, and the bell-enabled subcategory list, so the agent's view
 * matches what the user actually sees in the dashboard. Diverging on either
 * would make the agent report notifications the user cannot find.
 */
final class NotificationAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( self::class, 'can_read_notifications' );

		return array(
			'doublescale/list-my-notifications' => array(
				'module_slug'      => 'notifications',
				'label'            => __( 'List my notifications', 'doublescale' ),
				'description'      => __( 'Your own notifications, newest first, with whether each has been read. Always scoped to you — there is no way to read another user\'s notifications, and no user id to pass. Only notifications for categories you have the bell enabled on appear, matching the dashboard exactly. Use mark-notifications-read to clear unread ones.', 'doublescale' ),
				'category'         => AbilityCategories::CORE,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'unread_only' => array(
							'type'        => 'boolean',
							'description' => 'Only notifications you have not read yet.',
							'default'     => false,
						),
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'      => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_my_notifications' ),
			),

			'doublescale/get-notification-summary' => array(
				'module_slug'      => 'notifications',
				'label'            => __( 'Get my notification summary', 'doublescale' ),
				'description'      => __( 'How many unread notifications you have, broken down by category. Use this for "anything I need to look at" before listing them all.', 'doublescale' ),
				'category'         => AbilityCategories::CORE,
				'permission'       => $permission,
				'execute_callback' => array( self::class, 'get_notification_summary' ),
			),

			'doublescale/mark-notifications-read' => array(
				'module_slug'      => 'notifications',
				'label'            => __( 'Mark notifications as read', 'doublescale' ),
				'description'      => __( 'Mark your own notifications as read. Pass id to mark one; omit id to mark all of yours. There is no user id — this can never mark another user\'s notifications.', 'doublescale' ),
				'category'         => AbilityCategories::CORE,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'One notification id. Omit to mark all of yours as read.',
						),
					),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'mark_notifications_read' ),
			),
		);
	}

	/**
	 * Gate 2 — the base CRM access capability the REST controller uses.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_read_notifications(): bool {
		return current_user_can( 'doublescale_access' );
	}

	/**
	 * List the caller's notifications.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_my_notifications( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$user_id = get_current_user_id();
		$subcats = self::bell_enabled( $user_id );

		// No enabled categories means an empty bell, not an unfiltered read.
		// Saying WHY matters: the bell is a Pro channel and is off by default, so
		// an agent that reported a bare empty list would tell the user they have
		// no notifications when in fact nothing is being collected for them.
		if ( array() === $subcats ) {
			return AbilityResult::collection(
				array(),
				0,
				$limit,
				$offset,
				array(
					'reason' => __( 'You have no notification categories with the in-app bell switched on, so nothing is listed here. Turn the bell on in Settings → Notifications to collect them.', 'doublescale' ),
				)
			);
		}

		$query = self::scoped_query( $user_id, $subcats );

		if ( ! empty( $input['unread_only'] ) ) {
			$query->where( 'is_read', 0 );
		}

		$total = (int) $query->count();

		// Rebuilt rather than reused: the REST controller does the same because
		// counting first leaves builder state that corrupts the fetch.
		$fetch = self::scoped_query( $user_id, $subcats );
		if ( ! empty( $input['unread_only'] ) ) {
			$fetch->where( 'is_read', 0 );
		}

		$rows = $fetch->orderBy( 'created_at', 'desc' )
			->skip( $offset )
			->take( $limit )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape( $row );
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * Unread counts by category.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_notification_summary( array $input ): array {
		unset( $input );

		$user_id = get_current_user_id();
		$subcats = self::bell_enabled( $user_id );

		if ( array() === $subcats ) {
			return array(
				'unread_total' => 0,
				'by_category'  => array(),
				'reason'       => __( 'You have no notification categories with the in-app bell switched on, so nothing is counted here. Turn the bell on in Settings → Notifications to collect them.', 'doublescale' ),
			);
		}

		$rows = self::scoped_query( $user_id, $subcats )->where( 'is_read', 0 )->get();

		$by_category = array();
		$total       = 0;

		foreach ( $rows as $row ) {
			$key = (string) $row->subcategory;

			$by_category[ $key ] = ( $by_category[ $key ] ?? 0 ) + 1;
			++$total;
		}

		arsort( $by_category );

		return array(
			'unread_total' => $total,
			'by_category'  => $by_category,
		);
	}

	/**
	 * Mark the caller's notifications as read.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function mark_notifications_read( array $input ) {
		$invalid = AbilityInput::id( $input['id'] ?? null, 'id' );
		if ( $invalid ) {
			return $invalid;
		}

		$user_id = get_current_user_id();

		if ( ! empty( $input['id'] ) ) {
			$notification = NotificationModel::query()->forUser( $user_id )->find( (int) $input['id'] );
			if ( ! $notification ) {
				return AbilityResult::not_found( __( 'No notification found with that id.', 'doublescale' ) );
			}

			$was_unread = ! $notification->is_read;
			$notification->markAsRead();

			return array(
				'updated'          => (bool) $was_unread,
				'notification_id'  => (int) $notification->id,
				'marked_read'      => 1,
			);
		}

		$count = NotificationModel::markAllAsRead( $user_id );

		return array(
			'updated'     => $count > 0,
			'marked_read' => (int) $count,
		);
	}

	/**
	 * A query already narrowed to the caller and their enabled categories.
	 *
	 * @since 1.0.0
	 *
	 * @param int                $user_id Caller.
	 * @param array<int, string> $subcats Bell-enabled subcategories.
	 * @return object
	 */
	private static function scoped_query( int $user_id, array $subcats ) {
		return NotificationModel::query()
			->forUser( $user_id )
			->whereIn( 'subcategory', $subcats )
			->excludeSystemForNonAdmin();
	}

	/**
	 * Subcategories the user has the bell switched on for.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id Caller.
	 * @return array<int, string>
	 */
	private static function bell_enabled( int $user_id ): array {
		if ( ! class_exists( NotificationPreferences::class ) ) {
			return array();
		}

		$subcats = NotificationPreferences::get_bell_enabled_subcategories( $user_id );

		return is_array( $subcats ) ? array_values( $subcats ) : array();
	}

	/**
	 * Shape one notification row.
	 *
	 * The stored `data` blob holds the title, message, and links. Only the
	 * readable parts are surfaced — the link set is dashboard/mobile routing
	 * that means nothing to an agent and would just spend context.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Notification model.
	 * @return array<string, mixed>
	 */
	private static function shape( $row ): array {
		$data = $row->data ?? array();
		if ( ! is_array( $data ) ) {
			$decoded = json_decode( (string) $data, true );
			$data    = is_array( $decoded ) ? $decoded : array();
		}

		$message = AbilityResult::truncate( (string) ( $data['message'] ?? '' ), 500 );

		return array(
			'id'          => (int) $row->id,
			'category'    => (string) $row->subcategory,
			'title'       => (string) ( $data['title'] ?? '' ),
			'message'     => $message['text'],
			'truncated'   => $message['truncated'],
			'is_read'     => (bool) $row->is_read,
			'created_at'  => (string) $row->created_at,
		);
	}
}
