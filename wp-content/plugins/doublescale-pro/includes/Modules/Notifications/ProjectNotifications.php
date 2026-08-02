<?php
/**
 * Project Notifications Handler
 *
 * Subscribes to project lifecycle events and creates in-app notifications
 * (bell / browser / email / push) for project owners and the broader team.
 *
 * Audience: when a project has an owner (`owner_id`) the notification targets
 * that user; otherwise it fans out to every project-capable CRM user via
 * NotificationService::broadcast() (capability-filtered, preference-aware).
 *
 * @listens doublescale_project_created
 * @listens doublescale_project_updated
 * @listens doublescale_project_status_changed
 * @listens doublescale_project_comment_posted
 *
 * @since 2.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Pro\Modules\Projects\Models\ProjectDiscussionModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectActivityLogger;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectNotifications class.
 */
class ProjectNotifications {

	/**
	 * Subscribe to project lifecycle hooks.
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::PROJECTS ) ) {
			return;
		}

		add_action( 'doublescale_project_created', array( $this, 'on_project_created' ), 10, 1 );
		add_action( 'doublescale_project_updated', array( $this, 'on_project_updated' ), 10, 2 );
		add_action( 'doublescale_project_status_changed', array( $this, 'on_project_status_changed' ), 10, 3 );
		add_action( 'doublescale_project_comment_posted', array( $this, 'on_comment_posted' ), 10, 3 );
	}

	/**
	 * Handle project.created — a new project was added.
	 *
	 * @param mixed $project ProjectModel instance (validated in safely()).
	 */
	public function on_project_created( $project ): void {
		$this->safely(
			$project,
			function ( ProjectModel $p ) {
				$this->notify(
					$p,
					/* translators: %s: project title */
					sprintf( __( 'New project: %s', 'doublescale' ), $this->project_title( $p ) ),
					__( 'A new project was created.', 'doublescale' ),
					NotificationCategories::PROJECTS_CREATED
				);
			}
		);
	}

	/**
	 * Handle project.updated — fan out assignment and due-date subcategories.
	 *
	 * @param mixed $project ProjectModel instance (validated in safely()).
	 * @param mixed $changes Changed attributes.
	 */
	public function on_project_updated( $project, $changes ): void {
		$changes = is_array( $changes ) ? $changes : array();

		$this->safely(
			$project,
			function ( ProjectModel $p ) use ( $changes ) {
				if ( array_key_exists( 'owner_id', $changes ) ) {
					$new_owner = (int) $p->owner_id;
					$actor     = get_current_user_id();

					if ( $new_owner > 0 && $new_owner !== $actor ) {
						NotificationService::create(
							$new_owner,
							__( 'Project assigned to you', 'doublescale' ),
							$this->project_title( $p ),
							$this->links_for( $p ),
							NotificationCategories::PROJECTS_ASSIGNED,
							array( 'project_id' => (int) $p->id )
						);
					}
				}

				if ( array_key_exists( 'due_date', $changes ) ) {
					$this->notify(
						$p,
						/* translators: %s: project title */
						sprintf( __( 'Due date updated: %s', 'doublescale' ), $this->project_title( $p ) ),
						$this->format_due_date_message( $p ),
						NotificationCategories::PROJECTS_DUE_DATE
					);
				}
			}
		);
	}

	/**
	 * Handle project.status_changed — project moved to a new status.
	 *
	 * @param mixed $project        ProjectModel instance (validated in safely()).
	 * @param mixed $old_status_id  Previous status ID.
	 * @param mixed $new_status_id  New status ID.
	 */
	public function on_project_status_changed( $project, $old_status_id, $new_status_id ): void {
		$this->safely(
			$project,
			function ( ProjectModel $p ) use ( $old_status_id, $new_status_id ) {
				$old_label = ProjectActivityLogger::resolve_status_label( $old_status_id );
				$new_label = ProjectActivityLogger::resolve_status_label( $new_status_id );

				$this->notify(
					$p,
					/* translators: %s: project title */
					sprintf( __( 'Status changed: %s', 'doublescale' ), $this->project_title( $p ) ),
					/* translators: 1: old status label, 2: new status label */
					sprintf(
						__( 'Status changed from %1$s to %2$s.', 'doublescale' ),
						$old_label,
						$new_label
					),
					NotificationCategories::PROJECTS_STATUS_CHANGED
				);
			}
		);
	}

	/**
	 * Handle project.comment_posted — notify the project owner.
	 *
	 * @param mixed $project    ProjectModel instance (validated in safely()).
	 * @param mixed $discussion ProjectDiscussionModel instance.
	 * @param mixed $parent     Parent comment when replying.
	 */
	public function on_comment_posted( $project, $discussion, $parent = null ): void {
		unset( $parent );

		if ( ! $discussion instanceof ProjectDiscussionModel ) {
			return;
		}

		$this->safely(
			$project,
			function ( ProjectModel $p ) use ( $discussion ) {
				$owner_id = (int) ( $p->owner_id ?? 0 );
				$author   = (int) ( $discussion->user_id ?? 0 );

				if ( $owner_id <= 0 || $owner_id === $author ) {
					return;
				}

				$author_name = $this->user_display_name( $author );
				$is_reply    = (int) ( $discussion->parent_id ?? 0 ) > 0;

				NotificationService::create(
					$owner_id,
					$is_reply
						/* translators: %s: project title */
						? sprintf( __( 'New reply on %s', 'doublescale' ), $this->project_title( $p ) )
						/* translators: %s: project title */
						: sprintf( __( 'New comment on %s', 'doublescale' ), $this->project_title( $p ) ),
					/* translators: %s: comment author display name */
					sprintf( __( '%s left a comment.', 'doublescale' ), $author_name ),
					$this->links_for( $p ),
					NotificationCategories::PROJECTS_COMMENT,
					array(
						'project_id' => (int) $p->id,
						'comment_id' => (int) $discussion->id,
					)
				);
			}
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Send a notification to the project owner, or broadcast when unassigned.
	 *
	 * @param ProjectModel $project     The project.
	 * @param string       $title       Notification title.
	 * @param string       $body        Notification body.
	 * @param string       $subcategory NotificationCategories::PROJECTS_* subcategory.
	 */
	private function notify( ProjectModel $project, string $title, string $body, string $subcategory ): void {
		$links       = $this->links_for( $project );
		$metadata    = array( 'project_id' => (int) $project->id );
		$owner_id    = (int) ( $project->owner_id ?? 0 );
		$actor_id    = get_current_user_id();
		$exclude_ids = $actor_id > 0 ? array( $actor_id ) : array();

		if ( $owner_id > 0 ) {
			if ( $owner_id === $actor_id ) {
				return;
			}

			NotificationService::create( $owner_id, $title, $body, $links, $subcategory, $metadata );
			return;
		}

		NotificationService::broadcast( $title, $body, $links, $subcategory, $metadata, $exclude_ids );
	}

	/**
	 * Run a callback against the project, swallowing any Throwable.
	 *
	 * @param mixed    $project ProjectModel instance (or anything — validated here).
	 * @param callable $fn      Callback receiving the validated ProjectModel.
	 */
	private function safely( $project, callable $fn ): void {
		if ( ! ( $project instanceof ProjectModel ) ) {
			return;
		}

		try {
			$fn( $project );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Project in-app notification failed',
				array(
					'source'     => 'project-pro-notifications',
					'project_id' => (int) $project->id,
					'exception'  => $e->getMessage(),
					'file'       => $e->getFile(),
					'line'       => $e->getLine(),
				)
			);
		}
	}

	/**
	 * @param ProjectModel $project The project.
	 * @return string Non-empty title.
	 */
	private function project_title( ProjectModel $project ): string {
		$title = trim( (string) ( $project->title ?? '' ) );
		if ( '' === $title ) {
			return __( '(untitled project)', 'doublescale' );
		}

		return mb_substr( $title, 0, 140 );
	}

	/**
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private function user_display_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'Someone', 'doublescale' );
		}

		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : __( 'Someone', 'doublescale' );
	}

	/**
	 * @param ProjectModel $project The project.
	 * @return string
	 */
	private function format_due_date_message( ProjectModel $project ): string {
		$due_date = trim( (string) ( $project->due_date ?? '' ) );
		if ( '' === $due_date ) {
			return __( 'The due date was cleared.', 'doublescale' );
		}

		return sprintf(
			/* translators: %s: formatted due date */
			__( 'New due date: %s', 'doublescale' ),
			wp_date( get_option( 'date_format' ), strtotime( $due_date ) )
		);
	}

	/**
	 * Build the link payload for the notification.
	 *
	 * @param ProjectModel $project The project.
	 * @return array{web:string,mobile:string}
	 */
	private function links_for( ProjectModel $project ): array {
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=projects/' . (int) $project->id ),
			'mobile' => '/projects/' . (int) $project->id,
		);
	}
}
