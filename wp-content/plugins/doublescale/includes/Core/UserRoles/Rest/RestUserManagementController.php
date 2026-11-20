<?php
/**
 * REST Api: User Management Controller
 *
 * @since 1.0.0
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\UserRoles\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\Services\OwnershipReassigner;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Modules\Contacts\Services\LeadRouter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * User Management REST Controller
 */
class RestUserManagementController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'user-management';

	/**
	 * Register the routes for user management
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Get CRM users
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_crm_users' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
				),
			)
		);

		// Get CRM users for frontend (with search and pagination)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users/frontend',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_crm_users_frontend' ),
					'permission_callback' => array( $this, 'check_sales_rep_permissions' ),
					'args'                => array(
						'search'           => array(
							'description'       => 'Search term for user name or email',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'include'          => array(
							'description'       => 'Comma-separated user IDs to resolve for selected values',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filter_crm_users' => array(
							'description' => 'Limit results to known CRM roles (+ administrator)',
							'type'        => array( 'boolean', 'string' ),
							'default'     => false,
						),
						'per_page'         => array(
							'description' => 'Number of users per page',
							'type'        => 'integer',
							'default'     => 50,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'             => array(
							'description' => 'Page number',
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
						),
						'orderby'          => array(
							'description' => 'Order by field',
							'type'        => 'string',
							'default'     => 'display_name',
							'enum'        => array( 'display_name', 'user_email', 'ID' ),
						),
						'order'            => array(
							'description' => 'Order direction',
							'type'        => 'string',
							'default'     => 'asc',
							'enum'        => array( 'asc', 'desc' ),
						),
					),
				),
			)
		);

		// Assign CRM role to user
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users/(?P<id>[\d]+)/role',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'assign_crm_role' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'id'    => array(
							'required' => true,
							'type'     => 'integer',
						),
						// Accepts an array of roles (checkbox UI — a user can hold
						// several CRM roles). The legacy single `role` string is
						// still accepted for back-compat; see assign_crm_role().
						'roles' => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
								'enum' => UserRoles::get_known_role_slugs(),
							),
						),
						'role'  => array(
							'required' => false,
							'type'     => 'string',
							'enum'     => array_merge( UserRoles::get_assignable_role_slugs(), array( UserRoles::NONE ) ),
						),
					) + self::access_extra_args(),
				),
			)
		);

		// Add user by email and assign role
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_user_by_email' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'email' => array(
							'required' => true,
							'type'     => 'string',
							'format'   => 'email',
						),
						'roles' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
								'enum' => UserRoles::get_known_role_slugs(),
							),
						),
					) + self::access_extra_args(),
				),
			)
		);

		// What a user owns, and handing all of it to someone else (offboarding).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users/(?P<id>[\d]+)/owned-summary',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_owned_summary' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users/(?P<id>[\d]+)/reassign-all',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reassign_all_records' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'reassign_to' => array(
							'description' => __( 'User ID to receive the records, or "unassigned" to send contacts to the unassigned pool.', 'doublescale' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// Delete user CRM role
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_user_crm_role' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
				),
			)
		);
	}

	/**
	 * Get CRM users
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_crm_users( $request ) {
		$known_roles = UserRoles::get_known_role_slugs();

		$users = get_users(
			array(
				'role__in' => $known_roles,
			)
		);

		$formatted_users = array();

		foreach ( $users as $user ) {
			$user_crm_roles = array_values( array_intersect( $user->roles, $known_roles ) );

			$formatted_users[] = self::member_data(
				array(
					'id'         => $user->ID,
					'name'       => $user->display_name,
					'email'      => $user->user_email,
					'user_login' => $user->user_login,
					'role'       => '',
					// Effective role = the highest-priority one the user holds, so a
					// user with CRM Manager + Sales Rep reports as CRM Manager.
					'crm_role'   => UserRoles::get_highest_role( $user_crm_roles ),
					'roles'      => $user_crm_roles,
				),
				$user
			);
		}

		return new WP_REST_Response( $formatted_users, 200 );
	}

	/**
	 * Get CRM users for frontend with search and pagination
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_crm_users_frontend( $request ) {
		$search           = $request->get_param( 'search' );
		$per_page         = $request->get_param( 'per_page' ) ?: 50;
		$page             = $request->get_param( 'page' ) ?: 1;
		$orderby          = $request->get_param( 'orderby' ) ?: 'display_name';
		$order            = $request->get_param( 'order' ) ?: 'asc';
		$filter_crm_users = $request->get_param( 'filter_crm_users' ) ?: false;
		$include_raw      = $request->get_param( 'include' );

		$include_ids = array();
		if ( ! empty( $include_raw ) ) {
			$include_ids = array_values(
				array_filter(
					array_map( 'absint', explode( ',', (string) $include_raw ) )
				)
			);
		}

		$user_args = array(
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => $orderby,
			'order'   => strtoupper( $order ),
			'fields'  => 'all',
		);

		// Resolve specific user IDs (selected-value labels) without requiring search.
		if ( ! empty( $include_ids ) ) {
			$user_args['include'] = $include_ids;
			$user_args['number']  = max( count( $include_ids ), (int) $per_page );
			$user_args['offset']  = 0;
		}

		if ( ! empty( $search ) && strlen( $search ) >= 2 ) {
			$user_args['search']         = '*' . esc_attr( $search ) . '*';
			$user_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		if ( $filter_crm_users && empty( $include_ids ) ) {
			$user_args['role__in'] = array_merge( UserRoles::get_known_role_slugs(), array( UserRoles::ADMINISTRATOR ) );
		}

		$users = get_users( $user_args );

		$count_args = $user_args;
		unset( $count_args['number'] );
		unset( $count_args['offset'] );
		$count_args['fields'] = 'ID';
		$total_users          = count( get_users( $count_args ) );

		$formatted_users = array();
		foreach ( $users as $user ) {
			$formatted_users[] = array(
				'id'           => (int) $user->ID,
				'name'         => $user->display_name,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'username'     => $user->user_login,
				'roles'        => $user->roles,
				'avatar_urls'  => get_avatar_url( $user->ID ),
			);
		}

		$total_pages = ceil( $total_users / $per_page );

		return new WP_REST_Response(
			array(
				'users'      => $formatted_users,
				'pagination' => array(
					'total'        => $total_users,
					'per_page'     => $per_page,
					'current_page' => $page,
					'total_pages'  => $total_pages,
					'has_next'     => $page < $total_pages,
					'has_prev'     => $page > 1,
				),
			),
			200
		);
	}

	/**
	 * Add user by email and assign CRM role
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_user_by_email( $request ) {
		$email = sanitize_email( $request->get_param( 'email' ) );
		$roles = $request->get_param( 'roles' );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Invalid email address.', array( 'status' => 400 ) );
		}

		if ( empty( $roles ) || ! is_array( $roles ) ) {
			return new WP_Error( 'invalid_roles', 'Invalid roles provided.', array( 'status' => 400 ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
		}

		$check_crm_role = Permissions::check_user_has_role( $user->ID );
		if ( $check_crm_role ) {
			return new WP_Error( 'user_already_has_crm_role', 'User already has a CRM role.', array( 'status' => 400 ) );
		}

		$known_roles     = UserRoles::get_known_role_slugs();
		$requested_roles = array_values( array_intersect( (array) $roles, $known_roles ) );
		$prepared_roles  = $this->prepare_role_assignment( $requested_roles );
		if ( is_wp_error( $prepared_roles ) ) {
			return $prepared_roles;
		}
		$requested_roles = $prepared_roles;

		$access = $this->prepare_access_extras( $request, $requested_roles );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		// Add new CRM roles FIRST so listeners on doublescale_user_role_revoked
		// don't observe a transient zero-roles state if any of the existing roles
		// need to be removed afterward. (Same race as assign_crm_role.)
		foreach ( $requested_roles as $role ) {
			if ( ! in_array( $role, (array) $user->roles, true ) ) {
				$user->add_role( $role );
				do_action( 'doublescale_user_role_assigned', $user->ID, $role );
			}
		}

		// Remove only roles the admin explicitly left out of the request.
		foreach ( $known_roles as $crm_role ) {
			if ( in_array( $crm_role, $requested_roles, true ) ) {
				continue;
			}
			if ( in_array( $crm_role, (array) $user->roles, true ) ) {
				$user->remove_role( $crm_role );
				do_action( 'doublescale_user_role_revoked', $user->ID, $crm_role );
			}
		}

		$assigned_crm_roles = array_values( array_intersect( $user->roles, $known_roles ) );

		$this->save_access_extras( $user, $access, $assigned_crm_roles );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'User roles assigned successfully.',
				'user'    => self::member_data(
					array(
						'id'       => $user->ID,
						'name'     => $user->display_name,
						'email'    => $user->user_email,
						'role'     => '',
						// Effective role = highest-priority of the assigned set.
						'crm_role' => UserRoles::get_highest_role( $assigned_crm_roles ),
						'roles'    => $assigned_crm_roles,
					),
					$user
				),
			),
			201
		);
	}

	/**
	 * Assign CRM role to user
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function assign_crm_role( $request ) {
		$user_id = $request->get_param( 'id' );
		$roles   = $request->get_param( 'roles' );

		// Back-compat: accept a single `role` string when `roles[]` is absent.
		if ( ! is_array( $roles ) ) {
			$single = $request->get_param( 'role' );
			$roles  = ( $single && UserRoles::NONE !== $single ) ? array( $single ) : array();
		}

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
		}

		$known_roles      = UserRoles::get_known_role_slugs();
		$requested_roles  = array_values( array_intersect( (array) $roles, $known_roles ) );
		$prepared_roles   = $this->prepare_role_assignment( $requested_roles );
		if ( is_wp_error( $prepared_roles ) ) {
			return $prepared_roles;
		}
		$requested_roles = $prepared_roles;

		$access = $this->prepare_access_extras( $request, $requested_roles );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		// Add the new CRM roles FIRST so listeners on `doublescale_user_role_revoked`
		// observing "user still has a booking-eligible role" don't see a transient
		// zero-roles state during the revoke loop. Otherwise the booking module's
		// purge_host_data() listener triggers mid-swap and deletes the host's
		// calendar+availability before the new role is added.
		foreach ( $requested_roles as $role ) {
			if ( ! in_array( $role, (array) $user->roles, true ) ) {
				$user->add_role( $role );
				do_action( 'doublescale_user_role_assigned', $user->ID, $role );
			}
		}

		// Remove only roles the admin explicitly left out of the request.
		foreach ( $known_roles as $crm_role ) {
			if ( in_array( $crm_role, $requested_roles, true ) ) {
				continue;
			}
			if ( in_array( $crm_role, (array) $user->roles, true ) ) {
				$user->remove_role( $crm_role );
				do_action( 'doublescale_user_role_revoked', $user->ID, $crm_role );
			}
		}

		$assigned_crm_roles = array_values( array_intersect( $user->roles, $known_roles ) );

		$this->save_access_extras( $user, $access, $assigned_crm_roles );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'User role updated successfully.',
				'user'    => self::member_data(
					array(
						'id'       => $user->ID,
						// Effective role = highest-priority of the assigned set.
						'crm_role' => UserRoles::get_highest_role( $assigned_crm_roles ),
						'roles'    => $assigned_crm_roles,
					),
					$user
				),
			),
			200
		);
	}

	/**
	 * Remove user CRM role
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_user_crm_role( $request ) {
		$user_id = $request->get_param( 'id' );

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
		}

		// Removing someone's access must not orphan what they own: ask the
		// manager who takes it over first. (A role change keeps their records
		// with them, so only full removal asks.)
		$handover = $this->hand_over_records( (int) $user->ID, $request );
		if ( is_wp_error( $handover ) ) {
			return $handover;
		}

		$known_roles = UserRoles::get_known_role_slugs();

		foreach ( $known_roles as $crm_role ) {
			if ( in_array( $crm_role, $user->roles, true ) ) {
				$user->remove_role( $crm_role );
				do_action( 'doublescale_user_role_revoked', $user->ID, $crm_role );
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'User CRM access removed successfully.',
			),
			200
		);
	}

	/**
	 * Records a user owns, per kind.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_owned_summary( $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$summary = OwnershipReassigner::summary( $user_id );

		return new WP_REST_Response(
			array(
				'user_id' => $user_id,
				'total'   => (int) array_sum( array_column( $summary, 'count' ) ),
				'records' => $summary,
			),
			200
		);
	}

	/**
	 * Hand every record a user owns to another user (or contacts to the pool).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reassign_all_records( $request ) {
		$user_id = (int) $request->get_param( 'id' );
		if ( ! get_user_by( 'ID', $user_id ) ) {
			return new WP_Error( 'user_not_found', __( 'User not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$result = $this->hand_over_records( $user_id, $request, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'moved'   => $result,
			),
			200
		);
	}

	/**
	 * Offboarding gate. When the user owns nothing, pass. Otherwise the
	 * request must say where the records go (`reassign_to`: a user id, or
	 * "unassigned"); without it the answer is 409 with the summary so the
	 * Team screen can ask the manager. Nothing is ever deleted.
	 *
	 * @param int             $user_id  Leaving user.
	 * @param WP_REST_Request $request  Request (reads `reassign_to`).
	 * @param bool            $required Whether `reassign_to` is mandatory even when nothing is owned.
	 * @return array<string, int>|WP_Error Rows moved per kind.
	 */
	private function hand_over_records( int $user_id, $request, bool $required = false ) {
		$summary = OwnershipReassigner::summary( $user_id );
		$target  = $request->get_param( 'reassign_to' );

		if ( null === $target || '' === $target ) {
			if ( empty( $summary ) && ! $required ) {
				return array();
			}

			return new WP_Error(
				'doublescale_records_need_reassignment',
				__( 'This team member still owns records. Choose who should take them over.', 'doublescale' ),
				array(
					'status'  => 409,
					'total'   => (int) array_sum( array_column( $summary, 'count' ) ),
					'records' => $summary,
				)
			);
		}

		$to_user_id = null;
		if ( 'unassigned' !== $target ) {
			$to_user_id = (int) $target;
			if ( $to_user_id === $user_id || ! LeadRouter::is_valid_owner( $to_user_id ) ) {
				return new WP_Error(
					'doublescale_invalid_reassign_target',
					__( 'Pick another team member who can own contacts.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		try {
			return OwnershipReassigner::reassign_all( $user_id, $to_user_id );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'doublescale_reassign_failed',
				__( 'Some records could not be reassigned. Try again to move the rest.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Extra access an add-on lets the Team screen grant beside the roles
	 * (for example custom object types), sent as `access_extras` keys with
	 * the picker's `access_level`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function access_extra_args(): array {
		return array(
			'access_extras' => array(
				'required' => false,
				'type'     => 'array',
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'access_level'  => array(
				'required' => false,
				'type'     => 'string',
				'enum'     => array( 'manager', 'agent' ),
			),
		);
	}

	/**
	 * A team member's row, with what add-ons add to it (their extra access).
	 *
	 * @param array<string, mixed> $row  Member row.
	 * @param \WP_User            $user The member.
	 * @return array<string, mixed>
	 */
	private static function member_data( array $row, $user ): array {
		/**
		 * Add data to a Team member row, e.g. `access_extras` (the keys of the
		 * add-on rows ticked for them) and `access_level`.
		 *
		 * @param array    $row  Member row.
		 * @param \WP_User $user The member.
		 */
		$filtered = apply_filters( 'doublescale_team_member_data', $row, $user );

		return is_array( $filtered ) ? $filtered : $row;
	}

	/**
	 * Read and check the extra access sent with a role change. Null when the
	 * request has none (older clients), so what is stored stays as it is.
	 *
	 * @param WP_REST_Request    $request Request.
	 * @param array<int, string> $roles   Roles being assigned.
	 * @return array{extras: array<int, string>, level: string}|null|WP_Error
	 */
	private function prepare_access_extras( $request, array $roles ) {
		$has_extras = null !== $request->get_param( 'access_extras' );

		if ( ! $has_extras ) {
			if ( in_array( UserRoles::TEAM_MEMBER, $roles, true ) && 1 === count( $roles ) ) {
				return new WP_Error(
					'invalid_access_extras',
					__( 'Choose at least one module or record type.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
			return null;
		}

		$level  = 'manager' === $request->get_param( 'access_level' ) ? 'manager' : 'agent';
		$extras = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $key ) {
							return is_string( $key ) ? (string) preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( $key ) ) : '';
						},
						(array) $request->get_param( 'access_extras' )
					)
				)
			)
		);

		/**
		 * Keep only the extra access keys an add-on knows.
		 *
		 * @param string[] $extras Requested keys.
		 * @param string   $level  'manager' or 'agent'.
		 * @param string[] $roles  Roles being assigned.
		 */
		$extras = array_values( array_filter( (array) apply_filters( 'doublescale_team_access_extras', $extras, $level, $roles ), 'is_string' ) );

		if ( in_array( UserRoles::TEAM_MEMBER, $roles, true ) && empty( $extras ) ) {
			return new WP_Error(
				'invalid_access_extras',
				__( 'Choose at least one module or record type.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'extras' => $extras,
			'level'  => $level,
		);
	}

	/**
	 * Hand the saved extra access to the add-ons that store it.
	 *
	 * @param \WP_User                                            $user   The member.
	 * @param array{extras: array<int, string>, level: string}|null $access Checked extras, or null when none were sent.
	 * @param array<int, string>                                   $roles  Roles they now hold.
	 * @return void
	 */
	private function save_access_extras( $user, $access, array $roles ): void {
		if ( null === $access ) {
			return;
		}

		/**
		 * A Team member's extra access was saved.
		 *
		 * @param int      $user_id Member.
		 * @param string[] $extras  Keys of the add-on rows ticked for them.
		 * @param string   $level   'manager' or 'agent'.
		 * @param string[] $roles   Roles they now hold.
		 */
		do_action( 'doublescale_team_member_access_saved', (int) $user->ID, $access['extras'], $access['level'], $roles );
	}

	/**
	 * Resolve requested roles into assignable slugs, provisioning definitions when needed.
	 *
	 * @param array<int, string> $requested_roles Known DoubleScale role slugs.
	 * @return array<int, string>|WP_Error
	 */
	private function prepare_role_assignment( array $requested_roles ) {
		if ( empty( $requested_roles ) ) {
			return new WP_Error(
				'invalid_roles',
				__( 'Invalid roles provided.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// One general role on its own, or module roles (one level per module).
		$combination_error = UserRoles::role_combination_error( $requested_roles );
		if ( $combination_error ) {
			return $combination_error;
		}

		$assignable = array();

		foreach ( $requested_roles as $role ) {
			if ( ! UserRoles::is_role_module_enabled( $role ) ) {
				continue;
			}

			if ( null === get_role( $role ) ) {
				UserRoles::provision_role( $role );
			}

			if ( UserRoles::is_role_assignable( $role ) ) {
				$assignable[] = $role;
			}
		}

		if ( empty( $assignable ) ) {
			return new WP_Error(
				'roles_not_assignable',
				__( 'None of the selected roles could be assigned. Check that DoubleScale Pro is active and the required module is enabled.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return $assignable;
	}

	/**
	 * Check admin permissions
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_admin_permissions( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check sales rep permissions (less restrictive for frontend usage)
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_sales_rep_permissions( $request ) {
		return Permissions::has_agent_access()
			|| Permissions::can_assign_project_owner();
	}
}
