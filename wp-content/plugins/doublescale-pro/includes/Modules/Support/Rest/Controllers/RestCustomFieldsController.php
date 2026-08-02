<?php
/**
 * REST controller for support ticket custom field definitions.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Pro\Modules\Support\Services\CustomFieldsService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestCustomFieldsController class.
 */
class RestCustomFieldsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'support/custom-fields';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_definitions' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'scope' => array(
							'type'    => 'string',
							'default' => 'admin',
							'enum'    => array( 'admin', 'portal' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_definitions' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/support/portal/custom-fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_portal_definitions' ),
					'permission_callback' => array( $this, 'portal_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/meta',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_meta' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_definitions( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$scope = (string) $request->get_param( 'scope' );
		$data  = $this->service()->get_definitions( $scope );
		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_portal_definitions( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$data = $this->service()->get_definitions( 'portal' );
		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Field types and conditional-logic metadata for the settings UI.
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_meta( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$service = $this->service();

		$priorities = array();
		foreach ( TicketPriority::all() as $priority ) {
			$priorities[] = array(
				'value' => $priority,
				'label' => TicketPriority::get_label( $priority ),
			);
		}

		return new WP_REST_Response(
			array(
				'data' => array(
					'field_types'          => $service->get_field_types(),
					'condition_sources'    => CustomFieldsService::CONDITION_SOURCES,
					'condition_operators'        => CustomFieldsService::CONDITION_OPERATORS,
					'choice_condition_operators' => CustomFieldsService::CHOICE_CONDITION_OPERATORS,
					'ticket_priorities'    => $priorities,
				),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_definitions( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$definitions = isset( $params['custom_fields'] ) && is_array( $params['custom_fields'] )
			? $params['custom_fields']
			: ( is_array( $params ) ? $params : array() );

		$result = $this->service()->save_definitions( $definitions );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array( 'data' => $this->service()->get_definitions( 'admin' ) ),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Unused.
	 * @return bool|WP_Error
	 */
	public function admin_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::has_support_access() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access support tickets.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @param WP_REST_Request $request Unused.
	 * @return bool|WP_Error
	 */
	public function settings_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::can_access_support_settings() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to manage support settings.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @param WP_REST_Request $request Unused.
	 * @return bool|WP_Error
	 */
	public function portal_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in to access the support portal.', 'doublescale' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * @return CustomFieldsService
	 */
	private function service(): CustomFieldsService {
		return new CustomFieldsService();
	}
}
