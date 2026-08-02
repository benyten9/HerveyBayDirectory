<?php
/**
 * Typeform REST controller (integration settings + inbound webhook).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Typeform;

use DoubleScale\Modules\Forms\Services\FormsManager;
use DoubleScale\Pro\Modules\Forms\Typeform\Form as TypeformForm;
use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Typeform REST controller.
 */
class RestController extends RestIntegrationController {

	/**
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/webhook",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'verify_webhook_signature' ),
			)
		);
	}

	/**
	 * Integration settings: personal access token only.
	 *
	 * @return array
	 */
	public function get_settings_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'access_token' => array(
					'label'       => __( 'Personal access token', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
					'description' => __( 'Create one in Typeform → Account settings → Personal tokens. Then add a form under Forms → SaaS Forms → Typeform.', 'doublescale' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$new_settings = $request->get_param( 'settings' ) ?? array();

		if ( empty( $new_settings ) ) {
			return parent::update( $request );
		}

		$existing = $this->integration->get_settings();
		if ( ! empty( $existing['webhook_secret'] ) ) {
			$new_settings['webhook_secret'] = $existing['webhook_secret'];
		}

		$request->set_param( 'settings', $new_settings );

		$response = parent::update( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		WebhookService::ensure_integration_secret();

		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_webhook_signature( WP_REST_Request $request ) {
		$secret = $this->integration->get_setting( 'webhook_secret' );
		if ( empty( $secret ) ) {
			return false;
		}

		$signature_header = $request->get_header( 'typeform-signature' );
		if ( empty( $signature_header ) ) {
			return false;
		}

		$payload  = $request->get_body();
		$expected = 'sha256=' . base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );

		return hash_equals( $expected, $signature_header );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload = json_decode( $request->get_body(), true );

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid payload.' ), 400 );
		}

		if ( ( $payload['event_type'] ?? '' ) !== 'form_response' ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$form_response = $payload['form_response'] ?? array();
		$typeform_id   = $form_response['form_id'] ?? '';

		if ( empty( $typeform_id ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		/** @var TypeformForm $handler */
		$handler = FormsManager::instance()->get_form( 'typeform' );
		if ( ! $handler ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$fields = $handler->map_definition_fields( $form_response['definition']['fields'] ?? array() );

		$submission = array(
			'entry'    => array(
				'fields' => $handler->normalize_answers( $form_response['answers'] ?? array() ),
			),
			'fields'   => $fields,
			'form_id'  => $typeform_id,
			'entry_id' => $form_response['token'] ?? ( $payload['event_id'] ?? '' ),
		);

		if ( $handler->is_form_active( $typeform_id ) ) {
			$handler->process_form( $submission );
		}

		$handler->process_automations( $submission );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
