<?php
/**
 * Client Portal contact profile endpoint.
 *
 * Lets a logged-in customer update their own CRM contact record.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Services\ContactUpdateNotifier;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalContactController.
 */
class RestPortalContactController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/contact';

	/**
	 * Fields a customer may update on their own profile.
	 *
	 * @var string[]
	 */
	private const SELF_EDITABLE_FIELDS = array(
		'first_name',
		'last_name',
		'phone',
		'whatsapp_phone',
		'address_1',
		'address_2',
		'city',
		'state',
		'country',
		'zip',
	);

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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function get_endpoint_args(): array {
		$args = array();
		foreach ( self::SELF_EDITABLE_FIELDS as $field ) {
			$args[ $field ] = array(
				'type'     => 'string',
				'required' => false,
			);
		}

		return $args;
	}

	/**
	 * Return the current customer's contact profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_item( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_Error(
				'rest_portal_contact_missing',
				__( 'No contact record is linked to your account.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->shape_contact( $contact ), 200 );
	}

	/**
	 * Update the current customer's contact profile.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_item( $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_Error(
				'rest_portal_contact_missing',
				__( 'No contact record is linked to your account.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$data = array();
		foreach ( self::SELF_EDITABLE_FIELDS as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error(
				'rest_portal_contact_no_changes',
				__( 'No profile fields were provided to update.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$changes = ContactUpdateNotifier::collect_field_changes( $contact, $data );
		if ( empty( $changes ) ) {
			return new WP_REST_Response( $this->shape_contact( $contact ), 200 );
		}

		$contact->update( $data );
		$contact->refresh();

		ContactUpdateNotifier::fire(
			$contact,
			array(
				'updated_by' => 'contact',
				'changes'    => $changes,
			)
		);

		return new WP_REST_Response( $this->shape_contact( $contact ), 200 );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return array<string, mixed>
	 */
	private function shape_contact( ContactModel $contact ): array {
		$payload = array();
		foreach ( self::SELF_EDITABLE_FIELDS as $field ) {
			$payload[ $field ] = $contact->{$field};
		}
		$payload['email'] = $contact->email;

		return $payload;
	}
}
