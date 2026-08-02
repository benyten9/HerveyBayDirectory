<?php

/**
 * Class RestLeadScoringRuleController
 *
 * This class is responsible for handling the Lead Scoring Rule REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring\Rest\Controllers;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleModel;

/**
 * RestLeadScoringRuleController class
 */
class RestLeadScoringRuleController extends RestController {


	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'lead-scoring-rules';

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
							'description' => __( 'IDs of the lead scoring rules.', 'doublescale'),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/active',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_active_rules' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
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
			'title'      => 'lead_scoring_rule',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'description' => esc_html__( 'Unique identifier for the object.', 'doublescale'),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'title'      => array(
					'description' => esc_html__( 'Title of the rule.', 'doublescale'),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'required'    => true,
				),
				'status'     => array(
					'description' => esc_html__( 'Status of the rule.', 'doublescale'),
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'required'    => true,
				),
				'points'     => array(
					'description' => esc_html__( 'Points for the rule.', 'doublescale'),
					'type'        => 'integer',
					'required'    => true,
				),
				'is_adding'  => array(
					'description' => esc_html__( 'Whether the rule adds or subtracts points.', 'doublescale'),
					'type'        => 'boolean',
					'required'    => true,
				),
				'settings'   => array(
					'description' => esc_html__( 'Settings of the rule.', 'doublescale'),
					'type'        => 'object',
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
			'status'   => array(
				'description'       => __( 'Filter by status (active/inactive).', 'doublescale'),
				'type'              => 'string',
				'enum'              => array( 'active', 'inactive' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description' => __( 'Number of items to return in one page.', 'doublescale'),
				'type'        => 'integer',
				'default'     => 10,
			),
			'page'     => array(
				'description' => __( 'Current page of the collection.', 'doublescale'),
				'type'        => 'integer',
				'default'     => 1,
			),
			'ids'      => array(
				'description' => __( 'IDs of the lead scoring rules.', 'doublescale'),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'from'     => array(
				'description' => __( 'Start date for filtering rules.', 'doublescale'),
				'type'        => 'string',
				'format'      => 'date',
			),
			'to'       => array(
				'description' => __( 'End date for filtering rules.', 'doublescale'),
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
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			$keyword  = $request->get_param( 'keyword' ) ? $request->get_param( 'keyword' ) : '';
			$status   = $request->get_param( 'status' ) ?? null;
			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$from     = $request->get_param( 'from' ) ?? null;
			$to       = $request->get_param( 'to' ) ?? null;

			$query       = LeadScoringRuleModel::query();
			$total_count = $query->count();

			if ( $keyword ) {
				$query->where( 'title', 'LIKE', '%' . $keyword . '%' );
			}
			if ( $status ) {
				$query->where( 'status', $status );
			}
			if ( $from ) {
				$query->where( 'created_at', '>=', $from );
			}
			if ( $to ) {
				$query->where( 'created_at', '<=', $to );
			}

			$rules = $query->orderBy( 'created_at', 'desc' )->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $rules->toArray() + array( 'total_count' => $total_count ), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get active rules
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_active_rules( $request ) {
		try {
			$rules = LeadScoringRuleModel::get_active_rules();
			return new WP_REST_Response( $rules, 200 );
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
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_items( $request ) {
		try {
			$rule_ids = $request->get_param( 'ids' );
			LeadScoringRuleModel::whereIn( 'id', $rule_ids )->delete();

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
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$rule_data = $this->prepare_rule( $request );
			$rule      = LeadScoringRuleModel::create( $rule_data );

			return new WP_REST_Response( $rule, 201 );
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
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		try {
			$rule_id = $request->get_param( 'id' );
			$rule    = LeadScoringRuleModel::find( $rule_id );

			if ( ! $rule ) {
				return new WP_Error( 'error', __( 'Lead scoring rule not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $rule, 200 );
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
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			$rule_id = $request->get_param( 'id' );
			$rule    = LeadScoringRuleModel::find( $rule_id );

			if ( ! $rule ) {
				return new WP_Error( 'error', __( 'Lead scoring rule not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$rule_data = $this->prepare_rule( $request );
			$rule->update( $rule_data );

			return new WP_REST_Response( $rule, 200 );
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
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$rule_id = $request->get_param( 'id' );
			$rule    = LeadScoringRuleModel::find( $rule_id );

			if ( ! $rule ) {
				return new WP_Error( 'error', __( 'Lead scoring rule not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$rule->delete();

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
	public function prepare_rule( $request ) {
		$rule_data = array();

		$rule_data['title']     = $request->get_param( 'title' );
		$rule_data['status']    = $request->get_param( 'status' );
		$rule_data['points']    = $request->get_param( 'points' );
		$rule_data['is_adding'] = $request->get_param( 'is_adding' );
		$rule_data['settings']  = $request->get_param( 'settings' );

		foreach ( $rule_data as $key => $value ) {
			if ( is_null( $value ) ) {
				unset( $rule_data[ $key ] );
			}
		}

		return $rule_data;
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
