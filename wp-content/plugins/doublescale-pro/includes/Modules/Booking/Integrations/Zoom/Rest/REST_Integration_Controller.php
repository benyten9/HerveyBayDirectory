<?php
/**
 * Zoom global integration settings (Server-to-Server app credentials) + disconnect-by-calendar.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Rest;

use Exception;
use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Integration\Rest\REST_Integration_Controller as Abstract_REST_Integration_Controller;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @property \DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Integration $integration
 */
class REST_Integration_Controller extends Abstract_REST_Integration_Controller {

	/**
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'delete_permissions_check' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function delete_permissions_check( $request ) {
		return $this->current_user_can_manage_integrations();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get( $request ) {
		try {
			$accounts = array();
			$hosts    = CalendarModel::where( 'type', 'host' )->get();
			foreach ( $hosts as $calendar ) {
				$user = $calendar->user;
				if ( ! $user ) {
					continue;
				}
				$data      = $calendar->get_meta( $this->integration->meta_key, array() );
				$data_keys = array_keys( $data );
				$row       = array();
				foreach ( $data_keys as $key ) {
					if ( ! empty( $data ) && ! empty( $data[ $key ] ) && ! empty( $data[ $key ]['app_credentials'] ) && ! empty( $data[ $key ]['app_credentials']['account_id'] ) ) {
						$row['id']    = $calendar->id;
						$row['name']  = $calendar->name;
						$row['email'] = $user->user_email;
						break;
					}
				}
				if ( ! empty( $row ) ) {
					$accounts[] = $row;
				}
			}

			return new WP_REST_Response(
				array(
					'accounts' => $accounts,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'type'        => array( 'integer', 'string' ),
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
						'account_id'    => array(
							'type'        => 'string',
							'description' => __( 'Account ID.', 'doublescale' ),
							'context'     => array( 'view', 'edit', 'embed' ),
							'required'    => true,
						),
						'client_id'     => array(
							'type'        => 'string',
							'description' => __( 'Client ID.', 'doublescale' ),
							'context'     => array( 'view', 'edit', 'embed' ),
							'required'    => false,
						),
						'client_secret' => array(
							'type'        => 'string',
							'description' => __( 'Secret Key.', 'doublescale' ),
							'context'     => array( 'view', 'edit', 'embed' ),
							'required'    => true,
						),
					),
					'additionalProperties' => true,
				),
				'tokens'          => array(
					'type'                 => 'object',
					'description'          => __( 'Credentials for the account.', 'doublescale' ),
					'context'              => array( 'view', 'edit', 'embed' ),
					'required'             => false,
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
				'config'          => array(
					'type'                 => 'object',
					'description'          => __( 'Configuration for the account.', 'doublescale' ),
					'context'              => array( 'view', 'edit', 'embed' ),
					'required'             => false,
					'additionalProperties' => true,
				),
			),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( $request ) {
		try {
			$settings = $request->get_param( 'settings' ) ?? array();

			$validator = $this->integration->validate( $settings );
			if ( ! $validator ) {
				return new WP_Error( 'rest_invalid_request', __( 'Invalid settings.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$app_credentials = $settings['app_credentials'] ?? array();
			if ( empty( $app_credentials['client_id'] ) || empty( $app_credentials['client_secret'] ) ) {
				return new WP_Error( 'rest_invalid_request', __( 'Client ID and Secret Key are required.', 'doublescale' ), array( 'status' => 400 ) );
			}

			try {
				if ( empty( $settings['id'] ) ) {
					$tokens = $this->exchange_zoom_client_credentials(
						array(
							'client_id'     => $app_credentials['client_id'],
							'client_secret' => $app_credentials['client_secret'],
							'account_id'    => $app_credentials['account_id'],
						)
					);
				} else {
					$this->integration->set_host( $settings['id'] );
					$tokens = $this->exchange_zoom_client_credentials(
						array(
							'client_id'     => $app_credentials['client_id'],
							'client_secret' => $app_credentials['client_secret'],
							'account_id'    => $app_credentials['account_id'],
						)
					);
				}

				if ( ! empty( $tokens ) ) {
					$settings['tokens'] = $tokens;
				}
			} catch ( Exception $e ) {
				return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
			}

			if ( empty( $settings['id'] ) ) {
				$this->integration->update_settings( $settings );
				return new WP_REST_Response(
					array(
						'settings' => $settings,
						'message'  => __( 'Global Zoom settings updated successfully.', 'doublescale' ),
					),
					200
				);
			}

			$this->integration->update_settings( $settings );

			return new WP_REST_Response(
				array(
					'settings' => $settings,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param array<string, string> $app_credentials Keys: client_id, client_secret, account_id.
	 * @return array<string, mixed>
	 * @throws Exception When Zoom rejects the request.
	 */
	private function exchange_zoom_client_credentials( array $app_credentials ) {
		$response = wp_remote_post(
			'https://zoom.us/oauth/token',
			array(
				'body'    => array(
					'grant_type' => 'account_credentials',
					'account_id' => Arr::get( $app_credentials, 'account_id' ),
				),
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( Arr::get( $app_credentials, 'client_id' ) . ':' . Arr::get( $app_credentials, 'client_secret' ) ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			throw new Exception( __( 'Invalid response from Zoom.', 'doublescale' ) );
		}

		if ( isset( $data['error'] ) ) {
			if ( 'invalid_client' === $data['error'] ) {
				throw new Exception( $data['reason'] ?? __( 'Invalid client credentials provided.', 'doublescale' ) );
			}
			throw new Exception( $data['reason'] ?? (string) $data['error'] );
		}

		if ( ! isset( $data['access_token'], $data['token_type'], $data['expires_in'] ) ) {
			throw new Exception( __( 'Invalid token response format from Zoom.', 'doublescale' ) );
		}

		if ( ! isset( $data['api_url'] ) ) {
			$data['api_url'] = 'https://api.zoom.us';
		}

		return $data;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( $request ) {
		try {
			$settings    = $request->get_param( 'settings' ) ?? array();
			$calendar_id = $settings['calendar_id'] ?? '';

			if ( empty( $calendar_id ) ) {
				return new WP_Error( 'rest_invalid_request', __( 'Calendar ID is required.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$calendar = CalendarModel::find( $calendar_id );
			if ( empty( $calendar ) ) {
				return new WP_Error( 'rest_invalid_request', __( 'Calendar not found.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$calendar->update_meta( $this->integration->meta_key, array() );

			return new WP_REST_Response( null, 204 );
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}
}
