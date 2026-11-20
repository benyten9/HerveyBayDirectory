<?php
/**
 * Client Portal → Files: customers upload, list and remove their own files.
 *
 *   GET    /portal/files            the customer's uploads + the upload policy
 *   POST   /portal/files            multipart `file`
 *   DELETE /portal/files/{id}       one of the customer's own uploads
 *   GET    /portal/files/settings   admin: the switch + type restriction
 *   POST   /portal/files/settings   admin: save them
 *
 * The customer is always the session's contact ({@see PortalIdentity}); no
 * route takes a contact id, so there is nothing to tamper with.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalFileService;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Portal\Services\PortalUploadSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalFilesController.
 */
class RestPortalFilesController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/files';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'customer_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'customer_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'customer_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'enabled'        => array( 'type' => 'boolean' ),
						'restrict_types' => array( 'type' => 'boolean' ),
						'allowed_types'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Logged-in customer (not portal-blocked staff), and the feature is on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function customer_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		$identity = PortalIdentity::permission_check();
		if ( true !== $identity ) {
			return $identity;
		}

		if ( ! PortalUploadSettings::is_enabled() ) {
			return new WP_Error(
				'rest_portal_uploads_disabled',
				__( 'File uploads are not available in the portal.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Portal settings are site settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function admin_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return current_user_can( 'manage_options' );
	}

	/**
	 * The customer's own uploads and the upload policy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$contact = PortalIdentity::current_contact();
		$service = new PortalFileService();

		return new WP_REST_Response(
			array(
				'items'  => $contact ? $service->list_for_contact( $contact ) : array(),
				'policy' => $service->policy_for_contact( $contact ),
			),
			200
		);
	}

	/**
	 * Upload one file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_Error(
				'rest_portal_contact_missing',
				__( 'No contact record is linked to your account.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		$service    = new PortalFileService();
		$attachment = $service->store( $contact, $file, rest_sanitize_boolean( $request->get_param( 'share_with_company' ) ), (int) $request->get_param( 'company_id' ) );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		return new WP_REST_Response( $service->shape( $attachment ), 201 );
	}

	/**
	 * Remove one of the customer's own uploads.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return PortalIdentity::not_found( __( 'File not found.', 'doublescale' ) );
		}

		$id      = (int) $request->get_param( 'id' );
		$deleted = ( new PortalFileService() )->delete( $contact, $id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return new WP_REST_Response( PortalUploadSettings::settings(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		$input = array();
		foreach ( array( 'enabled', 'restrict_types', 'allowed_types' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}

		$saved = PortalUploadSettings::update( $input );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( $saved, 200 );
	}
}
