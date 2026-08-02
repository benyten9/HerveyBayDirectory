<?php
/**
 * ProjectActivityLogger — turns project lifecycle domain events into
 * `activity_type='project_event'` rows on the project's activity stream.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectDiscussionModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;

/**
 * ProjectActivityLogger class.
 */
class ProjectActivityLogger {

	/**
	 * Register WP action listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_project_updated', array( $this, 'on_project_updated' ), 10, 2 );
		add_action( 'doublescale_project_file_attached', array( $this, 'on_file_attached' ), 10, 2 );
		add_action( 'doublescale_project_file_removed', array( $this, 'on_file_removed' ), 10, 2 );
		add_action( 'doublescale_project_converted_from_deal', array( $this, 'on_converted_from_deal' ), 10, 2 );
		add_action( 'doublescale_project_comment_posted', array( $this, 'on_comment_posted' ), 10, 3 );
	}

	/**
	 * @param ProjectModel $project Updated project.
	 * @param array        $changes Changed attributes.
	 */
	public function on_project_updated( $project, $changes ): void {
		if ( ! $project instanceof ProjectModel || ! is_array( $changes ) ) {
			return;
		}

		if ( isset( $changes['title'] ) ) {
			$this->log_project_event(
				$project,
				'title_changed',
				array(
					'field' => 'title',
					'from'  => $project->getOriginal( 'title' ),
					'to'    => $project->title,
				)
			);
		}

		if ( isset( $changes['description'] ) ) {
			$this->log_project_event(
				$project,
				'description_changed',
				array( 'field' => 'description' )
			);
		}

		if ( isset( $changes['owner_id'] ) ) {
			$this->log_project_event(
				$project,
				'owner_changed',
				array_merge(
					$this->user_change_payload(
						(int) $project->getOriginal( 'owner_id' ),
						(int) $project->owner_id
					),
					array( 'field' => 'owner_id' )
				)
			);
		}

		if ( isset( $changes['budget'] ) ) {
			$this->log_project_event(
				$project,
				'budget_changed',
				array(
					'field' => 'budget',
					'from'  => $project->getOriginal( 'budget' ),
					'to'    => $project->budget,
				)
			);
		}

		if ( isset( $changes['start_date'] ) ) {
			$this->log_project_event(
				$project,
				'start_date_changed',
				array(
					'field' => 'start_date',
					'from'  => $project->getOriginal( 'start_date' ),
					'to'    => $project->start_date,
				)
			);
		}

		if ( isset( $changes['due_date'] ) ) {
			$this->log_project_event(
				$project,
				'due_date_changed',
				array(
					'field' => 'due_date',
					'from'  => $project->getOriginal( 'due_date' ),
					'to'    => $project->due_date,
				)
			);
		}
	}

	/**
	 * @param ProjectModel    $project    Parent project.
	 * @param AttachmentModel $attachment Attached file.
	 */
	public function on_file_attached( $project, $attachment ): void {
		if ( ! $project instanceof ProjectModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_project_file_event( $project, $attachment, ActivityTypes::FILE_ATTACHED );
	}

	/**
	 * @param ProjectModel    $project    Parent project.
	 * @param AttachmentModel $attachment Removed file.
	 */
	public function on_file_removed( $project, $attachment ): void {
		if ( ! $project instanceof ProjectModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_project_file_event( $project, $attachment, ActivityTypes::FILE_REMOVED );
	}

	/**
	 * @param ProjectModel $project Created project.
	 * @param DealModel    $deal    Source deal.
	 */
	public function on_converted_from_deal( $project, $deal ): void {
		if ( ! $project instanceof ProjectModel || ! $deal instanceof DealModel ) {
			return;
		}

		$this->log_project_event(
			$project,
			'converted_from_deal',
			array(
				'deal_id'    => (int) $deal->id,
				'deal_title' => (string) $deal->title,
			)
		);
	}

	/**
	 * Log a posted project comment so it appears in the activity feed.
	 *
	 * Mirrors the task comment feed: a top-level comment carries its own content,
	 * a reply additionally carries the parent's author and excerpt so the feed can
	 * quote what was replied to.
	 *
	 * @param ProjectModel                 $project    Parent project.
	 * @param ProjectDiscussionModel       $discussion Posted comment or reply.
	 * @param ProjectDiscussionModel|null  $parent     Parent comment when replying.
	 */
	public function on_comment_posted( $project, $discussion, $parent = null ): void {
		if ( ! $project instanceof ProjectModel || ! $discussion instanceof ProjectDiscussionModel ) {
			return;
		}

		$is_reply = $parent instanceof ProjectDiscussionModel;
		$payload  = array(
			'content'    => (string) $discussion->body,
			'comment_id' => (int) $discussion->id,
		);

		if ( $is_reply ) {
			$payload['parent_comment_id']        = (int) $parent->id;
			$payload['parent_comment_author_id'] = (int) $parent->user_id;
			$payload['parent_comment_author']    = self::user_display_name( (int) $parent->user_id );
			$payload['parent_comment_excerpt']   = wp_trim_words(
				wp_strip_all_tags( (string) $parent->body ),
				12,
				'…'
			);
		}

		$this->log_project_event(
			$project,
			$is_reply ? 'comment_replied' : 'comment_posted',
			$payload
		);
	}

	/**
	 * Display name for a user id, empty when the user no longer exists.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private static function user_display_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Write a project_event activity associated with the given project.
	 *
	 * @param ProjectModel $project   Parent project.
	 * @param string       $event_key Stable event identifier.
	 * @param array        $payload   Extra data merged into activity.data.
	 * @param int|null     $user_id   Acting user; defaults to current user.
	 */
	private function log_project_event( ProjectModel $project, string $event_key, array $payload = array(), $user_id = null ): void {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return;
		}

		$data = array_merge(
			$payload,
			array(
				'project_id' => (int) $project->id,
				'event_key'  => $event_key,
			)
		);

		$activity = ActivityModel::create(
			array(
				'contact_id'    => $project->contact_id,
				'activity_type' => ActivityTypes::PROJECT_EVENT,
				'data'          => $data,
				'user_id'       => null === $user_id ? get_current_user_id() : (int) $user_id,
			)
		);

		if ( ! $activity ) {
			return;
		}

		ActivityAssociationModel::create(
			array(
				'activity_id' => $activity->id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_PROJECT,
				'entity_id'   => (int) $project->id,
			)
		);
	}

	/**
	 * Write a file attachment activity associated with the given project.
	 *
	 * @param ProjectModel    $project    Parent project.
	 * @param AttachmentModel $attachment Attachment row.
	 * @param string          $event_type Activity type constant.
	 */
	private function log_project_file_event( ProjectModel $project, AttachmentModel $attachment, string $event_type ): void {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return;
		}

		$shaped = ( new AttachmentService() )->shape_for_api( $attachment );

		$activity = ActivityModel::create(
			array(
				'contact_id'    => (int) $project->contact_id,
				'activity_type' => $event_type,
				'data'          => array(
					'project_id'    => (int) $project->id,
					'file_name'     => (string) $attachment->file_name,
					'attachment_id' => (int) $attachment->id,
					'file_size'     => (int) $attachment->file_size,
					'file_type'     => (string) $attachment->file_type,
					'url'           => (string) ( $shaped['url'] ?? '' ),
					'event_key'     => $event_type,
				),
				'user_id'       => get_current_user_id(),
			)
		);

		if ( ! $activity ) {
			return;
		}

		ActivityAssociationModel::create(
			array(
				'activity_id' => $activity->id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_PROJECT,
				'entity_id'   => (int) $project->id,
			)
		);
	}

	/**
	 * Build a from/to payload with human-readable user labels.
	 *
	 * @param int $from_id Previous user ID (0 = unassigned).
	 * @param int $to_id   New user ID (0 = unassigned).
	 * @return array{from: int, to: int, from_name: string, to_name: string}
	 */
	private function user_change_payload( int $from_id, int $to_id ): array {
		return array(
			'from'      => $from_id,
			'to'        => $to_id,
			'from_name' => self::resolve_user_label( $from_id ),
			'to_name'   => self::resolve_user_label( $to_id ),
		);
	}

	/**
	 * Resolve a WP user ID to a display label for activity rendering.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function resolve_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'Unassigned', 'doublescale' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return (string) $user_id;
		}

		return (string) $user->display_name;
	}

	/**
	 * Resolve a project status ID to a display label for activity rendering.
	 *
	 * @param int|string|null $status_id Status ID.
	 * @return string
	 */
	public static function resolve_status_label( $status_id ): string {
		if ( empty( $status_id ) ) {
			return __( 'No status', 'doublescale' );
		}

		$status = ProjectStatusModel::find( (int) $status_id );
		if ( ! $status ) {
			return (string) $status_id;
		}

		return (string) $status->name;
	}
}
