<?php
/**
 * Class RestWebhookController
 *
 * Public inbound webhook endpoint for Pro automations. Lives in Pro because
 * the `webhook_received` trigger that consumes it is Pro-only — the free
 * plugin has no consumer for the action this controller fires.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * RestWebhookController class.
 */
class RestWebhookController extends RestController {

	/**
	 * REST base — kept as `automations` so the public route stays at
	 * `/wp-json/doublescale/v1/automations/webhook`, matching webhook URLs
	 * already configured by Pro users in third-party tools (Zapier, etc.).
	 *
	 * @var string
	 */
	protected $rest_base = 'automations';

	/**
	 * Register routes.
	 *
	 * Public endpoint by design: this is the inbound HTTP webhook that
	 * third-party services POST to in order to trigger a DoubleScale
	 * automation. Caller cannot present a WP cookie/nonce because it is
	 * not a logged-in WordPress user. Authentication is enforced inside
	 * receive_webhook(): the request must include the matching
	 * `doublescale_key` (a per-automation secret generated when the
	 * automation is created); requests with a missing or wrong key are
	 * rejected.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/webhook',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_webhook' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'doublescale_id'  => array(
							'description' => __( 'The automation ID.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'doublescale_key' => array(
							'description' => __( 'The automation key.', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Receive webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive_webhook( $request ) {
		$webhook_id  = $request->get_param( 'doublescale_id' );
		$request_key = $request->get_param( 'doublescale_key' );
		$params      = $request->get_params();
		unset( $params['doublescale_id'] );
		unset( $params['doublescale_key'] );

		$automation = AutomationModel::find( $webhook_id );
		if ( ! $automation ) {
			return new WP_Error( 'not_found', __( 'Automation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$stored_key = $automation->get_setting( 'webhook_key' );
		if ( ! $stored_key || ! hash_equals( $stored_key, $request_key ) ) {
			return new WP_Error( 'unauthorized', __( 'Unauthorized.', 'doublescale' ), array( 'status' => 401 ) );
		}

		do_action( 'doublescale_webhook_receive', $automation, $params );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Webhook received.',
			),
			200
		);
	}
}
