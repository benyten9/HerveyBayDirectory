<?php
/**
 * Per-host-calendar account CRUD and remote entity routes (…/integrations/{slug}/{calendar_id}/accounts/…).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Integration\Rest;

use DoubleScale\Modules\Booking\Abstracts\Integration;
use DoubleScale\Modules\Booking\Abstracts\REST_Controller;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @property Integration $integration
 */
class REST_Account_Controller extends REST_Controller {

	/**
	 * Integration instance.
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * REST base fragment (includes calendar_id regex).
	 *
	 * @var string
	 */
	public $rest_base = 'accounts';

	/**
	 * Optional entity sub-routes (e.g. calendars) → remote_data method names.
	 *
	 * @var array<string, array{callback: string}>|null
	 */
	protected $entities;

	/**
	 * @param Integration $integration Integration.
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;
		$this->rest_base   = 'integrations/' . $this->integration->slug . '/(?P<calendar_id>[\d]+)/' . $this->rest_base;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Entity map for GET …/accounts/{id}/{entity}.
	 *
	 * @return array<string, array{callback: string}>
	 */
	public function get_entities() {
		return $this->entities ?? array();
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/(?P<id>[\w]+)",
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
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::DELETABLE ),
				),
			)
		);

		$entities = $this->get_entities();

		foreach ( $entities as $entity => $data ) {
			register_rest_route(
				$this->namespace,
				"/{$this->rest_base}/(?P<id>[\w]+)/{$entity}",
				array(
					'methods'             => 'GET',
					'callback'            => function ( $request ) use ( $entity ) {
						return $this->get_remote_data( $request, $entity );
					},
					'permission_callback' => array( $this, 'get_entity_permissions_check' ),
				)
			);
		}
	}

	/**
	 * Item schema.
	 */
	public function get_item_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'     => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'Name of the account.', 'doublescale' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'required'    => true,
				),
				'tokens' => array(
					'type'                 => 'object',
					'description'          => __( 'Credentials for the account.', 'doublescale' ),
					'context'              => array( 'view', 'edit', 'embed' ),
					'required'             => true,
					'properties'           => array(
						'access_token'  => array(
							'type'        => 'string',
							'description' => __( 'Access token for the account.', 'doublescale' ),
							'context'     => array( 'view', 'edit', 'embed' ),
							'required'    => true,
						),
						'refresh_token' => array(
							'type'        => 'string',
							'description' => __( 'Refresh token for the account.', 'doublescale' ),
							'context'     => array( 'view', 'edit', 'embed' ),
							'required'    => true,
						),
					),
					'additionalProperties' => true,
				),
				'config' => array(
					'type'                 => 'object',
					'description'          => __( 'Configuration for the account.', 'doublescale' ),
					'context'              => array( 'view', 'edit', 'embed' ),
					'required'             => true,
					'additionalProperties' => true,
				),
			),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param string          $entity  Entity key.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_remote_data( $request, $entity ) {
		$host_id    = $request->get_param( 'calendar_id' );
		$account_id = $request->get_param( 'id' );
		$connect    = $this->integration->connect( $host_id, $account_id );
		if ( is_wp_error( $connect ) || ! $connect ) {
			return new WP_Error( 'unable_to_connect', __( 'Unable to connect to the integration.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$params   = $request->get_params();
		$entities = $this->get_entities();
		if ( ! isset( $entities[ $entity ] ) ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$spec   = $entities[ $entity ];
		$result = $this->integration->remote_data->{$spec['callback']}( $params );

		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			$host_id = $request->get_param( 'calendar_id' );
			$this->integration->set_host( $host_id );
			$accounts = $this->integration->accounts->get_accounts();
			$entities = $this->get_entities();
			if ( ! empty( $entities ) && $this->integration->remote_data ) {
				foreach ( $accounts as $key => $account ) {
					$connect = $this->integration->connect( $host_id, $key );
					if ( is_wp_error( $connect ) || ! $connect ) {
						continue;
					}
					foreach ( $entities as $entity => $data ) {
						$accounts[ $key ][ $entity ] = $this->integration->remote_data->{$data['callback']}( $account );
					}
				}
			}

			return new WP_REST_Response( $accounts, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		try {
			$account_id = $request->get_param( 'id' );
			$host_id    = $request->get_param( 'calendar_id' );
			$this->integration->set_host( $host_id );
			$account = $this->integration->accounts->get_account( $account_id );

			return new WP_REST_Response( $account, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			$account_id = $request->get_param( 'id' );
			$host_id    = $request->get_param( 'calendar_id' );
			$config     = $request->get_param( 'config' );
			$this->integration->set_host( $host_id );
			$account = $this->integration->accounts->update_account(
				$account_id,
				array(
					'config' => $config,
				)
			);

			return new WP_REST_Response( $account, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$account_id = $request->get_param( 'id' );
			$host_id    = $request->get_param( 'calendar_id' );
			$this->integration->set_host( $host_id );
			$this->integration->accounts->delete_account( $account_id );

			return new WP_REST_Response( null, 204 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return $this->integration_permissions_check();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->integration_permissions_check();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return $this->integration_permissions_check();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->integration_permissions_check();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_entity_permissions_check( $request ) {
		return $this->integration_permissions_check();
	}

	/**
	 * Shared capability check for account routes.
	 */
	protected function integration_permissions_check() {
		if (
			current_user_can( 'manage_options' )
			|| current_user_can( 'doublescale_booking_manage_own_calendars' )
			|| current_user_can( 'doublescale_booking_manage_all_calendars' )
		) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage this integration.', 'doublescale' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
