<?php
/**
 * REST API: Link Trigger CRUD controller.
 *
 * Registers `/doublescale/v1/link-triggers` and `/link-triggers/{id}` routes
 * for the link-trigger management UI. Moved to Pro alongside the rest of the
 * link-triggers feature; when Pro is not active these routes do not exist.
 *
 * @package DoubleScale\Pro\Modules\LinkTriggers\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\LinkTriggers\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use Exception;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\LinkTriggers\Models\LinkTriggerModel;

class RestLinkTriggerController extends RestController {

	protected $rest_base = 'link-triggers';

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
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'IDs of the link triggers.', 'doublescale' ),
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
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.', 'doublescale' ),
						'type'        => 'integer',
					),
				),
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

	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description'       => __( 'Limit results to those matching a string.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description' => __( 'Number of items to return in one page.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 10,
			),
			'page'     => array(
				'description' => __( 'Current page of the collection.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
			),
			'ids'      => array(
				'description' => __( 'IDs of the link triggers.', 'doublescale' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'from'     => array(
				'description' => __( 'Start date for filtering link triggers.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'to'       => array(
				'description' => __( 'End date for filtering link triggers.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
		);
	}

	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'link_trigger',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'name'        => array(
					'description' => __( 'Name of the link trigger.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'hash'        => array(
					'description' => __( 'Unique hash for the link trigger.', 'doublescale' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'status'      => array(
					'description' => __( 'Status of the link trigger.', 'doublescale' ),
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'default'     => 'active',
				),
				'settings'    => array(
					'description' => __( 'Settings of the link trigger.', 'doublescale' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'click_count' => array(
					'description' => __( 'Click count of the link trigger.', 'doublescale' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'created_at'  => array(
					'description' => __( 'The date the link trigger was created.', 'doublescale' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'updated_at'  => array(
					'description' => __( 'The date the link trigger was last updated.', 'doublescale' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);
	}

	public function get_items( $request ) {
		try {
			$keyword  = $request->get_param( 'keyword' ) ? $request->get_param( 'keyword' ) : '';
			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$from     = $request->get_param( 'from' ) ?? null;
			$to       = $request->get_param( 'to' ) ?? null;

			$query       = LinkTriggerModel::query();
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
			$link_triggers = $query->orderBy( 'created_at', 'desc' )->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $link_triggers->toArray() + array( 'total_count' => $total_count ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'doublescale_link_trigger_get_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function delete_items( $request ) {
		try {
			$link_trigger_ids = $request->get_param( 'ids' );
			LinkTriggerModel::whereIn( 'id', $link_trigger_ids )->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( Exception $e ) {
			return new WP_Error( 'doublescale_link_trigger_bulk_delete_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function get_item( $request ) {
		try {
			$id = $request->get_param( 'id' );

			$link_trigger = LinkTriggerModel::find( $id );

			if ( ! $link_trigger ) {
				return new WP_Error( 'doublescale_link_trigger_not_found', __( 'Link trigger not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $link_trigger, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'doublescale_link_trigger_get_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function create_item( $request ) {
		try {
			$link_trigger_data = $this->prepare_link_trigger( $request );
			$link_trigger      = LinkTriggerModel::create( $link_trigger_data );

			return new WP_REST_Response( $link_trigger, 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'doublescale_link_trigger_create_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function update_item( $request ) {
		try {
			$id = $request->get_param( 'id' );

			$link_trigger = LinkTriggerModel::find( $id );

			if ( ! $link_trigger ) {
				return new WP_Error( 'doublescale_link_trigger_not_found', __( 'Link trigger not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$link_trigger_data = $this->prepare_link_trigger( $request );
			$link_trigger->update( $link_trigger_data );

			return new WP_REST_Response( $link_trigger, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'doublescale_link_trigger_update_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function delete_item( $request ) {
		try {
			$id = $request->get_param( 'id' );

			$link_trigger = LinkTriggerModel::find( $id );

			if ( ! $link_trigger ) {
				return new WP_Error( 'doublescale_link_trigger_not_found', __( 'Link trigger not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$link_trigger->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( Exception $e ) {
			return new WP_Error( 'doublescale_link_trigger_delete_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	protected function prepare_link_trigger( $request ) {
		$link_trigger_data = array(
			'name'     => $request->get_param( 'name' ),
			'hash'     => wp_generate_password( 32, false ),
			'status'   => $request->get_param( 'status' ),
			'settings' => $request->get_param( 'settings' ),
		);

		foreach ( $link_trigger_data as $key => $value ) {
			if ( empty( $value ) ) {
				unset( $link_trigger_data[ $key ] );
			}
		}

		return $link_trigger_data;
	}

	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	public function get_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
