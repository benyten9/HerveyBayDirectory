<?php
/**
 * REST API: Companies and their member contacts.
 *
 * @package DoubleScale\Modules\Contacts\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Companies\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Scope\OwnerScope;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Companies\Models\CompanyModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Companies\Services\CompanyService;
use DoubleScale\Pro\Modules\Companies\Services\CompanyRecords;
use DoubleScale\Pro\Modules\Companies\Services\CompanyFiles;
use DoubleScale\Modules\Portal\Services\PortalFileService;
use DoubleScale\Modules\Activities\Services\ActivityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestCompanyController class.
 */
class RestCompanyController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'companies';

	/**
	 * Columns the collection may be sorted by.
	 *
	 * @var string[]
	 */
	private const SORTABLE_COLUMNS = array( 'name', 'created_at', 'updated_at' );

	/**
	 * Text columns a company can be created or updated with.
	 *
	 * @var string[]
	 */
	private const TEXT_FIELDS = array( 'name', 'domain', 'email', 'phone', 'website', 'registration_number', 'tax_vat_number' );

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
					'args'                => array_merge(
						array(
							'search'   => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'page'     => array(
								'type'    => 'integer',
								'default' => 1,
								'minimum' => 1,
							),
							'per_page' => array(
								'type'    => 'integer',
								'default' => 20,
								'minimum' => 1,
								'maximum' => 100,
							),
						),
						$this->get_sorting_collection_params( self::SORTABLE_COLUMNS )
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/members',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_member' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
					'args'                => array(
						'contact_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'role'       => array(
							'type' => 'string',
							'enum' => CompanyService::ROLES,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/members/(?P<contact_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_member' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_member' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
			)
		);

		// The company page: tab lists, tile figures and the members' timeline.
		foreach ( array(
			'records'  => 'get_records',
			'summary'  => 'get_summary',
			'timeline' => 'get_timeline',
		) as $route => $callback ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/' . $route,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, $callback ),
						'permission_callback' => array( $this, 'read_permissions_check' ),
					),
				)
			);
		}

		// The company's own files: upload, show in the members' portal, remove.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_attachment' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments/(?P<attachment_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_attachment_visibility' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
					'args'                => array(
						'client_visible' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_attachment' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
			)
		);

		// Contacts with the company's email domain who belong to no company.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/domain-matches',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_domain_matches' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
			)
		);

		// The contact page's Companies list: every company of the contact.
		register_rest_route(
			$this->namespace,
			'/contacts/(?P<contact_id>\d+)/companies',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_contact_companies' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_contact_company' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
					'args'                => array(
						'company_id' => array( 'type' => 'integer' ),
						'name'       => array( 'type' => 'string' ),
						'role'       => array(
							'type' => 'string',
							'enum' => CompanyService::ROLES,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/contacts/(?P<contact_id>\d+)/companies/(?P<company_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_contact_company' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
					'args'                => array(
						'role'    => array(
							'type' => 'string',
							'enum' => CompanyService::ROLES,
						),
						'is_main' => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_contact_company' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
			)
		);

		// One company of the contact (their main one): older screens and apps.
		register_rest_route(
			$this->namespace,
			'/contacts/(?P<contact_id>\d+)/company',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_contact_company' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_contact_company' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function read_permissions_check() {
		return Permissions::can_read_contacts();
	}

	/**
	 * @return bool
	 */
	public function edit_permissions_check() {
		return Permissions::has_agent_access();
	}

	/**
	 * @return bool
	 */
	public function delete_permissions_check() {
		return Permissions::can_delete_contacts();
	}

	/**
	 * Paginated company list ({data, total}, as InfiniteScrollSelect expects).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$query  = CompanyModel::query();
		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$like = '%' . $search . '%';
			$query->where(
				static function ( $q ) use ( $like ) {
					$q->where( 'name', 'like', $like )->orWhere( 'domain', 'like', $like )->orWhere( 'email', 'like', $like );
				}
			);
		}

		$total    = ( clone $query )->count();
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$order_by = in_array( $request->get_param( 'orderby' ), self::SORTABLE_COLUMNS, true ) ? $request->get_param( 'orderby' ) : 'name';
		$order    = 'desc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'desc' : 'asc';

		$companies = $query->orderBy( $order_by, $order )->skip( ( $page - 1 ) * $per_page )->take( $per_page )->get();
		$counts    = $this->member_counts( $companies->pluck( 'id' )->all() );

		$data = array();
		foreach ( $companies as $company ) {
			$item                  = $this->prepare_company( $company );
			$item['members_count'] = $counts[ (int) $company->id ] ?? 0;
			$data[]                = $item;
		}

		return new WP_REST_Response(
			array(
				'data'  => $data,
				'total' => $total,
			)
		);
	}

	/**
	 * One company with its members.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$company = CompanyModel::find( (int) $request['id'] );
		if ( ! $company ) {
			return $this->not_found();
		}

		$item            = $this->prepare_company( $company );
		$item['members'] = CompanyService::members( (int) $company->id );

		return new WP_REST_Response( $item );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = $this->sanitize_payload( $request );
		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'doublescale_company_name_required', __( 'Company name is required.', 'doublescale' ), array( 'status' => 422 ) );
		}
		$data['owner_user_id'] = get_current_user_id() ?: null;

		// A first contact is held to the contact rules before anything is created.
		$contact_id = (int) $request->get_param( 'contact_id' );
		if ( $contact_id > 0 && ContactModel::find( $contact_id ) ) {
			$contact = $this->contact_for( $contact_id, true );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}
		}

		$company = CompanyModel::create( $data );

		if ( $contact_id > 0 && ContactModel::find( $contact_id ) ) {
			CompanyService::add_contact_company( $contact_id, (int) $company->id, CompanyService::ROLE_PRIMARY );
		}

		do_action( 'doublescale_company_created', (int) $company->id );

		return new WP_REST_Response( $this->prepare_company( $company->fresh() ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$company = CompanyModel::find( (int) $request['id'] );
		if ( ! $company ) {
			return $this->not_found();
		}

		$data = $this->sanitize_payload( $request );
		if ( array_key_exists( 'name', $data ) && '' === $data['name'] ) {
			return new WP_Error( 'doublescale_company_name_required', __( 'Company name is required.', 'doublescale' ), array( 'status' => 422 ) );
		}

		$renamed = isset( $data['name'] ) && $data['name'] !== $company->name;
		$company->fill( $data )->save();
		CompanyService::flush();

		// Members' company_name text follows a rename.
		if ( $renamed ) {
			$ids = array_column( CompanyService::members( (int) $company->id ), 'contact_id' );
			if ( $ids ) {
				ContactModel::whereIn( 'id', $ids )->update( array( 'company_name' => $company->name ) );
			}
		}

		return new WP_REST_Response( $this->prepare_company( $company->fresh() ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$company = CompanyModel::find( (int) $request['id'] );
		if ( ! $company ) {
			return $this->not_found();
		}

		CompanyService::delete_company( (int) $company->id );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_member( $request ) {
		$company    = CompanyModel::find( (int) $request['id'] );
		$contact_id = (int) $request->get_param( 'contact_id' );
		if ( ! $company ) {
			return $this->not_found();
		}
		$contact = $this->contact_for( $contact_id, true );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		// Joining keeps the contact's other companies.
		CompanyService::add_contact_company( $contact_id, (int) $company->id, $request->get_param( 'role' ) ?: null );

		return new WP_REST_Response( array( 'members' => CompanyService::members( (int) $company->id ) ) );
	}

	/**
	 * Change a member's role and/or portal access (`access`: list of areas, or null for the role default).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_member( $request ) {
		$company_id = (int) $request['id'];
		$contact_id = (int) $request['contact_id'];
		$contact    = $this->contact_for( $contact_id, true );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		if ( ! CompanyService::is_member( $company_id, $contact_id ) ) {
			return $this->not_found();
		}

		$changes = array();
		$role    = $request->get_param( 'role' );
		if ( null !== $role ) {
			if ( ! in_array( $role, CompanyService::ROLES, true ) ) {
				return new WP_Error( 'doublescale_company_role', __( 'Unknown role.', 'doublescale' ), array( 'status' => 422 ) );
			}
			$changes['role'] = $role;
		}
		CompanyService::update_member( $company_id, $contact_id, $changes );

		return new WP_REST_Response( array( 'members' => CompanyService::members( $company_id ) ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_member( $request ) {
		$company_id = (int) $request['id'];
		$contact_id = (int) $request['contact_id'];
		$contact    = $this->contact_for( $contact_id, true );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		if ( ! CompanyService::is_member( $company_id, $contact_id ) ) {
			return $this->not_found();
		}

		// Only this company: the contact's other companies stay.
		CompanyService::remove_contact_company( $contact_id, $company_id );

		return new WP_REST_Response( array( 'members' => CompanyService::members( $company_id ) ) );
	}

	/**
	 * The contact's company and role, or null.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_contact_company( $request ) {
		$contact = $this->contact_for( (int) $request['contact_id'], false );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		return new WP_REST_Response( $this->contact_company( (int) $contact->id ) );
	}

	/**
	 * Every company of the contact, main first, with their role there.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_contact_companies( $request ) {
		$contact = $this->contact_for( (int) $request['contact_id'], false );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		return new WP_REST_Response( $this->contact_companies( (int) $contact->id ) );
	}

	/**
	 * Add the contact to a company (company_id, or `name` to find or create
	 * one), keeping their other companies.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_contact_company( $request ) {
		$contact = $this->contact_for( (int) $request['contact_id'], true );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$contact_id = (int) $contact->id;
		$company_id = $this->company_from_request( $request );
		if ( $company_id <= 0 ) {
			return new WP_Error( 'doublescale_company_required', __( 'Pick a company or type a name.', 'doublescale' ), array( 'status' => 422 ) );
		}
		if ( ! CompanyModel::find( $company_id ) ) {
			return $this->not_found();
		}
		$role = $request->get_param( 'role' );
		CompanyService::add_contact_company( $contact_id, $company_id, in_array( $role, CompanyService::ROLES, true ) ? $role : null );

		return new WP_REST_Response( $this->contact_companies( $contact_id ), 201 );
	}

	/**
	 * Change the contact's role in one company, or make it their main one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_contact_company( $request ) {
		$contact = $this->contact_for( (int) $request['contact_id'], true );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$contact_id = (int) $contact->id;
		$company_id = (int) $request['company_id'];
		if ( ! CompanyService::is_member( $company_id, $contact_id ) ) {
			return $this->not_found();
		}
		$role = $request->get_param( 'role' );
		if ( null !== $role ) {
			CompanyService::update_member( $company_id, $contact_id, array( 'role' => $role ) );
		}
		if ( true === $request->get_param( 'is_main' ) ) {
			CompanyService::set_main_company( $contact_id, $company_id );
		}
		return new WP_REST_Response( $this->contact_companies( $contact_id ) );
	}

	/**
	 * Take the contact out of one company.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_contact_company( $request ) {
		$contact = $this->contact_for( (int) $request['contact_id'], true );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$contact_id = (int) $contact->id;
		$company_id = (int) $request['company_id'];
		if ( ! CompanyService::is_member( $company_id, $contact_id ) ) {
			return $this->not_found();
		}
		CompanyService::remove_contact_company( $contact_id, $company_id );
		return new WP_REST_Response( $this->contact_companies( $contact_id ) );
	}

	/**
	 * The contact a contact-company route works on, held to the same rules as
	 * the contact routes: a scoped agent does not see another agent's contact
	 * (404), and changing one is for its owner, CRM Managers and
	 * administrators (403).
	 *
	 * @param int  $contact_id Contact id.
	 * @param bool $write      Whether the route changes the contact.
	 * @return ContactModel|WP_Error
	 */
	private function contact_for( int $contact_id, bool $write ) {
		$contact = ContactModel::find( $contact_id );
		if ( ! $contact || ! OwnerScope::current()->can_view_contact( $contact ) ) {
			return $this->not_found();
		}
		if ( $write && ! OwnerScope::current()->can_edit_contact( $contact ) ) {
			return new WP_Error(
				'doublescale_contact_not_owned',
				__( 'Only the owner of this contact can do that.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return $contact;
	}

	/**
	 * The company a request names: `company_id`, else `name` (an existing
	 * company with that name, or a new one).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int 0: none named.
	 */
	private function company_from_request( $request ): int {
		$company_id = (int) $request->get_param( 'company_id' );
		$name       = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		if ( ! $company_id && '' !== $name ) {
			$existing   = CompanyModel::whereRaw( 'LOWER(name) = ?', array( strtolower( $name ) ) )->first();
			$company_id = $existing ? (int) $existing->id : (int) CompanyModel::create(
				array(
					'name'          => $name,
					'owner_user_id' => get_current_user_id() ?: null,
				)
			)->id;
		}
		return $company_id;
	}

	/**
	 * Make a company the contact's main one (company_id, joining it when
	 * needed), or take them out of their main company (company_id 0). With
	 * `name` instead of an id, a company with that name is created first.
	 * Their other companies stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_contact_company( $request ) {
		$contact = $this->contact_for( (int) $request['contact_id'], true );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$contact_id = (int) $contact->id;

		$company_id = $this->company_from_request( $request );
		if ( $company_id && ! CompanyModel::find( $company_id ) ) {
			return $this->not_found();
		}

		$role = $request->get_param( 'role' );
		CompanyService::set_contact_company( $contact_id, $company_id, in_array( $role, CompanyService::ROLES, true ) ? $role : null );

		return new WP_REST_Response( $this->contact_company( $contact_id ) );
	}

	/**
	 * One page of the members' records of a type (`type`: projects, tickets,
	 * invoices, proposals or files).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_records( $request ) {
		$company = CompanyModel::find( (int) $request['id'] );
		if ( ! $company ) {
			return $this->not_found();
		}
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$result   = CompanyRecords::page( $company, sanitize_key( (string) $request->get_param( 'type' ) ), $page, $per_page );
		if ( null === $result ) {
			return new WP_Error( 'doublescale_company_record_type', __( 'This list is not available.', 'doublescale' ), array( 'status' => 403 ) );
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * The company page's figures and the tabs this user gets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( $request ) {
		$company = CompanyModel::find( (int) $request['id'] );
		if ( ! $company ) {
			return $this->not_found();
		}
		return new WP_REST_Response(
			array(
				'figures' => CompanyRecords::summary( $company ),
				'types'   => CompanyRecords::types(),
			)
		);
	}

	/**
	 * The members' activity timeline (notes, calls, meetings, emails, ticket
	 * replies, tasks), newest first; only members whose activity this user
	 * may read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_timeline( $request ) {
		$company = CompanyModel::find( (int) $request['id'] );
		if ( ! $company ) {
			return $this->not_found();
		}
		$manager = ActivityManager::instance();
		$ids     = array_values(
			array_filter(
				array_column( CompanyService::members( (int) $company->id ), 'contact_id' ),
				static function ( $id ) use ( $manager ) {
					return $manager->can_access_contact_activities( (int) $id );
				}
			)
		);
		$contact = (int) $request->get_param( 'contact_id' );
		if ( $contact > 0 ) {
			$ids = in_array( $contact, $ids, true ) ? array( $contact ) : array();
		}
		$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		// Tasks about the company itself show with everyone's activity.
		$with_company = $contact <= 0;
		if ( empty( $ids ) && ! $with_company ) {
			return new WP_REST_Response(
				array(
					'data' => array(),
					'meta' => array(
						'total'        => 0,
						'per_page'     => $per_page,
						'current_page' => $page,
						'total_pages'  => 0,
					),
				)
			);
		}
		return new WP_REST_Response(
			$manager->get_unified_timeline(
				array(
					'contact_ids' => $ids,
					'company_id'  => $with_company ? (int) $company->id : 0,
					'sort_by'     => 'activity_date',
					'sort_order'  => 'desc',
				),
				$per_page,
				$page
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_domain_matches( $request ) {
		if ( ! CompanyModel::find( (int) $request['id'] ) ) {
			return $this->not_found();
		}
		return new WP_REST_Response( CompanyService::domain_matches( (int) $request['id'] ) );
	}

	/**
	 * The contact's companies, main first, with their role and main flag.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function contact_companies( int $contact_id ): array {
		$out = array();
		foreach ( CompanyService::memberships( $contact_id ) as $row ) {
			$company = CompanyModel::find( (int) $row->company_id );
			if ( ! $company ) {
				continue;
			}
			$item            = $this->prepare_company( $company );
			$item['role']    = (string) $row->role;
			$item['is_main'] = (bool) $row->is_main;
			$out[]           = $item;
		}
		return $out;
	}

	/**
	 * The contact's main company with their role there, or null.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<string, mixed>|null
	 */
	private function contact_company( int $contact_id ) {
		$rows = CompanyService::memberships( $contact_id );
		if ( empty( $rows ) ) {
			return null;
		}
		$company = CompanyModel::find( (int) $rows[0]->company_id );
		if ( ! $company ) {
			return null;
		}
		$item           = $this->prepare_company( $company );
		$item['role']   = (string) $rows[0]->role;
		$item['access'] = CompanyService::areas_for( $contact_id );
		return $item;
	}

	/**
	 * @param CompanyModel $company Company.
	 * @return array<string, mixed>
	 */
	private function prepare_company( $company ): array {
		$logo_url = $company->logo_id ? wp_get_attachment_image_url( (int) $company->logo_id, 'thumbnail' ) : null;

		return array(
			'id'                  => (int) $company->id,
			'name'                => (string) $company->name,
			'domain'              => $company->domain,
			'email'               => $company->email,
			'phone'               => $company->phone,
			'website'             => $company->website,
			'address'             => $company->address,
			'registration_number' => $company->registration_number,
			'tax_vat_number'      => $company->tax_vat_number,
			'logo_id'             => $company->logo_id ? (int) $company->logo_id : null,
			'logo_url'            => $logo_url ?: null,
			'owner_user_id'       => $company->owner_user_id ? (int) $company->owner_user_id : null,
			'notes'               => $company->notes,
			'created_at'          => $company->created_at ? (string) $company->created_at : null,
			'updated_at'          => $company->updated_at ? (string) $company->updated_at : null,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function sanitize_payload( $request ): array {
		$data = array();
		foreach ( self::TEXT_FIELDS as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$value          = trim( sanitize_text_field( (string) $request->get_param( $field ) ) );
				$data[ $field ] = '' === $value && 'name' !== $field ? null : $value;
			}
		}
		if ( isset( $data['email'] ) && ! is_email( $data['email'] ) ) {
			unset( $data['email'] );
		}
		if ( isset( $data['website'] ) ) {
			$data['website'] = esc_url_raw( $data['website'] ) ?: null;
		}
		if ( null !== $request->get_param( 'notes' ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		}
		if ( null !== $request->get_param( 'logo_id' ) ) {
			$data['logo_id'] = absint( $request->get_param( 'logo_id' ) ) ?: null;
		}
		if ( isset( $data['domain'] ) ) {
			$data['domain'] = CompanyService::normalize_domain( (string) $data['domain'] ) ?: null;
		}
		if ( is_array( $request->get_param( 'address' ) ) ) {
			$data['address'] = array_map( 'sanitize_text_field', array_map( 'strval', $request->get_param( 'address' ) ) );
		}
		return $data;
	}

	/**
	 * @param int[] $ids Company ids.
	 * @return array<int, int>
	 */
	private function member_counts( array $ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$table = CompanyService::table();
		$in    = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT company_id, COUNT(*) AS n FROM `{$table}` WHERE company_id IN ({$in}) GROUP BY company_id" );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->company_id ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Upload a file to the company; `client_visible` shows it to the members
	 * in the client portal right away.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment( $request ) {
		$company = CompanyModel::find( (int) $request['id'] );
		if ( ! $company ) {
			return $this->not_found();
		}

		$files      = $request->get_file_params();
		$attachment = CompanyFiles::upload(
			(int) $company->id,
			isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null,
			rest_sanitize_boolean( $request->get_param( 'client_visible' ) )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		return new WP_REST_Response( array( 'id' => (int) $attachment->id ), 201 );
	}

	/**
	 * Show or hide a company file in the members' client portal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_attachment_visibility( $request ) {
		$attachment = CompanyFiles::find( (int) $request['id'], (int) $request['attachment_id'] );
		if ( ! $attachment ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		CompanyFiles::set_visible( $attachment, rest_sanitize_boolean( $request->get_param( 'client_visible' ) ) );

		return new WP_REST_Response( array( 'client_visible' => PortalFileService::is_client_visible( $attachment ) ) );
	}

	/**
	 * Remove a company file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_attachment( $request ) {
		$attachment = CompanyFiles::find( (int) $request['id'], (int) $request['attachment_id'] );
		if ( ! $attachment ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$attachment->delete();

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * @return WP_Error
	 */
	private function not_found() {
		return new WP_Error( 'doublescale_company_not_found', __( 'Company not found.', 'doublescale' ), array( 'status' => 404 ) );
	}
}
