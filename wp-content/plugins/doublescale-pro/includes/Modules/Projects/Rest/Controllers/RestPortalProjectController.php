<?php
/**
 * Client Portal project endpoints.
 *
 *   GET /doublescale/v1/portal/projects             (the contact's projects)
 *   GET /doublescale/v1/portal/projects/{id}        (one project, ownership-gated)
 *   GET /doublescale/v1/portal/projects/{id}/tasks  (its tasks, when shared)
 *   GET|POST /doublescale/v1/portal/projects/{id}/tasks/{task_id}/comments
 *   GET|POST /doublescale/v1/portal/projects/{id}/tasks/{task_id}/files
 *   DELETE   /doublescale/v1/portal/projects/{id}/tasks/{task_id}/files/{file_id}
 *           (a task's thread with the team and its files: {@see PortalTaskThread})
 *
 * Both routes reuse {@see PortalIdentity} for the login + lowercased-email
 * contact resolve, and gate ownership on `contact_id` returning 404 (not 403)
 * on a mismatch so project ids can't be enumerated. Payloads are shaped to the
 * data a customer may see — title, status, dates, budget, progress (with the
 * task count behind it), the invoices and proposals they were sent, the files
 * the team shared and the client-safe part of the history — never
 * owner/internal fields, discussions, unshared files, notes or emails. Task
 * titles only from a project the team shares its tasks on (`portal_show_tasks`):
 * the task itself with its assignee's name (never their email), and of the
 * team's comments only those marked Visible to client.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\ModuleManager;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Modules\Projects\Rest\ProjectShaper;
use DoubleScale\Pro\Modules\Projects\Services\PortalTaskThread;
use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalProjectController.
 */
class RestPortalProjectController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/projects',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_projects' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/projects/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_project' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/projects/(?P<id>[\d]+)/tasks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_project_tasks' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		$task_base = '/' . $this->rest_base . '/projects/(?P<id>[\d]+)/tasks/(?P<task_id>[\d]+)';
		register_rest_route(
			$this->namespace,
			$task_base . '/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_task_comments' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_task_comment' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			$task_base . '/files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_task_files' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_task_file' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			$task_base . '/files/(?P<file_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_task_file' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * List the contact's projects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_projects( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'projects' );
		if ( $disabled ) {
			return $disabled;
		}

		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response( array( 'data' => array() ), 200 );
		}

		$query = \DoubleScale\Pro\Modules\Projects\Services\ProjectPortalScope::apply( ProjectModel::with( array( 'status' ) ), (int) $contact->id )
			->orderBy( 'id', 'desc' )
			->limit( 100 );

		// Count every project's tasks in the same query, not two per project.
		$counts_tasks = doublescale_module_data_ready( 'tasks' );
		if ( $counts_tasks ) {
			$query->withCount(
				array(
					'tasks as tasks_total',
					'tasks as tasks_completed' => static function ( $tasks ) {
						$tasks->where( 'status', TaskStatus::COMPLETED );
					},
				)
			);
		}

		// Whether this client can see tickets at all; each project still has to show them.
		$viewer       = PortalIdentity::current_contact();
		$tickets_seen = $viewer
			&& class_exists( '\DoubleScale\Modules\Support\Services\TicketProject' )
			&& ModuleManager::isEnabled( 'support' )
			&& \DoubleScale\Modules\Support\Services\TicketProject::portal_has_tickets( $viewer );

		$data = array();
		foreach ( $query->get() as $project ) {
			$row   = $this->shape_project( $project );
			// The project shows its tickets to the client (they can file tickets under it).
			$row['show_tickets'] = $tickets_seen && (bool) $project->portal_show_tickets;
			$tasks = $counts_tasks
				? $this->task_counts( (int) $project->tasks_total, (int) $project->tasks_completed )
				: $this->task_counts( 0, 0 );

			// Same rules as the detail: progress from tasks or as set by hand,
			// and the task count only when it is what the progress comes from.
			$row['progress']      = $project->calculate_progress ? $tasks['percent'] : (int) ( $project->progress ?? 0 );
			$row['task_progress'] = $project->calculate_progress ? $tasks : null;

			$data[] = $row;
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * Same shape and rounding as {@see ProjectModel::getTaskProgressAttribute()}.
	 *
	 * @param int $total     Project tasks.
	 * @param int $completed Completed project tasks.
	 * @return array{total:int, completed:int, percent:int}
	 */
	private function task_counts( int $total, int $completed ): array {
		return array(
			'total'     => $total,
			'completed' => $completed,
			'percent'   => $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0,
		);
	}

	/**
	 * One project detail (ownership-gated), including invoice/proposal totals.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_project( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'projects' );
		if ( $disabled ) {
			return $disabled;
		}

		$project = $this->resolve_own_project( $request );
		if ( $project instanceof WP_Error ) {
			return $project;
		}

		$documents = ProjectShaper::client_documents( $project );

		$data                  = $this->shape_project( $project );
		$data['progress']      = $project->resolveProgress();
		// The task count only explains the percentage when it comes from tasks.
		$data['task_progress'] = $project->calculate_progress ? $project->task_progress : null;
		$data['documents']     = $documents;
		$data['financials']    = ProjectShaper::client_financials( $documents );
		// Only the files the team shared, and only client-safe history.
		$data['attachments']   = ProjectShaper::client_attachments( $project );
		// The custom fields the team marked "Visible to the client".
		$data['custom_fields'] = ProjectShaper::client_fields( $project );
		$data['activities']    = ProjectShaper::client_activities( $project, $documents, PortalIdentity::current_contact() );
		// The portal shows a Tasks tab (GET …/tasks) only when this says so.
		$data['show_tasks']    = $this->shares_tasks( $project );
		// A Tickets tab (GET portal/tickets?project_id=…) only when the team shows
		// this project's tickets, Support is on, and the client has Support.
		$data['show_tickets']  = $this->shows_tickets( $project );
		// The hours the team expects: the estimate goes with the time worked,
		// only where the team shares that. How it is billed stays with the team.
		$data['estimated_hours'] = $this->estimated_hours( $project );

		/**
		 * What a client sees of one of their projects. Add-ons add their part
		 * here (time tracking adds `time_worked`); keep it client-safe.
		 *
		 * @param array<string, mixed> $data    Client-facing project.
		 * @param ProjectModel         $project Project.
		 */
		$data = (array) apply_filters( 'doublescale_portal_project_data', $data, $project );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * The tasks of one of the client's projects, for its board, list and
	 * calendar: every column of the team's task board (statuses are shared by
	 * all projects) and the project's tasks by due date. 404 unless the team
	 * shares the project's tasks, like a project that is not theirs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_project_tasks( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'projects' );
		if ( $disabled ) {
			return $disabled;
		}

		$project = $this->resolve_own_project( $request );
		if ( $project instanceof WP_Error ) {
			return $project;
		}

		// A free that predates the Tasks tab does not guard this route: hold it
		// to the client's own access here too.
		$contact = PortalIdentity::current_contact();
		if ( function_exists( 'doublescale_portal_can' ) && ! doublescale_portal_can( 'projects', $contact ) ) {
			return new WP_Error(
				'portal_feature_unavailable',
				__( 'This part of the portal is not available.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->shares_tasks( $project ) ) {
			return new WP_Error(
				'project_tasks_not_shared',
				__( 'This project does not share its tasks.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$statuses = TaskStatusModel::orderBy( 'sort_order', 'asc' )->orderBy( 'id', 'asc' )->get();
		$columns  = array();
		$first    = array(
			'open'   => null,
			'closed' => null,
		);
		foreach ( $statuses as $status ) {
			$is_closed = 'closed' === (string) $status->status;
			$columns[] = array(
				'id'        => (int) $status->id,
				'name'      => (string) $status->name,
				'color'     => (string) ( $status->color ?? '' ),
				'is_closed' => $is_closed,
			);
			$kind = $is_closed ? 'closed' : 'open';
			if ( null === $first[ $kind ] ) {
				$first[ $kind ] = (int) $status->id;
			}
		}
		$known = array_column( $columns, 'id' );

		$tasks = TaskModel::forProject( (int) $project->id )
			->with( array( 'subtasks', 'assignedUser' ) )
			->orderBy( 'due_date', 'asc' )
			->orderBy( 'id', 'asc' )
			->limit( 500 )
			->get();

		$rows = array();
		foreach ( $tasks as $task ) {
			$is_completed = TaskStatus::COMPLETED === (string) $task->status;
			$status_id    = (int) $task->status_id;
			// Tasks the team's board has not placed yet (or whose column was
			// removed) show where the board puts them: the first open column,
			// or the first closed one when done. Reading never moves them.
			if ( ! in_array( $status_id, $known, true ) ) {
				$status_id = (int) ( $is_completed ? ( $first['closed'] ?? $first['open'] ) : ( $first['open'] ?? $first['closed'] ) );
			}

			$subtasks = array();
			foreach ( $task->subtasks as $subtask ) {
				$subtasks[] = array(
					'id'           => (int) $subtask->id,
					'title'        => (string) $subtask->title,
					'is_completed' => (bool) $subtask->is_completed,
					'due_date'     => $this->day( $subtask->due_date ),
				);
			}

			// Who does it: the name only (the user chose to show it; never the email).
			$assignee = $task->assignedUser ? (string) ( $task->assignedUser->toArray()['display_name'] ?? '' ) : '';

			$rows[] = array(
				'id'               => (int) $task->id,
				'title'            => (string) $task->title,
				'description'      => (string) $task->description,
				'assignee'         => '' !== $assignee ? array( 'name' => $assignee ) : null,
				'due_time'         => $task->due_time ? substr( (string) $task->due_time, 0, 5 ) : null,
				'status_id'        => $status_id,
				'is_completed'     => $is_completed,
				'priority'         => (string) $task->priority,
				'due_date'         => $this->day( $task->due_date ),
				'completed_at'     => $task->completed_at ? (string) $task->completed_at : null,
				'is_overdue'       => ! $is_completed && (bool) $task->is_overdue,
				'subtasks'         => $subtasks,
				'subtask_progress' => array(
					'total'     => count( $subtasks ),
					'completed' => count( array_filter( array_column( $subtasks, 'is_completed' ) ) ),
				),
			);
		}

		return new WP_REST_Response(
			array(
				'statuses' => $columns,
				'tasks'    => $rows,
				// The task window's Comments and Files tabs.
				'client'   => array(
					'comments' => PortalTaskThread::portal_supported(),
					'files'    => PortalTaskThread::portal_supported(),
				),
			),
			200
		);
	}

	/**
	 * The task's thread with the client, oldest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_task_comments( WP_REST_Request $request ) {
		$scope = $this->resolve_shared_task( $request );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		return new WP_REST_Response( array( 'data' => PortalTaskThread::comments( $scope['task'], $scope['contact'] ) ), 200 );
	}

	/**
	 * The client writes to the team on a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_task_comment( WP_REST_Request $request ) {
		$scope = $this->resolve_shared_task( $request );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		$comment = PortalTaskThread::add_comment( $scope['task'], $scope['project'], $scope['contact'], (string) $request->get_param( 'body' ) );
		if ( $comment instanceof WP_Error ) {
			return $comment;
		}
		return new WP_REST_Response( $comment, 201 );
	}

	/**
	 * The task's files the client sees, and whether they may upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_task_files( WP_REST_Request $request ) {
		$scope = $this->resolve_shared_task( $request );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		return new WP_REST_Response(
			array(
				'data'   => PortalTaskThread::files( $scope['task'], $scope['contact'] ),
				'upload' => PortalTaskThread::upload_policy(),
			),
			200
		);
	}

	/**
	 * The client uploads a file to a task (multipart, field `file`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_task_file( WP_REST_Request $request ) {
		$scope = $this->resolve_shared_task( $request );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		$stored = PortalTaskThread::add_file( $scope['task'], $scope['project'], $scope['contact'], $file );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}
		$shaped = array_values(
			array_filter(
				PortalTaskThread::files( $scope['task'], $scope['contact'] ),
				static fn( $row ) => (int) $row['id'] === (int) $stored->id
			)
		);
		return new WP_REST_Response( $shaped[0] ?? array( 'id' => (int) $stored->id ), 201 );
	}

	/**
	 * The client removes a file they uploaded to a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_task_file( WP_REST_Request $request ) {
		$scope = $this->resolve_shared_task( $request );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}
		$deleted = PortalTaskThread::delete_file( $scope['task'], $scope['contact'], (int) $request->get_param( 'file_id' ) );
		if ( $deleted instanceof WP_Error ) {
			return $deleted;
		}
		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => (int) $request->get_param( 'file_id' ),
			),
			200
		);
	}

	/**
	 * The client's project, a task of it, and the client — or the 404 any
	 * other combination gets (not theirs, not shared, another project's task,
	 * an older portal, projects hidden from them).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{project: ProjectModel, task: TaskModel, contact: \DoubleScale\Modules\Contacts\Models\ContactModel}|WP_Error
	 */
	private function resolve_shared_task( WP_REST_Request $request ) {
		$disabled = $this->require_module( 'projects' );
		if ( $disabled ) {
			return $disabled;
		}
		$project = $this->resolve_own_project( $request );
		if ( $project instanceof WP_Error ) {
			return $project;
		}
		$contact = PortalIdentity::current_contact();
		if ( function_exists( 'doublescale_portal_can' ) && ! doublescale_portal_can( 'projects', $contact ) ) {
			return new WP_Error(
				'portal_feature_unavailable',
				__( 'This part of the portal is not available.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$not_found = new WP_Error( 'task_not_found', __( 'Task not found.', 'doublescale' ), array( 'status' => 404 ) );
		if ( ! $this->shares_tasks( $project ) || ! PortalTaskThread::portal_supported() ) {
			return $not_found;
		}
		$task = TaskModel::find( (int) $request->get_param( 'task_id' ) );
		if ( ! $task || TaskEntityType::PROJECT !== (int) $task->entity_type || (int) $task->entity_id !== (int) $project->id ) {
			return $not_found;
		}

		return array(
			'project' => $project,
			'task'    => $task,
			'contact' => $contact,
		);
	}

	/**
	 * Whether the client sees this project's tasks: the team turned it on for
	 * the project and the Tasks module is on.
	 *
	 * @param ProjectModel $project Project.
	 * @return bool
	 */
	/**
	 * Whether the client sees this project's tickets in the portal.
	 *
	 * @param ProjectModel $project Project.
	 */
	private function shows_tickets( ProjectModel $project ): bool {
		$contact = PortalIdentity::current_contact();
		return $contact
			&& class_exists( '\DoubleScale\Modules\Support\Services\TicketProject' )
			&& ModuleManager::isEnabled( 'support' )
			&& \DoubleScale\Modules\Support\Services\TicketProject::portal_allowed( (int) $project->id, $contact );
	}

	private function shares_tasks( ProjectModel $project ): bool {
		return (bool) $project->portal_show_tasks
			&& doublescale_module_data_ready( 'tasks' )
			&& ModuleManager::isEnabled( 'tasks' );
	}

	/**
	 * The hours the team expects the project to take, for the client: only on
	 * a project that shows its time worked, and only when there is one.
	 *
	 * @param ProjectModel $project Project.
	 * @return array{min: float|null, max: float|null}|null
	 */
	private function estimated_hours( ProjectModel $project ): ?array {
		if ( ! $project->portal_show_time ) {
			return null;
		}
		$min = null !== $project->estimated_hours_min ? (float) $project->estimated_hours_min : null;
		$max = null !== $project->estimated_hours_max ? (float) $project->estimated_hours_max : null;
		if ( null === $min && null === $max ) {
			return null;
		}
		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * A stored date as its day ("Y-m-d"), or null when there is none.
	 *
	 * @param mixed $value Stored date or datetime.
	 * @return string|null
	 */
	private function day( $value ): ?string {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		return substr( $value, 0, 10 );
	}

	/**
	 * Resolve a project by id and assert it belongs to the current contact.
	 * Returns a 404 (not 403) on a mismatch so ids can't be enumerated.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return ProjectModel|WP_Error
	 */
	private function resolve_own_project( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		$not_found = new WP_Error(
			'project_not_found',
			__( 'Project not found.', 'doublescale' ),
			array( 'status' => 404 )
		);

		if ( ! $contact ) {
			return $not_found;
		}

		$project = ProjectModel::with( array( 'status' ) )->find( (int) $request->get_param( 'id' ) );
		if ( ! $project || ! \DoubleScale\Pro\Modules\Projects\Services\ProjectPortalScope::owns( $project, (int) $contact->id ) ) {
			return $not_found;
		}

		return $project;
	}

	/**
	 * Shape a project row for the client (no owner/internal fields).
	 *
	 * @param ProjectModel $project Project.
	 * @return array<string, mixed>
	 */
	private function shape_project( ProjectModel $project ): array {
		return array(
			'id'          => (int) $project->id,
			'title'       => (string) $project->title,
			'description' => (string) $project->description,
			'status'      => $project->status
				? array(
					'id'           => (int) $project->status->id,
					'name'         => (string) $project->status->name,
					'color'        => (string) ( $project->status->color ?? '#8775EC' ),
					'bg_color'     => (string) ( $project->status->bg_color ?? '#F4F2FE' ),
					'is_completed' => (bool) $project->status->is_completed,
					'position'     => (int) ( $project->status->position ?? 0 ),
				)
				: null,
			'budget'      => null !== $project->budget ? (float) $project->budget : null,
			'currency'    => (string) $project->currency,
			'start_date'  => $project->start_date ? (string) $project->start_date : null,
			'due_date'    => $project->due_date ? (string) $project->due_date : null,
			'created_at'  => (string) $project->created_at,
			// Set to the client's company: colleagues see it too.
			'shared'      => ! empty( $project->company_id ),
			'company_id'  => $project->company_id ? (int) $project->company_id : null,
		);
	}
}
