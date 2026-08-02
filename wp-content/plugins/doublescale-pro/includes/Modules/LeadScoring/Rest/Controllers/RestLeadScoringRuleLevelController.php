<?php

/**
 * Class RestLeadScoringRuleLevelController
 *
 * This class is responsible for handling the Lead Scoring Rule Level REST Api
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
use DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel;

/**
 * RestLeadScoringRuleLevelController class
 */
class RestLeadScoringRuleLevelController extends RestController {





	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'lead-scoring-levels';

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
							'description' => __( 'IDs of the lead scoring levels.', 'doublescale'),
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
			'/' . $this->rest_base . '/for-score/(?P<score>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_level_for_score' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'score' => array(
							'description' => __( 'Score to get level for.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/with-achievement/(?P<score>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_levels_with_achievement' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'score' => array(
							'description' => __( 'Score to check achievements against.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/slug/(?P<slug>[a-zA-Z0-9-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_by_slug' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'slug' => array(
							'description' => __( 'Slug of the level.', 'doublescale'),
							'type'        => 'string',
							'required'    => true,
						),
					),
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
			 'title'      => 'lead_scoring_level',
			 'type'       => 'object',
			 'properties' => array(
				 'id'         => array(
					 'description' => esc_html__( 'Unique identifier for the object.', 'doublescale'),
					 'type'        => 'integer',
					 'readonly'    => true,
				 ),
				 'name'       => array(
					 'description' => esc_html__( 'Name of the level.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
					 'required'    => true,
				 ),
				 'slug'       => array(
					 'description' => esc_html__( 'Slug of the level.', 'doublescale'),
					 'type'        => 'string',
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_title',
					 ),
				 ),
				 'points'     => array(
					 'description' => esc_html__( 'Minimum points required for this level.', 'doublescale'),
					 'type'        => 'integer',
					 'required'    => true,
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
			'order'    => array(
				'description'       => __( 'Order direction for points sorting (asc/desc).', 'doublescale'),
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'asc',
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
				'description' => __( 'IDs of the lead scoring levels.', 'doublescale'),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'from'     => array(
				'description' => __( 'Start date for filtering levels.', 'doublescale'),
				'type'        => 'string',
				'format'      => 'date',
			),
			'to'       => array(
				'description' => __( 'End date for filtering levels.', 'doublescale'),
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
			$order    = $request->get_param( 'order' ) ? $request->get_param( 'order' ) : 'asc';
			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$from     = $request->get_param( 'from' ) ?? null;
			$to       = $request->get_param( 'to' ) ?? null;

			$query       = LeadScoringRuleLevelModel::query();
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

			// order by the points in descending order
			$query->orderBy( 'points', 'desc' );

			$levels = $query->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $levels->toArray() + array( 'total_count' => $total_count ), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get level for a specific score
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_level_for_score( $request ) {
		try {
			$score = $request->get_param( 'score' );
			$level = LeadScoringRuleLevelModel::get_level_for_score( $score );

			if ( ! $level ) {
				return new WP_Error( 'error', __( 'No level found for this score.', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $level, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get levels with achievement status
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_levels_with_achievement( $request ) {
		try {
			$score  = $request->get_param( 'score' );
			$levels = LeadScoringRuleLevelModel::get_levels_with_achievement_status( $score );

			return new WP_REST_Response( $levels, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get level by slug
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_by_slug( $request ) {
		try {
			$slug  = $request->get_param( 'slug' );
			$level = LeadScoringRuleLevelModel::get_by_slug( $slug );

			if ( ! $level ) {
				return new WP_Error( 'error', __( 'Level not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $level, 200 );
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
			$level_ids = $request->get_param( 'ids' );
			LeadScoringRuleLevelModel::whereIn( 'id', $level_ids )->delete();

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
			$level_data = $this->prepare_level( $request );
			$level      = LeadScoringRuleLevelModel::create( $level_data );

			return new WP_REST_Response( $level, 201 );
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
			$level_id = $request->get_param( 'id' );
			$level    = LeadScoringRuleLevelModel::find( $level_id );

			if ( ! $level ) {
				return new WP_Error( 'error', __( 'Lead scoring level not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $level, 200 );
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
			$level_id = $request->get_param( 'id' );
			$level    = LeadScoringRuleLevelModel::find( $level_id );

			if ( ! $level ) {
				return new WP_Error( 'error', __( 'Lead scoring level not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$level_data = $this->prepare_level( $request );
			$level->update( $level_data );

			return new WP_REST_Response( $level, 200 );
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
			$level_id = $request->get_param( 'id' );
			$level    = LeadScoringRuleLevelModel::find( $level_id );

			if ( ! $level ) {
				return new WP_Error( 'error', __( 'Lead scoring level not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$level->delete();

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
	public function prepare_level( $request ) {
		$level_data = array();

		$level_data['name']   = $request->get_param( 'name' );
		$level_data['slug']   = $request->get_param( 'slug' );
		$level_data['points'] = $request->get_param( 'points' );

		foreach ( $level_data as $key => $value ) {
			if ( is_null( $value ) ) {
				unset( $level_data[ $key ] );
			}
		}

		return $level_data;
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
