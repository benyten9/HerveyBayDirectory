<?php
/**
 * Mollie REST controller.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Mollie
 */

namespace DoubleScale\Pro\Modules\Integrations\Mollie;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;

defined( 'ABSPATH' ) || exit;

/**
 * RestController class.
 */
class RestController extends RestIntegrationController {

	/**
	 * @return void
	 */
	public function register_additional_routes(): void {
		register_rest_route(
			$this->namespace,
			'/integrations/mollie/connect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect_account' ),
				'permission_callback' => array( $this, 'update_permissions_check' ),
				'args'                => array(
					'mode' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'test', 'live' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		( new Webhook() )->register_routes();
	}

	/**
	 * Mollie has no webhook registration API — the webhook URL is passed on
	 * each payment — so connect only validates the key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect_account( \WP_REST_Request $request ) {
		$mode     = (string) $request->get_param( 'mode' );
		$settings = $this->integration->get_settings();

		$api_key = (string) ( $settings[ "{$mode}_api_key" ] ?? '' );
		if ( '' === $api_key ) {
			return new \WP_Error(
				'missing_credentials',
				__( 'Save the Mollie API key for this mode before connecting.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$validated = $this->integration->validate(
			array_merge( $settings, array( 'mode' => $mode ) )
		);
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error(
				'mollie_connect_failed',
				$validated->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$methods     = ( new Api( $api_key ) )->list_methods();
		$method_names = array();
		if ( $methods['success'] ) {
			foreach ( (array) ( $methods['data']['_embedded']['methods'] ?? array() ) as $method ) {
				if ( is_array( $method ) && ! empty( $method['description'] ) ) {
					$method_names[] = (string) $method['description'];
				}
			}
		}

		$webhook_url = Webhook::notification_url();
		$is_local    = $this->is_local_webhook_url( $webhook_url );

		return new \WP_REST_Response(
			array(
				'success'     => true,
				'mode'        => $mode,
				'methods'     => $method_names,
				'webhook_url' => $is_local ? '' : $webhook_url,
				'message'     => $is_local
					? __(
						'Credentials validated. This site is not publicly reachable, so Mollie webhooks are skipped — payments are confirmed when the customer returns from checkout.',
						'doublescale'
					)
					: '',
			),
			200
		);
	}

	/**
	 * @return array
	 */
	public function get_settings_schema() {
		$arg = array( 'sanitize_callback' => 'sanitize_text_field' );

		$properties = array(
			'mode' => array(
				'label'       => __( 'Mode', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'test', 'live' ),
				'arg_options' => $arg,
			),
		);

		foreach ( array( 'test', 'live' ) as $mode ) {
			$properties[ "{$mode}_api_key" ] = array(
				'label'       => sprintf( '%s API key', ucfirst( $mode ) ),
				'type'        => 'string',
				'arg_options' => $arg,
			);
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * @param string $url Webhook URL.
	 * @return bool
	 */
	private function is_local_webhook_url( string $url ): bool {
		$host = \wp_parse_url( $url, PHP_URL_HOST );
		if ( ! \is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = \strtolower( $host );

		return \in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| \str_ends_with( $host, '.local' );
	}
}
