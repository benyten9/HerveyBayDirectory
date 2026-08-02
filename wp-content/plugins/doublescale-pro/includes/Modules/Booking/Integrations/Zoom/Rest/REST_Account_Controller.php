<?php

/**
 * Class REST Integration Account Controller
 *
 * This class is responsible for handling the Integration Account REST API
 *
 * @since 1.0.0
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Rest;

use DoubleScale\Modules\Booking\Integration\Rest\REST_Account_Controller as Abstract_REST_Account_Controller;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use Exception;
use Illuminate\Support\Arr;
use DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Api;

/**
 * Rest Integration Account Controller
 */
class REST_Account_Controller extends Abstract_REST_Account_Controller {

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		 parent::register_routes();

		// Do not use get_endpoint_args_for_item_schema() here: WordPress REST JSON-Schema
		// validation rejects valid bodies (nested object + string types) with a generic
		// "Invalid parameter(s): app_credentials". Credentials are validated in create_item().
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test-connection',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Get item schema
	 *
	 * @return array
	 */
	public function get_item_schema() {
		 return array(
			 'type'       => 'object',
			 'properties' => array(
				 'id'              => array(
					 'type'        => array( 'integer', 'string' ),
					 'description' => \__( 'Unique identifier for the object.', 'doublescale' ),
					 'context'     => array( 'view', 'edit', 'embed' ),
					 'readonly'    => false,
				 ),
				 'name'            => array(
					 'type'        => 'string',
					 'description' => \__( 'Name of the account.', 'doublescale' ),
					 'context'     => array( 'view', 'edit', 'embed' ),
					 'required'    => false,
				 ),
				 'app_credentials' => array(
					 'type'                 => 'object',
					 'description'          => \__( 'Credentials for the account.', 'doublescale' ),
					 'context'              => array( 'view', 'edit', 'embed' ),
					 'required'             => true,
					 'properties'           => array(
						 'account_id'    => array(
							 'type'        => 'string',
							 'description' => \__( 'Account ID.', 'doublescale' ),
							 'context'     => array( 'view', 'edit', 'embed' ),
							 'required'    => true,
						 ),
						 'client_id'     => array(
							 'type'        => 'string',
							 'description' => \__( 'Client ID.', 'doublescale' ),
							 'context'     => array( 'view', 'edit', 'embed' ),
							 'required'    => false,
						 ),
						 'client_secret' => array(
							 'type'        => 'string',
							 'description' => \__( 'Secret Key.', 'doublescale' ),
							 'context'     => array( 'view', 'edit', 'embed' ),
							 'required'    => true,
						 ),
					 ),
					 'additionalProperties' => true,
				 ),
				 'tokens'          => array(
					 'type'                 => 'object',
					 'description'          => \__( 'Credentials for the account.', 'doublescale' ),
					 'context'              => array( 'view', 'edit', 'embed' ),
					 'required'             => false,
					 'properties'           => array(
						 'access_token'  => array(
							 'type'        => 'string',
							 'description' => \__( 'Access token for the account.', 'doublescale' ),
							 'context'     => array( 'view', 'edit', 'embed' ),
							 'required'    => true,
						 ),
						 'refresh_token' => array(
							 'type'        => 'string',
							 'description' => \__( 'Refresh token for the account.', 'doublescale' ),
							 'context'     => array( 'view', 'edit', 'embed' ),
							 'required'    => true,
						 ),
					 ),
					 'additionalProperties' => true,
				 ),
				 'config'          => array(
					 'type'                 => 'object',
					 'description'          => \__( 'Configuration for the account.', 'doublescale' ),
					 'context'              => array( 'view', 'edit', 'embed' ),
					 'required'             => false,
					 'additionalProperties' => true,
				 ),
			 ),
		 );
	}

	/**
	 * Trim and stringify Zoom credential fields from JSON (numbers from some clients break strict checks).
	 *
	 * @param array<string, mixed> $params Request params.
	 * @return array<string, mixed>
	 */
	private function normalize_zoom_credential_params( array $params ) {
		if ( ! isset( $params['app_credentials'] ) || ! is_array( $params['app_credentials'] ) ) {
			return $params;
		}
		foreach ( array( 'account_id', 'client_id', 'client_secret' ) as $key ) {
			if ( ! array_key_exists( $key, $params['app_credentials'] ) ) {
				continue;
			}
			$val = $params['app_credentials'][ $key ];
			if ( null === $val ) {
				$params['app_credentials'][ $key ] = '';
				continue;
			}
			if ( is_scalar( $val ) ) {
				$params['app_credentials'][ $key ] = trim( (string) $val );
			}
		}
		return $params;
	}

	/**
	 * Create item
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$params = $this->normalize_zoom_credential_params( $request->get_params() );

		if ( ! isset( $params['app_credentials'] ) || ! is_array( $params['app_credentials'] ) ) {
			return new WP_Error(
				'missing_app_credentials',
				\__(
					'Your Zoom credentials were not received. Please enter Account ID, Client ID, and Secret Key in all three fields, then click save again. If this keeps happening, refresh the page.',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		$account_id    = Arr::get( $params, 'app_credentials.account_id' );
		$client_id     = Arr::get( $params, 'app_credentials.client_id' );
		$client_secret = Arr::get( $params, 'app_credentials.client_secret' );
		$host_id       = $request->get_param( 'calendar_id' );

		if ( '' === $account_id || null === $account_id ) {
			return new WP_Error(
				'missing_account_id',
				\__(
					'Please enter your Zoom Account ID. In the Zoom Marketplace, open your Server-to-Server OAuth app and copy the value labeled “Account ID”.',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		if ( '' === $client_id || null === $client_id ) {
			return new WP_Error(
				'missing_client_id',
				\__(
					'Please enter your Zoom Client ID. In the Zoom Marketplace, open the same Server-to-Server OAuth app and copy “Client ID”.',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		if ( '' === $client_secret || null === $client_secret ) {
			return new WP_Error(
				'missing_client_secret',
				\__(
					'Please enter your Zoom Client Secret. In the Zoom Marketplace, open the same app and copy “Client Secret” (you may need to reveal or regenerate it).',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $host_id ) ) {
			return new WP_Error(
				'missing_calendar_id',
				\__(
					'The calendar could not be determined from the request URL. Go back to the calendar’s Integrations tab and try connecting Zoom again.',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		$this->integration->set_host( $host_id );
		$app_credentials = array(
			'account_id'    => $account_id,
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);

		try {
			$data         = $this->get_tokens( $app_credentials );
			$account_data = $this->validate_zoom_token_and_user( $data );

			$name         = Arr::get( $account_data, 'data.email', '' );
			$storage_key  = $this->zoom_meta_account_key( $account_id );
			$account_exists = $this->integration->accounts->get_account( $storage_key );

			if ( $account_exists ) {
				$this->integration->accounts->update_account(
					$storage_key,
					array(
						'name'            => $name,
						'tokens'          => $data,
						'app_credentials' => $app_credentials,
						'config'          => array(),
					)
				);
			} else {
				$this->integration->accounts->add_account(
					$storage_key,
					array(
						'name'            => $name,
						'tokens'          => $data,
						'app_credentials' => $app_credentials,
						'config'          => array(),
					)
				);
			}

			return new WP_REST_Response(
				array(
					'saved'              => true,
					'storage_account_id' => $storage_key,
					'account_email'      => $name,
					'zoom_user_id'       => Arr::get( $account_data, 'data.id', '' ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'add_zoom_account_error',
				$this->zoom_connection_error_message( $e ),
				array( 'status' => 400 )
			);
		}
	}

	/**
	 * User-facing summary when Zoom token or user lookup fails.
	 */
	private function zoom_connection_error_message( Exception $e ): string {
		$raw = trim( $e->getMessage() );
		if ( '' === $raw ) {
			return \__(
				'Zoom did not accept these credentials. Double-check Account ID, Client ID, and Secret from the same Server-to-Server OAuth app, then try again.',
				'doublescale'
			);
		}
		return sprintf(
			/* translators: %s: technical detail from Zoom or the server */
			\__( 'Could not connect to Zoom: %s', 'doublescale' ),
			$raw
		);
	}

	/**
	 * Validate Server-to-Server OAuth credentials against Zoom (token + users/me) without persisting.
	 *
	 * POST {@see self::$rest_base}/test-connection — same JSON body as create (app_credentials).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection( WP_REST_Request $request ) {
		$params = $this->normalize_zoom_credential_params( $request->get_params() );

		if ( ! isset( $params['app_credentials'] ) || ! is_array( $params['app_credentials'] ) ) {
			return new WP_Error(
				'missing_app_credentials',
				\__(
					'Your Zoom credentials were not received. Please enter Account ID, Client ID, and Secret Key, then try again.',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		$account_id    = Arr::get( $params, 'app_credentials.account_id' );
		$client_id     = Arr::get( $params, 'app_credentials.client_id' );
		$client_secret = Arr::get( $params, 'app_credentials.client_secret' );
		$host_id       = $request->get_param( 'calendar_id' );

		if ( '' === $account_id || null === $account_id || '' === $client_id || null === $client_id || '' === $client_secret || null === $client_secret ) {
			return new WP_Error(
				'rest_invalid_request',
				\__(
					'Please fill in all three fields: Zoom Account ID, Client ID, and Client Secret (from the same Server-to-Server OAuth app).',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $host_id ) ) {
			return new WP_Error(
				'missing_calendar_id',
				\__(
					'The calendar could not be determined from the request URL. Open this host’s calendar integrations page and try again.',
					'doublescale'
				),
				array( 'status' => 400 )
			);
		}

		$this->integration->set_host( $host_id );
		$app_credentials = array(
			'account_id'    => $account_id,
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);

		try {
			$data         = $this->get_tokens( $app_credentials );
			$account_data = $this->validate_zoom_token_and_user( $data );

			return new WP_REST_Response(
				array(
					'valid'         => true,
					'account_email' => Arr::get( $account_data, 'data.email', '' ),
					'zoom_user_id'  => Arr::get( $account_data, 'data.id', '' ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'zoom_test_connection_failed',
				$this->zoom_connection_error_message( $e ),
				array( 'status' => 400 )
			);
		}
	}

	/**
	 * Meta storage key for an account row (must match {@see \DoubleScale\Modules\Booking\Integration\Accounts::add_account()}).
	 *
	 * @param string|int $zoom_account_id Zoom "Account ID" from the Marketplace app.
	 * @return int|string
	 */
	private function zoom_meta_account_key( $zoom_account_id ) {
		if ( is_numeric( (string) $zoom_account_id ) ) {
			return (int) $zoom_account_id;
		}
		return abs( (int) crc32( (string) $zoom_account_id ) );
	}

	/**
	 * @param array<string, mixed> $token_data Response from Zoom OAuth token endpoint.
	 * @return array<string, mixed> Result of {@see Api::get_account_info()}.
	 * @throws Exception When token or Zoom user payload is invalid.
	 */
	private function validate_zoom_token_and_user( array $token_data ) {
		if ( empty( $token_data['access_token'] ) ) {
			throw new Exception(
				\__(
					'Zoom did not return an access token. Usually that means the Account ID, Client ID, or Secret does not match your Server-to-Server OAuth app.',
					'doublescale'
				)
			);
		}

		$api          = new Api( $token_data['access_token'], $this->integration );
		$account_data = $api->get_account_info();

		if ( empty( $account_data['success'] ) || empty( $account_data['data'] ) || ! is_array( $account_data['data'] ) ) {
			throw new Exception(
				\__(
					'Zoom accepted a token but we could not load your Zoom user profile. Check that the app has the “user:read:user_info” scope (or full user read) enabled, then try again.',
					'doublescale'
				)
			);
		}

		return $account_data;
	}

	/**
	 * Create item permissions check
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return current_user_can( 'manage_options' ) || current_user_can( 'doublescale_booking_manage_own_calendars' );
	}

	/**
	 * Get tokens
	 *
	 * @param array $app_credentials App credentials.
	 * @return array
	 */
	private function get_tokens( $app_credentials ) {
		$response = \wp_remote_post(
			'https://zoom.us/oauth/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'grant_type' => 'account_credentials',
					'account_id' => Arr::get( $app_credentials, 'account_id' ),
				),
				'headers' => array(
					'Authorization' => 'Basic ' . \base64_encode( Arr::get( $app_credentials, 'client_id' ) . ':' . Arr::get( $app_credentials, 'client_secret' ) ),
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new Exception(
				sprintf(
					/* translators: %d: HTTP status code from Zoom */
					\__( 'Zoom OAuth token request failed (HTTP %d).', 'doublescale' ),
					$code
				)
			);
		}

		$body = \wp_remote_retrieve_body( $response );
		$data = \json_decode( $body, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			throw new Exception( \__( 'Invalid response from Zoom.', 'doublescale' ) );
		}

		if ( isset( $data['error'] ) ) {
			$code   = (string) $data['error'];
			$reason = isset( $data['reason'] ) ? (string) $data['reason'] : $code;
			if ( 'invalid_client' === $code ) {
				throw new Exception(
					\__(
						'Zoom rejected the Client ID or Client Secret. Copy them again from your Server-to-Server OAuth app (Credentials tab).',
						'doublescale'
					)
				);
			}
			if ( 'invalid_request' === $code && false !== strpos( strtolower( $reason ), 'account' ) ) {
				throw new Exception(
					\__(
						'Zoom rejected the Account ID. It must be the “Account ID” from the same Server-to-Server OAuth app (not your Zoom sign-in email).',
						'doublescale'
					)
				);
			}
			throw new Exception(
				sprintf(
					/* translators: %s: message returned by Zoom */
					\__( 'Zoom returned an error: %s', 'doublescale' ),
					$reason
				)
			);
		}

		return $data;
	}
}
