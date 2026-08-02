<?php
/**
 * Typeform form integration (Forms module).
 *
 * Personal access token is stored under Integrations; each connection is
 * configured here like any other form builder.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Typeform;

use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Services\FormsManager;
use DoubleScale\Pro\Modules\Integrations\Typeform\Api;
use DoubleScale\Pro\Modules\Integrations\Typeform\Integration;
use DoubleScale\Pro\Modules\Integrations\Typeform\WebhookService;
use DoubleScale\Pro\Modules\Forms\SaasFormAutomationWebhookSync;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Typeform Forms handler.
 */
class Form extends Abstracts_Form {

	/**
	 * @var string
	 */
	public $slug = 'typeform';

	/**
	 * @var string
	 */
	public $name = 'Typeform';

	/**
	 * @var string
	 */
	public $description = 'Capture leads when a Typeform response is submitted.';

	/**
	 * @var string
	 */
	public $platform = 'saas';

	/**
	 * @var bool
	 */
	public $is_pro = true;

	/**
	 * @return void
	 */
	public function load_hooks() {
		add_action( "wp_ajax_doublescale_{$this->slug}_get_fields", array( $this, 'ajax_get_fields' ) );
		add_action( "wp_ajax_doublescale_{$this->slug}_get_form_select_options", array( $this, 'ajax_get_form_select_options' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'capture_form_before_delete' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'maybe_sync_webhook_after_form_rest' ), 10, 3 );
	}

	/**
	 * External Typeform ids captured before a Forms delete request runs, keyed by
	 * DoubleScale form id. The delete callback removes the row before
	 * rest_request_after_callbacks fires, so we must read form_id while the row
	 * still exists and stash it here for the after-callbacks handler.
	 *
	 * @var array<int,string>
	 */
	private $pending_deleted_form_ids = array();

	/**
	 * Enabled when the Typeform integration has a valid access token.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		try {
			$integration = IntegrationsManager::instance()->get_integration( 'typeform' );
			return $integration && $integration->is_connected();
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * @return array
	 */
	public function get_form_options() {
		return array(
			'form_id' => array(
				'label'       => __( 'Typeform', 'doublescale' ),
				'type'        => 'ajax_select',
				'ajax_action' => "doublescale_{$this->slug}_get_form_select_options",
			),
		);
	}

	/**
	 * @param string $form_id Typeform form ID.
	 * @return array
	 */
	public function get_fields( $form_id ) {
		$api = $this->get_api();
		if ( ! $api || empty( $form_id ) ) {
			return array();
		}

		$response = $api->get_form( $form_id );
		if ( empty( $response['success'] ) ) {
			return array();
		}

		return $this->map_definition_fields( $response['data']['fields'] ?? array() );
	}

	/**
	 * @return void
	 */
	public function ajax_get_fields() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';

		if ( empty( $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form ID', 'doublescale' ) ) );
		}

		wp_send_json_success( $this->get_fields( $form_id ) );
	}

	/**
	 * @return void
	 */
	public function ajax_get_form_select_options() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$api = $this->get_api();
		if ( ! $api ) {
			wp_send_json_error(
				array(
					'message' => __( 'Connect Typeform in Integrations with a personal access token first.', 'doublescale' ),
				)
			);
		}

		$response = $api->get_forms();
		$options  = array();

		if ( ! empty( $response['success'] ) ) {
			foreach ( $response['data']['items'] ?? array() as $form ) {
				$id = $form['id'] ?? '';
				if ( '' === $id ) {
					continue;
				}
				$options[ $id ] = $form['title'] ?? $id;
			}
		}

		if ( empty( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'No Typeforms found.', 'doublescale' ) ) );
		}

		wp_send_json_success( $options );
	}

	/**
	 * Snapshot the external Typeform id(s) of the form(s) about to be deleted.
	 *
	 * The Forms delete callbacks remove the row(s) before
	 * rest_request_after_callbacks fires, so by the time we would clean up the
	 * webhook the form_id is already unreadable. Read it here, while the rows
	 * still exist, for both the single (/forms/{id}) and bulk (/forms + ids)
	 * delete routes.
	 *
	 * @param WP_REST_Response|\WP_Error|null $response Short-circuit response.
	 * @param array                           $handler  Handler.
	 * @param WP_REST_Request                 $request  Request.
	 * @return WP_REST_Response|\WP_Error|null Unmodified.
	 */
	public function capture_form_before_delete( $response, $handler, $request ) {
		if ( 'DELETE' !== $request->get_method() ) {
			return $response;
		}

		$route = untrailingslashit( $request->get_route() );
		if ( ! preg_match( '#^/doublescale/v1/forms(?:/(\d+))?$#', $route, $matches ) ) {
			return $response;
		}

		$ids = array();
		if ( ! empty( $matches[1] ) ) {
			$ids[] = (int) $matches[1];
		} else {
			$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		}

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$form = FormModel::find( $id );
			if ( $form && 'typeform' === $form->form_type && ! empty( $form->form_id ) ) {
				$this->pending_deleted_form_ids[ $id ] = (string) $form->form_id;
			}
		}

		return $response;
	}

	/**
	 * Sync Typeform webhooks after a Forms REST save or delete.
	 *
	 * @param WP_REST_Response|\WP_Error $response Response.
	 * @param array                      $handler  Handler.
	 * @param WP_REST_Request            $request  Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function maybe_sync_webhook_after_form_rest( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}

		$route = untrailingslashit( $request->get_route() );
		if ( ! preg_match( '#^/doublescale/v1/forms(?:/(\d+))?$#', $route, $matches ) ) {
			return $response;
		}

		$method = $request->get_method();

		if ( 'DELETE' === $method ) {
			// Row(s) are gone now; act on the ids captured in
			// capture_form_before_delete(). Remove the shared webhook only when
			// no active automation or other form connection still needs it, so a
			// form deletion never breaks an automation on the same Typeform form.
			foreach ( $this->pending_deleted_form_ids as $id => $external_id ) {
				SaasFormAutomationWebhookSync::remove_webhook_if_unused( 'typeform', $external_id );
				unset( $this->pending_deleted_form_ids[ $id ] );
			}
			return $response;
		}

		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( empty( $data['form_type'] ) || 'typeform' !== $data['form_type'] ) {
			return $response;
		}

		$form = FormModel::find( $data['id'] ?? 0 );
		if ( ! $form ) {
			return $response;
		}

		$sync = WebhookService::sync_for_form_model( $form );
		if ( is_wp_error( $sync ) ) {
			doublescale_get_logger()->warning(
				__( 'Typeform webhook sync failed after form save', 'doublescale' ),
				array(
					'code'    => 'typeform_webhook_sync_failed',
					'message' => $sync->get_error_message(),
					'form_id' => $form->id,
				)
			);
		}

		return $response;
	}

	/**
	 * Map Typeform field definitions for the mapping UI.
	 *
	 * @param array $definition_fields Raw fields.
	 * @return array
	 */
	public function map_definition_fields( array $definition_fields ) {
		$fields = array();

		foreach ( $definition_fields as $field ) {
			$field_id = $field['id'] ?? '';
			if ( '' === $field_id ) {
				continue;
			}

			$fields[ $field_id ] = array(
				'label' => $field['title'] ?? $field_id,
				'type'  => 'email' === ( $field['type'] ?? '' ) ? 'email' : 'text',
			);
		}

		return $fields;
	}

	/**
	 * @param array $answers Typeform answers.
	 * @return array
	 */
	public function normalize_answers( array $answers ) {
		$fields = array();

		foreach ( $answers as $answer ) {
			$field_id = $answer['field']['id'] ?? '';
			if ( '' === $field_id ) {
				continue;
			}
			$fields[ $field_id ] = $this->extract_answer_value( $answer );
		}

		return $fields;
	}

	/**
	 * @param array $answer Single answer.
	 * @return string
	 */
	public function extract_answer_value( array $answer ) {
		$type = $answer['type'] ?? '';

		switch ( $type ) {
			case 'text':
				return (string) ( $answer['text'] ?? '' );
			case 'email':
				return (string) ( $answer['email'] ?? '' );
			case 'phone_number':
				return (string) ( $answer['phone_number'] ?? '' );
			case 'number':
				return isset( $answer['number'] ) ? (string) $answer['number'] : '';
			case 'boolean':
				return ! empty( $answer['boolean'] ) ? 'yes' : 'no';
			case 'choice':
				return (string) ( $answer['choice']['label'] ?? '' );
			case 'choices':
				return implode( ', ', array_map( 'strval', $answer['choices']['labels'] ?? array() ) );
			case 'date':
				return (string) ( $answer['date'] ?? '' );
			case 'url':
				return (string) ( $answer['url'] ?? '' );
			case 'file_url':
				return (string) ( $answer['file_url'] ?? '' );
			default:
				foreach ( array( 'text', 'email', 'url', 'number' ) as $key ) {
					if ( isset( $answer[ $key ] ) ) {
						return (string) $answer[ $key ];
					}
				}
				return '';
		}
	}

	/**
	 * @return Api|false
	 */
	private function get_api() {
		try {
			/** @var Integration $integration */
			$integration = IntegrationsManager::instance()->get_integration( 'typeform' );
			return $integration->connect();
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
