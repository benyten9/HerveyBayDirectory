<?php
/**
 * Account_Controller class.
 *
 * @since 1.4.10
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Emailit\REST;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Account_Controller as Abstract_Account_Controller;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Traits\Account_Controller_Creatable;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Traits\Account_Controller_Gettable;
use DoubleScale\Modules\Smtp\Providers\Emailit\Account_API;

/**
 * Account_Controller class.
 *
 * @since 1.4.10
 */
class Account_Controller extends Abstract_Account_Controller {
	use Account_Controller_Gettable;
	use Account_Controller_Creatable;

	/**
	 * Register controller routes
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
	 * @return array
	 */
	protected function get_credentials_schema() {
		return array(
			'api_key' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
	}

	/**
	 * Get account id & name, after checking the key with Emailit.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array|WP_Error array of id & name if success.
	 */
	protected function get_account_info( $request ) {
		$credentials = $request->get_param( 'credentials' );
		$api_key     = $credentials['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			return new WP_Error( 'doublescale_smtp_emailit_api_key_missing', __( 'API key is missing.', 'doublescale' ) );
		}

		$verified = ( new Account_API( $api_key ) )->verify();
		if ( is_wp_error( $verified ) ) {
			// 400, not Emailit's own 401: a 401 from our REST route reads as "WordPress session expired".
			return new WP_Error( 'doublescale_smtp_emailit_api_key_invalid', $verified->get_error_message(), array( 'status' => 400 ) );
		}

		return array(
			'id'   => $request->get_param( 'id' ),
			'name' => $request->get_param( 'name' ),
		);
	}
}
