<?php
/**
 * A project's team, and the people who may join or own a project.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Projects\Capabilities;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Rest\ProjectShaper;
use DoubleScale\Pro\Modules\Projects\Services\ProjectTeam;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Same shape as the contact collaborators routes: one route, three methods.
 */
class RestProjectTeamController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'projects';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/team',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_team' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_member' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'user_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_member' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'user_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/team-candidates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_team_candidates' ),
				'permission_callback' => array( $this, 'team_candidates_permissions_check' ),
				'args'                => $this->people_params() + array(
					'project_id' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/owner-candidates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_owner_candidates' ),
				'permission_callback' => array( $this, 'owner_candidates_permissions_check' ),
				'args'                => $this->people_params(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_team( $request ) {
		$project = ProjectModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $project || ! Capabilities::can_read_project( (int) $project->id ) ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $this->team_response( $project );
	}

	/**
	 * Add someone to the team (the owner or a manager).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_member( $request ) {
		$project = ProjectModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $project ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$user_id = (int) $request->get_param( 'user_id' );
		$error   = ProjectTeam::candidate_error( $project, $user_id );
		if ( $error ) {
			return $error;
		}

		ProjectTeam::add( (int) $project->id, $user_id );

		return $this->team_response( $project );
	}

	/**
	 * Take someone off the team (the owner or a manager).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_member( $request ) {
		$project = ProjectModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $project ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$user_id = (int) $request->get_param( 'user_id' );
		if ( (int) $project->owner_id === $user_id ) {
			return new WP_Error( 'doublescale_project_member_is_owner', __( 'This person owns the project. Change the owner instead.', 'doublescale' ), array( 'status' => 400 ) );
		}

		ProjectTeam::remove( (int) $project->id, $user_id );

		return $this->team_response( $project );
	}

	/**
	 * People who may join a team: agents who can open projects (CRM Agent,
	 * Project Member). Managers already see every project, so they are offered
	 * only to themselves ("me"), first. The owner and members are left out.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_team_candidates( $request ) {
		$project_id = (int) $request->get_param( 'project_id' );
		$exclude    = array();
		if ( $project_id > 0 ) {
			$project = ProjectModel::find( $project_id );
			if ( ! $project ) {
				return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			$exclude = ProjectTeam::team_user_ids( $project );
		}

		$me     = get_current_user_id();
		$people = array();
		foreach ( $this->find_staff( (string) $request->get_param( 'search' ) ) as $user ) {
			$id = (int) $user->ID;
			if ( $id === $me || in_array( $id, $exclude, true ) ) {
				continue;
			}
			if ( PermissionsCompat::is_agent_tier( $id ) && PermissionsCompat::has_project_access( $id ) ) {
				$people[] = $user;
			}
		}

		$offer_me = ! in_array( $me, $exclude, true )
			&& ! PermissionsCompat::is_agent_tier( $me )
			&& PermissionsCompat::has_project_access( $me )
			&& $this->matches_search( wp_get_current_user(), (string) $request->get_param( 'search' ) );
		if ( $offer_me ) {
			array_unshift( $people, wp_get_current_user() );
		}

		return $this->people_response( $people, $request );
	}

	/**
	 * People who may own a project: anyone who can open projects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_owner_candidates( $request ) {
		$people = array();
		foreach ( $this->find_staff( (string) $request->get_param( 'search' ) ) as $user ) {
			if ( PermissionsCompat::has_project_access( (int) $user->ID ) ) {
				$people[] = $user;
			}
		}

		return $this->people_response( $people, $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function read_permissions_check( $request ) {
		return PermissionsCompat::has_project_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function manage_permissions_check( $request ) {
		return Capabilities::can_manage_project_team( (int) $request->get_param( 'id' ) );
	}

	/**
	 * With a project: whoever may change its team. Without (a project being
	 * created): whoever may create projects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function team_candidates_permissions_check( $request ) {
		$project_id = (int) $request->get_param( 'project_id' );
		if ( $project_id > 0 ) {
			return Capabilities::can_manage_project_team( $project_id );
		}

		$user_id = get_current_user_id();
		return user_can( $user_id, 'doublescale_project_manage_own_projects' )
			|| user_can( $user_id, 'doublescale_project_manage_all_projects' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function owner_candidates_permissions_check( $request ) {
		return PermissionsCompat::can_assign_project_owner();
	}

	/**
	 * @param ProjectModel $project Project.
	 * @return WP_REST_Response
	 */
	private function team_response( ProjectModel $project ): WP_REST_Response {
		return new WP_REST_Response(
			ProjectShaper::team( $project, Capabilities::can_manage_project_team( (int) $project->id ) ),
			200
		);
	}

	/**
	 * Staff with a role that could reach projects, matching a search.
	 *
	 * @param string $search Name, login or email (2+ characters), or empty.
	 * @return \WP_User[]
	 */
	private function find_staff( string $search ): array {
		$roles = array_values(
			array_filter(
				array(
					'administrator',
					UserRoles::CRM_MANAGER,
					PermissionsCompat::crm_agent_role(),
					UserRoles::PROJECT_MANAGER,
					UserRoles::PROJECT_MEMBER,
				)
			)
		);

		$args = array(
			'role__in' => $roles,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'number'   => 500,
		);

		$search = trim( $search );
		if ( strlen( $search ) >= 2 ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		return get_users( $args );
	}

	/**
	 * @param \WP_User $user   User.
	 * @param string   $search Search text.
	 * @return bool
	 */
	private function matches_search( $user, string $search ): bool {
		$search = trim( $search );
		if ( strlen( $search ) < 2 ) {
			return true;
		}
		foreach ( array( $user->user_login, $user->user_email, $user->display_name ) as $field ) {
			if ( false !== stripos( (string) $field, $search ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The users/frontend shape, so the same picker component reads it.
	 *
	 * @param \WP_User[]      $people  People, in order.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function people_response( array $people, $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$total    = count( $people );
		$me       = get_current_user_id();

		$users = array();
		foreach ( array_slice( $people, ( $page - 1 ) * $per_page, $per_page ) as $user ) {
			$id      = (int) $user->ID;
			$users[] = array(
				'id'           => $id,
				'name'         => (string) $user->display_name,
				'display_name' => (string) $user->display_name,
				'email'        => (string) $user->user_email,
				'avatar_url'   => (string) get_avatar_url( $id, array( 'size' => 48 ) ),
				'role_label'   => ProjectTeam::role_label( $id ),
				'is_me'        => $id === $me,
			);
		}

		return new WP_REST_Response(
			array(
				'users'      => $users,
				'pagination' => array(
					'total'        => $total,
					'per_page'     => $per_page,
					'current_page' => $page,
					'total_pages'  => (int) max( 1, ceil( $total / $per_page ) ),
				),
			),
			200
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function people_params(): array {
		return array(
			'search'   => array( 'type' => 'string' ),
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
			),
		);
	}
}
