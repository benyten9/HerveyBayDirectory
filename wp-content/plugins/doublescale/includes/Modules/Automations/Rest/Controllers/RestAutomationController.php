<?php

/**
 * Class RestAutomationController
 * This class is responsible for handling the Automation REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\TriggersManager;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Automations\Services\GoalsManager;
use DoubleScale\Modules\Automations\Services\VersionManager;
use DoubleScale\Modules\Automations\Services\WorkflowPortabilityManager;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Tracking\Models\TrackingTemplateModel;

/**
 * RestAutomationController class
 */
class RestAutomationController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'automations';

	/**
	 * Register routes
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
							'description' => __( 'The IDs of the items to delete.', 'doublescale' ),
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
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Get the steps of an automation.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/steps',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_steps' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Version history (undo / redo) for an automation.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/versions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_versions' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/undo',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'undo' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/redo',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'redo' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/restore/(?P<version>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'restore_version' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
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

		// Export a single workflow as a portable JSON envelope.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export_workflow' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Import a workflow from a previously exported envelope.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_workflow' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		// Get the contacts of an automation.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/contacts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_contacts' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'per_page' => array(
							'description'       => __( 'Maximum number of items to be returned in result set.', 'doublescale' ),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'description'       => __( 'Current page of the collection.', 'doublescale' ),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'keyword'  => array(
							'description'       => __( 'Search keyword.', 'doublescale' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Get the triggers.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/triggers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_triggers' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Get the actions.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/actions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_actions' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Get the merge tags.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/merge-tags',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_merge_tags' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Get the rules.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/rules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rules' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Get the goals.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/goals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_goals' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// check if you have any condtions is related with this trigger
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check-conditions',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'check_conditions' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'automation_id' => array(
							'description' => __( 'The automation ID.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'is_check'      => array(
							'description' => __( 'Whether to check conditions.', 'doublescale' ),
							'type'        => 'boolean',
							'default'     => true,
						),
						'is_delete'     => array(
							'description' => __( 'Whether to delete conditions.', 'doublescale' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Collection params
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description'       => __( 'Search keyword.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'doublescale' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'doublescale' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
			'from'     => array(
				'description' => __( 'Start date for filtering automations.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'to'       => array(
				'description' => __( 'End date for filtering automations.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
		);
	}

	/**
	 * Schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'automation',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'       => array(
					'description' => __( 'The name of the automation.', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'trigger'    => array(
					'description' => __( 'The trigger of the automation.', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'status'     => array(
					'description' => __( 'The status of the automation.', 'doublescale' ),
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive' ),
					'default'     => 'active',
				),
				'settings'   => array(
					'description' => __( 'The settings of the automation.', 'doublescale' ),
					'type'        => 'object',
				),
				'created_at' => array(
					'description' => __( 'The date the automation was created.', 'doublescale' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'updated_at' => array(
					'description' => __( 'The date the automation was last updated.', 'doublescale' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get goals
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_goals( $request ) {
		$goals = GoalsManager::instance()->get_sources();

		return new WP_REST_Response( $goals, 200 );
	}

	/**
	 * Get rules
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_rules( $request ) {
		$trigger    = $request->get_param( 'trigger' );
		$form_id    = $request->get_param( 'form_id' );
		$trigger_id = $request->get_param( 'trigger_id' );
		$post_id    = $request->get_param( 'post_id' );

		$forms = class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' )
			? \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms()
			: array();

		// If we have a specific form_id and trigger_id, register field rules for that form only
		if ( ! empty( $form_id ) && ! empty( $trigger_id ) && in_array( $trigger_id, array_keys( $forms ) ) ) {
			$form_instance = $forms[ $trigger_id ];
			if ( $form_instance && method_exists( $form_instance, 'register_field_rules_for_form' ) ) {
				// For Elementor forms, set the post_id property before getting fields
				if ( $trigger_id === 'elementor' && ! empty( $post_id ) ) {
					$form_instance->post_id = $post_id;
				}
				$form_instance->register_field_rules_for_form( $form_id );
			}
		}

		if ( class_exists( 'DoubleScale\Modules\Automations\Services\RulesManager' ) ) {
			$rules = RulesManager::instance()->get_groups();
		} else {
			$rules = array();
		}

		return new WP_REST_Response( $rules, 200 );
	}

	/**
	 * Get triggers
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_triggers( $request ) {
		TriggersManager::instance()->sync_form_trigger_sources();
		$triggers = TriggersManager::instance()->get_sources();

		return new WP_REST_Response( $triggers, 200 );
	}

	/**
	 * Get actions
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_actions( $request ) {
		$actions = ActionsManager::instance()->get_sources();

		return new WP_REST_Response( $actions, 200 );
	}

	/**
	 * Get merge tags
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_merge_tags( $request ) {
		$form_id    = $request->get_param( 'form_id' );
		$trigger_id = $request->get_param( 'trigger_id' );
		$post_id    = $request->get_param( 'post_id' );

		$forms = class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' )
			? \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms()
			: array();

		// If we have a specific form_id and trigger_id, register merge tags for that form only
		if ( ! empty( $form_id ) && ! empty( $trigger_id ) && in_array( $trigger_id, array_keys( $forms ) ) ) {
			$form_instance = $forms[ $trigger_id ];
			if ( $form_instance ) {
				// For Elementor forms, set the post_id property before getting fields
				if ( $trigger_id === 'elementor' && ! empty( $post_id ) ) {
					$form_instance->post_id = $post_id;
				}
				$form_instance->register_merge_tags_for_form( $form_id );
			}
		}

		$merge_tags = MergeTagsManager::instance()->get_groups();

		return new WP_REST_Response( $merge_tags, 200 );
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

			$query       = AutomationModel::query();
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
			$automations = $query->orderBy( 'created_at', 'desc' )->paginate( $per_page, array( '*' ), 'page', $page );

			// Check dependencies for each automation
			foreach ( $automations as $automation ) {
				$automation = $this->check_and_mark_dependencies( $automation, 'get_items' );
			}

			return new WP_REST_Response( $automations->toArray() + array( 'total_count' => $total_count ), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get steps
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_steps( $request ) {
		try {
			$id    = $request->get_param( 'id' );
			$steps = AutomationStepModel::where( 'automation_id', $id )->get();

			if ( ! $steps ) {
				return new WP_Error( 'not_found', __( 'Steps not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$steps = $steps->toArray();

			return new WP_REST_Response( $steps, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get contacts
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_contacts( $request ) {
		try {
			$id       = $request->get_param( 'id' );
			$per_page = $request->get_param( 'per_page' ) ?? 10;
			$page     = $request->get_param( 'page' ) ?? 1;
			$keyword  = $request->get_param( 'keyword' ) ?? '';

			$query = AutomationContactModel::where( 'automation_id', $id );

			// Apply keyword search if provided
			if ( ! empty( $keyword ) ) {
				$query->whereHas(
					'contact',
					function ( $q ) use ( $keyword ) {
						$q->where( 'email', 'LIKE', '%' . $keyword . '%' );
					}
				);
			}

			$automation_contacts = $query->with( 'contact', 'processes.step', 'current_step', 'next_step' )
				->orderBy( 'created_at', 'desc' )
				->paginate( $per_page, array( '*' ), 'page', $page );

			return new WP_REST_Response( $automation_contacts, 200 );
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
			$id         = $request->get_param( 'id' );
			$automation = AutomationModel::with(
				array(
					'steps' => function ( $query ) {
						$query->where( 'status', 'active' );
					},
				)
			)->find( $id );

			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Capture a baseline version (the state the editor opens in) so the
			// very first edit can be undone. No-op once any version exists, and
			// guaranteed before any step mutation regardless of frontend timing.
			VersionManager::instance()->ensure_baseline( $id );

			// Check plugin dependencies and add warnings
			$automation = $this->check_and_mark_dependencies( $automation );

			return new WP_REST_Response( $automation, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get the version history (undo / redo state) for an automation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_versions( $request ) {
		try {
			$id         = $request->get_param( 'id' );
			$automation = AutomationModel::find( $id );

			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$manager = VersionManager::instance();
			$manager->ensure_baseline( $id );

			return new WP_REST_Response( $manager->get_history( $id ), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Undo the last change to an automation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function undo( $request ) {
		return $this->step_history( $request, 'undo' );
	}

	/**
	 * Redo the next change to an automation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function redo( $request ) {
		return $this->step_history( $request, 'redo' );
	}

	/**
	 * Restore an automation to a specific version.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function restore_version( $request ) {
		try {
			$id      = $request->get_param( 'id' );
			$version = $request->get_param( 'version' );

			$automation = AutomationModel::find( $id );
			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( ! VersionManager::instance()->restore( $id, $version ) ) {
				return new WP_Error( 'restore_failed', __( 'Could not restore this version.', 'doublescale' ), array( 'status' => 400 ) );
			}

			return $this->history_response( $id );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Gate workflow export / import behind the Pro add-on.
	 *
	 * Returns a WP_Error when Pro is not active so the routes still exist (and the
	 * API surface stays stable) but cannot be used on Free installs.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error|null WP_Error if Pro is inactive, null otherwise.
	 */
	private function require_pro_for_portability() {
		if ( function_exists( 'doublescale_is_pro_addon_active' ) && doublescale_is_pro_addon_active() ) {
			return null;
		}

		return new WP_Error(
			'pro_required',
			__( 'Importing and exporting workflows requires DoubleScale Pro to be installed and activated.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Export a single workflow as a portable JSON envelope.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_workflow( $request ) {
		try {
			$pro_guard = $this->require_pro_for_portability();
			if ( is_wp_error( $pro_guard ) ) {
				return $pro_guard;
			}

			$id     = (int) $request->get_param( 'id' );
			$result = WorkflowPortabilityManager::instance()->export( $id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new WP_REST_Response( $result, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Import a workflow from a previously exported envelope.
	 *
	 * The new automation is created inactive; any references that could not be
	 * resolved on this site (missing trigger / action plugins, etc.) are returned
	 * as `unresolved` so the client can surface them for review.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_workflow( $request ) {
		try {
			$pro_guard = $this->require_pro_for_portability();
			if ( is_wp_error( $pro_guard ) ) {
				return $pro_guard;
			}

			$payload = $request->get_json_params();
			$result  = WorkflowPortabilityManager::instance()->import( $payload );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Reload with active steps and compute dependency warnings using the
			// same logic the editor uses, so the client can flag what needs review.
			$automation = AutomationModel::with(
				array(
					'steps' => function ( $query ) {
						$query->where( 'status', 'active' );
					},
				)
			)->find( $result->id );

			$unresolved = array();
			if ( $automation ) {
				$automation = $this->check_and_mark_dependencies( $automation );
				$unresolved = $automation->_warnings ?? array();
			}

			return new WP_REST_Response(
				array(
					'id'         => (int) $result->id,
					'name'       => $result->name,
					'unresolved' => $unresolved,
				),
				201
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Shared undo / redo handler.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request   Request object.
	 * @param string          $direction 'undo' or 'redo'.
	 *
	 * @return WP_REST_Response
	 */
	private function step_history( $request, $direction ) {
		try {
			$id         = $request->get_param( 'id' );
			$automation = AutomationModel::find( $id );

			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$manager = VersionManager::instance();
			$manager->ensure_baseline( $id );

			$moved = 'undo' === $direction ? $manager->undo( $id ) : $manager->redo( $id );

			if ( ! $moved ) {
				$message = 'undo' === $direction
					? __( 'Nothing to undo.', 'doublescale' )
					: __( 'Nothing to redo.', 'doublescale' );
				return new WP_Error( 'history_unavailable', $message, array( 'status' => 400 ) );
			}

			return $this->history_response( $id );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Build the response returned after a successful undo / redo / restore:
	 * the refreshed automation (with active steps loaded) plus undo / redo flags,
	 * so the client can re-render and update its buttons in one round-trip.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Automation ID.
	 *
	 * @return WP_REST_Response
	 */
	private function history_response( $id ) {
		$automation = AutomationModel::with(
			array(
				'steps' => function ( $query ) {
					$query->where( 'status', 'active' );
				},
			)
		)->find( $id );

		$automation = $this->check_and_mark_dependencies( $automation );

		$history                = VersionManager::instance()->get_history( $id );
		$data                   = $automation->toArray();
		$data['version_cursor'] = $history['cursor'];
		$data['can_undo']       = $history['can_undo'];
		$data['can_redo']       = $history['can_redo'];

		return new WP_REST_Response( $data, 200 );
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
			$automation_data               = $this->prepare_automation( $request );
			$automation_data['created_by'] = get_current_user_id() ?: null;
			$automation                    = AutomationModel::create( $automation_data );

			if ( ! $automation ) {
				return new WP_Error( 'error', __( 'Failed to create automation.', 'doublescale' ), array( 'status' => 500 ) );
			}

			$is_form = false;
			$trigger = TriggersManager::instance()->get_trigger( $automation->trigger );
			if ( empty( $trigger ) ) {
				if ( ! class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' ) ) {
					$automation->delete();
					throw new \Exception( 'Trigger not found.' );
				}
				$form = \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_form( $automation->trigger );
				if ( empty( $form ) ) {
					$automation->delete();
					throw new \Exception( 'Trigger not found.' );
				} else {
					$is_form = true;
				}
			}

			if ( ! $is_form ) {
				$trigger->set_settings( $automation );
			}

			/**
			 * Fires after an automation is created (and its trigger settings stored).
			 *
			 * SaaS form integrations (Typeform, Jotform) use this to register the
			 * inbound webhook for the automation's selected form, so the trigger
			 * works without a separate Forms → SaaS Forms connection.
			 *
			 * @param AutomationModel $automation Saved automation.
			 * @param string|null     $old_status Previous status (null on create).
			 */
			do_action( 'doublescale_automation_saved', $automation, null );

			return new WP_REST_Response( $automation, 201 );
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
			$id         = $request->get_param( 'id' );
			$automation = AutomationModel::find( $id );

			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$automation_data = $this->prepare_automation( $request );
			$old_status      = $automation->status;
			$automation->fill( $automation_data );
			$automation->save();

			// Fire hook when automation is paused (only on actual status transition).
			if ( isset( $automation_data['status'] ) && 'paused' === $automation_data['status'] && 'paused' !== $old_status ) {
				do_action( 'doublescale_automation_paused', $automation, 'manual' );
			}

			/** @see create_item() for the hook contract. */
			do_action( 'doublescale_automation_saved', $automation, $old_status );

			// Capture a version for undo / redo (skipped while restoring).
			VersionManager::instance()->snapshot( $automation->id, __( 'Updated automation', 'doublescale' ) );

			$automation->load(
				array(
					'steps' => function ( $query ) {
						$query->whereIn( 'status', array( 'active', 'draft' ) );
					},
				)
			);

			return new WP_REST_Response( $automation, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Duplicate an automation and all of its active workflow steps.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_item( $request ) {
		try {
			$id         = (int) $request->get_param( 'id' );
			$automation = AutomationModel::find( $id );

			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$automation_data = $automation->toArray();
			unset( $automation_data['id'], $automation_data['created_at'], $automation_data['updated_at'] );

			$settings = is_array( $automation_data['settings'] ?? null ) ? $automation_data['settings'] : array();
			unset(
				$settings['_version_cursor'],
				$settings['_trigger_label'],
				$settings['_trigger_warning'],
				$settings['_trigger_warning_message']
			);
			$automation_data['settings']   = $settings;
			$automation_data['name']       = $automation->name . ' - Copy';
			$automation_data['status']     = 'inactive';
			$automation_data['created_by'] = get_current_user_id() ?: null;

			$new_automation = AutomationModel::create( $automation_data );
			if ( ! $new_automation ) {
				return new WP_Error( 'error', __( 'Failed to duplicate automation.', 'doublescale' ), array( 'status' => 500 ) );
			}

			$this->duplicate_automation_steps( $automation->id, $new_automation->id );

			$new_automation = AutomationModel::with(
				array(
					'steps' => function ( $query ) {
						$query->where( 'status', 'active' );
					},
				)
			)->find( $new_automation->id );

			$new_automation = $this->check_and_mark_dependencies( $new_automation );

			return new WP_REST_Response( $new_automation, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Copy all active steps from one automation to another.
	 *
	 * @since 1.0.0
	 *
	 * @param int $source_automation_id Source automation ID.
	 * @param int $new_automation_id    New automation ID.
	 *
	 * @return void
	 */
	private function duplicate_automation_steps( $source_automation_id, $new_automation_id ) {
		$steps = AutomationStepModel::where( 'automation_id', $source_automation_id )
			->where( 'status', 'active' )
			->get()
			->all();

		if ( empty( $steps ) ) {
			return;
		}

		$id_map  = array();
		$pending = $steps;

		while ( ! empty( $pending ) ) {
			$progress = false;

			foreach ( $pending as $index => $step ) {
				$parent_id = (int) $step->parent_id;
				if ( $parent_id > 0 && ! isset( $id_map[ $parent_id ] ) ) {
					continue;
				}

				$settings = is_array( $step->settings ) ? $step->settings : array();
				$settings = $this->prepare_step_settings_for_duplicate( $settings, (string) $step->action );

				$new_step = AutomationStepModel::create(
					array(
						'automation_id' => $new_automation_id,
						'parent_id'     => $parent_id > 0 ? $id_map[ $parent_id ] : 0,
						'action'        => $step->action,
						'type'          => $step->type,
						'condition'     => $step->condition,
						'status'        => 'active',
						'settings'      => $settings,
						'order'         => (int) $step->order,
					)
				);

				$id_map[ (int) $step->id ] = (int) $new_step->id;
				unset( $pending[ $index ] );
				$progress = true;
			}

			if ( ! $progress ) {
				throw new \Exception( __( 'Could not duplicate workflow steps.', 'doublescale' ) );
			}

			$pending = array_values( $pending );
		}

		$new_steps = AutomationStepModel::where( 'automation_id', $new_automation_id )->get();
		foreach ( $new_steps as $new_step ) {
			$settings = is_array( $new_step->settings ) ? $new_step->settings : array();
			$updated  = $this->remap_step_ids_in_settings( $settings, $id_map );
			if ( $updated !== $settings ) {
				$new_step->settings = $updated;
				$new_step->save();
			}
		}
	}

	/**
	 * Prepare step settings so email/SMS templates are copied instead of shared.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $settings Step settings.
	 * @param string $action   Step action slug.
	 *
	 * @return array
	 */
	private function prepare_step_settings_for_duplicate( array $settings, $action ) {
		foreach (
			array(
				'_action_label',
				'_action_warning',
				'_action_warning_message',
				'_goal_label',
				'_goal_warning',
				'_goal_warning_message',
			) as $runtime_key
		) {
			unset( $settings[ $runtime_key ] );
		}

		$channel_map = array(
			'send_email'    => 'email',
			'send_sms'      => 'sms',
			'send_whatsapp' => 'whatsapp',
		);

		if ( ! isset( $channel_map[ $action ] ) || 'whatsapp' === $channel_map[ $action ] ) {
			return $settings;
		}

		if ( empty( $settings['template_ids'] ) || ! is_array( $settings['template_ids'] ) ) {
			return $settings;
		}

		$template_id = (int) reset( $settings['template_ids'] );
		$template    = TrackingTemplateModel::find( $template_id );
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
			$id         = $request->get_param( 'id' );
			$automation = AutomationModel::find( $id );

			if ( ! $automation ) {
				return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			/**
			 * Fires before an automation is deleted. SaaS form integrations use
			 * this to remove an inbound webhook that is no longer needed.
			 *
			 * @param AutomationModel $automation Automation about to be deleted.
			 */
			do_action( 'doublescale_automation_deleted', $automation );

			$automation->delete();

			return new WP_REST_Response( null, 204 );
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
			$ids = $request->get_param( 'ids' );
			if ( empty( $ids ) ) {
				return new WP_Error( 'error', __( 'No IDs provided.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$automations = AutomationModel::find( $ids );
			if ( ! $automations ) {
				return new WP_Error( 'not_found', __( 'Automations not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			foreach ( $automations as $automation ) {
				/** @see delete_item() for the hook contract. */
				do_action( 'doublescale_automation_deleted', $automation );
				$automation->delete();
			}

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Check and mark plugin dependencies
	 * Adds warnings and stores labels for unavailable triggers/actions
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation The automation object.
	 *
	 * @return AutomationModel
	 */
	private function check_and_mark_dependencies( $automation, $context = 'get_item' ) {
		$has_warnings = false;
		$warnings     = array();

		if ( ! empty( $automation->trigger ) ) {
			$trigger = TriggersManager::instance()->get_trigger( $automation->trigger );

			$update_trigger_settings = function ( $label, $warning = false, $warning_message = '' ) use ( &$automation ) {
				$settings                   = $automation->settings ?: array();
				$settings['_trigger_label'] = $label;
				if ( $warning ) {
					$settings['_trigger_warning'] = true;
					if ( '' !== $warning_message ) {
						$settings['_trigger_warning_message'] = $warning_message;
					}
				} else {
					unset( $settings['_trigger_warning'], $settings['_trigger_warning_message'] );
				}
				$automation->settings = $settings;
			};

			if ( empty( $trigger ) ) {
				$form = class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' )
					? \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_form( $automation->trigger )
					: null;

				if ( ! empty( $form ) && ! empty( $form->is_pro ) && $form->is_pro ) {
					$has_warnings     = true;
					$form_pro_message = __( 'Form trigger requires Plugin Pro to be installed and activated.', 'doublescale' );
					$warnings[]       = array(
						'type'    => 'trigger',
						'slug'    => $automation->trigger,
						'message' => $form_pro_message,
					);
					$update_trigger_settings( $automation->trigger, true, $form_pro_message );
				} elseif ( empty( $form ) || ! $form->is_enabled() ) {
					if ( 'typeform' === $automation->trigger ) {
						$form_inactive_message = __( 'Connect Typeform in Integrations with a personal access token before using this trigger.', 'doublescale' );
					} elseif ( 'jotform' === $automation->trigger ) {
						$form_inactive_message = __( 'Connect Jotform in Integrations with an API key before using this trigger.', 'doublescale' );
					} else {
						$form_inactive_message = __( 'Trigger requires a plugin that is not currently active.', 'doublescale' );
					}
					$has_warnings          = true;
					$warnings[]            = array(
						'type'    => 'trigger',
						'slug'    => $automation->trigger,
						'message' => $form_inactive_message,
					);
					$update_trigger_settings( $automation->trigger, true, $form_inactive_message );
				} else {
					$update_trigger_settings( $form->name, false );
				}
			} else {
				$trigger_plugin_check = $this->check_trigger_plugin_dependency( $trigger );

				if ( ! $trigger_plugin_check['is_active'] || $trigger_plugin_check['is_pro'] ) {
					$has_warnings = true;
					$warnings[]   = array(
						'type'         => 'trigger',
						'slug'         => $automation->trigger,
						'message'      => $trigger_plugin_check['message'],
						'plugin_label' => $trigger_plugin_check['plugin_label'],
					);
					$update_trigger_settings( $trigger->name, true, $trigger_plugin_check['message'] );
				} else {
					$update_trigger_settings( $trigger->name, false );
				}
			}
		}

		if ( $context === 'get_items' ) {
			$automation->_warnings = $warnings;
			return $automation;
		}
		// Check each action step's availability
		if ( ! empty( $automation->steps ) ) {
			foreach ( $automation->steps as $step ) {
				if ( ( $step->type === 'action' || $step->type === 'delay' ) && ! empty( $step->action ) ) {
					try {
						$action = ActionsManager::instance()->get_action( $step->action );

						// Action exists, check if its required plugin is active
						$action_plugin_check = $this->check_action_plugin_dependency( $action );

						if ( ! $action_plugin_check['is_active'] || $action_plugin_check['is_pro'] ) {
							$has_warnings = true;
							$warnings[]   = array(
								'type'         => 'action',
								'step_id'      => $step->id,
								'slug'         => $step->action,
								'message'      => $action_plugin_check['message'],
								'plugin_label' => $action_plugin_check['plugin_label'],
							);

							// Store action label, warning flag, and warning message
							$settings                            = $step->settings ?: array();
							$settings['_action_label']           = $action->name;
							$settings['_action_warning']         = true;
							$settings['_action_warning_message'] = $action_plugin_check['message'];
							$step->settings                      = $settings;
						} else {
							// Action exists and plugin is active, store its label and clear warnings
							$settings                  = $step->settings ?: array();
							$settings['_action_label'] = $action->name;
							unset( $settings['_action_warning'] );
							unset( $settings['_action_warning_message'] );
							$step->settings = $settings;
						}
					} catch ( \Exception $e ) {
						// Action not found - plugin missing
						$has_warnings    = true;
						$warning_message = __( 'Action requires a plugin that is not currently active.', 'doublescale' );
						$warnings[]      = array(
							'type'    => 'action',
							'step_id' => $step->id,
							'slug'    => $step->action,
							'message' => $warning_message,
						);

						// Store action label (slug) if not already stored, warning flag, and warning message
						$settings = $step->settings ?: array();
						if ( empty( $settings['_action_label'] ) ) {
							$settings['_action_label'] = $step->action;
						}
						$settings['_action_warning']         = true;
						$settings['_action_warning_message'] = $warning_message;
						$step->settings                      = $settings;
					}
				} elseif ( $step->type === 'condition' && ! empty( $step->settings ) ) {
					// Check condition/rule dependencies
					$condition_check = $this->check_condition_plugin_dependencies( $step->settings );

					if ( $condition_check['has_warnings'] ) {
						$has_warnings = true;
						$warnings[]   = array(
							'type'              => 'condition',
							'step_id'           => $step->id,
							'message'           => $condition_check['message'],
							'plugin_labels'     => $condition_check['plugin_labels'],
							'unavailable_count' => $condition_check['unavailable_count'],
						);

						// Store condition warning and unavailable rules info as step properties (not in settings)
						$step->_condition_warning       = true;
						$step->_unavailable_rules       = $condition_check['unavailable_rules'];
						$step->_unavailable_rules_count = $condition_check['unavailable_count'];
					} else {
						// All rules have their plugins active - clean up warning flags
						$step->_condition_warning       = false;
						$step->_unavailable_rules       = array();
						$step->_unavailable_rules_count = 0;
					}
				} elseif ( $step->type === 'goal' && ! empty( $step->action ) ) {

					try {
						$goal = GoalsManager::instance()->get_goal( $step->action );

						// Goal exists, check if its required plugin is active
						$goal_plugin_check = $this->check_goal_plugin_dependency( $goal );

						if ( ! $goal_plugin_check['is_active'] || $goal_plugin_check['is_pro'] ) {
							$has_warnings = true;
							$warnings[]   = array(
								'type'         => 'goal',
								'step_id'      => $step->id,
								'slug'         => $step->action,
								'message'      => $goal_plugin_check['message'],
								'plugin_label' => $goal_plugin_check['plugin_label'],
							);

							// Store goal label, warning flag, and warning message
							$settings                          = $step->settings ?: array();
							$settings['_goal_label']           = $goal->name;
							$settings['_goal_warning']         = true;
							$settings['_goal_warning_message'] = $goal_plugin_check['message'];
							$step->settings                    = $settings;
						} else {
							// Goal exists and plugin is active, store its label and clear warnings
							$settings                = $step->settings ?: array();
							$settings['_goal_label'] = $goal->name;
							unset( $settings['_goal_warning'] );
							unset( $settings['_goal_warning_message'] );
							$step->settings = $settings;
						}
					} catch ( \Exception $e ) {
						// Goal not found - plugin missing
						$has_warnings    = true;
						$warning_message = __( 'Goal requires a plugin that is not currently active.', 'doublescale' );
						$warnings[]      = array(
							'type'    => 'goal',
							'step_id' => $step->id,
							'slug'    => $step->action,
							'message' => $warning_message,
						);

						// Store goal label (slug) if not already stored, warning flag, and warning message
						$settings = $step->settings ?: array();
						if ( empty( $settings['_goal_label'] ) ) {
							$settings['_goal_label'] = $step->action;
						}
						$settings['_goal_warning']         = true;
						$settings['_goal_warning_message'] = $warning_message;
						$step->settings                    = $settings;
					}
				}
			}
		}

		// Add warnings metadata to automation
		if ( $has_warnings ) {
			$automation->_warnings = $warnings;
		}

		return $automation;
	}

	/**
	 * Check trigger plugin dependency
	 * Returns whether the trigger's required plugin is active
	 *
	 * @since 1.0.0
	 *
	 * @param object $trigger The trigger object.
	 *
	 * @return array Array with 'is_active', 'message', and 'plugin_label' keys
	 */
	private function check_trigger_plugin_dependency( $trigger ) {
		// Define plugin dependencies based on trigger source and group
		$plugin_dependencies = array(
			'crm'         => array(
				'contact'       => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
				'link_triggers' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
				'webhooks'      => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
				'deal'          => array(
					'module' => 'deals',
					'label'  => 'Pipelines & Deals',
				),
			),
			'woocommerce' => array(
				'order'        => array(
					'plugin' => 'woocommerce/woocommerce.php',
					'label'  => 'WooCommerce',
				),
				'cart'         => array(
					'plugin' => 'woocommerce/woocommerce.php',
					'label'  => 'WooCommerce',
				),
				'review'       => array(
					'plugin' => 'woocommerce/woocommerce.php',
					'label'  => 'WooCommerce',
				),
				'subscription' => array(
					'plugin' => 'woocommerce-subscriptions/woocommerce-subscriptions.php',
					'label'  => 'WooCommerce Subscriptions',
				),
				'wishlist'     => array(
					'plugin' => 'woocommerce-wishlists/woocommerce-wishlists.php',
					'label'  => 'WooCommerce Wishlists',
				),
				'membership'   => array(
					'plugin' => 'woocommerce-memberships/woocommerce-memberships.php',
					'label'  => 'WooCommerce Memberships',
				),
			),
			'edd'         => array(
				'order' => array(
					'plugin' => 'easy-digital-downloads/easy-digital-downloads.php',
					'label'  => 'Easy Digital Downloads',
				),
			),
			'lms'         => array(
				'learndash' => array(
					'plugin' => 'sfwd-lms/sfwd_lms.php',
					'label'  => 'LearnDash',
				),
			),
			'memberpress' => array(
				'memberpress' => array(
					'plugin' => 'memberpress/memberpress.php',
					'label'  => 'MemberPress',
				),
			),
			'booking'     => array(
				'booking' => array(
					'module' => 'booking',
					'label'  => 'Booking',
				),
			),
			'support'     => array(
				'support' => array(
					'module' => 'support',
					'label'  => 'Helpdesk',
				),
			),
			'tasks'       => array(
				'task'    => array(
					'module' => 'tasks',
					'label'  => 'Tasks',
				),
				'subtask' => array(
					'module' => 'tasks',
					'label'  => 'Tasks',
				),
			),
			'sales'       => array(
				'sales' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
			),
			'pmpro'       => array(
				'pmpro' => array(
					'plugin' => 'paid-memberships-pro/paid-memberships-pro.php',
					'label'  => 'Paid Memberships Pro',
				),
			),
			'tutorlms'    => array(
				'tutorlms' => array(
					'plugin' => 'tutor/tutor.php',
					'label'  => 'Tutor LMS',
				),
			),
			'memberpress' => array(
				'memberpress' => array(
					'plugin' => 'memberpress/memberpress.php',
					'label'  => 'MemberPress',
				),
			),
		);
		// Check if trigger has a source and group that requires a plugin
		if ( ! empty( $trigger->source ) && ! empty( $trigger->group ) ) {
			if ( isset( $plugin_dependencies[ $trigger->source ][ $trigger->group ] ) ) {
				$dependency = $plugin_dependencies[ $trigger->source ][ $trigger->group ];
				$is_pro     = isset( $trigger->is_pro ) && $trigger->is_pro;

				// Module-off wins over Pro/plugin checks (mirrors {@see check_action_plugin_dependency()}).
				if ( ! empty( $dependency['module'] ) ) {
					$module_active = function_exists( 'doublescale_is_module_active' )
						&& doublescale_is_module_active( (string) $dependency['module'] );
					if ( ! $module_active ) {
						$module_label = $this->get_action_module_label( (string) $dependency['module'] );
						return array(
							'is_active'    => false,
							'is_pro'       => false,
							'message'      => sprintf(
								/* translators: %s: module name (e.g. Support, Booking) */
								__( 'This trigger requires the %s module to be enabled under Settings → Modules.', 'doublescale' ),
								$module_label
							),
							'plugin_label' => $module_label,
						);
					}
				}

				if ( empty( $dependency['plugin'] ) ) {
					$is_active = true;
				} else {
					$is_active = doublescale_is_plugin_active( $dependency['plugin'] );
				}

				if ( $is_pro ) {
					return array(
						'is_active'    => $is_active,
						'is_pro'       => true,
						'message'      => __( 'This trigger requires Plugin Pro to be installed and activated.', 'doublescale' ),
						'plugin_label' => $dependency['label'],
					);
				}

				if ( ! $is_active ) {
					return array(
						'is_active'    => false,
						'is_pro'       => false,
						/* translators: %s: plugin name */
						'message'      => sprintf( __( 'This trigger requires %s to be installed and activated.', 'doublescale' ), $dependency['label'] ),
						'plugin_label' => $dependency['label'],
					);
				}
			}
		}

		// Form integration triggers use `source` = "forms" and are not listed in
		// `$plugin_dependencies` above. Without this branch, Pro-only form stubs still register
		// in {@see TriggersManager} but list/detail dependency checks never surface the Pro warning
		// (unlike CRM triggers such as Contact Subscribed).
		if ( 'forms' === ( $trigger->source ?? '' ) ) {
			if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( 'forms' ) ) {
				return array(
					'is_active'    => false,
					'is_pro'       => false,
					'message'      => __( 'This trigger requires the Forms module to be enabled under Settings → Modules.', 'doublescale' ),
					'plugin_label' => __( 'Forms', 'doublescale' ),
				);
			}
			if ( ! empty( $trigger->is_pro ) && function_exists( 'doublescale_is_pro_addon_active' ) && ! doublescale_is_pro_addon_active() ) {
				return array(
					'is_active'    => false,
					'is_pro'       => true,
					'message'      => __( 'This trigger requires Plugin Pro to be installed and activated.', 'doublescale' ),
					'plugin_label' => __( 'Double Scale Pro', 'doublescale' ),
				);
			}

			$trigger_slug = $trigger->slug ?? '';
			if ( in_array( $trigger_slug, array( 'typeform', 'jotform' ), true ) ) {
				$saas_check = $this->check_saas_form_integration_dependency( $trigger_slug );
				if ( ! $saas_check['is_active'] ) {
					return $saas_check;
				}
			}
		}

		if ( 'sales' === ( $trigger->source ?? '' ) ) {
			$item_slug = isset( $trigger->slug ) ? (string) $trigger->slug : '';
			$dep       = doublescale_automation_module_dependency_result(
				doublescale_automation_sales_item_modules( $item_slug ),
				'trigger'
			);
			if ( ! $dep['is_active'] ) {
				return $dep;
			}
			if ( ! empty( $trigger->is_pro ) && function_exists( 'doublescale_is_pro_addon_active' ) && ! doublescale_is_pro_addon_active() ) {
				return array(
					'is_active'    => false,
					'is_pro'       => true,
					'message'      => __( 'This trigger requires Plugin Pro to be installed and activated.', 'doublescale' ),
					'plugin_label' => __( 'Double Scale Pro', 'doublescale' ),
				);
			}
		}

		// No dependency or plugin is active
		return array(
			'is_active'    => true,
			'is_pro'       => false,
			'message'      => '',
			'plugin_label' => '',
		);
	}

	/**
	 * SaaS form automations (Typeform, Jotform) require Integrations credentials
	 * (not a WordPress plugin).
	 *
	 * @param string $slug Integration slug (e.g. 'typeform', 'jotform').
	 * @return array{is_active:bool,is_pro:bool,message:string,plugin_label:string}
	 */
	private function check_saas_form_integration_dependency( $slug ) {
		if ( 'jotform' === $slug ) {
			$label   = __( 'Jotform', 'doublescale' );
			$message = __( 'Connect Jotform in Integrations with an API key before using this trigger.', 'doublescale' );
		} else {
			$label   = __( 'Typeform', 'doublescale' );
			$message = __( 'Connect Typeform in Integrations with a personal access token before using this trigger.', 'doublescale' );
		}

		if ( ! class_exists( '\DoubleScale\Core\Managers\IntegrationsManager' ) ) {
			return array(
				'is_active'    => false,
				'is_pro'       => false,
				'message'      => $message,
				'plugin_label' => $label,
			);
		}

		try {
			$integration = \DoubleScale\Core\Managers\IntegrationsManager::instance()->get_integration( $slug );
			if ( $integration && $integration->is_connected() ) {
				return array(
					'is_active'    => true,
					'is_pro'       => false,
					'message'      => '',
					'plugin_label' => '',
				);
			}
		} catch ( \Exception $e ) {
			// Fall through to disconnected message.
		}

		return array(
			'is_active'    => false,
			'is_pro'       => false,
			'message'      => $message,
			'plugin_label' => $label,
		);
	}

	/**
	 * Check action plugin dependency
	 * Returns whether the action's required plugin is active
	 *
	 * @since 1.0.0
	 *
	 * @param object $action The action object.
	 *
	 * @return array Array with 'is_active', 'message', and 'plugin_label' keys
	 */
	private function check_action_plugin_dependency( $action ) {
		// Define plugin dependencies based on action source and group
		$plugin_dependencies = array(
			'crm'         => array(
				'deal'  => array(
					'plugin' => '',
					'module' => 'deals',
					'label'  => 'Double Scale Pro',
				),
				'delay' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
			),
			'support'     => array(
				'support' => array(
					'plugin' => '',
					'module' => 'support',
					'label'  => 'Double Scale Pro',
				),
			),
			'tasks'       => array(
				'task' => array(
					'plugin' => '',
					'module' => 'tasks',
					'label'  => 'Double Scale Pro',
				),
			),
			'sales'       => array(
				'sales' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
			),
			'woocommerce' => array(
				'order'  => array(
					'plugin' => 'woocommerce/woocommerce.php',
					'label'  => 'WooCommerce',
				),
				'coupon' => array(
					'plugin' => 'woocommerce/woocommerce.php',
					'label'  => 'WooCommerce',
				),
			),
			'wp'          => array(
				'user' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
			),
			'lms'         => array(
				'learndash' => array(
					'plugin' => 'sfwd-lms/sfwd_lms.php',
					'label'  => 'LearnDash',
				),
			),
			'email'       => array(
				'email' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
			),
			'message'     => array(
				'sms'      => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
				'whatsapp' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
			),
			'send_data'   => array(
				'slack'        => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
				'zapier'       => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
				'http_request' => array(
					'plugin' => '',
					'label'  => 'Double Scale Pro',
				),
			),
			'pmpro'       => array(
				'pmpro' => array(
					'plugin' => 'paid-memberships-pro/paid-memberships-pro.php',
					'label'  => 'Paid Memberships Pro',
				),
			),
			'memberpress' => array(
				'memberpress' => array(
					'plugin' => 'memberpress/memberpress.php',
					'label'  => 'MemberPress',
				),
			),
		);

		// Check if action has a source and group that requires a plugin
		if ( ! empty( $action->source ) && ! empty( $action->group ) ) {
			if ( isset( $plugin_dependencies[ $action->source ][ $action->group ] ) ) {
				$dependency = $plugin_dependencies[ $action->source ][ $action->group ];
				$is_pro     = isset( $action->is_pro ) && $action->is_pro;

				// A turned-off module wins over Pro/plugin checks: the action's
				// feature is unavailable regardless of whether Pro is active, so
				// surface the "enable the module" guidance first.
				if ( ! empty( $dependency['module'] ) ) {
					$module_active = function_exists( 'doublescale_is_module_active' )
						&& doublescale_is_module_active( (string) $dependency['module'] );
					if ( ! $module_active ) {
						$module_label = $this->get_action_module_label( (string) $dependency['module'] );
						return array(
							'is_active'    => false,
							'is_pro'       => false,
							'message'      => sprintf(
								/* translators: %s: module name (e.g. Support, Deals) */
								__( 'This action requires the %s module to be enabled under Settings → Modules.', 'doublescale' ),
								$module_label
							),
							'plugin_label' => $module_label,
						);
					}
				}

				if ( empty( $dependency['plugin'] ) ) {
					$is_active = true;
				} else {
					$is_active = doublescale_is_plugin_active( $dependency['plugin'] );
				}
				if ( $is_pro ) {
					return array(
						'is_active'    => $is_active,
						'is_pro'       => $is_pro,
						'message'      => sprintf(
							__( 'This action requires Plugin Pro to be installed and activated.', 'doublescale' ),
							$dependency['label']
						),
						'plugin_label' => $dependency['label'],
					);
				}
				if ( ! $is_active ) {
					return array(
						'is_active'    => false,
						'message'      => sprintf(
							/* translators: %s: plugin name */
							__( 'This action requires %s to be installed and activated.', 'doublescale' ),
							$dependency['label']
						),
						'plugin_label' => $dependency['label'],
					);
				}
			}
		}

		if ( 'sales' === ( $action->source ?? '' ) ) {
			$item_slug = isset( $action->slug ) ? (string) $action->slug : '';
			$dep       = doublescale_automation_module_dependency_result(
				doublescale_automation_sales_item_modules( $item_slug ),
				'action'
			);
			if ( ! $dep['is_active'] ) {
				return $dep;
			}
			if ( ! empty( $action->is_pro ) && function_exists( 'doublescale_is_pro_addon_active' ) && ! doublescale_is_pro_addon_active() ) {
				return array(
					'is_active'    => false,
					'is_pro'       => true,
					'message'      => __( 'This action requires Plugin Pro to be installed and activated.', 'doublescale' ),
					'plugin_label' => __( 'Double Scale Pro', 'doublescale' ),
				);
			}
		}

		// No dependency or plugin is active
		return array(
			'is_active'    => true,
			'is_pro'       => false,
			'message'      => '',
			'plugin_label' => '',
		);
	}

	/**
	 * Human-readable label for a module slug used in action dependency warnings.
	 *
	 * @since 1.0.1
	 *
	 * @param string $module Module slug (e.g. 'support', 'deals').
	 * @return string Display label, falling back to a title-cased slug.
	 */
	private function get_action_module_label( $module ) {
		if ( function_exists( 'doublescale_automation_module_label' ) ) {
			return doublescale_automation_module_label( (string) $module );
		}

		$labels = array(
			'support' => __( 'Helpdesk', 'doublescale' ),
			'deals'   => __( 'Pipelines & Deals', 'doublescale' ),
			'booking' => __( 'Booking', 'doublescale' ),
			'forms'   => __( 'Forms', 'doublescale' ),
			'sales'   => __( 'Sales', 'doublescale' ),
		);

		return $labels[ $module ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $module ) );
	}

	/**
	 * Check condition plugin dependencies
	 * Returns information about unavailable rules in the condition
	 *
	 * @since 1.0.0
	 *
	 * @param array $condition_settings The condition settings array containing rule groups.
	 *
	 * @return array Array with 'has_warnings', 'unavailable_rules', 'unavailable_count', and 'message' keys
	 */
	private function check_condition_plugin_dependencies( $condition_settings ) {
		// Define plugin dependencies based on rule group
		$plugin_dependencies = array(
			'woocommerce'               => array(
				'plugin'     => 'woocommerce/woocommerce.php',
				'label'      => 'WooCommerce',
				'is_enabled' => doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'woocommerce_current_order' => array(
				'plugin'     => 'woocommerce/woocommerce.php',
				'label'      => 'WooCommerce',
				'is_enabled' => doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'woocommerce_membership'    => array(
				'plugin'     => 'woocommerce-memberships/woocommerce-memberships.php',
				'label'      => 'WooCommerce Memberships',
				'is_enabled' => doublescale_is_plugin_active( 'woocommerce-memberships/woocommerce-memberships.php' ),
			),
			'woocommerce_whishlist'     => array(
				'plugin'     => 'woocommerce-wishlist/woocommerce-wishlist.php',
				'label'      => 'WooCommerce Wishlist',
				'is_enabled' => doublescale_is_plugin_active( 'woocommerce-wishlist/woocommerce-wishlist.php' ),
			),
			'woocommerce_subscription'  => array(
				'plugin'     => 'woocommerce-subscriptions/woocommerce-subscriptions.php',
				'label'      => 'WooCommerce Subscriptions',
				'is_enabled' => doublescale_is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ),
			),
			'woocommerce_review'        => array(
				'plugin'     => 'woocommerce/woocommerce.php',
				'label'      => 'WooCommerce',
				'is_enabled' => doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'cart'                      => array(
				'plugin'     => 'woocommerce/woocommerce.php',
				'label'      => 'WooCommerce',
				'is_enabled' => doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ),
			),
			'learndash'                 => array(
				'plugin'     => 'sfwd-lms/sfwd_lms.php',
				'label'      => 'LearnDash',
				'is_enabled' => doublescale_is_plugin_active( 'sfwd-lms/sfwd_lms.php' ),
			),
			'memberpress'               => array(
				'plugin'     => 'memberpress/memberpress.php',
				'label'      => 'MemberPress',
				'is_enabled' => doublescale_is_plugin_active( 'memberpress/memberpress.php' ),
			),
			'pmpro'                     => array(
				'plugin'     => 'paid-memberships-pro/paid-memberships-pro.php',
				'label'      => 'Paid Memberships Pro',
				'is_enabled' => doublescale_is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ),
			),
			'proposal'                  => array(
				'label'      => __( 'Proposals & Invoices', 'doublescale' ),
				'is_enabled' => doublescale_automation_modules_available( array( 'sales', 'documents' ) ),
			),
			'invoice'                   => array(
				'label'      => __( 'Proposals & Invoices', 'doublescale' ),
				'is_enabled' => doublescale_automation_modules_available( array( 'sales', 'documents' ) ),
			),
			'contract'                  => array(
				'label'      => __( 'Contracts', 'doublescale' ),
				'is_enabled' => doublescale_automation_modules_available( array( 'sales', 'contracts' ) ),
			),
			'credit_note'               => array(
				'label'      => __( 'Credit Notes', 'doublescale' ),
				'is_enabled' => doublescale_automation_modules_available( array( 'sales', 'credit_notes' ) ),
			),
			'support'                   => array(
				'label'      => __( 'Helpdesk', 'doublescale' ),
				'is_enabled' => function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'support' ),
			),
		);

		// set forms
		$forms = class_exists( '\DoubleScale\Modules\Forms\Services\FormsManager' )
			? \DoubleScale\Modules\Forms\Services\FormsManager::instance()->get_all_forms()
			: array();
		foreach ( $forms as $form ) {
			$plugin_dependencies[ $form->slug ] = array(
				'plugin'     => $form->slug,
				'label'      => $form->name,
				'is_enabled' => $form->is_enabled(),
			);
		}

		$unavailable_rules  = array();
		$unavailable_groups = array();

		// Check each rule group in the condition settings
		if ( is_array( $condition_settings ) ) {
			foreach ( $condition_settings as $group_index => $rule_group ) {
				if ( is_array( $rule_group ) ) {
					foreach ( $rule_group as $rule_index => $rule ) {
						$selected_group = isset( $rule['selectedGroup'] ) ? $rule['selectedGroup'] : '';
						$rule_slug      = isset( $rule['rule'] ) ? $rule['rule'] : '';

						// Check if this rule's group requires a plugin
						if ( ! empty( $selected_group ) && isset( $plugin_dependencies[ $selected_group ] ) ) {
							$dependency = $plugin_dependencies[ $selected_group ];
							$is_active  = $dependency['is_enabled'];

							if ( ! $is_active ) {
								// Store unavailable rule information
								$unavailable_rules[] = array(
									'group_index'  => $group_index,
									'rule_index'   => $rule_index,
									'rule_slug'    => $rule_slug,
									'group_slug'   => $selected_group,
									'plugin_label' => $dependency['label'],
								);

								// Track unique plugin groups
								if ( ! in_array( $dependency['label'], $unavailable_groups ) ) {
									$unavailable_groups[] = $dependency['label'];
								}
							}
						}
					}
				}
			}
		}

		$has_warnings      = count( $unavailable_rules ) > 0;
		$unavailable_count = count( $unavailable_rules );
		$unique_groups     = implode( ', ', array_unique( $unavailable_groups ) );

		$message = '';
		if ( $has_warnings ) {
			if ( $unavailable_count === 1 ) {
				$message = sprintf(
					/* translators: %s: plugin name */
					__( 'This condition uses 1 rule that requires %s to be installed and activated.', 'doublescale' ),
					$unique_groups
				);
			} else {
				$message = sprintf(
					/* translators: %1$d: number of rules, %2$s: plugin names */
					__( 'This condition uses %1$d rules that require plugins (%2$s) to be installed and activated.', 'doublescale' ),
					$unavailable_count,
					$unique_groups
				);
			}
		}

		return array(
			'has_warnings'      => $has_warnings,
			'unavailable_rules' => $unavailable_rules,
			'unavailable_count' => $unavailable_count,
			'message'           => $message,
			'plugin_labels'     => $unavailable_groups,
		);
	}


	/**
	 * Check goal plugin dependency
	 * Returns whether the goal's required plugin is active
	 *
	 * @since 1.0.0
	 *
	 * @param object $goal The goal object.
	 *
	 * @return array Array with 'is_active', 'message', and 'plugin_labels' keys
	 */
	private function check_goal_plugin_dependency( $goal ) {
		// Define plugin dependencies based on goal source and group
		$plugin_dependencies = array(
			'woocommerce' => array(
				'coupon' => array(
					'plugin' => 'woocommerce/woocommerce.php',
					'label'  => 'WooCommerce',
				),
			),
		);

		// Check if action has a source and group that requires a plugin
		if ( ! empty( $goal->source ) && ! empty( $goal->group ) ) {
			if ( isset( $plugin_dependencies[ $goal->source ][ $goal->group ] ) ) {
				$dependency = $plugin_dependencies[ $goal->source ][ $goal->group ];
				if ( empty( $dependency['plugin'] ) ) {
					$is_active = true;
				} else {
					$is_active = doublescale_is_plugin_active( $dependency['plugin'] );
				}
				$is_pro = isset( $goal->is_pro ) && $goal->is_pro;

				if ( $is_pro ) {
					return array(
						'is_active'    => $is_active,
						'is_pro'       => true,
						'message'      => sprintf(
							__( 'This goal requires Plugin Pro to be installed and activated.', 'doublescale' ),
							$dependency['label']
						),
						'plugin_label' => $dependency['label'],
					);
				}

				if ( ! $is_active ) {
					return array(
						'is_active'    => false,
						'is_pro'       => false,
						'message'      => sprintf(
							/* translators: %s: plugin name */
							__( 'This goal requires %s to be installed and activated.', 'doublescale' ),
							$dependency['label']
						),
						'plugin_label' => $dependency['label'],
					);
				}
			}
		}

		// No dependency or plugin is active
		return array(
			'is_active'    => true,
			'is_pro'       => false,
			'message'      => '',
			'plugin_label' => '',
		);
	}


	/**
	 * Check conditions
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_conditions( $request ) {
		$automation_id = $request->get_param( 'automation_id' );
		$is_check      = $request->get_param( 'is_check' );
		$is_delete     = $request->get_param( 'is_delete' );

		if ( ! class_exists( 'DoubleScale\Modules\Automations\Services\RulesManager' ) ) {
			return new WP_Error(
				'missing_dependency',
				__( 'Double Scale Pro is not installed. Please install and activate Double Scale Pro to use this automation.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$automation = AutomationModel::find( $automation_id );
		if ( ! $automation ) {
			return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		// get trigger
		$trigger = TriggersManager::instance()->get_trigger( $automation->trigger );
		if ( ! $trigger ) {
			return new WP_Error( 'not_found', __( 'Trigger not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$trigger_source = $trigger->source;
		$trigger_slug   = $trigger->slug;

		$conditions = $automation->steps()->where( 'type', 'condition' )->get();

		$count = 0;

		foreach ( $conditions as $condition ) {
			$condition_settings = $condition->settings;
			foreach ( $condition_settings as $group_index => $rule_group ) {
				if ( is_array( $rule_group ) ) {
					foreach ( $rule_group as $rule_index => $rule ) {
						$group_slug    = $rule['selectedGroup'];
						$group_manager = RulesManager::instance()->get_group_by_slug( $group_slug );
						$rule_manager  = RulesManager::instance()->get_rule( $rule['rule'] );
						if ( ! $rule_manager ) {
							continue;
						}
						$required_triggers = isset( $group_manager['triggers'] ) ? $group_manager['triggers'] : array();

						if ( $group_slug === $trigger_source || in_array( $trigger_slug, $required_triggers ) ) {
							++$count;
							if ( $is_check ) {
								return new WP_REST_Response(
									array(
										'count' => $count,
									),
									200
								);
							}
							if ( $is_delete ) {
								// delete this rule from condition settings
								unset( $condition_settings[ $group_index ][ $rule_index ] );
								$condition->settings = $condition_settings;
								$condition->save();
							}
						}
					}
				}
			}
		}

		return new WP_REST_Response(
			array(
				'count' => $count,
			),
			200
		);
	}

	/**
	 * Prepare automation
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	protected function prepare_automation( $request ) {
		$automation_data = array(
			'name'     => $request->get_param( 'name' ),
			'trigger'  => $request->get_param( 'trigger' ),
			'status'   => $request->get_param( 'status' ) ?? 'active',
			'settings' => $request->get_param( 'settings' ) ?? array(),
		);

		foreach ( $automation_data as $key => $value ) {
			if ( empty( $value ) && $value !== 0 ) {
				unset( $automation_data[ $key ] );
			}
		}

		return $automation_data;
	}

	/**
	 * Get items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Get item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Create item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
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
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Delete items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Delete item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
