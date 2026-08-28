<?php
/**
 * Square REST controller.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Square
 */

namespace DoubleScale\Pro\Modules\Integrations\Square;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\SquareInvoiceWebhookHandler;

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
			'/integrations/square/connect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect_account' ),
				'permission_callback' => array( $this, 'update_permissions_check' ),
				'args'                => array(
					'mode' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'sandbox', 'production' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/square/locations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_locations' ),
				'permission_callback' => array( $this, 'update_permissions_check' ),
				'args'                => array(
					'mode' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'sandbox', 'production' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		( new Webhook() )->register_routes();
	}

	/**
	 * Locations for the saved credentials, so the UI can offer a picker.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_locations( \WP_REST_Request $request ) {
		$mode = (string) $request->get_param( 'mode' );

		return new \WP_REST_Response(
			array( 'locations' => Integration::instance()->get_locations( $mode ) ),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect_account( \WP_REST_Request $request ) {
		$mode     = (string) $request->get_param( 'mode' );
		$settings = $this->integration->get_settings();

		$access_token = $settings[ "{$mode}_access_token" ] ?? '';
		if ( '' === $access_token ) {
			return new \WP_Error(
				'missing_credentials',
				__( 'Save the Square access token for this mode before connecting.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$api       = new Api( $access_token, $mode );
		$locations = $api->list_locations();
		if ( ! $locations['success'] ) {
			return new \WP_Error( 'square_connect_failed', $locations['message'] ?? '', array( 'status' => 400 ) );
		}

		$webhook_url         = Webhook::notification_url();
		$existing_signature  = $settings[ "{$mode}_signature_key" ] ?? '';

		if ( $this->is_local_webhook_url( $webhook_url ) ) {
			if ( '' === $existing_signature ) {
				return new \WP_Error(
					'square_webhook_local_dev',
					__(
						'Local sites cannot auto-register Square webhooks. Create a webhook subscription in the Square Developer Dashboard, paste the signature key, save, then retry.',
						'doublescale'
					),
					array( 'status' => 400 )
				);
			}

			return new \WP_REST_Response(
				array(
					'success'     => true,
					'mode'        => $mode,
					'webhook_url' => $webhook_url,
					'message'     => __(
						'Credentials validated. Using your saved signature key for local delivery.',
						'doublescale'
					),
				),
				200
			);
		}

		$subscription = $api->create_webhook_subscription(
			$webhook_url,
			/**
			 * Square webhook events for invoice online payments.
			 *
			 * @param string[] $events Default event list.
			 */
			apply_filters(
				'doublescale_square_webhook_events',
				SquareInvoiceWebhookHandler::default_webhook_events()
			)
		);

		if ( ! $subscription['success'] ) {
			return new \WP_Error( 'square_webhook_create_failed', $subscription['message'] ?? '', array( 'status' => 400 ) );
		}

		$settings[ "{$mode}_subscription_id" ] = $subscription['id'] ?? '';
		// Square returns the signature key exactly once, at creation.
		if ( ! empty( $subscription['signature_key'] ) ) {
			$settings[ "{$mode}_signature_key" ] = $subscription['signature_key'];
		}
		$this->integration->update_settings( $settings );

		return new \WP_REST_Response(
			array(
				'success'         => true,
				'mode'            => $mode,
				'subscription_id' => $subscription['id'] ?? '',
				'webhook_url'     => $webhook_url,
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
				'enum'        => array( 'sandbox', 'production' ),
				'arg_options' => $arg,
			),
		);

		foreach ( array( 'sandbox', 'production' ) as $mode ) {
			foreach ( array( 'access_token', 'location_id', 'signature_key', 'subscription_id' ) as $field ) {
				$properties[ "{$mode}_{$field}" ] = array(
					'label'       => sprintf( '%s %s', ucfirst( $mode ), str_replace( '_', ' ', $field ) ),
					'type'        => 'string',
					'arg_options' => $arg,
				);
			}
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
