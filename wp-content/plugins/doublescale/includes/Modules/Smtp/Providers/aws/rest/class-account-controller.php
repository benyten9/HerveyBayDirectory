<?php
/**
 * Account_Controller class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Aws\REST;

use WP_Error;
use WP_REST_Request;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Account_Controller as Abstract_Account_Controller;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Traits\Account_Controller_Creatable;
use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Traits\Account_Controller_Gettable;
use DoubleScale\Modules\Smtp\Providers\Aws\Ses_Client;
use DoubleScale\Modules\Smtp\Providers\Aws\Ses_Exception;
use DoubleScale\Modules\Smtp\Providers\Aws\Ses_Query_Client;

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-ses-query-client.php';

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
			'access_key' => array(
				'type'     => 'string',
				'required' => true,
			),
			'secret_key' => array(
				'type'     => 'string',
				'required' => true,
			),
			'region'     => array(
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
		$access_key   = $credentials['access_key'] ?? '';
		$secret_key   = $credentials['secret_key'] ?? '';
		$region       = $credentials['region'] ?? '';
		$account_name = $request->get_param( 'name' );
		$account_id   = $request->get_param( 'id' );

		if ( empty( $account_name ) ) {
			return new WP_Error( 'doublescale_smtp_aws_account_name_missing', __( 'Account name is missing.', 'doublescale' ) );
		}

		if ( empty( $access_key ) ) {
			return new WP_Error( 'doublescale_smtp_aws_access_key_missing', __( 'Access key is missing.', 'doublescale' ) );
		}

		if ( empty( $secret_key ) ) {
			return new WP_Error( 'doublescale_smtp_aws_secret_key_missing', __( 'Secret key is missing.', 'doublescale' ) );
		}

		if ( empty( $region ) ) {
			return new WP_Error( 'doublescale_smtp_aws_region_missing', __( 'Region is missing.', 'doublescale' ) );
		}

		try {
			$ses = new Ses_Client(
				new Ses_Query_Client(
					$access_key,
					$secret_key,
					$region
				)
			);
			$ses->listIdentities(
				array(
					'IdentityType' => 'EmailAddress',
				)
			);

			return array(
				'id'   => $account_id,
				'name' => $account_name,
			);
		} catch ( Ses_Exception $e ) {
			return new WP_Error( 'doublescale_smtp_aws_error', $e->getMessage() );
		}
	}
}
