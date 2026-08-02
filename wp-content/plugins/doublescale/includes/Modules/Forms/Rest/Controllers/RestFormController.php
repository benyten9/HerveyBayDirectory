<?php

/**
 * Class RestFormController
 * This class is responsible for handling the Form REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Forms\Models\FormModel;

/**
 * RestFormController class
 */
class RestFormController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'forms';

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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'IDs of the forms.', 'doublescale'),
							'type'        => 'array',
							'items'       => array(
								'type' => 'integer',
							),
							'required'    => true,
						),
					),
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
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::READABLE ),
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
	 * Get schema for the controller.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		 return array(
			 '$schema'    => 'http://json-schema.org/draft-04/schema#',
			 'title'      => 'form',
			 'type'       => 'object',
			 'properties' => array(
				 'id'         => array(
					 'description' => esc_html__( 'Unique identifier for the object.', 'doublescale'),
					 'type'        => 'integer',
				 ),
				 'name'       => array(
					 'description' => esc_html__( 'Name of the form.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
					 'required'    => true,
				 ),
				 'form_type'  => array(
					 'description' => esc_html__( 'Type of the form.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'form_id'    => array(
					 'description' => esc_html__( 'ID of the form.', 'doublescale'),
					 'type'        => array( 'integer', 'string' ),
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'data'       => array(
					 'description' => esc_html__( 'Data of the form.', 'doublescale'),
					 'type'        => 'object',
				 ),
				 'status'     => array(
					 'description' => esc_html__( 'Status of the form.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'created_at' => array(
					 'description' => esc_html__( 'Date the object was created.', 'doublescale'),
					 'type'        => 'string',
					 'format'      => 'date-time',
					 'readonly'    => true,
				 ),
				 'updated_at' => array(
					 'description' => esc_html__( 'Date the object was last modified.', 'doublescale'),
					 'type'        => 'string',
					 'format'      => 'date-time',
					 'readonly'    => true,
				 ),
			 ),
		 );
	}

	/**
	 * Get collection params
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description'       => __( 'Limit results to those matching a string.', 'doublescale'),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description'       => __( 'Number of items to return in one page.', 'doublescale'),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'doublescale'),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'ids'      => array(
				'description' => __( 'IDs of the forms.', 'doublescale'),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'from'     => array(
				'description' => __( 'Start date for filtering forms.', 'doublescale'),
				'type'        => 'string',
				'format'      => 'date',
			),
			'to'       => array(
				'description' => __( 'End date for filtering forms.', 'doublescale'),
				'type'        => 'string',
				'format'      => 'date',
			),
		);
	}

	/**
	 * Get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		try {
			$keyword  = $request->get_param( 'keyword' ) ? $request->get_param( 'keyword' ) : '';
			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$from     = $request->get_param( 'from' ) ?? null;
			$to       = $request->get_param( 'to' ) ?? null;

			$query       = FormModel::query();
			$total_count = $query->count();

			if ( $keyword ) {
				$query->where( 'name', 'LIKE', '%' . $keyword . '%' );
			}
			if ( $from ) {
				$query->where( 'created_at', '>=', $from );
			}
			if ( $to ) {
				$query->where( 'created_at', '<=', $to );
			}
			$forms = $query->orderBy( 'created_at', 'desc' )->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $forms->toArray() + array( 'total_count' => $total_count ), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_items( $request ) {
		try {
			$form_ids = $request->get_param( 'ids' );
			FormModel::whereIn( 'id', $form_ids )->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function create_item( $request ) {
		try {
			$form_data = $this->prepare_form( $request );
			$form      = FormModel::create( $form_data );

			return new WP_REST_Response( $form, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		try {
			$form_id = $request->get_param( 'id' );
			$form    = FormModel::find( $form_id );

			if ( ! $form ) {
				return new WP_Error( 'error', __( 'Form not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $form, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		try {
			$form_id = $request->get_param( 'id' );
			$form    = FormModel::find( $form_id );

			if ( ! $form ) {
				return new WP_Error( 'error', __( 'Form not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$form_data = $this->prepare_form( $request );
			$form->update( $form_data );

			return new WP_REST_Response( $form, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_item( $request ) {
		try {
			$form_id = $request->get_param( 'id' );
			$form    = FormModel::find( $form_id );

			if ( ! $form ) {
				return new WP_Error( 'error', __( 'Form not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$form->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare item for database
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	public function prepare_form( $request ) {
		$form_data = array();

		$form_data['name']      = $request->get_param( 'name' );
		$form_data['form_type'] = $request->get_param( 'form_type' );
		$form_data['form_id']   = $request->get_param( 'form_id' );
		$form_data['data']      = $request->get_param( 'data' );
		$form_data['status']    = $request->get_param( 'status' );

		foreach ( $form_data as $key => $value ) {
			if ( empty( $value ) ) {
				unset( $form_data[ $key ] );
			}
		}

		return $form_data;
	}

	/**
	 * Check if a given request has access to get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to create items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to get a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to update a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
