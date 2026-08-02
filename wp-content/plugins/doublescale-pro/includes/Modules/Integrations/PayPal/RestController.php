<?php
/**
 * PayPal REST controller.
 *
 * @package DoubleScale\Pro\Modules\Integrations\PayPal
 */

namespace DoubleScale\Pro\Modules\Integrations\PayPal;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\PayPalInvoiceWebhookHandler;

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
			'/integrations/paypal/connect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect_account' ),
				'permission_callback' => array( $this, 'update_permissions_check' ),
				'args'                => array(
					'mode' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'sandbox', 'live' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		( new Webhook() )->register_routes();
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect_account( \WP_REST_Request $request ) {
		$mode     = $request->get_param( 'mode' );
		$settings = $this->integration->get_settings();

		$client_id = $settings[ "{$mode}_client_id" ] ?? '';
		$secret    = $settings[ "{$mode}_secret" ] ?? '';
		if ( '' === $client_id || '' === $secret ) {
			return new \WP_Error(
				'missing_credentials',
				__( 'Save the PayPal client ID and secret for this mode before connecting.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$api    = new Api( $client_id, $secret, $mode );
		$token  = $api->get_access_token();
		if ( ! $token['success'] ) {
			return new \WP_Error( 'paypal_connect_failed', $token['message'] ?? '', array( 'status' => 400 ) );
		}

		if ( \defined( 'DOUBLESCALE_PUBLIC_REST_URL' ) && \DOUBLESCALE_PUBLIC_REST_URL ) {
			$webhook_url = \trailingslashit( \DOUBLESCALE_PUBLIC_REST_URL ) . 'doublescale/v1/integrations/paypal/webhook';
		} else {
			$webhook_url = \rest_url( 'doublescale/v1/integrations/paypal/webhook' );
		}

		$existing_webhook_id = $settings[ "{$mode}_webhook_id" ] ?? '';

		if ( $this->is_local_webhook_url( $webhook_url ) ) {
			if ( '' === $existing_webhook_id ) {
				return new \WP_Error(
					'paypal_webhook_local_dev',
					__(
						'Local sites cannot auto-register PayPal webhooks. Create a webhook in the PayPal Developer Dashboard, paste the webhook ID, save, then retry.',
						'doublescale'
					),
					array( 'status' => 400 )
				);
			}

			return new \WP_REST_Response(
				array(
					'success'     => true,
					'mode'        => $mode,
					'webhook_id'  => $existing_webhook_id,
					'webhook_url' => $webhook_url,
					'message'     => __(
						'Credentials validated. Using your saved webhook ID for local delivery.',
						'doublescale'
					),
				),
				200
			);
		}

		$webhook = $api->create_webhook(
			$webhook_url,
			/**
			 * PayPal webhook events for invoice online payments.
			 *
			 * @param string[] $events Default event list.
			 */
			apply_filters(
				'doublescale_paypal_webhook_events',
				PayPalInvoiceWebhookHandler::default_webhook_events()
			)
		);

		if ( ! $webhook['success'] ) {
			return new \WP_Error( 'paypal_webhook_create_failed', $webhook['message'] ?? '', array( 'status' => 400 ) );
		}

		$settings[ "{$mode}_webhook_id" ] = $webhook['id'];
		$this->integration->update_settings( $settings );

		return new \WP_REST_Response(
			array(
				'success'     => true,
				'mode'        => $mode,
				'webhook_id'  => $webhook['id'],
				'webhook_url' => $webhook_url,
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
				'enum'        => array( 'sandbox', 'live' ),
				'arg_options' => $arg,
			),
		);

		foreach ( array( 'sandbox', 'live' ) as $mode ) {
			foreach ( array( 'client_id', 'secret', 'webhook_id' ) as $field ) {
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
