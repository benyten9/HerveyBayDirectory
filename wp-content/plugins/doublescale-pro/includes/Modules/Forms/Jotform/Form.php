<?php
/**
 * Jotform form integration (Forms module).
 *
 * API key is stored under Integrations; each connection is configured here
 * like any other form builder.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Jotform;

use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Pro\Modules\Integrations\Jotform\Api;
use DoubleScale\Pro\Modules\Integrations\Jotform\Integration;
use DoubleScale\Pro\Modules\Integrations\Jotform\WebhookService;
use DoubleScale\Pro\Modules\Forms\SaasFormAutomationWebhookSync;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Jotform Forms handler.
 */
class Form extends Abstracts_Form {

	/**
	 * @var string
	 */
	public $slug = 'jotform';

	/**
	 * @var string
	 */
	public $name = 'Jotform';

	/**
	 * @var string
	 */
	public $description = 'Capture leads when a Jotform submission is received.';

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
	 * External Jotform ids captured before a Forms delete request runs, keyed by
	 * DoubleScale form id. The delete callback removes the row before
	 * rest_request_after_callbacks fires, so we must read form_id while the row
	 * still exists and stash it here for the after-callbacks handler.
	 *
	 * @var array<int,string>
	 */
	private $pending_deleted_form_ids = array();

	/**
	 * Enabled when the Jotform integration has a valid API key.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		try {
			$integration = IntegrationsManager::instance()->get_integration( 'jotform' );
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
				'label'       => __( 'Jotform', 'doublescale' ),
				'type'        => 'ajax_select',
				'ajax_action' => "doublescale_{$this->slug}_get_form_select_options",
			),
		);
	}

	/**
	 * @param string $form_id Jotform form ID.
	 * @return array
	 */
	public function get_fields( $form_id ) {
		$api = $this->get_api();
		if ( ! $api || empty( $form_id ) ) {
			return array();
		}

		$response = $api->get_form_questions( $form_id );
		if ( empty( $response['success'] ) ) {
			return array();
		}

		return $this->map_definition_fields( $response['data']['content'] ?? array() );
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
					'message' => __( 'Connect Jotform in Integrations with an API key first.', 'doublescale' ),
				)
			);
		}

		$response = $api->get_forms();
		$options  = array();

		if ( ! empty( $response['success'] ) ) {
			foreach ( $response['data']['content'] ?? array() as $form ) {
				$id = $form['id'] ?? '';
				if ( '' === $id ) {
					continue;
				}
				$options[ $id ] = $form['title'] ?? $id;
			}
		}

		if ( empty( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'No Jotform forms found.', 'doublescale' ) ) );
		}

		wp_send_json_success( $options );
	}

	/**
	 * Snapshot the external Jotform id(s) of the form(s) about to be deleted.
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
			if ( $form && 'jotform' === $form->form_type && ! empty( $form->form_id ) ) {
				$this->pending_deleted_form_ids[ $id ] = (string) $form->form_id;
			}
		}

		return $response;
	}

	/**
	 * Sync Jotform webhooks after a Forms REST save or delete.
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
			// form deletion never breaks an automation on the same Jotform form.
			foreach ( $this->pending_deleted_form_ids as $id => $external_id ) {
				SaasFormAutomationWebhookSync::remove_webhook_if_unused( 'jotform', $external_id );
				unset( $this->pending_deleted_form_ids[ $id ] );
			}
			return $response;
		}

		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( empty( $data['form_type'] ) || 'jotform' !== $data['form_type'] ) {
			return $response;
		}

		$form = FormModel::find( $data['id'] ?? 0 );
		if ( ! $form ) {
			return $response;
		}

		$sync = WebhookService::sync_for_form_model( $form );
		if ( is_wp_error( $sync ) ) {
			doublescale_get_logger()->warning(
				__( 'Jotform webhook sync failed after form save', 'doublescale' ),
				array(
					'code'    => 'jotform_webhook_sync_failed',
					'message' => $sync->get_error_message(),
					'form_id' => $form->id,
				)
			);
		}

		return $response;
	}

	/**
	 * Map Jotform question definitions for the mapping UI.
	 *
	 * Jotform returns questions as a map keyed by qid. Non-input control types
	 * (headings, dividers, submit buttons, etc.) are skipped. Fields are keyed
	 * by the question `name` so they line up with {@see normalize_answers()}.
	 *
	 * @param array $questions Raw questions (content map).
	 * @return array
	 */
	public function map_definition_fields( array $questions ) {
		$skip_types = array(
			'control_head',
			'control_text',
			'control_divider',
			'control_button',
			'control_captcha',
			'control_pagebreak',
			'control_collapse',
			'control_image',
		);

		$fields = array();

		foreach ( $questions as $question ) {
			$type = $question['type'] ?? '';
			$name = $question['name'] ?? '';

			if ( '' === $name || in_array( $type, $skip_types, true ) ) {
				continue;
			}

			$fields[ $name ] = array(
				'label' => $question['text'] ?? $name,
				'type'  => 'control_email' === $type ? 'email' : 'text',
			);
		}

		return $fields;
	}

	/**
	 * Normalize a Jotform `rawRequest` payload into field_id => value.
	 *
	 * rawRequest keys look like `q{qid}_{name}`; we key normalized answers by
	 * `name` to match {@see map_definition_fields()}. Compound values (arrays,
	 * e.g. name/address subfields) are flattened to a readable string.
	 *
	 * @param array $raw Decoded rawRequest.
	 * @return array
	 */
	public function normalize_answers( array $raw ) {
		$fields = array();

		foreach ( $raw as $key => $value ) {
			if ( ! preg_match( '/^q\d+_(.+)$/', (string) $key, $m ) ) {
				continue;
			}
			$name            = $m[1];
			$fields[ $name ] = $this->flatten_value( $value );
		}

		return $fields;
	}

	/**
	 * Flatten a Jotform answer value to a string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function flatten_value( $value ) {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $part ) {
				if ( is_array( $part ) ) {
					$part = implode( ' ', array_map( 'strval', $part ) );
				}
				$part = trim( (string) $part );
				if ( '' !== $part ) {
					$parts[] = $part;
				}
			}
			return implode( ' ', $parts );
		}

		return (string) $value;
	}

	/**
	 * @return Api|false
	 */
	private function get_api() {
		try {
			/** @var Integration $integration */
			$integration = IntegrationsManager::instance()->get_integration( 'jotform' );
			return $integration->connect();
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
