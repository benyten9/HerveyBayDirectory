<?php
/**
 * TaskCommentNotifier — parses @mentions in task comments and fires notifications.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * TaskCommentNotifier class.
 */
class TaskCommentNotifier {

	/**
	 * Notify assignee and mentioned users after a comment is created or updated.
	 *
	 * @param TaskModel $task        Parent task.
	 * @param string    $body        Comment HTML body.
	 * @param bool      $is_new      True when the comment was just created.
	 */
	public static function notify( TaskModel $task, string $body, bool $is_new = true ): void {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::TASKS ) ) {
			return;
		}

		$current_user = get_current_user_id();
		$author       = get_userdata( $current_user );
		$author_name  = $author ? $author->display_name : __( 'Someone', 'doublescale' );
		$link         = self::get_task_link( $task );
		$plain        = wp_strip_all_tags( $body );

		$mentioned_ids = self::parse_mention_user_ids( $body );
		$notified      = array();

		foreach ( $mentioned_ids as $user_id ) {
			if ( $user_id === $current_user || in_array( $user_id, $notified, true ) ) {
				continue;
			}

			NotificationService::create(
				$user_id,
				/* translators: %s: task title */
				sprintf( __( 'Mentioned on "%s"', 'doublescale' ), $task->title ),
				/* translators: 1: author name, 2: comment excerpt */
				sprintf(
					__( '%1$s mentioned you in a comment: %2$s', 'doublescale' ),
					$author_name,
					self::excerpt( $plain )
				),
				$link,
				NotificationCategories::TASKS_COMMENT_MENTION,
				array(
					'task_id' => (int) $task->id,
				)
			);

			$notified[] = $user_id;
		}

		if ( ! $is_new ) {
			return;
		}

		$assignee = (int) $task->assigned_to;
		if ( $assignee && $assignee !== $current_user && ! in_array( $assignee, $notified, true ) ) {
			NotificationService::create(
				$assignee,
				/* translators: %s: task title */
				sprintf( __( 'New comment on "%s"', 'doublescale' ), $task->title ),
				/* translators: 1: author name, 2: comment excerpt */
				sprintf(
					__( '%1$s commented: %2$s', 'doublescale' ),
					$author_name,
					self::excerpt( $plain )
				),
				$link,
				NotificationCategories::TASKS_COMMENT,
				array(
					'task_id' => (int) $task->id,
				)
			);
		}
	}

	/**
	 * Parse `@[Display Name](user:ID)` tokens from comment HTML.
	 *
	 * @param string $body Comment body.
	 * @return int[] Unique mentioned user IDs.
	 */
	public static function parse_mention_user_ids( string $body ): array {
		$ids = array();

		if ( preg_match_all( '/@\[([^\]]+)\]\(user:(\d+)\)/', $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$user_id = absint( $match[2] );
				if ( $user_id > 0 ) {
					$ids[] = $user_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function excerpt( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) <= 120 ) {
			return $text;
		}

		return substr( $text, 0, 117 ) . '...';
	}

	/**
	 * @param TaskModel $task Task.
	 * @return array{web: string, mobile: string}
	 */
	private static function get_task_link( TaskModel $task ): array {
		$entity_type = (int) $task->entity_type;

		if ( 1 === $entity_type ) {
			$web = admin_url( 'admin.php?page=doublescale&path=contacts&id=' . $task->entity_id . '&tab=tasks' );
		} elseif ( 2 === $entity_type ) {
			$web = admin_url( 'admin.php?page=doublescale&path=pipeline/deal&id=' . $task->entity_id );
		} else {
			$web = admin_url( 'admin.php?page=doublescale&path=tasks' );
		}

		return array(
			'web'    => $web,
			'mobile' => '/tasks/' . $task->id,
		);
	}
}
