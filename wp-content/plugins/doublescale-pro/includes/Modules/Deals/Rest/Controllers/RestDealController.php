<?php

/**
 * REST Api: Deal controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 * @subpackage RestApi
 */

namespace DoubleScale\Pro\Modules\Deals\Rest\Controllers;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Deals\Services\DealManager;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\PermissionsCompat;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Deal REST Controller class
 */
class RestDealController extends RestController
{










	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'deals';

	/**
	 * Polymorphic attachable_type for deal file attachments.
	 */
	private const DEAL_ATTACHABLE_TYPE = 'deal';

	/**
	 * Maximum upload size per deal attachment (10 MB).
	 */
	private const DEAL_ATTACHMENT_MAX_BYTES = 10485760;

	/**
	 * Maximum active attachments per deal.
	 */
	private const DEAL_ATTACHMENT_MAX_COUNT = 20;

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes()
	{
		// Deals endpoints
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

		// Deal stage movement
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/move-stage',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array($this, 'move_to_stage'),
				'permission_callback' => array($this, 'update_item_permissions_check'),
			)
		);

		// Invoice/proposal totals linked to this deal.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/financials',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_financials'),
				'permission_callback' => array($this, 'get_item_permissions_check'),
			)
		);

		// Link an existing invoice to this deal.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attach-invoice',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'attach_invoice'),
				'permission_callback' => array($this, 'update_item_permissions_check'),
				'args'                => array(
					'invoice_id' => array(
						'description' => __('Invoice to link to this deal.', 'doublescale'),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		// Link an existing proposal to this deal.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attach-proposal',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'attach_proposal'),
				'permission_callback' => array($this, 'update_item_permissions_check'),
				'args'                => array(
					'proposal_id' => array(
						'description' => __('Proposal to link to this deal.', 'doublescale'),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		// Deal pipeline movement
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/move-pipeline',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array($this, 'move_to_pipeline'),
				'permission_callback' => array($this, 'update_item_pipeline_permissions_check'),
			)
		);

		// Overdue deals
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/overdue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_overdue_deals'),
				'permission_callback' => array($this, 'get_items_permissions_check'),
			)
		);

		// Deal statistics
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/statistics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_statistics'),
				'permission_callback' => array($this, 'get_items_permissions_check'),
			)
		);

		// Bulk operations
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array($this, 'bulk_update'),
				'permission_callback' => array($this, 'update_items_permissions_check'),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-delete',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'bulk_delete'),
				'permission_callback' => array($this, 'delete_items_permissions_check'),
			)
		);

		// Deal file attachments.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_attachments' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_attachment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments/(?P<attachment_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_attachment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get a collection of deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items($request)
	{
		$filters = array(
			'pipeline_id'         => $request->get_param('pipeline_id'),
			'stage_id'            => $request->get_param('stage_id'),
			'status'              => $request->get_param('status'),
			'owner_id'            => $request->get_param('owner_id'),
			'contact_id'          => $request->get_param('contact_id'),
			'value_min'           => $request->get_param('value_min'),
			'value_max'           => $request->get_param('value_max'),
			'date_from'           => $request->get_param('date_from'),
			'date_to'             => $request->get_param('date_to'),
			'expected_close_from' => $request->get_param('expected_close_from'),
			'expected_close_to'   => $request->get_param('expected_close_to'),
			'search'              => $request->get_param('search'),
			'sort_by'             => $request->get_param('sort_by'),
			'sort_order'          => $request->get_param('sort_order'),
			'priority'            => $request->get_param('priority'),
		);

		// Remove null values
		$filters = array_filter(
			$filters,
			function ($value) {
				return $value !== null && $value !== '';
			}
		);

		$deals = DealManager::instance()->get_deals_with_filters($filters);

		$data = array();
		foreach ($deals as $deal) {
			$data[] = $this->prepare_item_for_response($deal, $request);
		}

		$response = new WP_REST_Response($data, 200);
		$response->header('X-Total-Count', count($data));

		return $response;
	}

	/**
	 * Get one deal
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item($request)
	{
		$deal_id            = $request->get_param('id');
		$with_relationships = $request->get_param('with_relationships');

		if ($with_relationships) {
			$deal = DealModel::with(array('contact', 'pipeline', 'stage', 'owner', 'activities', 'custom_fields'))->find($deal_id);
		} else {
			$deal = DealModel::with(array('contact', 'pipeline', 'stage', 'owner', 'custom_fields'))->find($deal_id);
		}

		if (! $deal) {
			return new WP_Error('deal_not_found', 'Deal not found', array('status' => 404));
		}

		// Sales Rep can only view their own deals
		// Sales Manager, CRM Manager, and Administrator can view all deals
		if (Permissions::is_sales_rep() && ! PermissionsCompat::has_sales_manager_access()) {
			if ($deal->owner_id != get_current_user_id()) {
				return new WP_Error('deal_not_found', 'Deal not found', array('status' => 404));
			}
		}

		$data = $this->prepare_item_for_response($deal, $request);

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Create one deal
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item($request)
	{
		// Validate required fields
		$title       = $request->get_param('title');
		$contact_id  = $request->get_param('contact_id');
		$pipeline_id = $request->get_param('pipeline_id');
		$stage_id    = $request->get_param('stage_id');

		// Check for required fields
		if (empty($title)) {
			return new WP_Error(
				'missing_title',
				__('Deal title is required.', 'doublescale'),
				array('status' => 400)
			);
		}

		if (empty($contact_id)) {
			return new WP_Error(
				'missing_contact',
				__('Contact is required to create a deal.', 'doublescale'),
				array('status' => 400)
			);
		}

		if (empty($pipeline_id)) {
			return new WP_Error(
				'missing_pipeline',
				__('Pipeline is required to create a deal.', 'doublescale'),
				array('status' => 400)
			);
		}

		if (empty($stage_id)) {
			return new WP_Error(
				'missing_stage',
				__('Pipeline stage is required to create a deal.', 'doublescale'),
				array('status' => 400)
			);
		}

		// Sales Rep deals are always assigned to themselves
		$owner_id = $request->get_param('owner_id');
		if (Permissions::is_sales_rep() && ! PermissionsCompat::has_sales_manager_access()) {
			$owner_id = get_current_user_id();
		}

		if ($owner_id) {
			$validation_error = $this->validate_owner_id($owner_id);
			if (is_wp_error($validation_error)) {
				return $validation_error;
			}
		}

		// Validate deal value
		$value = $request->get_param('value');
		if ($value !== null && $value !== '' && floatval($value) < 0) {
			return new WP_Error(
				'invalid_value',
				__('Deal value cannot be negative.', 'doublescale'),
				array('status' => 400)
			);
		}

		// Validate expected_close_date if provided
		$expected_close_date = $request->get_param('expected_close_date');
		if ($expected_close_date !== null && $expected_close_date !== '') {
			$validated_date = $this->validate_and_sanitize_date($expected_close_date);
			if (is_wp_error($validated_date)) {
				return $validated_date;
			}
			$expected_close_date = $validated_date;
		} else {
			$expected_close_date = null;
		}

		$data = array(
			'title'               => sanitize_text_field($title),
			'contact_id'          => intval($contact_id),
			'pipeline_id'         => intval($pipeline_id),
			'stage_id'            => intval($stage_id),
			'value'               => $value !== null && $value !== '' ? floatval($value) : 0,
			// Currency is not client-writable; freezes on first proposal/invoice link.
			'expected_close_date' => $expected_close_date,
			'priority'            => sanitize_text_field($request->get_param('priority')),
			'owner_id'            => $owner_id ? intval($owner_id) : null,
			'source'              => sanitize_text_field($request->get_param('source')),
		);

		// Remove only empty strings, keep explicit nulls (allows clearing date fields)
		$data = array_filter(
			$data,
			function ($value) {
				return $value !== '';
			}
		);

		$deal = DealManager::instance()->create_deal($data);

		if (! $deal) {
			return new WP_Error(
				'creation_failed',
				__('Failed to create deal. Please check if the contact, pipeline, and stage exist.', 'doublescale'),
				array('status' => 500)
			);
		}

		$sync_custom_fields = $deal->sync_custom_fields($request->get_param('custom_fields'));
		if (is_wp_error($sync_custom_fields)) {
			return $sync_custom_fields;
		}

		$deal->load(array('contact', 'pipeline', 'stage', 'owner', 'custom_fields'));
		$response_data = $this->prepare_item_for_response($deal, $request);

		return new WP_REST_Response($response_data, 201);
	}

	/**
	 * Update one deal
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item($request)
	{

		$deal_id = $request->get_param('id');
		$data    = array();

		// Validate owner_id if being updated
		$owner_id = $request->get_param('owner_id');
		if ($owner_id !== null && $owner_id !== '') {
			$validation_error = $this->validate_owner_id($owner_id);
			if (is_wp_error($validation_error)) {
				return $validation_error;
			}
		}

		// Currency is not client-writable; freezes on first proposal/invoice link.
		$fields = array( 'title', 'contact_id', 'pipeline_id', 'stage_id', 'value', 'expected_close_date', 'owner_id', 'source', 'priority', 'status', 'lost_reason' );
		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			// Special handling for probability - allow explicit null to revert to stage default
			// if ( $field === 'probability' && $request->has_param( $field ) ) {
			// $data[ $field ] = $value !== null ? floatval( $value ) : null;
			// }
			if ( $value !== null ) {
				if ( in_array( $field, array( 'title', 'source', 'priority', 'status', 'lost_reason' ), true ) ) {
					$data[ $field ] = sanitize_text_field( $value );
				} elseif ( $field === 'expected_close_date' ) {
					$validated_date = $this->validate_and_sanitize_date($value);
					if (is_wp_error($validated_date)) {
						return $validated_date;
					}
					$data[$field] = $validated_date;
				} elseif (in_array($field, array('contact_id', 'pipeline_id', 'stage_id', 'owner_id'))) {
					$data[$field] = intval($value);
				} elseif ($field === 'value') {
					$data[$field] = floatval($value);
				}
			}
		}

		$deal = DealManager::instance()->update_deal($deal_id, $data);

		if (! $deal) {
			return new WP_Error('update_failed', 'Failed to update deal', array('status' => 500));
		}

		if ( $request->has_param( 'custom_fields' ) ) {
			$sync_custom_fields = $deal->sync_custom_fields( $request->get_param( 'custom_fields' ) );
			if ( is_wp_error( $sync_custom_fields ) ) {
				return $sync_custom_fields;
			}
		}

		$deal->load(array('contact', 'pipeline', 'stage', 'owner', 'custom_fields'));
		$response_data = $this->prepare_item_for_response($deal, $request);

		return new WP_REST_Response($response_data, 200);
	}

	/**
	 * Delete one deal
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item($request)
	{
		$deal_id = $request->get_param('id');

		$deleted = DealManager::instance()->delete_deal($deal_id);

		if (! $deleted) {
			return new WP_Error('delete_failed', 'Failed to delete deal', array('status' => 500));
		}

		return new WP_REST_Response(array('deleted' => true), 200);
	}

	/**
	 * Move deal to stage
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function move_to_stage($request)
	{
		$deal_id            = $request->get_param('id');
		$stage_id           = intval($request->get_param('stage_id'));
		$update_probability = $request->get_param('update_probability') ? true : false;
		$user_id            = get_current_user_id();

		// Load the deal first to check current stage
		$deal = DealModel::with(array('contact', 'pipeline', 'stage', 'owner'))->find($deal_id);

		if (! $deal) {
			return new WP_Error('deal_not_found', 'Deal not found', array('status' => 404));
		}

		// Check if the deal is already in the target stage
		if ($deal->stage_id == $stage_id) {
			$data            = $this->prepare_item_for_response($deal, $request);
			$data['message'] = 'Deal is already in this stage';
			return new WP_REST_Response($data, 200);
		}

		$moved = DealManager::instance()->move_deal_to_stage($deal_id, $stage_id, $user_id, $update_probability);

		if (! $moved) {
			return new WP_Error('move_failed', 'Failed to move deal to stage', array('status' => 500));
		}

		// Reload the deal to get updated stage information
		$deal = DealModel::with(array('contact', 'pipeline', 'stage', 'owner'))->find($deal_id);
		$data = $this->prepare_item_for_response($deal, $request);

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Invoice/proposal totals for documents linked to this deal.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_financials($request)
	{
		$deal_id = intval($request->get_param('id'));

		if (! DealModel::find($deal_id)) {
			return new WP_Error('deal_not_found', __('Deal not found', 'doublescale'), array('status' => 404));
		}

		return new WP_REST_Response(DealManager::instance()->get_financials($deal_id), 200);
	}

	/**
	 * Link an existing invoice to this deal.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function attach_invoice($request)
	{
		$deal_id    = intval($request->get_param('id'));
		$invoice_id = intval($request->get_param('invoice_id'));

		if (! DealModel::find($deal_id)) {
			return new WP_Error('deal_not_found', __('Deal not found', 'doublescale'), array('status' => 404));
		}
		if ($invoice_id <= 0) {
			return new WP_Error('missing_invoice', __('invoice_id is required.', 'doublescale'), array('status' => 400));
		}

		if (! DealManager::instance()->attach_invoice($deal_id, $invoice_id)) {
			return new WP_Error('attach_failed', __('Failed to attach invoice.', 'doublescale'), array('status' => 500));
		}

		return new WP_REST_Response(DealManager::instance()->get_financials($deal_id), 201);
	}

	/**
	 * Link an existing proposal to this deal.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function attach_proposal($request)
	{
		$deal_id     = intval($request->get_param('id'));
		$proposal_id = intval($request->get_param('proposal_id'));

		if (! DealModel::find($deal_id)) {
			return new WP_Error('deal_not_found', __('Deal not found', 'doublescale'), array('status' => 404));
		}
		if ($proposal_id <= 0) {
			return new WP_Error('missing_proposal', __('proposal_id is required.', 'doublescale'), array('status' => 400));
		}

		if (! DealManager::instance()->attach_proposal($deal_id, $proposal_id)) {
			return new WP_Error('attach_failed', __('Failed to attach proposal.', 'doublescale'), array('status' => 500));
		}

		return new WP_REST_Response(DealManager::instance()->get_financials($deal_id), 201);
	}

	/**
	 * Move deal to pipeline
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function move_to_pipeline($request)
	{
		$deal_id     = intval($request->get_param('id'));
		$pipeline_id = intval($request->get_param('pipeline_id'));
		$stage_id    = $request->get_param('stage_id') ? intval($request->get_param('stage_id')) : null;
		$user_id     = get_current_user_id();

		$moved = DealManager::instance()->move_deal_to_pipeline($deal_id, $pipeline_id, $stage_id, $user_id);

		if (! $moved) {
			return new WP_Error('move_failed', 'Failed to move deal to pipeline', array('status' => 500));
		}

		$deal = DealModel::with(array('contact', 'pipeline', 'stage', 'owner'))->find($deal_id);
		$data = $this->prepare_item_for_response($deal, $request);

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Get overdue deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_overdue_deals($request)
	{
		$owner_id = $request->get_param('owner_id');

		$deals = DealManager::instance()->get_overdue_deals($owner_id);

		$data = array();
		foreach ($deals as $deal) {
			$data[] = $this->prepare_item_for_response($deal, $request);
		}

		return new WP_REST_Response($data, 200);
	}

	/**
	 * Get deal statistics
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_statistics($request)
	{
		$user_id = $request->get_param('user_id');
		$filters = array(
			'date_from' => $request->get_param('date_from'),
			'date_to'   => $request->get_param('date_to'),
		);

		// Remove null values
		$filters = array_filter(
			$filters,
			function ($value) {
				return $value !== null && $value !== '';
			}
		);

		$statistics = DealManager::instance()->get_deal_statistics($user_id, $filters);

		return new WP_REST_Response($statistics, 200);
	}

	/**
	 * Bulk update deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function bulk_update($request)
	{
		$deal_ids = $request->get_param('deal_ids');
		$data     = $request->get_param('data');

		if (! is_array($deal_ids) || empty($deal_ids)) {
			return new WP_Error('invalid_data', 'Deal IDs array is required', array('status' => 400));
		}

		if (! is_array($data) || empty($data)) {
			return new WP_Error('invalid_data', 'Update data is required', array('status' => 400));
		}

		// Validate owner_id if being bulk updated
		if (isset($data['owner_id']) && $data['owner_id'] !== null && $data['owner_id'] !== '') {
			$validation_error = $this->validate_owner_id($data['owner_id']);
			if (is_wp_error($validation_error)) {
				return $validation_error;
			}
		}

		$user_id       = get_current_user_id();
		$updated_count = DealManager::instance()->bulk_update_deals($deal_ids, $data, $user_id);

		return new WP_REST_Response(array('updated_count' => $updated_count), 200);
	}

	/**
	 * Bulk delete deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function bulk_delete($request)
	{
		$deal_ids = $request->get_param('deal_ids');

		if (! is_array($deal_ids) || empty($deal_ids)) {
			return new WP_Error('invalid_data', 'Deal IDs array is required', array('status' => 400));
		}

		$deleted_count = DealManager::instance()->bulk_delete_deals($deal_ids);

		return new WP_REST_Response(array('deleted_count' => $deleted_count), 200);
	}

	/**
	 * Prepare the item for the REST response
	 *
	 * @param Deal            $deal Deal object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	public function prepare_item_for_response($deal, $request)
	{
		$data = array(
			'id'                  => $deal->id,
			'title'               => $deal->title,
			'contact_id'          => $deal->contact_id,
			'pipeline_id'         => $deal->pipeline_id,
			'stage_id'            => $deal->stage_id,
			'value'               => $deal->value,
			'currency'            => $deal->currency,
			'expected_close_date' => $deal->expected_close_date,
			'probability'         => $deal->probability,
			'priority'            => $deal->priority,
			'status'              => $deal->status,
			'owner_id'            => $deal->owner_id,
			'source'              => $deal->source,
			'lost_reason'         => $deal->lost_reason,
			'won_time'            => $deal->won_time,
			'lost_time'           => $deal->lost_time,
			'is_overdue'          => $deal->is_overdue,
			'days_until_close'    => $deal->days_until_close,
			'weighted_value'      => $deal->weighted_value,
			'created_at'          => $deal->created_at,
			'updated_at'          => $deal->updated_at,
		);

		// Include relationships if loaded
		if ($deal->relationLoaded('contact') && $deal->contact) {
			$data['contact'] = array(
				'id'             => $deal->contact->id,
				'first_name'     => $deal->contact->first_name,
				'last_name'      => $deal->contact->last_name,
				'email'          => $deal->contact->email,
				'phone'          => $deal->contact->phone,
				'whatsapp_phone' => $deal->contact->whatsapp_phone,
			);
		}

		if ($deal->relationLoaded('pipeline') && $deal->pipeline) {
			$data['pipeline'] = array(
				'id'   => $deal->pipeline->id,
				'name' => $deal->pipeline->name,
			);
		}

		if ($deal->relationLoaded('stage') && $deal->stage) {
			$data['stage'] = array(
				'id'              => $deal->stage->id,
				'name'            => $deal->stage->name,
				'color'           => $deal->stage->color,
				'win_probability' => $deal->stage->win_probability,
			);
		}

		if ($deal->relationLoaded('owner') && $deal->owner) {
			$data['owner'] = array(
				'id'           => $deal->owner->ID,
				'display_name' => $deal->owner->display_name,
				'email'        => $deal->owner->user_email,
			);
		}

		if ($deal->relationLoaded('activities')) {
			$data['activities'] = array();
			foreach ($deal->activities as $activity) {
				$data['activities'][] = $this->prepare_activity_for_response($activity);
			}
		}

		if ($deal->relationLoaded('custom_fields')) {
			$data['custom_fields'] = array();
			foreach ($deal->custom_fields as $custom_field) {
				$data['custom_fields'][] = $custom_field->toArray();
			}
		}

		$data['linked_project'] = null;
		if (
			function_exists( 'doublescale_is_module_storage_ready' )
			&& doublescale_is_module_storage_ready( 'projects', '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' )
		) {
			try {
				$linked = \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::with( 'status' )
					->where( 'deal_id', (int) $deal->id )
					->first();
				if ( $linked ) {
					$status = null;
					if ( $linked->relationLoaded( 'status' ) && $linked->status ) {
						$status = array(
							'id'    => (int) $linked->status->id,
							'name'  => (string) $linked->status->name,
							'color' => (string) $linked->status->color,
						);
					}
					$data['linked_project'] = array(
						'id'     => (int) $linked->id,
						'title'  => (string) $linked->title,
						'status' => $status,
					);
				}
			} catch ( \Throwable $e ) {
				$data['linked_project'] = null;
			}
		}

		return $data;
	}

	/**
	 * Prepare activity for response
	 *
	 * @param mixed $activity Activity object.
	 *
	 * @return array
	 */
	protected function prepare_activity_for_response($activity)
	{
		$data = array(
			'id'                => $activity->id,
			'deal_id'           => $activity->deal_id,
			'activity_type'     => $activity->activity_type,
			'data'              => $activity->data,
			'user_id'           => $activity->user_id,
			'formatted_message' => $activity->formatted_message,
			'created_at'        => $activity->created_at,
		);

		if ($activity->relationLoaded('user') && $activity->user) {
			$data['user'] = array(
				'id'           => $activity->user->ID,
				'display_name' => $activity->user->display_name,
			);
		}

		if ($activity->relationLoaded('comments')) {
			$data['comments'] = $activity->comments->toArray();
		}

		return $data;
	}



	/**
	 * Validate owner_id parameter
	 *
	 * @param int $owner_id Owner ID to validate.
	 *
	 * @return true|WP_Error True if valid, WP_Error if invalid.
	 */
	private function validate_owner_id($owner_id)
	{
		// Convert to integer
		$owner_id = intval($owner_id);

		// Check for positive integer
		if ($owner_id <= 0) {
			return new WP_Error(
				'invalid_owner_id',
				'Owner ID must be a positive number.',
				array('status' => 400)
			);
		}

		// Check if user exists in WordPress
		$user = get_user_by('id', $owner_id);
		if (! $user) {
			return new WP_Error(
				'user_not_found',
				'The specified owner does not exist.',
				array('status' => 400)
			);
		}

		// Optional: Check if user has appropriate capabilities
		if (! user_can($user, 'read')) {
			return new WP_Error(
				'inactive_user',
				'The specified owner account is inactive.',
				array('status' => 400)
			);
		}

		return true;
	}

	/**
	 * Validate and sanitize date parameter
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $date_value Date value to validate (expects Y-m-d format).
	 *
	 * @return string|null Valid date string in Y-m-d format or null.
	 */
	private function validate_and_sanitize_date($date_value)
	{
		// Handle null or empty - these are valid (field is nullable)
		if ($date_value === null || $date_value === '') {
			return null;
		}

		// Sanitize the input
		$clean_date = sanitize_text_field($date_value);

		// If not already in YYYY-MM-DD format, try to convert it
		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean_date)) {
			// Try to parse the date string (handles formats like "November 22, 2025")
			$timestamp = strtotime($clean_date);

			if ($timestamp === false) {
				return new WP_Error(
					'invalid_date_format',
					sprintf(
						/* translators: %s: the provided date value */
						__('Invalid date format "%s". Expected format: YYYY-MM-DD (e.g., 2025-12-31) or a valid date string.', 'doublescale'),
						$clean_date
					),
					array('status' => 400)
				);
			}

			// Convert to YYYY-MM-DD format
			$clean_date = gmdate('Y-m-d', $timestamp);
		}

		// Validate it's a real date (not 2025-13-45)
		$date_parts = explode('-', $clean_date);
		if (! checkdate((int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0])) {
			return new WP_Error(
				'invalid_date',
				sprintf(
					/* translators: %s: the provided date value */
					__('Invalid date "%s". Please provide a valid date.', 'doublescale'),
					$clean_date
				),
				array('status' => 400)
			);
		}

		return $clean_date;
	}

	/**
	 * Validate and sanitize datetime parameter
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $datetime_value Datetime value to validate (expects Y-m-d H:i:s format).
	 *
	 * @return string|null Valid datetime string in Y-m-d H:i:s format or null.
	 */
	private function validate_and_sanitize_datetime($datetime_value)
	{
		// Handle null or empty - these are valid (field is nullable)
		if ($datetime_value === null || $datetime_value === '') {
			return null;
		}

		// Sanitize the input
		$clean_datetime = sanitize_text_field($datetime_value);

		// Validate format: Y-m-d H:i:s (e.g., 2025-12-31 14:30:00)
		if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $clean_datetime)) {
			return null; // Invalid format
		}

		// Validate it's a real datetime
		$datetime_obj = \DateTime::createFromFormat('Y-m-d H:i:s', $clean_datetime);
		if (! $datetime_obj || $datetime_obj->format('Y-m-d H:i:s') !== $clean_datetime) {
			return null; // Invalid datetime
		}

		return $clean_datetime;
	}

	/**
	 * Check if user can access deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check($request)
	{
		return Permissions::can_read_deals();
	}

	/**
	 * Check if user can access single deal
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_item_permissions_check($request)
	{
		return Permissions::can_read_deals();
	}

	/**
	 * Check if user can create deals
	 *
	 * Sales Rep, Sales Manager, CRM Manager, and Administrator can create deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function create_item_permissions_check($request)
	{
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if user can update deals
	 *
	 * Sales Rep can update their own deals
	 * Sales Manager, CRM Manager, and Administrator can update any deal
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function update_item_permissions_check($request)
	{
		// Sales Manager and above can update any deal
		if (PermissionsCompat::has_sales_manager_access()) {
			return true;
		}

		// Sales Rep can only update their own deals
		if (Permissions::is_sales_rep()) {
			$deal_id = $request->get_param('id');
			$deal    = DealModel::find($deal_id);
			if ($deal && $deal->owner_id == get_current_user_id()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can delete deals
	 *
	 * Sales Manager, CRM Manager, and Administrator can delete deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function delete_item_permissions_check($request)
	{
		return PermissionsCompat::has_sales_manager_access();
	}

	/**
	 * Check if user can move deals to different pipeline
	 *
	 * Sales Manager, CRM Manager, and Administrator can move deals between pipelines
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function update_item_pipeline_permissions_check($request)
	{
		return PermissionsCompat::has_sales_manager_access();
	}

	/**
	 * Check if user can bulk update deals
	 *
	 * Sales Manager, CRM Manager, and Administrator can bulk update deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function update_items_permissions_check($request)
	{
		return PermissionsCompat::has_sales_manager_access();
	}

	/**
	 * Check if user can bulk delete deals
	 *
	 * Sales Manager, CRM Manager, and Administrator can bulk delete deals
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function delete_items_permissions_check($request)
	{
		return PermissionsCompat::has_sales_manager_access();
	}

	/**
	 * List active file attachments for a deal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_attachments( $request ) {
		try {
			$deal = $this->get_deal_for_attachment( $request );
			if ( is_wp_error( $deal ) ) {
				return $deal;
			}

			return new WP_REST_Response(
				array(
					'items' => $this->get_deal_attachments_shaped( (int) $deal->id ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Upload a file attachment to a deal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment( $request ) {
		try {
			$deal = $this->get_deal_for_attachment( $request, true );
			if ( is_wp_error( $deal ) ) {
				return $deal;
			}

			$files = $request->get_file_params();
			$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
			if ( ! $file ) {
				return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$existing_count = AttachmentModel::forType( self::DEAL_ATTACHABLE_TYPE )
				->where( 'attachable_id', (int) $deal->id )
				->active()
				->count();

			$too_many = $this->guard_deal_attachment_count( (int) $existing_count );
			if ( $too_many ) {
				return $too_many;
			}

			$service    = $this->attachment_service();
			$attachment = $service->store_upload(
				$file,
				self::DEAL_ATTACHABLE_TYPE,
				(int) $deal->id,
				array( 'user_id' => get_current_user_id() ),
				array(
					'status'         => 'active',
					'max_size_bytes' => self::DEAL_ATTACHMENT_MAX_BYTES,
					'meta'           => array( 'deal_id' => (int) $deal->id ),
				)
			);

			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_deal_file_attached', $deal, $attachment );

			return new WP_REST_Response(
				$service->shape_for_api( $attachment ),
				201
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a deal file attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_attachment( $request ) {
		try {
			$deal = $this->get_deal_for_attachment( $request, true );
			if ( is_wp_error( $deal ) ) {
				return $deal;
			}

			$attachment = $this->find_deal_attachment( $deal, (int) $request->get_param( 'attachment_id' ) );
			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_deal_file_removed', $deal, $attachment );

			$attachment->delete();

			return new WP_REST_Response(
				array(
					'deleted' => true,
					'id'      => (int) $request->get_param( 'attachment_id' ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Resolve a deal for attachment endpoints with ownership scoping.
	 *
	 * @param WP_REST_Request $request        Request.
	 * @param bool            $require_update Whether update access is required.
	 * @return DealModel|WP_Error
	 */
	private function get_deal_for_attachment( $request, bool $require_update = false ) {
		$deal_id = (int) $request->get_param( 'id' );
		$deal    = DealModel::find( $deal_id );

		if ( ! $deal ) {
			return new WP_Error( 'deal_not_found', 'Deal not found', array( 'status' => 404 ) );
		}

		if ( Permissions::is_sales_rep() && ! PermissionsCompat::has_sales_manager_access() ) {
			if ( (int) $deal->owner_id !== (int) get_current_user_id() ) {
				if ( $require_update ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'Sorry, you are not allowed to edit this deal.', 'doublescale' ),
						array( 'status' => 403 )
					);
				}

				return new WP_Error( 'deal_not_found', 'Deal not found', array( 'status' => 404 ) );
			}
		}

		return $deal;
	}

	/**
	 * Fetch active deal attachments shaped for API responses.
	 *
	 * @param int $deal_id Deal ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_deal_attachments_shaped( int $deal_id ): array {
		$service = $this->attachment_service();
		$rows    = AttachmentModel::forType( self::DEAL_ATTACHABLE_TYPE )
			->where( 'attachable_id', $deal_id )
			->active()
			->orderBy( 'created_at', 'desc' )
			->get();

		$shaped = array();
		foreach ( $rows as $attachment ) {
			$shaped[] = $service->shape_for_api( $attachment );
		}

		return $shaped;
	}

	/**
	 * Resolve a deal-scoped attachment row.
	 *
	 * @param DealModel $deal          Parent deal.
	 * @param int       $attachment_id Attachment row id.
	 * @return AttachmentModel|WP_Error
	 */
	private function find_deal_attachment( $deal, int $attachment_id ) {
		$attachment = AttachmentModel::forType( self::DEAL_ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $deal->id )
			->where( 'id', $attachment_id )
			->first();

		if ( ! $attachment ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $attachment;
	}

	/**
	 * Enforce the per-deal attachment count cap.
	 *
	 * @param int $existing_count Active attachments already on the deal.
	 * @return WP_Error|null
	 */
	private function guard_deal_attachment_count( int $existing_count ): ?WP_Error {
		if ( $existing_count >= self::DEAL_ATTACHMENT_MAX_COUNT ) {
			return new WP_Error(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files allowed per deal */
					_n(
						'You can attach at most %d file to this deal.',
						'You can attach at most %d files to this deal.',
						self::DEAL_ATTACHMENT_MAX_COUNT,
						'doublescale'
					),
					self::DEAL_ATTACHMENT_MAX_COUNT
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * @return AttachmentService
	 */
	private function attachment_service(): AttachmentService {
		return new AttachmentService();
	}
}
