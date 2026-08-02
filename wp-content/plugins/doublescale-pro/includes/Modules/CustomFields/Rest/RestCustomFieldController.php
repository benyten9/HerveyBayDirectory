<?php

/**
 * REST Api: class RestCustomFieldController
 * This class is responsible for handling the custom field controller
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\CustomFields\Rest;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use Illuminate\Support\Str;

/**
 * Custom_Field_Controller class
 */
class RestCustomFieldController extends RestController {




	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'custom-fields';

	/**
	 * Register the routes for the controller.
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
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Custom field IDs.', 'doublescale'),
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
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
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
	 * Get schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		 return array(
			 '$schema'    => 'http://json-schema.org/draft-04/schema#',
			 'title'      => 'custom_field',
			 'type'       => 'object',
			 'properties' => array(
				 'id'         => array(
					 'description' => __( 'Unique identifier for the object.', 'doublescale'),
					 'type'        => 'integer',
					 'readonly'    => true,
				 ),
				 'name'       => array(
					 'description' => __( 'Name of the custom field.', 'doublescale'),
					 'type'        => 'string',
					 'required'    => true,
					 'args'        => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'type'       => array(
					 'description' => __( 'Type of the custom field.', 'doublescale'),
					 'type'        => 'string',
					 'required'    => true,
					 'enum'        => array(
						 'text',
						 'textarea',
						 'select',
						 'checkbox',
						 'radio',
						 'multiselect',
						 'boolean',
						 'date',
						 'number',
						 'email',
						 'url',
						 'phone',
						 'file',
					 ),
				 ),
				 'attributes' => array(
					 'description' => __( 'Attributes of the custom field.', 'doublescale'),
					 'type'        => 'array',
					 'items'       => array(
						 'type'       => 'object',
						 'properties' => array(
							 'label' => array(
								 'description' => __( 'Label of the attribute.', 'doublescale'),
								 'type'        => 'string',
								 'required'    => true,
								 'args'        => array(
									 'sanitize_callback' => 'sanitize_text_field',
								 ),
							 ),
							 'value' => array(
								 'description' => __( 'Value of the attribute.', 'doublescale'),
								 'type'        => 'string',
								 'required'    => true,
								 'args'        => array(
									 'sanitize_callback' => 'sanitize_text_field',
								 ),
							 ),
						 ),
					 ),
				 ),
				 'group_id'   => array(
					 'description' => __( 'Group ID of the custom field.', 'doublescale'),
					 'type'        => 'integer',
					 'required'    => true,
				 ),
				 'scope'      => array(
					 'type'        => 'string',
					 'description' => 'Scope',
					 'required'    => true,
					 'enum'        => array( 'contact', 'deal', 'task', 'project' ),
				 ),
				 'created_at' => array(
					 'type'        => 'string',
					 'description' => 'Created at',
					 'context'     => array( 'view', 'edit', 'embed' ),
					 'readonly'    => true,
				 ),
				 'updated_at' => array(
					 'type'        => 'string',
					 'description' => 'Updated at',
					 'context'     => array( 'view', 'edit', 'embed' ),
					 'readonly'    => true,
				 ),
			 ),
		 );
	}

	/**
	 * Get all custom fields
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$keyword  = $request->get_param( 'keyword' ) ?? '';
			$from     = $request->get_param( 'from' ) ?? null;
			$to       = $request->get_param( 'to' ) ?? null;

			$query       = CustomFieldModel::query();
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

			$custom_fields = $query->orderBy( 'created_at', 'desc' )->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $custom_fields->toArray() + array( 'total_count' => $total_count ), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_custom_field_get_items', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		try {
			$custom_field_id = $request->get_param( 'id' );
			$custom_field    = CustomFieldModel::find( $custom_field_id );

			if ( ! $custom_field ) {
				return new WP_Error( 'rest_custom_field_get_item', __( 'Custom field not found', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $custom_field, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_custom_field_get_item', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$name  = $request->get_param( 'name' );
			$scope = $request->get_param( 'scope' );

			if ( empty( $name ) ) {
				return new WP_Error( 'rest_custom_field_create_item', __( 'Custom field name is required', 'doublescale'), array( 'status' => 400 ) );
			}

			if ( empty( $scope ) ) {
				return new WP_Error( 'rest_custom_field_create_item', __( 'Custom field scope is required', 'doublescale'), array( 'status' => 400 ) );
			}

			$custom_field_data = $this->prepare_custom_field( $request );

			// Ensure we have a slug in the data
			if ( ! isset( $custom_field_data['slug'] ) || empty( $custom_field_data['slug'] ) ) {
				// Use the same Str class that's used in the model
				$slug = Str::slug( $custom_field_data['name'] );
				if ( empty( $slug ) ) {
					$slug = 'custom-field-' . time();
				}

				if ( CustomFieldModel::where( 'slug', $slug )->exists() ) {
					return new WP_Error( 'rest_custom_field_create_item', __( 'Slug already exists', 'doublescale'), array( 'status' => 400 ) );
				}

				$custom_field_data['slug'] = $slug;
			}

			try {
				// Create the custom field
				$custom_field = CustomFieldModel::create( $custom_field_data );

				// Check if the custom field was created successfully
				if ( ! $custom_field || ! isset( $custom_field->id ) || $custom_field->id === 0 ) {
					return new WP_Error( 'rest_custom_field_create_item', __( 'Failed to create custom field', 'doublescale'), array( 'status' => 500 ) );
				}

				// Refresh the model from database to ensure we have the correct data
				$custom_field = CustomFieldModel::find( $custom_field->id );
			} catch ( \Exception $e ) {
				return new WP_Error( 'rest_custom_field_create_item', $e->getMessage(), array( 'status' => 500 ) );
			}

			return new WP_REST_Response( $custom_field, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_custom_field_create_item', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			$custom_field_id = $request->get_param( 'id' );
			$custom_field    = CustomFieldModel::find( $custom_field_id );

			if ( ! $custom_field ) {
				return new WP_Error( 'rest_custom_field_update_item', __( 'Custom field not found', 'doublescale'), array( 'status' => 404 ) );
			}

			$custom_field_data = $this->prepare_custom_field( $request );
			$custom_field->update( $custom_field_data );

			return new WP_REST_Response( $custom_field, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_custom_field_update_item', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$custom_field_id = $request->get_param( 'id' );
			$custom_field    = CustomFieldModel::find( $custom_field_id );

			if ( ! $custom_field ) {
				return new WP_Error( 'rest_custom_field_delete_item', __( 'Custom field not found', 'doublescale'), array( 'status' => 404 ) );
			}

			$custom_field->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_custom_field_delete_item', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare custom field data
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return array
	 */
	public function prepare_custom_field( $request ) {
		$custom_field_data = array(
			'name'       => $request->get_param( 'name' ),
			'type'       => $request->get_param( 'type' ),
			'attributes' => $request->get_param( 'attributes' ),
			'group_id'   => $request->get_param( 'group_id' ) ? $request->get_param( 'group_id' ) : 0,
			'scope'      => $request->get_param( 'scope' ),
		);

		foreach ( $custom_field_data as $key => $value ) {
			// Keep `attributes` even when empty/falsey so unchecking Required
			// (e.g. `{ "required": false }`) is persisted instead of skipped.
			if ( 'attributes' === $key ) {
				if ( null === $value ) {
					unset( $custom_field_data[ $key ] );
				}
				continue;
			}

			if ( empty( $value ) && 'group_id' !== $key ) {
				unset( $custom_field_data[ $key ] );
			}
		}

		return $custom_field_data;
	}

	/**
	 * Delete multiple custom fields
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_items( $request ) {
		try {
			$ids = $request->get_param( 'ids' );

			if ( empty( $ids ) ) {
				return new WP_Error( 'rest_custom_field_delete_items', __( 'No custom field IDs provided', 'doublescale'), array( 'status' => 400 ) );
			}

			$custom_fields = CustomFieldModel::whereIn( 'id', $ids )->get();

			if ( $custom_fields->isEmpty() ) {
				return new WP_Error( 'rest_custom_field_delete_items', __( 'Custom fields not found', 'doublescale'), array( 'status' => 404 ) );
			}

			// Delete the custom fields using whereIn and delete method
			CustomFieldModel::whereIn( 'id', $ids )->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_custom_field_delete_items', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Check if a given request has access to get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::can_read_custom_field_definitions( $request->get_param( 'scope' ) );
	}

	/**
	 * Check if a given request has access to get a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::can_read_custom_field_definitions( $request->get_param( 'scope' ) );
	}

	/**
	 * Check if a given request has access to create a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to update a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete a custom field
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete multiple custom fields
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
