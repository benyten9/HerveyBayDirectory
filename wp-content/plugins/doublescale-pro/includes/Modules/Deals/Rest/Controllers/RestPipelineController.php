<?php

/**
 * REST Api: Pipeline controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 * @subpackage RestApi
 */

namespace DoubleScale\Pro\Modules\Deals\Rest\Controllers;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\Deals\Models\PipelineModel;
use DoubleScale\Pro\Modules\Deals\Models\PipelineStageModel;
use DoubleScale\Pro\Modules\Deals\Services\PipelineManager;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\PermissionsCompat;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Pipeline REST Controller class
 */
class RestPipelineController extends RestController
{
	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'pipelines';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes()
	{
		// Pipelines endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'get_items'),
					'permission_callback' => array($this, 'get_items_permissions_check'),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'create_item'),
					'permission_callback' => array($this, 'create_item_permissions_check'),
					'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'get_item'),
					'permission_callback' => array($this, 'get_item_permissions_check'),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array($this, 'update_item'),
					'permission_callback' => array($this, 'update_item_permissions_check'),
					'args'                => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array($this, 'delete_item'),
					'permission_callback' => array($this, 'delete_item_permissions_check'),
				),
			)
		);

		// Pipeline stages endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<pipeline_id>[\d]+)/stages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'get_stages'),
					'permission_callback' => array($this, 'get_items_permissions_check'),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'create_stage'),
					'permission_callback' => array($this, 'create_item_permissions_check'),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<pipeline_id>[\d]+)/stages/(?P<stage_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array($this, 'update_stage'),
					'permission_callback' => array($this, 'update_item_permissions_check'),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array($this, 'delete_stage'),
					'permission_callback' => array($this, 'delete_item_permissions_check'),
				),
			)
		);

		// Pipeline analytics endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_analytics'),
				'permission_callback' => array($this, 'get_analytics_permissions_check'),
			)
		);

		// Pipeline duplication endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'duplicate_pipeline'),
				'permission_callback' => array($this, 'create_item_permissions_check'),
			)
		);

		// Bulk sort order endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sort-order',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array($this, 'update_sort_order'),
				'permission_callback' => array($this, 'update_item_permissions_check'),
			)
		);
	}

	/**
	 * Get a collection of pipelines
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items($request)
	{
		$with_stages = $request->get_param('with_stages');
		$with_stats  = $request->get_param('with_stats');

		$pipelines = PipelineModel::query();

		// Sales Rep can only see pipelines where they have deals
		// Sales Manager, CRM Manager, and Administrator can see all pipelines
		if (Permissions::is_sales_rep() && ! PermissionsCompat::has_sales_manager_access()) {
			$pipeline_ids = PipelineManager::instance()->get_pipeline_ids_for_owner(get_current_user_id());
			$pipelines    = $pipelines->whereIn('id', $pipeline_ids);
		}

		if ($with_stages) {
			$pipelines = $pipelines->with('stages')->orderBy('sort_order')->get();
		} else {
			$pipelines = $pipelines->orderBy('sort_order')->get();
		}

		$data = array();
		foreach ($pipelines as $pipeline) {
			$pipeline_data = $this->prepare_item_for_response($pipeline, $request);

			if ($with_stats) {
				$pipeline_data['stats'] = array(
					'total_value'             => $pipeline->total_value,
					'total_value_by_currency' => $pipeline->total_value_by_currency,
					'deal_count'              => $pipeline->deal_count,
				);
			}

			$data[] = $pipeline_data;
		}

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Get one pipeline
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item($request)
	{
		$pipeline_id = $request->get_param('id');
		$with_stages = $request->get_param('with_stages');
		$with_stats  = $request->get_param('with_stats');

		// Sales Rep can only access pipelines where they have deals
		// Sales Manager, CRM Manager, and Administrator can access all pipelines
		if (Permissions::is_sales_rep() && ! PermissionsCompat::has_sales_manager_access()) {
			$pipeline_ids = PipelineManager::instance()->get_pipeline_ids_for_owner(get_current_user_id());
			if (! in_array($pipeline_id, $pipeline_ids)) {
				return new WP_Error('pipeline_not_found', 'Pipeline not found', array('status' => 404));
			}
		}

		if ($with_stats) {
			$pipeline = PipelineManager::instance()->get_pipeline_with_stats($pipeline_id);
		} elseif ($with_stages) {
			$pipeline = PipelineModel::with('stages')->find($pipeline_id);
		} else {
			$pipeline = PipelineModel::find($pipeline_id);
		}

		if (! $pipeline) {
			return new WP_Error('pipeline_not_found', 'Pipeline not found', array('status' => 404));
		}

		$data = $this->prepare_item_for_response($pipeline, $request);

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Create one pipeline
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item($request)
	{
		$name        = sanitize_text_field($request->get_param('name'));
		$description = sanitize_textarea_field($request->get_param('description'));
		$stages      = $request->get_param('stages');

		if (empty($name)) {
			return new WP_Error('missing_name', 'Pipeline name is required', array('status' => 400));
		}

		$pipeline = PipelineManager::instance()->create_pipeline_with_stages($name, $description, $stages);

		if (! $pipeline) {
			return new WP_Error('creation_failed', 'Failed to create pipeline', array('status' => 500));
		}

		$pipeline->load('stages');
		$data = $this->prepare_item_for_response($pipeline, $request);

		return new WP_REST_Response($data, 201);
	}

	/**
	 * Update one pipeline
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item($request)
	{
		$pipeline_id = $request->get_param('id');
		$pipeline    = PipelineModel::find($pipeline_id);

		if (! $pipeline) {
			return new WP_Error('pipeline_not_found', 'Pipeline not found', array('status' => 404));
		}

		$name        = $request->get_param('name');
		$description = $request->get_param('description');
		$sort_order  = $request->get_param('sort_order');
		$stages      = $request->get_param('stages');

		if (! empty($name)) {
			$pipeline->name = sanitize_text_field($name);
		}

		if (isset($description)) {
			$pipeline->description = sanitize_textarea_field($description);
		}

		if (isset($sort_order)) {
			$pipeline->sort_order = intval($sort_order);
		}

		$pipeline->save();

		// Handle stages if provided
		if (is_array($stages)) {
			$this->sync_pipeline_stages($pipeline_id, $stages);
		}

		// Reload pipeline with stages
		$pipeline->load('stages');
		$data = $this->prepare_item_for_response($pipeline, $request);

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Sync pipeline stages - create new stages, update existing ones, and delete removed ones
	 *
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $stages      Array of stage data.
	 *
	 * @return void
	 */
	protected function sync_pipeline_stages($pipeline_id, $stages)
	{
		// Get existing stage IDs for this pipeline
		$existing_stages    = PipelineStageModel::where('pipeline_id', $pipeline_id)->get();
		$existing_stage_ids = $existing_stages->pluck('id')->toArray();
		$submitted_stage_ids = array();

		foreach ($stages as $index => $stage_data) {
			$stage_id = isset($stage_data['id']) ? intval($stage_data['id']) : 0;

			if ($stage_id && in_array($stage_id, $existing_stage_ids)) {
				// Update existing stage
				$stage = PipelineStageModel::find($stage_id);
				if ($stage) {
					if (isset($stage_data['name'])) {
						$stage->name = sanitize_text_field($stage_data['name']);
					}
					if (isset($stage_data['color'])) {
						$stage->color = sanitize_text_field($stage_data['color']);
					}
					if (isset($stage_data['win_probability'])) {
						$stage->win_probability = floatval($stage_data['win_probability']);
					}
					$stage->sort_order = $index;
					$stage->save();
					$submitted_stage_ids[] = $stage_id;
				}
			} else {
				// Create new stage
				$new_stage = PipelineManager::instance()->add_stage(
					$pipeline_id,
					sanitize_text_field($stage_data['name'] ?? 'New Stage'),
					sanitize_text_field($stage_data['color'] ?? '#1E3A8A'),
					floatval($stage_data['win_probability'] ?? 50),
					$index
				);
				if ($new_stage) {
					$submitted_stage_ids[] = $new_stage->id;
				}
			}
		}

		// Delete stages that were removed (not in submitted list)
		$stages_to_delete = array_diff($existing_stage_ids, $submitted_stage_ids);
		foreach ($stages_to_delete as $stage_id) {
			$stage = PipelineStageModel::find($stage_id);
			if ($stage) {
				$stage->delete();
			}
		}
	}

	/**
	 * Delete one pipeline
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item($request)
	{
		$pipeline_id   = $request->get_param('id');
		$move_deals_to = $request->get_param('move_deals_to');

		$deleted = PipelineManager::instance()->delete_pipeline($pipeline_id, $move_deals_to);

		if (! $deleted) {
			return new WP_Error('delete_failed', 'Failed to delete pipeline', array('status' => 500));
		}

		return new WP_REST_Response(array('deleted' => true), 200);
	}

	/**
	 * Get pipeline stages
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_stages($request)
	{
		$pipeline_id = $request->get_param('pipeline_id');

		$pipeline = PipelineModel::with('stages')->find($pipeline_id);

		if (! $pipeline) {
			return new WP_Error('pipeline_not_found', 'Pipeline not found', array('status' => 404));
		}

		$data = array();
		foreach ($pipeline->stages as $stage) {
			$data[] = $this->prepare_stage_for_response($stage);
		}

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Create pipeline stage
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_stage($request)
	{
		$pipeline_id     = $request->get_param('pipeline_id');
		$name            = sanitize_text_field($request->get_param('name'));
		$color           = sanitize_text_field($request->get_param('color'));
		$win_probability = $request->get_param('win_probability');
		$position        = $request->get_param('position');

		if (empty($name)) {
			return new WP_Error('missing_name', 'Stage name is required', array('status' => 400));
		}

		// Default win_probability to 50 if not provided
		$win_probability = isset($win_probability) ? floatval($win_probability) : 50.0;

		$stage = PipelineManager::instance()->add_stage(
			$pipeline_id,
			$name,
			$color ?: '#6d78d8',
			$win_probability,
			$position
		);

		if (! $stage) {
			return new WP_Error('creation_failed', 'Failed to create stage', array('status' => 500));
		}

		$data = $this->prepare_stage_for_response($stage);

		return new WP_REST_Response($data, 201);
	}

	/**
	 * Update pipeline stage
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_stage($request)
	{
		$stage_id = $request->get_param('stage_id');
		$stage    = PipelineStageModel::find($stage_id);

		if (! $stage) {
			return new WP_Error('stage_not_found', 'Stage not found', array('status' => 404));
		}

		$name            = $request->get_param('name');
		$color           = $request->get_param('color');
		$win_probability = $request->get_param('win_probability');
		$sort_order      = $request->get_param('sort_order');

		// Store old win_probability before updating
		$old_win_probability = $stage->win_probability;

		if (! empty($name)) {
			$stage->name = sanitize_text_field($name);
		}

		if (! empty($color)) {
			$stage->color = sanitize_text_field($color);
		}

		if (isset($win_probability)) {
			$stage->win_probability = floatval($win_probability);
		}

		if (isset($sort_order)) {
			$stage->sort_order = intval($sort_order);
		}

		$stage->save();

		// If win_probability changed, update deals that were using the old stage probability
		if (isset($win_probability) && $old_win_probability != floatval($win_probability)) {
			$new_win_probability = floatval($win_probability);

			// Update all deals in this stage that have the old probability (meaning they were using stage default)
			$stage->deals()
				->where('probability', $old_win_probability)
				->update(array(
					'probability' => $new_win_probability,
					'status'      => \DoubleScale\Pro\Modules\Deals\Models\DealModel::get_status_from_probability($new_win_probability),
				));
		}

		$data = $this->prepare_stage_for_response($stage);

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Delete pipeline stage
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_stage($request)
	{
		$stage_id = $request->get_param('stage_id');
		$stage    = PipelineStageModel::find($stage_id);

		if (! $stage) {
			return new WP_Error('stage_not_found', 'Stage not found', array('status' => 404));
		}

		$deleted = $stage->delete();

		if (! $deleted) {
			return new WP_Error('delete_failed', 'Failed to delete stage', array('status' => 500));
		}

		return new WP_REST_Response(array('deleted' => true), 200);
	}

	/**
	 * Get pipeline analytics
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_analytics($request)
	{
		$pipeline_id = $request->get_param('id');
		$filters     = array();

		if ($request->get_param('date_from')) {
			$filters['date_from'] = sanitize_text_field($request->get_param('date_from'));
		}

		if ($request->get_param('date_to')) {
			$filters['date_to'] = sanitize_text_field($request->get_param('date_to'));
		}

		$analytics = PipelineManager::instance()->get_pipeline_analytics($pipeline_id, $filters);

		if (empty($analytics)) {
			return new WP_Error('pipeline_not_found', 'Pipeline not found', array('status' => 404));
		}

		return new WP_REST_Response($analytics, 200);
	}

	/**
	 * Duplicate pipeline
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function duplicate_pipeline($request)
	{
		$pipeline_id = $request->get_param('id');
		$new_name    = sanitize_text_field($request->get_param('name'));

		$new_pipeline = PipelineManager::instance()->duplicate_pipeline($pipeline_id, $new_name);

		if (! $new_pipeline) {
			return new WP_Error('duplication_failed', 'Failed to duplicate pipeline', array('status' => 500));
		}

		$new_pipeline->load('stages');
		$data = $this->prepare_item_for_response($new_pipeline, $request);

		return new WP_REST_Response($data, 201);
	}

	/**
	 * Update pipeline sort order
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_sort_order($request)
	{
		$pipeline_ids = $request->get_param('pipeline_ids');

		if (! is_array($pipeline_ids) || empty($pipeline_ids)) {
			return new WP_Error('invalid_data', 'Pipeline IDs array is required', array('status' => 400));
		}

		$updated = PipelineManager::instance()->update_pipeline_sort_order($pipeline_ids);

		if (! $updated) {
			return new WP_Error('update_failed', 'Failed to update sort order', array('status' => 500));
		}

		return new WP_REST_Response(array('updated' => true), 200);
	}


	/**
	 * Prepare the item for the REST response
	 *
	 * @param Pipeline        $pipeline Pipeline object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	public function prepare_item_for_response($pipeline, $request)
	{
		$data = array(
			'id'          => $pipeline->id,
			'name'        => $pipeline->name,
			'description' => $pipeline->description,
			'sort_order'  => $pipeline->sort_order,
			'created_at'  => $pipeline->created_at,
			'updated_at'  => $pipeline->updated_at,
		);

		if ($pipeline->relationLoaded('stages')) {
			$data['stages'] = array();
			foreach ($pipeline->stages as $stage) {
				$data['stages'][] = $this->prepare_stage_for_response($stage);
			}
		}

		return $data;
	}

	/**
	 * Prepare stage for response
	 *
	 * @param Pipeline_Stage $stage Stage object.
	 *
	 * @return array
	 */
	protected function prepare_stage_for_response($stage)
	{
		return array(
			'id'              => $stage->id,
			'pipeline_id'     => $stage->pipeline_id,
			'name'            => $stage->name,
			'color'           => $stage->color,
			'sort_order'      => $stage->sort_order,
			'win_probability' => $stage->win_probability,
			'total_value'     => $stage->total_value ?? 0,
			'total_value_by_currency' => $stage->total_value_by_currency ?? array(),
			'deal_count'      => $stage->deal_count ?? 0,
			'created_at'      => $stage->created_at,
			'updated_at'      => $stage->updated_at,
		);
	}



	/**
	 * Check if user can access pipelines
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check($request)
	{
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if user can access single pipeline
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_item_permissions_check($request)
	{
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if user can create pipelines
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function create_item_permissions_check($request)
	{
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if user can update pipelines
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function update_item_permissions_check($request)
	{
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if user can delete pipelines
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function delete_item_permissions_check($request)
	{
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if user can access pipeline analytics
	 *
	 * Sales Manager, CRM Manager, and Administrator can access pipeline analytics
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_analytics_permissions_check($request)
	{
		return PermissionsCompat::has_sales_manager_access();
	}
}
