<?php
/**
 * REST: Contact → Client portal (one contact's own access).
 *
 *   GET    /doublescale/v1/contacts/{id}/portal-access   account + access per area
 *   PUT    /doublescale/v1/contacts/{id}/portal-access   { features: { key: bool } }
 *   DELETE /doublescale/v1/contacts/{id}/portal-access   back to the defaults
 *   POST   /doublescale/v1/contacts/{id}/portal-account  create the WordPress account
 *
 * A contact can only have their own access when they can log in, i.e. have a
 * WordPress account matching their email ({@see PortalIdentity::user_for_contact()}).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Scope\OwnerScope;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalFeatures;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Portal\Services\PortalInvite;
use DoubleScale\Modules\Portal\Services\PortalUserProvisioner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestContactPortalAccessController.
 */
class RestContactPortalAccessController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'contacts';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/portal-access',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_access' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_access' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'reset_access' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/portal-account',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_account' ),
					'permission_callback' => array( $this, 'account_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/portal-invite',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_invite' ),
					'permission_callback' => array( $this, 'invite_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Who may (re)send a client their invite: who may create the account or
	 * choose what the client sees.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function invite_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return self::can_invite();
	}

	/**
	 * Email the client their portal invite (again).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_invite( $request ) {
		$contact = $this->find_contact( $request );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		$sent = PortalInvite::send( $contact );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		$payload                = self::payload( $contact );
		$payload['invite_sent'] = true;

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * The card names the client's WordPress account, so it is for the staff
	 * who manage contacts — not for every role that can read one (project
	 * members, sales reps).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function read_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Changing it is editing the contact.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function edit_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Creating WordPress accounts is an administrative action, as on the
	 * Customer accounts card.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function account_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return self::can_create_accounts();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_access( $request ) {
		$contact = $this->find_contact( $request );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		return new WP_REST_Response( self::payload( $contact ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_access( $request ) {
		$contact = $this->find_contact( $request );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		$features = $request->get_param( 'features' );
		if ( ! is_array( $features ) ) {
			return new WP_Error(
				'invalid_features',
				__( 'Send the areas to save as an object of area => true/false.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$saved = PortalAccess::save_override( $contact, $features );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( self::payload( $contact ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_access( $request ) {
		$contact = $this->find_contact( $request );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		PortalAccess::clear_override( (int) $contact->id );

		return new WP_REST_Response( self::payload( $contact ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_account( $request ) {
		$contact = $this->find_contact( $request );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		if ( '' === trim( (string) $contact->email ) || ! is_email( (string) $contact->email ) ) {
			return new WP_Error(
				'portal_no_email',
				__( 'Add an email address to this contact first: the client portal signs in by email.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$result = PortalUserProvisioner::provision( $contact );
		if ( 'error' === $result['status'] ) {
			return new WP_Error(
				'portal_account_failed',
				'' !== $result['message'] ? $result['message'] : __( 'The portal account could not be created.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$payload                   = self::payload( $contact );
		$payload['account_status'] = $result['status'];

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * What the contact card shows.
	 *
	 * @param ContactModel $contact Contact.
	 * @return array<string, mixed>
	 */
	public static function payload( ContactModel $contact ): array {
		$user      = PortalIdentity::user_for_contact( $contact );
		$own       = PortalAccess::override_for( (int) $contact->id );
		$defaults  = PortalFeatures::defaults();
		$effective = PortalAccess::for_contact( $contact );

		$rows = array();
		foreach ( PortalFeatures::all() as $feature ) {
			$key    = $feature['key'];
			$rows[] = array_merge(
				PortalFeatures::describe( $feature ),
				array(
					'default'   => (bool) ( $defaults[ $key ] ?? $feature['default'] ),
					'custom'    => null !== $own && array_key_exists( $key, $own ) ? (bool) $own[ $key ] : null,
					'effective' => ! empty( $effective[ $key ] ),
				)
			);
		}

		return array(
			'contact_id'         => (int) $contact->id,
			'has_email'          => '' !== trim( (string) $contact->email ) && (bool) is_email( (string) $contact->email ),
			'account'            => $user ? array(
				'user_id'      => (int) $user->ID,
				// The username is half of a login; staff who cannot list users get the display name only.
				'login'        => current_user_can( 'list_users' ) ? (string) $user->user_login : '',
				'display_name' => (string) $user->display_name,
				'edit_url'     => current_user_can( 'edit_user', $user->ID ) ? esc_url_raw( get_edit_user_link( $user->ID ) ) : '',
				// A team member's account signs in to the dashboard: no invite.
				'can_invite'   => self::can_invite() && PortalInvite::is_client_login( $user ),
				'invited_at'   => (int) get_user_meta( $user->ID, PortalInvite::META_SENT_AT, true ) ?: null,
			) : null,
			'customized'         => null !== $own,
			'features'           => $rows,
			'can_edit'           => Permissions::has_crm_manager_access(),
			'can_create_account' => self::can_create_accounts(),
			'can_manage_modules' => current_user_can( 'manage_options' ),
			'pro_active'         => PortalFeatures::is_pro_active(),
		);
	}

	/**
	 * @return bool
	 */
	private static function can_create_accounts(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'create_users' );
	}

	/**
	 * @return bool
	 */
	private static function can_invite(): bool {
		return self::can_create_accounts() || Permissions::has_crm_manager_access();
	}

	/**
	 * A contact the caller cannot see answers like one that does not exist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return ContactModel|WP_Error
	 */
	private function find_contact( $request ) {
		$contact_id = (int) $request->get_param( 'id' );
		$hidden     = OwnerScope::contact_reference_error( $contact_id );
		if ( $hidden ) {
			return $hidden;
		}

		$contact = ContactModel::find( $contact_id );
		if ( ! $contact instanceof ContactModel ) {
			return new WP_Error( 'contact_not_found', __( 'Contact not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $contact;
	}
}
