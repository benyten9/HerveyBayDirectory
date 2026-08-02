<?php

/**
 * Class Custom_Fields_Group_Controller
 * This class is responsible for handling the custom fields group controller
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
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel;

/**
 * Custom_Fields_Group_Controller class
 */
class RestCustomFieldsGroupController extends RestController {





	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'custom-fields-groups';

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
					'args'                => array(
						'new_group_id' => array(
							'description' => __( 'New group id to move the fields.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Duplicate route - POST to duplicate a group
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource to duplicate.', 'doublescale'),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'name' => array(
							'description' => __( 'Name for the duplicated group.', 'doublescale'),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);

		// Get fields for a group.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/fields',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'doublescale'),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fields' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
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
			 'title'      => 'custom_fields_group',
			 'type'       => 'object',
			 'properties' => array(
				 'id'         => array(
					 'description' => __( 'Unique identifier for the object.', 'doublescale'),
					 'type'        => 'integer',
					 'readonly'    => true,
				 ),
				 'name'       => array(
					 'description'  => __( 'Name of the custom fields group.', 'doublescale'),
					 'type'         => 'string',
					 'required'     => true,
					 'args_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'slug'       => array(
					 'description'  => __( 'Slug of the custom fields group.', 'doublescale'),
					 'type'         => 'string',
					 'args_options' => array(
						 'sanitize_callback' => 'sanitize_title',
					 ),
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
			$groups = CustomFieldsGroupModel::where( 'scope', $request->get_param( 'scope' ) )
				->with(
					array(
						'custom_fields' => function ( $query ) use ( $request ) {
							$query->where( 'scope', $request->get_param( 'scope' ) );
						},
					)
				)
				->get();

			return new WP_REST_Response( $groups, 200 );
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
			$group_id = $request->get_param( 'id' );
			$group    = CustomFieldsGroupModel::where( 'scope', $request->get_param( 'scope' ) )->find( $group_id );

			if ( ! $group ) {
				return new WP_Error( 'error', __( 'Custom fields group not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $group, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_fields( $request ) {
		try {
			$group_id = $request->get_param( 'id' );
			$group    = CustomFieldsGroupModel::where( 'scope', $request->get_param( 'scope' ) )->find( $group_id );

			if ( ! $group ) {
				return new WP_Error( 'error', __( 'Custom fields group not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$fields = $group->custom_fields;

			return new WP_REST_Response( $fields, 200 );
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
			$data                 = $this->prepare_group( $request );
			$group                = CustomFieldsGroupModel::create( $data );
			$group->custom_fields = array();

			return new WP_REST_Response( $group, 201 );
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
			$group_id = $request->get_param( 'id' );
			$group    = CustomFieldsGroupModel::with( 'custom_fields' )->find( $group_id );

			if ( ! $group ) {
				return new WP_Error( 'error', __( 'Custom fields group not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$group_data = $this->prepare_group( $request );

			$group->update( $group_data );

			// Return the updated group with its fields
			$updated_group = CustomFieldsGroupModel::with( 'custom_fields' )->find( $group_id );

			return new WP_REST_Response( $updated_group, 200 );
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
			$group_id = $request->get_param( 'id' );
			$group    = CustomFieldsGroupModel::find( $group_id );

			if ( ! $group ) {
				return new WP_Error( 'error', __( 'Custom fields group not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// First move all the fields to the the given group.
			$new_group_id = $request->get_param( 'new_group_id' ) ? $request->get_param( 'new_group_id' ) : 0;

			if ( $new_group_id ) {
				$new_group = CustomFieldsGroupModel::find( $new_group_id );

				if ( ! $new_group ) {
					return new WP_Error( 'error', __( 'New group not found.', 'doublescale'), array( 'status' => 404 ) );
				}

				$fields = $group->custom_fields;
				foreach ( $fields as $field ) {
					$field->group_id = $new_group_id; // Update the foreign key
					$field->save(); // Save the updated field
				}
			}

			$group->delete();

			return new WP_REST_Response(
				array(
					'message' => __( 'Custom fields group deleted.', 'doublescale'),
				),
				204
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Duplicate item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function duplicate_item( $request ) {
		try {
			$group_id       = $request->get_param( 'id' );
			$original_group = CustomFieldsGroupModel::with( 'custom_fields' )->find( $group_id );

			if ( ! $original_group ) {
				return new WP_Error( 'error', __( 'Custom fields group not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Prepare data for the new group
			$new_name = $request->get_param( 'name' ) ?: $original_group->name . ' (Copy)';
			$new_slug = $request->get_param( 'slug' ) ?: $original_group->slug . '-copy';

			// Ensure unique slug
			$counter   = 1;
			$base_slug = $new_slug;
			while ( CustomFieldsGroupModel::where( 'slug', $new_slug )->exists() ) {
				$new_slug = $base_slug . '-' . $counter;
				$counter++;
			}

			// Create the duplicate group
			$duplicate_data = array(
				'name'  => $new_name,
				'slug'  => $new_slug,
				'scope' => $original_group->scope,
			);

			$duplicate_group = CustomFieldsGroupModel::create( $duplicate_data );

			// Duplicate associated custom fields if they exist
			if ( ! empty( $original_group->custom_fields ) ) {
				foreach ( $original_group->custom_fields as $field ) {
					$field_data = $field->toArray();
					unset( $field_data['id'] ); // Remove the original ID
					unset( $field_data['created_at'] ); // Remove created_at
					unset( $field_data['updated_at'] ); // Remove updated_at

					$field_data['group_id'] = $duplicate_group->id; // Set new group ID

					// Generate unique field name if necessary
					$original_name = $field_data['name'];
					$counter       = 1;
					while ( CustomFieldModel::where( 'name', $field_data['name'] )->exists() ) {
						$field_data['name'] = $original_name . ' (Copy ' . $counter . ')';
						$counter++;
					}

					CustomFieldModel::create( $field_data );
				}
			}

			// Reload the group with its fields
			$duplicate_group = CustomFieldsGroupModel::with( 'custom_fields' )->find( $duplicate_group->id );

			return new WP_REST_Response( $duplicate_group, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}



	/**
	 * Prepare group from request
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return array $group The group model.
	 */
	protected function prepare_group( $request ) {
		$group = array(
			'name'  => $request->get_param( 'name' ),
			'slug'  => $request->get_param( 'slug' ),
			'scope' => $request->get_param( 'scope' ),
		);

		foreach ( $group as $key => $value ) {
			if ( ! $value ) {
				unset( $group[ $key ] );
			}
		}

		return $group;
	}

	/**
	 * Get items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::can_read_custom_field_definitions( $request->get_param( 'scope' ) );
	}

	/**
	 * Get item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::can_read_custom_field_definitions( $request->get_param( 'scope' ) );
	}

	/**
	 * Create item permissions check
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
	 * Update item permissions check
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
	 * Delete item permissions check
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
}
