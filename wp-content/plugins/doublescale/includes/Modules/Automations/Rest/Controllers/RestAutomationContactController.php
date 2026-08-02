<?php

/**
 * Class RestAutomationContactController
 * This class is responsible for handling the Automation Contact REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * RestAutomationContactController class
 */
class RestAutomationContactController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'automation-contacts';

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Schema for the Automation Contact
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'automation_contact',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'contact_id'     => array(
					'description' => __( 'The ID of the contact.', 'doublescale' ),
					'type'        => 'integer',
					'required'    => true,
				),
				'automation_id'  => array(
					'description' => __( 'The ID of the automation.', 'doublescale' ),
					'type'        => 'integer',
					'required'    => true,
				),
				'execution_time' => array(
					'description' => __( 'The time the automation should be executed.', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
				),
				'event'          => array(
					'description' => __( 'The event that triggered the automation.', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
				),
				'status'         => array(
					'description' => __( 'The status of the automation.', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
				),
				'step_id'        => array(
					'description' => __( 'The ID of the step.', 'doublescale' ),
					'type'        => 'integer',
					'required'    => true,
				),
				'created_at'     => array(
					'description' => __( 'The date the contact was created.', 'doublescale' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'updated_at'     => array(
					'description' => __( 'The date the contact was last updated.', 'doublescale' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Create an Automation Contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		try {
			$data               = $this->prepare_contact( $request );
			$automation_contact = AutomationContactModel::create( $data );

			if ( is_wp_error( $automation_contact ) ) {
				return $automation_contact;
			}

			return new WP_REST_Response( $automation_contact, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_contact_create_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get an Automation Contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		try {
			$automation_contact = AutomationContactModel::find( $request->get_param( 'id' ) );

			if ( ! $automation_contact ) {
				return new WP_Error( 'rest_automation_contact_not_found', __( 'Automation Contact not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$automation_contact->load( 'processes.step', 'current_step', 'next_step' );

			return new WP_REST_Response( $automation_contact, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_contact_get_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update an Automation Contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		try {
			$automation_contact = AutomationContactModel::find( $request->get_param( 'id' ) );

			if ( ! $automation_contact ) {
				return new WP_Error( 'rest_automation_contact_not_found', __( 'Automation Contact not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$data = $this->prepare_contact( $request );
			$automation_contact->fill( $data );
			$automation_contact->save();

			return new WP_REST_Response( $automation_contact, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_contact_update_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete an Automation Contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		try {
			$automation_contact = AutomationContactModel::find( $request->get_param( 'id' ) );

			if ( ! $automation_contact ) {
				return new WP_Error( 'rest_automation_contact_not_found', __( 'Automation Contact not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$automation_contact->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_contact_delete_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare the contact data
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return array
	 */
	private function prepare_contact( $request ) {
		$data = array(
			'contact_id'     => $request->get_param( 'contact_id' ),
			'automation_id'  => $request->get_param( 'automation_id' ),
			'execution_time' => $request->get_param( 'execution_time' ),
			'event'          => $request->get_param( 'event' ),
			'status'         => $request->get_param( 'status' ),
			'step_id'        => $request->get_param( 'step_id' ),
		);

		foreach ( $data as $key => $value ) {
			if ( empty( $value ) ) {
				unset( $data[ $key ] );
			}
		}

		return $data;
	}

	/**
	 * Check if a given request has access to create items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if a given request has access to get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if a given request has access to update items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if a given request has access to delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}
}
