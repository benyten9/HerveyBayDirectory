<?php
/**
 * Jotform REST controller (integration settings + inbound webhook).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Jotform;

use DoubleScale\Modules\Forms\Services\FormsManager;
use DoubleScale\Pro\Modules\Forms\Jotform\Form as JotformForm;
use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Jotform REST controller.
 */
class RestController extends RestIntegrationController {

	/**
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/webhook/(?P<token>[A-Za-z0-9]+)",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'verify_webhook_token' ),
			)
		);
	}

	/**
	 * Integration settings: API key only.
	 *
	 * @return array
	 */
	public function get_settings_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'api_key' => array(
					'label'       => __( 'API key', 'doublescale' ),
					'type'        => 'string',
					'required'    => true,
					'description' => __( 'Create one in Jotform → Account → API. Then add a form under Forms → SaaS Forms → Jotform.', 'doublescale' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$new_settings = $request->get_param( 'settings' ) ?? array();

		if ( empty( $new_settings ) ) {
			return parent::update( $request );
		}

		$existing = $this->integration->get_settings();
		if ( ! empty( $existing['webhook_token'] ) ) {
			$new_settings['webhook_token'] = $existing['webhook_token'];
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
	public function verify_webhook_token( WP_REST_Request $request ) {
		$expected = (string) $this->integration->get_setting( 'webhook_token' );
		if ( '' === $expected ) {
			return false;
		}

		$provided = (string) $request->get_param( 'token' );

		return hash_equals( $expected, $provided );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		// Jotform posts multipart/form-data with a `rawRequest` JSON field and `formID`.
		$params      = $request->get_body_params();
		$jotform_id  = $params['formID'] ?? '';
		$raw_request = $params['rawRequest'] ?? '';

		if ( empty( $jotform_id ) || empty( $raw_request ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$raw = json_decode( $raw_request, true );
		if ( ! is_array( $raw ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		/** @var JotformForm $handler */
		$handler = FormsManager::instance()->get_form( 'jotform' );
		if ( ! $handler ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$fields = $handler->get_fields( $jotform_id );

		$submission = array(
			'entry'    => array(
				'fields' => $handler->normalize_answers( $raw ),
			),
			'fields'   => $fields,
			'form_id'  => $jotform_id,
			'entry_id' => $params['submissionID'] ?? '',
		);

		if ( $handler->is_form_active( $jotform_id ) ) {
			$handler->process_form( $submission );
		}

		$handler->process_automations( $submission );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
