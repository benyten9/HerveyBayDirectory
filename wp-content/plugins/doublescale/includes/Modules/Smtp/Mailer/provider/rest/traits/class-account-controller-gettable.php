<?php
/**
 * Account_Controller_Gettable trait.
 *
 * @since 1.0.0
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Mailer\Provider\REST\Traits;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Provider;
use DoubleScale\Modules\Smtp\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Account_Controller_Gettable trait.
 *
 * @since 1.0.0
 *
 * @property Provider $provider
 */
trait Account_Controller_Gettable {

	/**
	 * Register gettable route
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function register_gettable_route() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[^\/\?]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Retrieves one item from the collection.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$id = $request->get_param( 'id' );

		if ( isset( $this->provider->accounts_remote_data ) && $this->provider->accounts_remote_data ) {
			$data = $this->provider->accounts_remote_data->get( $id, true );
		} else {
			$account_data = $this->provider->accounts->get_account_data( $id );
			if ( ! $account_data ) {
				return new WP_Error(
					'doublescale_smtp_account_not_found',
					__( 'Account not found.', 'doublescale' ),
					array( 'status' => 404 )
				);
			}
			$data = array(
				'id'   => $id,
				'name' => $account_data['name'] ?? '',
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to get a specific item.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access for the item, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		return Settings::user_can_manage_smtp_rest();
	}
}
