<?php
/**
 * Razorpay REST controller.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Razorpay
 */

namespace DoubleScale\Pro\Modules\Integrations\Razorpay;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\RazorpayInvoiceWebhookHandler;

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
			'/integrations/razorpay/connect',
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
	 * Razorpay has no webhook-registration API — the webhook and its secret are
	 * created by hand in the dashboard — so connect only validates credentials
	 * and reports what still needs doing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect_account( \WP_REST_Request $request ) {
		$mode     = (string) $request->get_param( 'mode' );
		$settings = $this->integration->get_settings();

		$key_id     = (string) ( $settings[ "{$mode}_key_id" ] ?? '' );
		$key_secret = (string) ( $settings[ "{$mode}_key_secret" ] ?? '' );

		if ( '' === $key_id || '' === $key_secret ) {
			return new \WP_Error(
				'missing_credentials',
				__( 'Save the Razorpay key ID and secret for this mode before connecting.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$validated = $this->integration->validate(
			array_merge( $settings, array( 'mode' => $mode ) )
		);
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error(
				'razorpay_connect_failed',
				$validated->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$has_webhook_secret = '' !== (string) ( $settings[ "{$mode}_webhook_secret" ] ?? '' );

		return new \WP_REST_Response(
			array(
				'success'            => true,
				'mode'               => $mode,
				'webhook_url'        => Webhook::notification_url(),
				'webhook_events'     => RazorpayInvoiceWebhookHandler::default_webhook_events(),
				'has_webhook_secret' => $has_webhook_secret,
				'message'            => $has_webhook_secret
					? ''
					: __(
						'Credentials validated. Add the webhook URL in the Razorpay Dashboard, then paste the webhook secret you chose here — refunds and out-of-band payments are not recorded without it.',
						'doublescale'
					),
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
			foreach ( array( 'key_id', 'key_secret', 'webhook_secret' ) as $field ) {
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
}
