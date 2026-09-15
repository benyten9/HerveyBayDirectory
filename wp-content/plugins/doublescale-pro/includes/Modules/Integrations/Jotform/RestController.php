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
		$provided = (string) $request->get_param( 'token' );

		if ( '' === $expected ) {
			doublescale_get_logger()->warning(
				__( 'Jotform webhook rejected: no webhook token stored', 'doublescale' ),
				array( 'code' => 'jotform_webhook_missing_token' )
			);
			return false;
		}

		if ( ! hash_equals( $expected, $provided ) ) {
			doublescale_get_logger()->warning(
				__( 'Jotform webhook rejected: token mismatch', 'doublescale' ),
				array( 'code' => 'jotform_webhook_token_mismatch' )
			);
			return false;
		}

		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$params = $this->extract_jotform_params( $request );

		$jotform_id  = (string) ( $params['formID'] ?? '' );
		$raw_request = $params['rawRequest'] ?? '';

		if ( empty( $jotform_id ) || empty( $raw_request ) ) {
			doublescale_get_logger()->warning(
				__( 'Jotform webhook ignored: missing formID or rawRequest', 'doublescale' ),
				array(
					'code'          => 'jotform_webhook_empty_payload',
					'has_form_id'   => '' !== $jotform_id,
					'has_raw'       => '' !== (string) $raw_request,
					'content_type'  => (string) $request->get_header( 'content-type' ),
					'param_keys'    => array_keys( $params ),
				)
			);
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$raw = is_array( $raw_request ) ? $raw_request : json_decode( (string) $raw_request, true );
		if ( ! is_array( $raw ) ) {
			doublescale_get_logger()->warning(
				__( 'Jotform webhook ignored: rawRequest is not valid JSON', 'doublescale' ),
				array(
					'code'     => 'jotform_webhook_invalid_raw',
					'form_id'  => $jotform_id,
					'json_err' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '',
				)
			);
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		/** @var JotformForm $handler */
		$handler = FormsManager::instance()->get_form( 'jotform' );
		if ( ! $handler ) {
			doublescale_get_logger()->error(
				__( 'Jotform webhook ignored: form handler not registered', 'doublescale' ),
				array(
					'code'    => 'jotform_webhook_handler_missing',
					'form_id' => $jotform_id,
				)
			);
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$fields     = $handler->get_fields( $jotform_id );
		$answers    = $handler->normalize_answers( $raw );
		$submission = array(
			'entry'    => array(
				'fields' => $answers,
			),
			'fields'   => $fields,
			'form_id'  => $jotform_id,
			'entry_id' => $params['submissionID'] ?? '',
		);

		$form_active = $handler->is_form_active( $jotform_id );

		doublescale_get_logger()->info(
			__( 'Jotform webhook received', 'doublescale' ),
			array(
				'code'          => 'jotform_webhook_received',
				'form_id'       => $jotform_id,
				'entry_id'      => $submission['entry_id'],
				'form_active'   => $form_active,
				'answer_keys'   => array_keys( $answers ),
				'question_keys' => array_keys( $fields ),
			)
		);

		if ( $form_active ) {
			$handler->process_form( $submission );
		} else {
			doublescale_get_logger()->warning(
				__( 'Jotform webhook: no active Forms connection for this form', 'doublescale' ),
				array(
					'code'    => 'jotform_webhook_form_inactive',
					'form_id' => $jotform_id,
				)
			);
		}

		$handler->process_automations( $submission );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Jotform usually posts multipart/form-data; some accounts send JSON.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function extract_jotform_params( WP_REST_Request $request ) {
		$params = $request->get_body_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( empty( $params['formID'] ) || empty( $params['rawRequest'] ) ) {
			$json = json_decode( $request->get_body(), true );
			if ( is_array( $json ) ) {
				$params = array_merge( $params, $json );
			}
		}

		if ( empty( $params['formID'] ) || empty( $params['rawRequest'] ) ) {
			$all = $request->get_params();
			if ( is_array( $all ) ) {
				$params = array_merge( $params, $all );
			}
		}

		return $params;
	}
}
