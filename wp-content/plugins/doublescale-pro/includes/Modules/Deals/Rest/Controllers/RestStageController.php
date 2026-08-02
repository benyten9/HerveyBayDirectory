<?php

/**
 * REST Api: Stage controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 * @subpackage RestApi
 */

namespace DoubleScale\Pro\Modules\Deals\Rest\Controllers;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stage REST Controller class
 */
class RestStageController extends RestController {





	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'stages';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		 // Single stage endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context'       => $this->get_context_param( array( 'default' => 'view' ) ),
						'with_pipeline' => array(
							'description' => 'Include pipeline relationship data.',
							'type'        => 'boolean',
							'default'     => false,
						),
						'with_deals'    => array(
							'description' => 'Include deals in this stage.',
							'type'        => 'boolean',
							'default'     => false,
						),
					),
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
					'args'                => array(
						'move_deals_to' => array(
							'description' => 'Stage ID to move deals to before deletion.',
							'type'        => 'integer',
							'required'    => false,
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Get a single stage.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$stage_id = $request->get_param( 'id' );
		$stage    = PipelineStageModel::find( $stage_id );

		if ( ! $stage ) {
			return new WP_Error( 'stage_not_found', 'Stage not found.', array( 'status' => 404 ) );
		}

		$data = $this->prepare_item_for_response( $stage, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Update a stage.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$stage_id = $request->get_param( 'id' );
		$stage    = PipelineStageModel::find( $stage_id );

		if ( ! $stage ) {
			return new WP_Error( 'stage_not_found', 'Stage not found.', array( 'status' => 404 ) );
		}

		$prepared_data = $this->prepare_item_for_database( $request );

		// Store old win_probability before updating
		$old_win_probability = $stage->win_probability;

		foreach ( $prepared_data as $key => $value ) {
			if ( in_array( $key, $stage->getFillable(), true ) ) {
				$stage->$key = $value;
			}
		}

		if ( ! $stage->save() ) {
			return new WP_Error( 'stage_update_failed', 'Failed to update stage.', array( 'status' => 500 ) );
		}

		// If win_probability changed, update deals that were using the old stage probability
		if ( isset( $prepared_data['win_probability'] ) && $old_win_probability != $prepared_data['win_probability'] ) {
			$new_win_probability = $prepared_data['win_probability'];

			// Update all deals in this stage that have the old probability (meaning they were using stage default)
			$stage->deals()
				->where( 'probability', $old_win_probability )
				->update( array(
					'probability' => $new_win_probability,
					'status'      => \DoubleScale\Pro\Modules\Deals\Models\DealModel::get_status_from_probability( $new_win_probability ),
				) );
		}

		do_action( 'doublescale_stage_updated', $stage );

		$data = $this->prepare_item_for_response( $stage, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Delete a stage.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$stage_id      = $request->get_param( 'id' );
		$move_deals_to = $request->get_param( 'move_deals_to' );

		$stage = PipelineStageModel::find( $stage_id );

		if ( ! $stage ) {
			return new WP_Error( 'stage_not_found', 'Stage not found.', array( 'status' => 404 ) );
		}

		// Check if there are deals in this stage
		$deals_count = $stage->deals()->count();

		if ( $deals_count > 0 ) {
			if ( ! $move_deals_to ) {
				return new WP_Error(
					'stage_has_deals',
					'Stage has deals. Specify move_deals_to parameter to move deals to another stage before deletion.',
					array(
						'status'      => 400,
						'deals_count' => $deals_count,
					)
				);
			}

			// Verify target stage exists and is in the same pipeline
			$target_stage = PipelineStageModel::find( $move_deals_to );
			if ( ! $target_stage || $target_stage->pipeline_id !== $stage->pipeline_id ) {
				return new WP_Error(
					'invalid_target_stage',
					'Target stage not found or not in the same pipeline.',
					array( 'status' => 400 )
				);
			}

			// Move all deals to the target stage
			$stage->deals()->update( array( 'stage_id' => $move_deals_to ) );
		}

		$previous = $stage->toArray();

		if ( ! $stage->delete() ) {
			return new WP_Error( 'stage_delete_failed', 'Failed to delete stage.', array( 'status' => 500 ) );
		}

		do_action( 'doublescale_stage_deleted', $previous );

		return new WP_REST_Response(
			array(
				'deleted'     => true,
				'previous'    => $previous,
				'moved_deals' => $deals_count,
			),
			200
		);
	}

	/**
	 * Prepare a single stage for response.
	 *
	 * @since 1.0.0
	 *
	 * @param PipelineStageModel $stage Stage object.
	 * @param WP_REST_Request      $request Request object.
	 * @return array Stage data.
	 */
	public function prepare_item_for_response( $stage, $request ) {
		$data = $stage->toArray();

		$with_pipeline = $request->get_param( 'with_pipeline' );
		$with_deals    = $request->get_param( 'with_deals' );

		if ( $with_pipeline ) {
			$data['pipeline'] = $stage->pipeline ? $stage->pipeline->toArray() : null;
		}

		if ( $with_deals ) {
			$data['deals']       = $stage->deals ? $stage->deals->toArray() : array();
			$data['deals_count'] = $stage->deals()->count();
		}

		return $data;
	}

	/**
	 * Prepare a stage for create or update operation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Prepared stage data.
	 */
	protected function prepare_item_for_database( $request ) {
		$prepared = array();

		if ( $request->has_param( 'name' ) ) {
			$prepared['name'] = sanitize_text_field( $request->get_param( 'name' ) );
		}

		if ( $request->has_param( 'color' ) ) {
			$prepared['color'] = sanitize_text_field( $request->get_param( 'color' ) );
		}

		if ( $request->has_param( 'sort_order' ) ) {
			$prepared['sort_order'] = absint( $request->get_param( 'sort_order' ) );
		}

		if ( $request->has_param( 'win_probability' ) ) {
			$prepared['win_probability'] = floatval( $request->get_param( 'win_probability' ) );
		}

		return $prepared;
	}

	/**
	 * Get the stage schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		 $schema = array(
			 '$schema'    => 'http://json-schema.org/draft-04/schema#',
			 'title'      => 'stage',
			 'type'       => 'object',
			 'properties' => array(
				 'id'              => array(
					 'description' => 'Unique identifier for the stage.',
					 'type'        => 'integer',
					 'context'     => array( 'view', 'edit' ),
					 'readonly'    => true,
				 ),
				 'pipeline_id'     => array(
					 'description' => 'The ID of the pipeline this stage belongs to.',
					 'type'        => 'integer',
					 'context'     => array( 'view', 'edit' ),
					 'readonly'    => true,
				 ),
				 'name'            => array(
					 'description' => 'The stage name.',
					 'type'        => 'string',
					 'context'     => array( 'view', 'edit' ),
					 'required'    => true,
				 ),
				 'color'           => array(
					 'description' => 'The stage color (hex code).',
					 'type'        => 'string',
					 'context'     => array( 'view', 'edit' ),
				 ),
				 'sort_order'      => array(
					 'description' => 'The sort order of the stage.',
					 'type'        => 'integer',
					 'context'     => array( 'view', 'edit' ),
				 ),
				 'win_probability' => array(
					 'description' => 'The win probability for deals in this stage (0-100).',
					 'type'        => 'number',
					 'context'     => array( 'view', 'edit' ),
				 ),
				 'created_at'      => array(
					 'description' => 'The date the stage was created, in the site\'s timezone.',
					 'type'        => 'string',
					 'format'      => 'date-time',
					 'context'     => array( 'view', 'edit' ),
					 'readonly'    => true,
				 ),
				 'updated_at'      => array(
					 'description' => 'The date the stage was last modified, in the site\'s timezone.',
					 'type'        => 'string',
					 'format'      => 'date-time',
					 'context'     => array( 'view', 'edit' ),
					 'readonly'    => true,
				 ),
			 ),
		 );

		 return $schema;
	}

	/**
	 * Check if a given request has access to get a specific item
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if a given request has access to update a specific item
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete a specific item
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
