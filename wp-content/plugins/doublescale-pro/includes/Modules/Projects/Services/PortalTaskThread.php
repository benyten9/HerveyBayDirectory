<?php
/**
 * A project task's conversation and files with its client, for the client
 * portal (the task window's Comments and Files tabs) and the team's side of it.
 *
 * Rules the user chose (2026-10-20):
 *  - The client reads a separate thread: what they wrote, and the team comments
 *    whose writer turned on "Visible to client" (`data.client_visible`, off by
 *    default). Every other comment and every reply stays with the team.
 *  - The client may comment and upload files; nothing else of the task changes.
 *  - Both sides are told: the assignee and the project owner get the usual
 *    notification when the client writes or uploads; the client gets an email
 *    with a link to the task when the team writes to them.
 *  - Files: the client sees the files the team marked visible
 *    (`meta.client_visible`) and the ones they uploaded.
 *
 * A client comment is a task note like the team's, with no WordPress user:
 * `data.actor = 'contact'`, `actor_name`, `contact_id` (the convention of the
 * other things a contact does from the portal). Client uploads follow the
 * portal's own upload rules (types, size, hourly limit, the Files switch) and
 * land in its protected folder.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\ModuleManager;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Portal\Services\PortalFeatures;
use DoubleScale\Modules\Portal\Services\PortalFileService;
use DoubleScale\Modules\Portal\Services\PortalNotifications;
use DoubleScale\Modules\Portal\Services\PortalUploadSettings;
use DoubleScale\Modules\Portal\Services\PortalUrl;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Core\Constants\TaskEntityType;
use WP_Error;

/**
 * PortalTaskThread.
 */
final class PortalTaskThread {

	/** Longest comment a client may send, in characters. */
	public const MAX_COMMENT_LENGTH = 5000;

	/** Comments a client may send in an hour, over all their tasks. */
	public const HOURLY_COMMENT_LIMIT = 60;

	/** Files a task holds, team's and client's together (the team's own cap). */
	public const MAX_FILES_PER_TASK = 20;

	/** Attachable type of task files. */
	private const ATTACHABLE_TYPE = 'task';

	/**
	 * Whether the portal can show this task's thread: a task of a project that
	 * shares its tasks, the Tasks module on, and a free portal with the tabs.
	 *
	 * @param TaskModel $task Task.
	 * @return bool
	 */
	public static function is_open( TaskModel $task ): bool {
		if ( TaskEntityType::PROJECT !== (int) $task->entity_type || ! self::portal_supported() ) {
			return false;
		}
		$project_id = (int) $task->entity_id;
		if ( ! array_key_exists( $project_id, self::$open_projects ) ) {
			$project                            = ProjectModel::find( $project_id );
			self::$open_projects[ $project_id ] = $project && self::project_shares( $project );
		}
		return self::$open_projects[ $project_id ];
	}

	/**
	 * Projects already checked in this request: project id => shares its tasks.
	 *
	 * @var array<int, bool>
	 */
	private static $open_projects = array();


	/**
	 * The project shares its tasks and the Tasks module is on.
	 *
	 * @param ProjectModel $project Project.
	 * @return bool
	 */
	public static function project_shares( ProjectModel $project ): bool {
		return (bool) $project->portal_show_tasks && ModuleManager::isEnabled( 'tasks' );
	}

	/**
	 * The installed free portal has the task window's Comments and Files tabs.
	 *
	 * @return bool
	 */
	public static function portal_supported(): bool {
		return defined( PortalFeatures::class . '::SUPPORTS_TASK_THREAD' );
	}

	/**
	 * Whether a task note belongs to the client's thread.
	 *
	 * @param ActivityModel $activity Note.
	 * @return bool
	 */
	public static function in_thread( ActivityModel $activity ): bool {
		$data = is_array( $activity->data ) ? $activity->data : array();
		return self::from_client( $activity ) || ! empty( $data['client_visible'] );
	}

	/**
	 * Whether the client wrote the note from the portal.
	 *
	 * @param ActivityModel $activity Note.
	 * @return bool
	 */
	public static function from_client( ActivityModel $activity ): bool {
		$data = is_array( $activity->data ) ? $activity->data : array();
		return 'contact' === ( $data['actor'] ?? '' ) && ! (int) $activity->user_id;
	}

	/**
	 * The client's name on a note they wrote.
	 *
	 * @param ActivityModel $activity Note.
	 * @return string
	 */
	public static function client_name( ActivityModel $activity ): string {
		$data = is_array( $activity->data ) ? $activity->data : array();
		$name = trim( (string) ( $data['actor_name'] ?? '' ) );
		return '' !== $name ? $name : __( 'Client', 'doublescale' );
	}

	/**
	 * The task's thread with the client, oldest first.
	 *
	 * @param TaskModel         $task    Task.
	 * @param ContactModel|null $contact The client reading it.
	 * @return array<int, array<string, mixed>>
	 */
	public static function comments( TaskModel $task, ?ContactModel $contact ): array {
		$ids = ActivityAssociationModel::query()
			->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TASK )
			->where( 'entity_id', (int) $task->id )
			->pluck( 'activity_id' )
			->all();
		if ( empty( $ids ) ) {
			return array();
		}

		$notes = ActivityModel::with( array( 'user' ) )
			->whereIn( 'id', $ids )
			->where( 'activity_type', 'note' )
			->orderBy( 'created_at', 'asc' )
			->orderBy( 'id', 'asc' )
			->get();

		// The client reading it: the team's comments are seen (free's
		// ClientSeen; an older free keeps no "seen").
		$mark_seen = $contact && class_exists( \DoubleScale\Modules\Portal\Services\ClientSeen::class );

		// On a company-shared project, colleagues see each other's comments.
		$project    = $task->project_id ? ProjectModel::find( (int) $task->project_id ) : null;
		$company_id = $project ? self::project_company( $project ) : 0;

		$rows = array();
		foreach ( $notes as $note ) {
			// A client's words are theirs alone: a task moved to another
			// client's project keeps them from the new client.
			if ( self::from_client( $note ) && ! self::written_by( $note, $contact ) && ! self::by_colleague( $note, $company_id ) ) {
				continue;
			}
			if ( self::in_thread( $note ) ) {
				if ( $mark_seen && ! self::from_client( $note ) ) {
					\DoubleScale\Modules\Portal\Services\ClientSeen::activity_seen( $note );
				}
				$rows[] = self::shape_comment( $note, $contact );
			}
		}
		return $rows;
	}

	/**
	 * One comment as the client sees it: plain text, the writer's name.
	 *
	 * @param ActivityModel     $activity Note.
	 * @param ContactModel|null $contact  The client reading it.
	 * @return array<string, mixed>
	 */
	public static function shape_comment( ActivityModel $activity, ?ContactModel $contact ): array {
		$data        = is_array( $activity->data ) ? $activity->data : array();
		$from_client = self::from_client( $activity );
		$name        = $from_client ? self::client_name( $activity ) : '';
		if ( ! $from_client ) {
			$user = $activity->relationLoaded( 'user' ) ? $activity->user : null;
			$arr  = $user ? $user->toArray() : array();
			$name = (string) ( $arr['display_name'] ?? '' );
			$name = '' !== $name ? $name : __( 'Team', 'doublescale' );
		}

		return array(
			'id'         => (int) $activity->id,
			'body'       => self::plain( (string) ( $data['content'] ?? '' ) ),
			'created_at' => (string) $activity->created_at,
			'author'     => array(
				'type' => $from_client ? 'client' : 'team',
				'name' => $name,
			),
			'mine'       => $from_client && self::written_by( $activity, $contact ),
		);
	}

	/**
	 * The company whose members share a project (0 when it is the contact's own).
	 *
	 * @param ProjectModel $project Project.
	 */
	private static function project_company( ProjectModel $project ): int {
		return (int) $project->company_id;
	}

	/**
	 * Whether a client note was written by a member of the project's company.
	 *
	 * @param ActivityModel $activity   Note.
	 * @param int           $company_id The project's company (0 when none shares it).
	 * @return bool
	 */
	private static function by_colleague( ActivityModel $activity, int $company_id ): bool {
		if ( $company_id <= 0 || ! class_exists( \DoubleScale\Pro\Modules\Companies\Services\CompanyService::class ) ) {
			return false;
		}
		$data = is_array( $activity->data ) ? $activity->data : array();
		$writer = (int) ( $data['contact_id'] ?? 0 );
		return $writer > 0 && \DoubleScale\Pro\Modules\Companies\Services\CompanyService::is_member( $company_id, $writer );
	}

	/**
	 * Whether this client wrote the note.
	 *
	 * @param ActivityModel     $activity Note.
	 * @param ContactModel|null $contact  The client.
	 * @return bool
	 */
	private static function written_by( ActivityModel $activity, ?ContactModel $contact ): bool {
		$data = is_array( $activity->data ) ? $activity->data : array();
		return $contact && (int) ( $data['contact_id'] ?? 0 ) === (int) $contact->id;
	}

	/**
	 * Save a comment the client wrote and tell the team.
	 *
	 * @param TaskModel    $task    Task (of $project).
	 * @param ProjectModel $project Project.
	 * @param ContactModel $contact The client.
	 * @param string       $raw     What they typed.
	 * @return array<string, mixed>|WP_Error The comment as the client sees it.
	 */
	public static function add_comment( TaskModel $task, ProjectModel $project, ContactModel $contact, string $raw ) {
		$text = trim( sanitize_textarea_field( $raw ) );
		if ( '' === $text ) {
			return new WP_Error( 'empty_comment', __( 'Write something first.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > self::MAX_COMMENT_LENGTH ) {
			return new WP_Error(
				'comment_too_long',
				/* translators: %d: most characters a comment may have */
				sprintf( __( 'A comment can be at most %d characters.', 'doublescale' ), self::MAX_COMMENT_LENGTH ),
				array( 'status' => 400 )
			);
		}

		$key  = 'doublescale_portal_task_comments_' . (int) $contact->id;
		$hits = (int) get_transient( $key );
		if ( $hits >= self::HOURLY_COMMENT_LIMIT ) {
			return new WP_Error(
				'portal_comment_rate_limited',
				__( 'You have sent a lot of comments in a short time. Please wait a while and try again.', 'doublescale' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

		$name     = self::contact_name( $contact );
		$activity = ActivityModel::create(
			array(
				'activity_type' => 'note',
				'user_id'       => null,
				'data'          => array(
					// Stored as the team's editor stores it, so their list shows it as written.
					'content'        => wpautop( esc_html( $text ) ),
					'actor'          => 'contact',
					'actor_name'     => $name,
					'contact_id'     => (int) $contact->id,
					'client_visible' => true,
					'source'         => 'client_portal',
				),
			)
		);
		if ( ! $activity ) {
			return new WP_Error( 'comment_failed', __( 'The comment could not be saved. Please try again.', 'doublescale' ), array( 'status' => 500 ) );
		}
		ActivityAssociationModel::create(
			array(
				'activity_id' => (int) $activity->id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_TASK,
				'entity_id'   => (int) $task->id,
			)
		);

		self::notify_team(
			$task,
			$project,
			/* translators: %s: task title */
			sprintf( __( 'Client comment on "%s"', 'doublescale' ), (string) $task->title ),
			/* translators: 1: client name, 2: comment excerpt */
			sprintf( __( '%1$s commented: %2$s', 'doublescale' ), $name, self::excerpt( $text ) )
		);

		return self::shape_comment( $activity, $contact );
	}

	/**
	 * Tell the client about a team comment written to them: in the portal's
	 * bell, emailed as they chose (free's PortalNotifications). An older free
	 * without the bell gets the email straight away, as before.
	 *
	 * @param TaskModel     $task     Task.
	 * @param ActivityModel $activity The team's comment.
	 * @return void
	 */
	public static function tell_client_about_comment( TaskModel $task, ActivityModel $activity ): void {
		$data   = is_array( $activity->data ) ? $activity->data : array();
		$author = wp_get_current_user();
		$name   = $author && $author->exists() ? (string) $author->display_name : __( 'Your team', 'doublescale' );
		$told   = self::notify_client(
			$task,
			'task_comment',
			/* translators: %s: task title */
			sprintf( __( 'New message on "%s"', 'doublescale' ), (string) $task->title ),
			$name . ': ' . self::plain( (string) ( $data['content'] ?? '' ) )
		);
		if ( ! $told ) {
			self::email_client( $task, $activity );
		}
	}

	/**
	 * Tell the client a task file is now theirs to see.
	 *
	 * @param TaskModel       $task Task.
	 * @param AttachmentModel $file File.
	 * @return void
	 */
	public static function tell_client_about_file( TaskModel $task, AttachmentModel $file ): void {
		self::notify_client(
			$task,
			'task_file',
			/* translators: %s: task title */
			sprintf( __( 'File shared on "%s"', 'doublescale' ), (string) $task->title ),
			(string) $file->file_name
		);
	}

	/**
	 * A team update for the client in the portal's bell (free decides about
	 * the email). False when the installed free has no bell.
	 *
	 * @param TaskModel $task  Task.
	 * @param string    $type  Type.
	 * @param string    $title Title.
	 * @param string    $body  Body.
	 * @return bool
	 */
	private static function notify_client( TaskModel $task, string $type, string $title, string $body ): bool {
		if ( ! class_exists( PortalNotifications::class ) ) {
			return false;
		}
		$project = ProjectModel::find( (int) $task->entity_id );
		if ( ! $project ) {
			return true;
		}
		// The project's contact, and on a company project the colleagues who
		// took part in this task. Never the whole company.
		$recipients = array_unique( array_merge( array( (int) $project->contact_id ), self::participants( $task, $project ) ) );
		foreach ( $recipients as $contact_id ) {
			PortalNotifications::add(
				$contact_id,
				array(
					'category' => PortalNotifications::TEAM_UPDATES,
					'type'     => $type,
					'area'     => 'projects',
					'title'    => $title,
					'body'     => $body,
					'route'    => '/projects/' . (int) $project->id . '/tasks/' . (int) $task->id,
				)
			);
		}
		return true;
	}

	/**
	 * Colleagues who commented on a task of a company-shared project and still
	 * see the company's projects.
	 *
	 * @param TaskModel    $task    Task.
	 * @param ProjectModel $project Its project.
	 * @return int[] Contact ids.
	 */
	private static function participants( TaskModel $task, ProjectModel $project ): array {
		$company_id = self::project_company( $project );
		$service    = \DoubleScale\Pro\Modules\Companies\Services\CompanyService::class;
		if ( $company_id <= 0 || ! class_exists( $service ) ) {
			return array();
		}
		$ids = ActivityAssociationModel::query()
			->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TASK )
			->where( 'entity_id', (int) $task->id )
			->pluck( 'activity_id' )
			->all();
		if ( empty( $ids ) ) {
			return array();
		}

		$writers = array();
		foreach ( ActivityModel::whereIn( 'id', $ids )->where( 'activity_type', 'note' )->get() as $note ) {
			if ( ! self::from_client( $note ) ) {
				continue;
			}
			$data   = is_array( $note->data ) ? $note->data : array();
			$writer = (int) ( $data['contact_id'] ?? 0 );
			if ( $writer > 0 && $writer !== (int) $project->contact_id && in_array( $company_id, $service::company_ids_for( $writer, 'projects' ), true ) ) {
				$writers[ $writer ] = true;
			}
		}
		return array_keys( $writers );
	}

	/**
	 * Email the client a team comment written to them, with a link to the task.
	 * Nothing when they cannot open it in the portal.
	 *
	 * @param TaskModel     $task     Task.
	 * @param ActivityModel $activity The team's comment.
	 * @return void
	 */
	public static function email_client( TaskModel $task, ActivityModel $activity ): void {
		$project = ProjectModel::find( (int) $task->entity_id );
		$contact = $project ? ContactModel::find( (int) $project->contact_id ) : null;
		if ( ! $project || ! $contact || ! is_email( (string) $contact->email ) ) {
			return;
		}
		if ( function_exists( 'doublescale_portal_can' ) && ! doublescale_portal_can( 'projects', $contact ) ) {
			return;
		}
		$url = PortalUrl::get_route_url( 'projects/' . (int) $project->id . '/tasks/' . (int) $task->id );
		if ( '' === $url ) {
			return;
		}

		$author  = wp_get_current_user();
		$name    = $author && $author->exists() ? (string) $author->display_name : __( 'Your team', 'doublescale' );
		$data    = is_array( $activity->data ) ? $activity->data : array();
		$text    = self::plain( (string) ( $data['content'] ?? '' ) );
		/* translators: %s: task title */
		$subject = sprintf( __( 'New message on "%s"', 'doublescale' ), (string) $task->title );
		$body    = '<p>' . esc_html(
			sprintf(
				/* translators: 1: team member, 2: task title, 3: project title */
				__( '%1$s wrote on the task "%2$s" in your project "%3$s":', 'doublescale' ),
				$name,
				(string) $task->title,
				(string) $project->title
			)
		) . '</p>'
			. '<blockquote>' . nl2br( esc_html( $text ) ) . '</blockquote>'
			. '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'View the task and reply', 'doublescale' ) . '</a></p>';

		try {
			( new Emails() )->send( (string) $contact->email, $subject, $body );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Task comment email to the client failed',
					array(
						'task_id' => (int) $task->id,
						'error'   => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Files the client sees on a task, newest first.
	 *
	 * @param TaskModel    $task    Task.
	 * @param ContactModel $contact The client.
	 * @return array<int, array<string, mixed>>
	 */
	public static function files( TaskModel $task, ContactModel $contact ): array {
		$service    = new AttachmentService();
		$project    = $task->project_id ? ProjectModel::find( (int) $task->project_id ) : null;
		$company_id = $project ? self::project_company( $project ) : 0;
		$rows       = array();
		foreach ( self::task_files( $task )->orderBy( 'created_at', 'desc' )->orderBy( 'id', 'desc' )->get() as $file ) {
			$mine = self::uploaded_by_contact( $file, $contact );
			// Another client's upload (a task moved between clients' projects)
			// stays theirs, unless a colleague shared it on a company project;
			// the team's files follow the task.
			$client_upload = ! (int) $file->user_id && (int) $file->contact_id > 0;
			$colleague     = $client_upload && $company_id > 0 && class_exists( \DoubleScale\Pro\Modules\Companies\Services\CompanyService::class )
				&& \DoubleScale\Pro\Modules\Companies\Services\CompanyService::is_member( $company_id, (int) $file->contact_id );
			if ( ! $mine && ! $colleague && ( $client_upload || ! self::is_client_visible( $file ) ) ) {
				continue;
			}
			$rows[] = array(
				'id'          => (int) $file->id,
				'file_name'   => (string) $file->file_name,
				'file_size'   => (int) $file->file_size,
				'file_type'   => (string) $file->file_type,
				'created_at'  => $file->created_at ? (string) $file->created_at : null,
				'url'         => $service->signed_url( $file ),
				'uploaded_by' => ! (int) $file->user_id && (int) $file->contact_id ? 'client' : 'team',
				'mine'        => $mine,
			);
		}
		return $rows;
	}

	/**
	 * What the Files tab says about uploading.
	 *
	 * @return array{can_upload: bool, allowed_extensions: string[], max_file_size_bytes: int}
	 */
	public static function upload_policy(): array {
		$allowed = PortalUploadSettings::allowed_extensions();
		return array(
			'can_upload'          => PortalUploadSettings::is_enabled() && ! empty( $allowed ),
			'allowed_extensions'  => $allowed,
			'max_file_size_bytes' => PortalUploadSettings::max_file_size_bytes(),
		);
	}

	/**
	 * Store a file the client uploaded to the task and tell the team.
	 *
	 * @param TaskModel                 $task    Task (of $project).
	 * @param ProjectModel              $project Project.
	 * @param ContactModel              $contact The client.
	 * @param array<string, mixed>|null $file    PHP upload array.
	 * @return AttachmentModel|WP_Error
	 */
	public static function add_file( TaskModel $task, ProjectModel $project, ContactModel $contact, ?array $file ) {
		if ( ! PortalUploadSettings::is_enabled() ) {
			return new WP_Error( 'rest_portal_uploads_disabled', __( 'File uploads are not available.', 'doublescale' ), array( 'status' => 403 ) );
		}

		// The same hourly budget as the portal's Files page.
		$key  = PortalFileService::rate_limit_key( (int) $contact->id );
		$hits = (int) get_transient( $key );
		if ( $hits >= PortalUploadSettings::HOURLY_UPLOAD_LIMIT ) {
			return new WP_Error(
				'rest_portal_upload_rate_limited',
				__( 'You have uploaded a lot of files in a short time. Please wait a while and try again.', 'doublescale' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

		if ( self::task_files( $task )->count() >= self::MAX_FILES_PER_TASK ) {
			return new WP_Error(
				'too_many_files',
				/* translators: %d: most files a task can hold */
				sprintf( __( 'This task already has %d files.', 'doublescale' ), self::MAX_FILES_PER_TASK ),
				array( 'status' => 400 )
			);
		}

		$checked = self::check_upload( $file );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$type = ( new PortalFileService() )->check_file( (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ) );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$file['name'] = $type['name'];

		$attachment = ( new AttachmentService() )->store_upload(
			$file,
			self::ATTACHABLE_TYPE,
			(int) $task->id,
			array( 'contact_id' => (int) $contact->id ),
			array(
				'status'             => 'active',
				'storage_subdir'     => PortalFileService::STORAGE_BASE . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ),
				'protected_base_dir' => PortalFileService::STORAGE_BASE,
				'randomize_name'     => true,
				'max_size_bytes'     => PortalUploadSettings::max_file_size_bytes(),
				'meta'               => array(
					'task_id'        => (int) $task->id,
					'contact_id'     => (int) $contact->id,
					'source'         => 'client_portal',
					'client_visible' => true,
				),
			)
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		do_action( 'doublescale_task_file_attached', $task, $attachment );

		$name = self::contact_name( $contact );
		self::notify_team(
			$task,
			$project,
			/* translators: %s: task title */
			sprintf( __( 'Client file on "%s"', 'doublescale' ), (string) $task->title ),
			/* translators: 1: client name, 2: file name */
			sprintf( __( '%1$s uploaded %2$s', 'doublescale' ), $name, (string) $attachment->file_name )
		);

		return $attachment;
	}

	/**
	 * Remove a file the client uploaded to the task; anything else is a 404.
	 *
	 * @param TaskModel    $task          Task.
	 * @param ContactModel $contact       The client.
	 * @param int          $attachment_id File.
	 * @return true|WP_Error
	 */
	public static function delete_file( TaskModel $task, ContactModel $contact, int $attachment_id ) {
		$file = self::task_files( $task )->where( 'id', $attachment_id )->first();
		if ( ! $file instanceof AttachmentModel || ! self::uploaded_by_contact( $file, $contact ) ) {
			return new WP_Error( 'file_not_found', __( 'File not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		do_action( 'doublescale_task_file_removed', $task, $file );
		$file->delete();

		return true;
	}

	/**
	 * Whether the team marked a task file visible to the client (or the client
	 * uploaded it).
	 *
	 * @param AttachmentModel $file File.
	 * @return bool
	 */
	public static function is_client_visible( AttachmentModel $file ): bool {
		$meta = is_array( $file->meta ) ? $file->meta : array();
		return ! empty( $meta['client_visible'] ) || ( ! (int) $file->user_id && (int) $file->contact_id > 0 );
	}

	/**
	 * Set whether the client sees a task file. A client's own upload stays visible.
	 *
	 * @param AttachmentModel $file    File.
	 * @param bool            $visible Visible.
	 * @return AttachmentModel
	 */
	public static function set_client_visible( AttachmentModel $file, bool $visible ): AttachmentModel {
		if ( ! (int) $file->user_id && (int) $file->contact_id > 0 ) {
			return $file;
		}
		$meta                   = is_array( $file->meta ) ? $file->meta : array();
		$newly_shared           = $visible && empty( $meta['client_visible'] );
		$meta['client_visible'] = $visible;
		if ( $visible ) {
			$meta['client_visible_at'] = current_time( 'mysql', true );
		} else {
			unset( $meta['client_visible_at'] );
		}
		// The whole array: a nested key set on the cast attribute is lost.
		$file->meta = $meta;
		$file->save();
		if ( $newly_shared ) {
			$task = TaskModel::find( (int) $file->attachable_id );
			if ( $task ) {
				self::tell_client_about_file( $task, $file );
			}
		}
		return $file;
	}

	/**
	 * A team comment as plain text for the client: mentions read "@Name",
	 * paragraphs and line breaks become new lines, no markup.
	 *
	 * @param string $html Stored comment.
	 * @return string
	 */
	public static function plain( string $html ): string {
		$text = (string) preg_replace( '/@\[([^\]]+)\]\(user:\d+\)/', '@$1', $html );
		$text = (string) preg_replace( '#<br\s*/?>\s*#i', "\n", $text );
		$text = (string) preg_replace( '#</(p|div|li|h[1-6])>\s*#i', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Tell the task's assignee and the project's owner, each once.
	 *
	 * @param TaskModel    $task    Task.
	 * @param ProjectModel $project Project.
	 * @param string       $title   Title.
	 * @param string       $message Message.
	 * @return void
	 */
	private static function notify_team( TaskModel $task, ProjectModel $project, string $title, string $message ): void {
		if ( ! class_exists( NotificationService::class ) ) {
			return;
		}
		$link = array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=projects/' . (int) $project->id ),
			'mobile' => '/tasks/' . (int) $task->id,
		);
		$users = array_unique( array_filter( array( (int) $task->assigned_to, (int) $project->owner_id ) ) );
		foreach ( $users as $user_id ) {
			NotificationService::create(
				$user_id,
				$title,
				$message,
				$link,
				NotificationCategories::TASKS_COMMENT,
				array(
					'task_id'    => (int) $task->id,
					'project_id' => (int) $project->id,
				)
			);
		}
	}

	/**
	 * @param TaskModel $task Task.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder|\DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	private static function task_files( TaskModel $task ) {
		return AttachmentModel::forType( self::ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $task->id )
			->active();
	}

	/**
	 * @param AttachmentModel $file    File.
	 * @param ContactModel    $contact The client.
	 * @return bool
	 */
	private static function uploaded_by_contact( AttachmentModel $file, ContactModel $contact ): bool {
		return ! (int) $file->user_id && (int) $file->contact_id === (int) $contact->id;
	}

	/**
	 * The portal's checks on the upload itself (the type is checked after).
	 *
	 * @param array<string, mixed>|null $file PHP upload array.
	 * @return true|WP_Error
	 */
	private static function check_upload( ?array $file ) {
		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		$size  = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || $size > PortalUploadSettings::max_file_size_bytes() ) {
			return new WP_Error(
				'file_too_large',
				/* translators: %s: largest file size, e.g. "10 MB" */
				sprintf( __( 'This file is too large. The limit is %s.', 'doublescale' ), size_format( PortalUploadSettings::max_file_size_bytes() ) ),
				array( 'status' => 400 )
			);
		}
		if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'The upload did not complete. Please try again.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( $size <= 0 ) {
			return new WP_Error( 'empty_file', __( 'This file is empty.', 'doublescale' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return string
	 */
	private static function contact_name( ContactModel $contact ): string {
		$name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
		return '' !== $name ? $name : (string) $contact->email;
	}

	/**
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		// Characters, not bytes: a byte cut tears Arabic mid-letter.
		return mb_strlen( $text ) <= 120 ? $text : mb_substr( $text, 0, 117 ) . '...';
	}
}
