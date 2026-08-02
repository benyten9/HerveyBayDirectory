<?php
/**
 * Apple CalDAV account REST (create + calendars entity).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Apple\Rest;

use DoubleScale\Pro\Modules\Booking\Integrations\Apple\Client;
use DoubleScale\Modules\Booking\Integration\Rest\REST_Account_Controller as Abstract_REST_Account_Controller;
use Exception;
use Illuminate\Support\Arr;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Rest Integration Account Controller
 */
class REST_Account_Controller extends Abstract_REST_Account_Controller {

	/**
	 * @var array<string, array{callback: string}>
	 */
	protected $entities = array(
		'calendars' => array(
			'callback' => 'fetch_calendars',
		),
	);

	/**
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
			)
		);
	}

	/**
	 * Item schema (Apple uses app_credentials + config).
	 */
	public function get_item_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'type'        => 'integer',
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => false,
				),
				'name'            => array(
					'type'        => 'string',
					'description' => __( 'Name of the account.', 'doublescale' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'required'    => false,
				),
				'app_credentials' => array(
					'type'                 => 'object',
					'description'          => __( 'Credentials for the account.', 'doublescale' ),
					'context'              => array( 'view', 'edit', 'embed' ),
					'required'             => true,
					'properties'           => array(
						'apple_id'     => array(
							'type'        => 'string',
							'description' => __( 'Apple ID for the account.', 'doublescale' ),
							'context'     => array( 'view', 'edit', 'embed' ),
							'required'    => true,
						),
						'app_password' => array(
							'type'        => 'string',
							'description' => __( 'App-specific password for the account.', 'doublescale' ),
							'context'     => array( 'view', 'edit', 'embed' ),
							'required'    => true,
						),
					),
					'additionalProperties' => true,
				),
				'config'          => array(
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
		if ( is_wp_error( $connect ) || ! ( $connect instanceof Client ) ) {
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
	public function create_item( $request ) {
		$params       = $request->get_params();
		$apple_id     = Arr::get( $params, 'app_credentials.apple_id' );
		$app_password = Arr::get( $params, 'app_credentials.app_password' );
		$host_id      = $request->get_param( 'calendar_id' );

		try {
			$client     = new Client( $apple_id, $app_password );
			$data       = $client->get_calendars();
			$account_id = Arr::get( $data, 'account_id' );
			if ( ! $account_id ) {
				throw new Exception( __( 'Could not add Apple account.', 'doublescale' ) );
			}
			$calendars = Arr::get( $data, 'calendars', array() );

			$this->integration->set_host( $host_id );
			$existing_account = $this->integration->accounts->get_account( $account_id );

			if ( $existing_account ) {
				return new WP_Error( 'account_exists', __( 'This Apple account already exists.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$this->integration->accounts->add_account(
				$account_id,
				array(
					'name'        => $apple_id,
					'credentials' => array(
						'apple_id'     => $apple_id,
						'app_password' => $app_password,
					),
					'config'      => array(
						'host_id' => $host_id,
					),
				)
			);

			return new WP_REST_Response( $calendars, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'add_apple_account_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if (
			current_user_can( 'manage_options' )
			|| current_user_can( 'doublescale_booking_manage_own_calendars' )
			|| current_user_can( 'doublescale_booking_manage_all_calendars' )
		) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to add this account.', 'doublescale' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
