<?php

/**
 * Class RestAutomationStepController
 * This class is responsible for handling the Automation Step REST Api
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
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Services\VersionManager;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Emails\EmailAttachmentResolver;
use DoubleScale\Modules\Emails\EmailRenderer;
use DoubleScale\Modules\Emails\EmailTrackingHelper;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * RestAutomationStepController class
 */
class RestAutomationStepController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'automation-steps';

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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reorder',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'reorder_step' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'direction'     => array(
							'description' => __( 'Direction to move the step (up, down, or move)', 'doublescale' ),
							'type'        => 'string',
							'enum'        => array( 'up', 'down', 'move' ),
							'required'    => false,
						),
						'updated_steps' => array(
							'description' => __( 'Array of steps with updated orders', 'doublescale' ),
							'type'        => 'object',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_step_analytics' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		// Send a test email for a "Send Email" action. Content-based (no step id)
		// so it also works while the step is still unsaved in the builder.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/send-test-email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_test_email' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'emails'     => array(
							'description' => __( 'Array of email addresses to send test emails to', 'doublescale' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'required'    => true,
						),
						'subject'    => array(
							'description' => __( 'Email subject', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
						),
						'body'       => array(
							'description' => __( 'Email body: builder JSON or raw HTML', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
						),
						'from_name'  => array(
							'description' => __( 'From name', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
						),
						'from_email' => array(
							'description' => __( 'From email address', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
						),
						'reply_to'   => array(
							'description' => __( 'Reply-to address', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);

		// Renders builder content straight from the request, so the device
		// preview works for steps that are still unsaved in the builder (the
		// template render endpoint can only read already-saved templates).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preview-email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'preview_email' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'body' => array(
							'description' => __( 'Email body: builder JSON or raw HTML', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/toggle',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'toggle_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'enabled' => array(
							'description' => __( 'Whether the step should run. False disables it without deleting it.', 'doublescale' ),
							'type'        => 'boolean',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Schema for the Automation Step
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'automation-step',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'automation_id' => array(
					'description' => __( 'The ID of the automation this step belongs to.', 'doublescale' ),
					'type'        => 'integer',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'parent_id'     => array(
					'description' => __( 'The ID of the parent step.', 'doublescale' ),
					'type'        => 'integer',
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'action'        => array(
					'description' => __( 'The action of the step.', 'doublescale' ),
					'type'        => 'string',
					'required'    => false,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'type'          => array(
					'description' => __( 'The type of the step.', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'condition'     => array(
					'description' => __( 'The condition of the step.', 'doublescale' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'status'        => array(
					'description' => __( 'The status of the step.', 'doublescale' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'settings'      => array(
					'description' => __( 'The settings of the step.', 'doublescale' ),
					'type'        => 'object',
					'arg_options' => array(
						'validate_callback' => array( $this, 'validate_item_settings' ),
					),
				),
				'order'         => array(
					'description' => __( 'Order of the list.', 'doublescale' ),
					'type'        => 'integer',
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'created_at'    => array(
					'description' => __( 'The date the object was created.', 'doublescale' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'updated_at'    => array(
					'description' => __( 'The date the object was last modified.', 'doublescale' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Validate the create item request
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value The value of the parameter.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param The parameter name.
	 *
	 * @return WP_Error|bool
	 */
	public function validate_item_settings( $value, $request, $param ) {
		try {
			$data        = $request->get_json_params();
			$action_type = $data['type'] ?? '';

			if ( 'action' === $action_type ) {
				$action = ActionsManager::instance()->get_action( $data['action'] );
				if ( empty( $action->get_attributes_schema() ) ) {
					return true;
				}

				$validator = rest_validate_value_from_schema( $data['settings'] ?? array(), $action->get_attributes_schema(), 'settings' );
				if ( is_wp_error( $validator ) ) {
					return $validator;
				}
			}

			return true;
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_validate_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create an Automation Step
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function create_item( $request ) {
		try {
			$step_data     = $this->prepare_step( $request );
			$updated_steps = $request->get_param( 'updated_steps' ) ?? array();

			$automation_step = AutomationStepModel::create( $step_data );

			if ( ! empty( $updated_steps ) ) {
				$this->update_orders( $updated_steps );
			}

			$automation_step = AutomationStepModel::find( $automation_step->id );
			$automation_step = $this->resolve_action_label( $automation_step );

			$this->snapshot_version( $automation_step, __( 'Added step', 'doublescale' ) );

			return new WP_REST_Response( $automation_step, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_create_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get an Automation Step
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		try {
			$automation_step = AutomationStepModel::find( $request->get_param( 'id' ) );

			if ( ! $automation_step ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $automation_step, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_get_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update an Automation Step
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		try {
			doublescale_get_logger()->error(
				'REST: update_item called for automation step',
				array(
					'step_id'   => $request->get_param( 'id' ),
					'step_data' => $request->get_params(),
					'code'      => 'rest_automation_step_update_called',
				)
			);

			$automation_step = AutomationStepModel::find( $request->get_param( 'id' ) );

			if ( ! $automation_step ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$step_data = $this->prepare_step( $request );

			doublescale_get_logger()->error(
				'REST: About to save automation step',
				array(
					'step_id'          => $automation_step->id,
					'prepared_data'    => $step_data,
					'current_settings' => $automation_step->settings,
					'code'             => 'rest_automation_step_before_save',
				)
			);

			$automation_step->fill( $step_data );
			$automation_step->save();

			$automation_step = $this->resolve_action_label( $automation_step );

			$this->snapshot_version( $automation_step, __( 'Edited step', 'doublescale' ) );

			doublescale_get_logger()->error(
				'REST: After save automation step',
				array(
					'step_id'          => $automation_step->id,
					'final_settings'   => $automation_step->settings,
					'has_template_ids' => isset( $automation_step->settings['template_ids'] ),
					'code'             => 'rest_automation_step_after_save',
				)
			);

			return new WP_REST_Response( $automation_step, 200 );
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				'REST: Exception in update_item',
				array(
					'step_id' => $request->get_param( 'id' ),
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
					'code'    => 'rest_automation_step_update_exception',
				)
			);
			return new WP_Error( 'rest_automation_step_update_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete an Automation Step
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_item( $request ) {
		try {
			$automation_step = AutomationStepModel::find( $request->get_param( 'id' ) );
			$updated_steps   = $request->get_param( 'updated_steps' ) ?? array();

			if ( ! $automation_step ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( 'draft' === $automation_step->status ) {
				$automation_step->delete();
			} else {
				$automation_step->status = AutomationStepModel::STATUS_DELETED;
				$automation_step->save();
			}

			if ( ! empty( $updated_steps ) ) {
				$this->update_orders( $updated_steps );
			}

			$this->snapshot_version( $automation_step, __( 'Deleted step', 'doublescale' ) );

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_delete_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Enable or disable an Automation Step.
	 *
	 * A disabled step stays in the workflow and keeps its settings, but the
	 * engine skips it, so contacts flow straight through to the next active
	 * step. Trigger and end_automation steps cannot be toggled.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_item( $request ) {
		try {
			$automation_step = AutomationStepModel::find( $request->get_param( 'id' ) );

			if ( ! $automation_step ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( in_array( $automation_step->type, array( 'trigger', 'end_automation' ), true ) ) {
				return new WP_Error(
					'rest_automation_step_not_toggleable',
					__( 'This step cannot be enabled or disabled.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			if ( AutomationStepModel::STATUS_DELETED === $automation_step->status ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$enabled = (bool) $request->get_param( 'enabled' );

			$automation_step->status = $enabled
				? AutomationStepModel::STATUS_ACTIVE
				: AutomationStepModel::STATUS_DISABLED;
			$automation_step->save();

			$this->snapshot_version(
				$automation_step,
				$enabled ? __( 'Enabled step', 'doublescale' ) : __( 'Disabled step', 'doublescale' )
			);

			return new WP_REST_Response( $automation_step, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_toggle_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Reorder an Automation Step
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function reorder_step( $request ) {
		try {
			$step_id       = $request->get_param( 'id' );
			$direction     = $request->get_param( 'direction' );
			$updated_steps = $request->get_param( 'updated_steps' ) ?? array();

			$automation_step = AutomationStepModel::find( $step_id );

			if ( ! $automation_step ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Validate direction
			if ( $direction && ! in_array( $direction, array( 'up', 'down', 'move' ), true ) ) {
				return new WP_Error( 'rest_automation_step_invalid_direction', __( 'Invalid direction. Must be "up", "down", or "move"', 'doublescale' ), array( 'status' => 400 ) );
			}

			// Update the orders based on the frontend calculations
			if ( ! empty( $updated_steps ) ) {
				$this->update_orders( $updated_steps );
			}

			// Return the updated step
			$automation_step = AutomationStepModel::find( $step_id );

			$this->snapshot_version( $automation_step, __( 'Reordered steps', 'doublescale' ) );

			return new WP_REST_Response( $automation_step, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_reorder_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Duplicate an Automation Step (and its children for condition steps).
	 *
	 * The copy is inserted immediately after the original within the same
	 * context (same parent and condition). Trigger and end_automation steps
	 * cannot be duplicated.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_item( $request ) {
		try {
			$source = AutomationStepModel::find( $request->get_param( 'id' ) );

			if ( ! $source ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( in_array( $source->type, array( 'trigger', 'end_automation' ), true ) ) {
				return new WP_Error( 'rest_automation_step_not_duplicatable', __( 'This step cannot be duplicated.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$insert_order = (int) $source->order + 1;

			// Shift down siblings in the same context to make room for the copy.
			$this->shift_context_orders( $source, $insert_order );

			// Collect the source step and all of its descendants (for conditions).
			$subtree = $this->collect_subtree( $source );

			$id_map      = array();
			$new_step_id = null;

			foreach ( $subtree as $step ) {
				$is_root  = ( (int) $step->id === (int) $source->id );
				$settings = is_array( $step->settings ) ? $step->settings : array();
				$settings = $this->prepare_settings_for_duplicate( $settings, (string) $step->action );

				$new_step = AutomationStepModel::create(
					array(
						'automation_id' => $step->automation_id,
						'parent_id'     => $is_root
							? (int) $step->parent_id
							: ( $id_map[ (int) $step->parent_id ] ?? 0 ),
						'action'        => $step->action,
						'type'          => $step->type,
						'condition'     => $step->condition,
						'status'        => 'active',
						'settings'      => $settings,
						'order'         => $is_root ? $insert_order : (int) $step->order,
					)
				);

				$id_map[ (int) $step->id ] = (int) $new_step->id;

				if ( $is_root ) {
					$new_step_id = (int) $new_step->id;
				}
			}

			// Remap any step-ID references that live inside settings.
			$created_ids = array_values( $id_map );
			$new_steps   = AutomationStepModel::whereIn( 'id', $created_ids )->get();
			foreach ( $new_steps as $new_step ) {
				$settings = is_array( $new_step->settings ) ? $new_step->settings : array();
				$updated  = $this->remap_step_ids_in_settings( $settings, $id_map );
				if ( $updated !== $settings ) {
					$new_step->settings = $updated;
					$new_step->save();
				}
			}

			$this->snapshot_version( $source, __( 'Duplicated step', 'doublescale' ) );

			// Return the freshly created steps with resolved labels.
			$created = AutomationStepModel::whereIn( 'id', $created_ids )->get();
			$result  = array();
			foreach ( $created as $step ) {
				$result[] = $this->resolve_action_label( $step );
			}

			return new WP_REST_Response(
				array(
					'new_step_id' => $new_step_id,
					'steps'       => $result,
				),
				201
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_duplicate_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Shift the order of sibling steps to make room for an inserted step.
	 *
	 * Steps in the same context (same parent and condition) whose order is
	 * greater than or equal to the insertion order are pushed down by one.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationStepModel $reference   The reference step providing the context.
	 * @param int                 $insert_order The order the new step will occupy.
	 *
	 * @return void
	 */
	private function shift_context_orders( $reference, $insert_order ) {
		$parent_id = (int) $reference->parent_id;

		$query = AutomationStepModel::where( 'automation_id', $reference->automation_id )
			->where( 'parent_id', $parent_id )
			->where( 'status', '!=', AutomationStepModel::STATUS_DELETED )
			->where( 'order', '>=', $insert_order );

		if ( $parent_id > 0 ) {
			$query->where( 'condition', $reference->condition );
		}

		$siblings = $query->get();
		foreach ( $siblings as $sibling ) {
			$sibling->order = (int) $sibling->order + 1;
			$sibling->save();
		}
	}

	/**
	 * Collect a step and all of its descendants (depth-first, parents first).
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationStepModel $step The root step.
	 *
	 * @return AutomationStepModel[]
	 */
	private function collect_subtree( $step ) {
		$collected = array( $step );

		if ( 'condition' === $step->type ) {
			$children = AutomationStepModel::where( 'parent_id', $step->id )
				->whereIn( 'status', AutomationStepModel::EDITABLE_STATUSES )
				->orderBy( 'order' )
				->get();

			foreach ( $children as $child ) {
				$collected = array_merge( $collected, $this->collect_subtree( $child ) );
			}
		}

		return $collected;
	}

	/**
	 * Prepare step settings for duplication.
	 *
	 * Strips runtime-only keys and, for email/SMS actions, inlines the template
	 * body/subject and removes template_ids so the model creates a fresh template
	 * instead of sharing the original.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $settings Step settings.
	 * @param string $action   Step action slug.
	 *
	 * @return array
	 */
	private function prepare_settings_for_duplicate( array $settings, $action ) {
		foreach (
			array(
				'_action_label',
				'_action_warning',
				'_action_warning_message',
				'_goal_label',
				'_goal_warning',
				'_goal_warning_message',
				'_condition_warning',
			) as $runtime_key
		) {
			unset( $settings[ $runtime_key ] );
		}

		$channel_map = array(
			'send_email'    => 'email',
			'send_sms'      => 'sms',
			'send_whatsapp' => 'whatsapp',
		);

		// WhatsApp templates are pre-approved by Meta, so keep the link as-is.
		if ( ! isset( $channel_map[ $action ] ) || 'whatsapp' === $channel_map[ $action ] ) {
			return $settings;
		}

		if ( empty( $settings['template_ids'] ) || ! is_array( $settings['template_ids'] ) ) {
			return $settings;
		}

		$template_id = (int) reset( $settings['template_ids'] );
		$template    = \DoubleScale\Modules\Tracking\Models\TrackingTemplateModel::find( $template_id );
		if ( $template ) {
			if ( 'email' === $channel_map[ $action ] ) {
				$settings['subject'] = $settings['subject'] ?? $template->settings['subject'] ?? $template->name ?? '';
				$settings['body']    = $settings['body'] ?? $template->body ?? '';
			} else {
				$settings['body'] = $settings['body'] ?? $template->body ?? '';
			}
		}

		unset( $settings['template_ids'] );

		return $settings;
	}

	/**
	 * Remap step ID references inside duplicated step settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Step settings.
	 * @param array $id_map   Map of old step ID => new step ID.
	 *
	 * @return array
	 */
	private function remap_step_ids_in_settings( array $settings, array $id_map ) {
		foreach ( $settings as $key => $value ) {
			$settings[ $key ] = $this->remap_step_ids_in_value( $value, $id_map );
		}

		return $settings;
	}

	/**
	 * Recursively replace dynamic step ID references in a value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value  Setting value.
	 * @param array $id_map Map of old step ID => new step ID.
	 *
	 * @return mixed
	 */
	private function remap_step_ids_in_value( $value, array $id_map ) {
		if ( is_string( $value ) ) {
			foreach ( $id_map as $old_id => $new_id ) {
				$value = str_replace( 'dynamic_id_' . $old_id, 'dynamic_id_' . $new_id, $value );
			}

			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->remap_step_ids_in_value( $item, $id_map );
			}
		}

		return $value;
	}

	/**
	 * Update orders of the steps
	 *
	 * @since 1.0.0
	 *
	 * @param array $steps Steps.
	 *
	 * @return void
	 */
	private function update_orders( $steps ) {
		foreach ( $steps as $step_id => $step_data ) {
			$step = AutomationStepModel::find( $step_id );
			if ( ! $step ) {
				continue;
			}

			if ( isset( $step_data['order'] ) ) {
				$step->order = (int) $step_data['order'];
			}

			if ( isset( $step_data['parent_id'] ) ) {
				$step->parent_id = (int) $step_data['parent_id'];
			}

			if ( array_key_exists( 'condition', $step_data ) ) {
				$step->condition = (string) $step_data['condition'];
			}

			$step->save();
		}
	}

	/**
	 * Capture a version snapshot of the parent automation for undo / redo.
	 *
	 * No-op while a restore is in progress (the VersionManager guards against
	 * recursion). Failures are swallowed by the manager so they never break the
	 * step mutation that triggered them.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationStepModel|null $step  The mutated step.
	 * @param string                   $label Short description of the change.
	 *
	 * @return void
	 */
	private function snapshot_version( $step, $label ) {
		if ( ! $step || empty( $step->automation_id ) ) {
			return;
		}
		VersionManager::instance()->snapshot( $step->automation_id, $label );
	}

	/**
	 * Resolve the action label for a step and inject it into settings.
	 *
	 * @param AutomationStepModel $step The step model.
	 *
	 * @return AutomationStepModel
	 */
	private function resolve_action_label( $step ) {
		if ( ! empty( $step->action ) && ( 'action' === $step->type || 'delay' === $step->type || 'goal' === $step->type ) ) {
			try {
				$action                    = ActionsManager::instance()->get_action( $step->action );
				$settings                  = $step->settings ?: array();
				$settings['_action_label'] = $action->name;
				unset( $settings['_action_warning'] );
				unset( $settings['_action_warning_message'] );
				$step->settings = $settings;
				$step->save();
			} catch ( \Exception $e ) {
				// Action not registered — keep slug as label
			}
		}
		return $step;
	}

	/**
	 * Prepare the step data
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return array
	 */
	private function prepare_step( $request ) {
		$step_data = array(
			'automation_id' => $request->get_param( 'automation_id' ),
			'parent_id'     => $request->get_param( 'parent_id' ),
			'action'        => $request->get_param( 'action' ),
			'type'          => $request->get_param( 'type' ),
			'condition'     => $request->get_param( 'condition' ),
			'status'        => $request->get_param( 'status' ),
			'settings'      => $request->get_param( 'settings' ),
			'order'         => $request->get_param( 'order' ),
		);

		// Remove empty values but preserve 0 values for numeric fields
		foreach ( $step_data as $key => $value ) {
			if ( is_null( $value ) || ( '' === $value ) ) {
				unset( $step_data[ $key ] );
			}
		}

		// Ensure order is always a valid positive integer
		if ( isset( $step_data['order'] ) ) {
			$step_data['order'] = max( 1, intval( $step_data['order'] ) );
		} else {
			$step_data['order'] = 1; // Default order if not provided
		}

		return $step_data;
	}

	/**
	 * Send a test email for a "Send Email" automation action.
	 *
	 * Mirrors the campaign builder's test send, but takes the content from the
	 * request instead of a saved template: an automation step's body lives in
	 * the builder's local state and may not be persisted yet.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */

	/**
	 * Render builder content to email HTML for the device preview.
	 *
	 * Takes the body from the request for the same reason send_test_email()
	 * does: the step's content may still be unsaved. Rendering goes through the
	 * same EmailRenderer the real send uses, so the preview shows the actual
	 * email markup — including the responsive CSS — rather than an approximation.
	 *
	 * No contact is passed, so merge tags stay unresolved; this is a layout
	 * preview, not a per-recipient one.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_email( $request ) {
		try {
			$body = $request->get_param( 'body' );

			if ( empty( $body ) ) {
				return new WP_Error(
					'missing_body',
					__( 'Email body is empty. Add content in the builder before previewing.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// The body arrives either as builder JSON ({"type":"builder","value":{...}})
			// or as raw HTML. Only the former needs rendering.
			$builder_data = null;
			$decoded      = is_array( $body ) ? $body : json_decode( (string) $body, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['type'] ) && 'builder' === $decoded['type'] && isset( $decoded['value'] ) ) {
					$builder_data = $decoded['value'];
				} elseif ( isset( $decoded['sections'] ) ) {
					$builder_data = $decoded;
				}
			}

			if ( null !== $builder_data ) {
				$renderer = new EmailRenderer();
				$html     = $renderer->render_from_builder_data( $builder_data );
			} else {
				// Raw HTML body — preview it as-is.
				$html = $body;
			}

			return new WP_REST_Response( array( 'html' => $html ), 200 );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'preview_failed',
				__( 'Failed to render the preview.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Send a test email for an automation step.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_email( $request ) {
		try {
			$emails = $request->get_param( 'emails' );

			if ( empty( $emails ) || ! is_array( $emails ) ) {
				return new WP_Error( 'invalid_emails', __( 'Please provide an array of email addresses', 'doublescale' ), array( 'status' => 400 ) );
			}

			$invalid_emails = array();
			foreach ( $emails as $email ) {
				if ( ! is_email( $email ) ) {
					$invalid_emails[] = $email;
				}
			}

			if ( ! empty( $invalid_emails ) ) {
				return new WP_Error(
					'invalid_emails',
					sprintf(
						/* translators: %s: comma-separated list of invalid email addresses */
						__( 'Invalid email address(es): %s', 'doublescale' ),
						implode( ', ', $invalid_emails )
					),
					array( 'status' => 400 )
				);
			}

			$body = $request->get_param( 'body' );

			if ( empty( $body ) ) {
				return new WP_Error( 'missing_body', __( 'Email body is empty. Add content in the builder before sending a test.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$subject    = $request->get_param( 'subject' );
			$subject    = ! empty( $subject ) ? $subject : __( 'Test Email', 'doublescale' );
			$from_name  = $request->get_param( 'from_name' ) ?: get_option( 'blogname' );
			$from_email = $request->get_param( 'from_email' ) ?: get_option( 'admin_email' );
			$reply_to   = $request->get_param( 'reply_to' );

			// The body arrives either as builder JSON ({"type":"builder","value":{...}})
			// or as raw HTML. Only the former needs rendering. REST may already
			// have decoded a JSON object into an array.
			$builder_data = null;
			$decoded      = is_array( $body ) ? $body : json_decode( (string) $body, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['type'] ) && 'builder' === $decoded['type'] && isset( $decoded['value'] ) ) {
					$builder_data = $decoded['value'];
				} elseif ( isset( $decoded['sections'] ) ) {
					$builder_data = $decoded;
				}
			}

			$sent_count    = 0;
			$failed_count  = 0;
			$failed_emails = array();
			$last_detail   = '';

			$attachment_paths = array();
			if ( null !== $builder_data && ! empty( $builder_data['attachments'] ) ) {
				$attachment_paths = EmailAttachmentResolver::resolve_paths( $builder_data['attachments'] );
			} elseif ( $request->get_param( 'attachments' ) ) {
				$attachment_paths = EmailAttachmentResolver::resolve_paths(
					(array) $request->get_param( 'attachments' )
				);
			}

			foreach ( $emails as $recipient_email ) {
				$email_sender               = new Emails();
				$email_sender->from_address = $from_email;
				$email_sender->from_name    = $from_name;
				if ( ! empty( $reply_to ) && is_email( $reply_to ) ) {
					$email_sender->reply_to = $reply_to;
				}

				// Merge tags resolve against the recipient when they are a known contact.
				$contact = ContactModel::get_by_email( $recipient_email ) ?? null;

				if ( null !== $builder_data ) {
					$renderer     = new EmailRenderer();
					$body_content = $renderer->render_from_builder_data( $builder_data, $contact );
				} else {
					$body_content = MergeTagsManager::instance()->process_merge_tags( $body, $contact );
				}

				if ( empty( $body_content ) ) {
					++$failed_count;
					$failed_emails[] = $recipient_email;
					continue;
				}

				$processed_subject = MergeTagsManager::instance()->process_merge_tags( $subject, $contact );
				$body_content      = EmailTrackingHelper::prepare_test_email_body( $body_content, $recipient_email, $contact );

				if ( $email_sender->send( $recipient_email, $processed_subject, $body_content, $attachment_paths ) ) {
					++$sent_count;
				} else {
					++$failed_count;
					$failed_emails[] = $recipient_email;
					$detail          = Emails::get_last_send_failure_detail();
					if ( '' !== $detail ) {
						$last_detail = $detail;
					}
				}
			}

			if ( $sent_count > 0 && 0 === $failed_count ) {
				return new WP_REST_Response(
					array(
						'success'    => true,
						'message'    => sprintf(
							/* translators: %d: number of emails sent */
							_n(
								'Test email sent successfully to %d recipient',
								'Test emails sent successfully to %d recipients',
								$sent_count,
								'doublescale'
							),
							$sent_count
						),
						'sent_count' => $sent_count,
					),
					200
				);
			}

			if ( $sent_count > 0 ) {
				return new WP_REST_Response(
					array(
						'success'       => true,
						'message'       => sprintf(
							/* translators: 1: number of successful sends, 2: number of failures */
							__( 'Test emails sent: %1$d succeeded, %2$d failed', 'doublescale' ),
							$sent_count,
							$failed_count
						),
						'sent_count'    => $sent_count,
						'failed_count'  => $failed_count,
						'failed_emails' => $failed_emails,
					),
					200
				);
			}

			$message = __( 'Failed to send test email', 'doublescale' );
			if ( '' !== $last_detail ) {
				$message .= ' ' . $last_detail;
			}

			return new WP_Error( 'send_failed', $message, array( 'status' => 500 ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get analytics for an Automation Step
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_step_analytics( $request ) {
		try {
			$step_id = $request->get_param( 'id' );

			// Verify step exists.
			$automation_step = AutomationStepModel::find( $step_id );

			if ( ! $automation_step ) {
				return new WP_Error( 'rest_automation_step_not_found', __( 'Automation Step not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Validate step supports analytics.
			$analytics_actions = array( 'send_email', 'send_sms', 'send_whatsapp' );
			if ( ! in_array( $automation_step->action, $analytics_actions, true ) ) {
				return new WP_Error( 'rest_automation_step_invalid_action', __( 'This step does not support analytics', 'doublescale' ), array( 'status' => 400 ) );
			}

			// Get all analytics in a single optimized query with JOIN to contacts table.
			global $wpdb;
			$contacts_table = $wpdb->prefix . 'doublescale_contacts';
			$tracking_table = $wpdb->prefix . 'doublescale_communication_tracking';

			// Determine the correct status column based on action type.
			$status_column_map = array(
				'send_email'    => 'email_status',
				'send_sms'      => 'sms_status',
				'send_whatsapp' => 'whatsapp_status',
			);
			$status_column     = $status_column_map[ $automation_step->action ] ?? 'email_status';

			$analytics_raw = CommunicationTrackingModel::byStep( $step_id )
				->leftJoin( $contacts_table . ' as contacts', $tracking_table . '.contact_id', '=', 'contacts.id' )
				->selectRaw(
					"
					COUNT(*) as total,
					SUM(CASE WHEN {$tracking_table}.status = ? THEN 1 ELSE 0 END) as total_sent,
					SUM(CASE WHEN {$tracking_table}.opened = 1 AND {$tracking_table}.status = ? THEN 1 ELSE 0 END) as opened_count,
					SUM(CASE WHEN {$tracking_table}.clicked = 1 AND {$tracking_table}.status = ? THEN 1 ELSE 0 END) as clicked_count,
					SUM(CASE WHEN {$tracking_table}.status = ? THEN 1 ELSE 0 END) as delivered_count,
					SUM(CASE WHEN {$tracking_table}.status = ? THEN 1 ELSE 0 END) as read_count,
					SUM(CASE WHEN contacts.{$status_column} = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed_count
				",
					array( TrackingStatus::SENT, TrackingStatus::SENT, TrackingStatus::SENT, TrackingStatus::DELIVERED, TrackingStatus::READ )
				)
				->first();

			// Extract metrics.
			$total_sent   = (int) $analytics_raw->total_sent;
			$opened       = (int) $analytics_raw->opened_count;
			$clicked      = (int) $analytics_raw->clicked_count;
			$delivered    = (int) $analytics_raw->delivered_count;
			$read         = (int) $analytics_raw->read_count;
			$unsubscribed = (int) $analytics_raw->unsubscribed_count;

			// If no messages sent, return zero analytics.
			if ( 0 === $total_sent ) {
				return new WP_REST_Response(
					array(
						'sent'             => 0,
						'opened'           => 0,
						'clicked'          => 0,
						'delivered'        => 0,
						'read'             => 0,
						'unsubscribed'     => 0,
						'openRate'         => 0,
						'clickRate'        => 0,
						'deliveryRate'     => 0,
						'readRate'         => 0,
						'unsubscribedRate' => 0,
					),
					200
				);
			}

			// Calculate rates as percentages.
			$open_rate         = $total_sent > 0 ? round( ( $opened / $total_sent ) * 100, 2 ) : 0;
			$click_rate        = $total_sent > 0 ? round( ( $clicked / $total_sent ) * 100, 2 ) : 0;
			$delivery_rate     = $total_sent > 0 ? round( ( $delivered / $total_sent ) * 100, 2 ) : 0;
			$read_rate         = $total_sent > 0 ? round( ( $read / $total_sent ) * 100, 2 ) : 0;
			$unsubscribed_rate = $total_sent > 0 ? round( ( $unsubscribed / $total_sent ) * 100, 2 ) : 0;

			$analytics = array(
				'sent'             => $total_sent,
				'opened'           => $opened,
				'clicked'          => $clicked,
				'delivered'        => $delivered,
				'read'             => $read,
				'unsubscribed'     => $unsubscribed,
				'openRate'         => $open_rate,
				'clickRate'        => $click_rate,
				'deliveryRate'     => $delivery_rate,
				'readRate'         => $read_rate,
				'unsubscribedRate' => $unsubscribed_rate,
			);

			return new WP_REST_Response( $analytics, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'rest_automation_step_analytics_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Check if a given request has access to create an item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to get an item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to update an item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete an item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
