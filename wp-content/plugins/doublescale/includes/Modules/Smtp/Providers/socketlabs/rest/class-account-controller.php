<?php
/**
 * Account_Controller class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SocketLabs\REST;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Account_Controller as Abstract_Account_Controller;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Traits\Account_Controller_Creatable;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Traits\Account_Controller_Gettable;

/**
 * Account_Controller class.
 *
 * @since 1.0.0
 */
class Account_Controller extends Abstract_Account_Controller {
	use Account_Controller_Gettable;
	use Account_Controller_Creatable;

	/**
	 * Register controller routes
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		$this->register_gettable_route();
		$this->register_creatable_route();
	}

	/**
	 * Get credentials schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_credentials_schema() {
		return array(
			'api_key'   => array(
				'type'     => 'string',
				'required' => true,
			),
			'server_id' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
	}

	/**
	 * Get account id & name
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array|WP_Error array of id & name if success.
	 */
	protected function get_account_info( $request ) {
		$credentials  = $request->get_param( 'credentials' );
		$api_key      = $credentials['api_key'] ?? '';
		$server_id    = $credentials['server_id'] ?? '';
		$account_name = $request->get_param( 'name' );
		$account_id   = $request->get_param( 'id' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'doublescale_smtp_socketlabs_api_key_missing', __( 'API key is missing.', 'doublescale' ) );
		}

		if ( empty( $server_id ) ) {
			return new WP_Error( 'doublescale_smtp_socketlabs_server_id_missing', __( 'Server ID is missing.', 'doublescale' ) );
		}

		$response = wp_remote_request(
			'https://inject.socketlabs.com/api/v1/email',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'serverId' => $server_id,
						'Messages' => array(
							array(
								'To'       => array( array( 'emailAddress' => 'validate@localhost' ) ),
								'From'     => array( 'emailAddress' => 'validate@localhost' ),
								'Subject'  => 'validation',
								'TextBody' => 'validation',
							),
						),
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$error_code    = $response_body['ErrorCode'] ?? '';

		if ( 401 === $status_code || 403 === $status_code || 'InvalidAuthentication' === $error_code ) {
			return new WP_Error( 'doublescale_smtp_socketlabs_invalid_credentials', __( 'Invalid API key or Server ID.', 'doublescale' ) );
		}

		return array(
			'id'   => $account_id,
			'name' => $account_name,
		);
	}
}
