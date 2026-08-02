<?php
/**
 * Account_Controller class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Mailjet\REST;

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
			'api_key'    => array(
				'type'     => 'string',
				'required' => true,
			),
			'secret_key' => array(
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
		$secret_key   = $credentials['secret_key'] ?? '';
		$account_name = $request->get_param( 'name' );
		$account_id   = $request->get_param( 'id' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'doublescale_smtp_mailjet_api_key_missing', __( 'API key is missing.', 'doublescale' ) );
		}

		if ( empty( $secret_key ) ) {
			return new WP_Error( 'doublescale_smtp_mailjet_secret_key_missing', __( 'Secret key is missing.', 'doublescale' ) );
		}

		return array(
			'id'   => $account_id,
			'name' => $account_name,
		);
	}
}
