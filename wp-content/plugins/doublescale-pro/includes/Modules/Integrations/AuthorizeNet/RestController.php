<?php
/**
 * Authorize.Net REST controller.
 *
 * @package DoubleScale\Pro\Modules\Integrations\AuthorizeNet
 */

namespace DoubleScale\Pro\Modules\Integrations\AuthorizeNet;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\AuthorizeNetInvoiceWebhookHandler;

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
			'/integrations/authorize-net/connect',
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

		( new Webhook() )->register_routes();
		( new HostedFormRedirect() )->register_routes();
	}

	/**
	 * Authorize.Net has no webhook-registration API usable with the standard
	 * transaction key, so connect only validates credentials and reports what
	 * still needs doing in the dashboard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect_account( \WP_REST_Request $request ) {
		$mode     = (string) $request->get_param( 'mode' );
		$settings = $this->integration->get_settings();

		$login_id        = (string) ( $settings[ "{$mode}_login_id" ] ?? '' );
		$transaction_key = (string) ( $settings[ "{$mode}_transaction_key" ] ?? '' );

		if ( '' === $login_id || '' === $transaction_key ) {
			return new \WP_Error(
				'missing_credentials',
				__( 'Save the Authorize.Net API login ID and transaction key for this mode before connecting.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$validated = $this->integration->validate(
			array_merge( $settings, array( 'mode' => $mode ) )
		);
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error(
				'authorize_net_connect_failed',
				$validated->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$has_signature_key = '' !== (string) ( $settings[ "{$mode}_signature_key" ] ?? '' );

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'mode'              => $mode,
				'webhook_url'       => Webhook::notification_url(),
				'webhook_events'    => AuthorizeNetInvoiceWebhookHandler::default_webhook_events(),
				'has_signature_key' => $has_signature_key,
				'message'           => $has_signature_key
					? ''
					: __(
						'Credentials validated. Add the webhook URL in the Authorize.Net dashboard, then paste the Signature Key here — refunds and payments completed outside the browser are not recorded without it.',
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
				'enum'        => array( 'sandbox', 'production' ),
				'arg_options' => $arg,
			),
			'account_currency' => array(
				'label'       => __( 'Merchant account currency', 'doublescale' ),
				'type'        => 'string',
				'arg_options' => $arg,
			),
		);

		foreach ( array( 'sandbox', 'production' ) as $mode ) {
			foreach ( array( 'login_id', 'transaction_key', 'signature_key' ) as $field ) {
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
