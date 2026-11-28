<?php
/** Explicit customer confirmations, never exposed as model write abilities.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment -- Closed transport follows the documented workflow service contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\SchemaRegistry;

/** WordPress nonce or signed HttpOnly guest session resolves the current actor. */
final class VisitorWorkflowApi {
	private VisitorWorkflowService $service;
	private ContractValidator $validator;

	public function __construct( Database $db, ?VisitorWorkflowService $service = null ) {
		$this->service = $service ?? new VisitorWorkflowService( $db );
		$schemas       = new SchemaRegistry();
		$schemas->register( 'workflow.create', '{"type":"object","properties":{"conversation_id":{"type":"string","pattern":"^[A-Za-z0-9_.:-]{1,128}$"},"request_id":{"type":"string","pattern":"^[A-Za-z0-9_.:-]{1,128}$"},"adapter_id":{"enum":["booking.request","booking.cancel","review.submit"]},"directory_id":{"type":"integer","minimum":1},"listing_id":{"type":"integer","minimum":1},"inputs":{"type":"object","maxProperties":20}},"required":["conversation_id","request_id","adapter_id","directory_id","listing_id","inputs"],"additionalProperties":false}' );
		$schemas->register( 'workflow.action', '{"type":"object","properties":{"expected_version":{"type":"integer","minimum":1},"action":{"enum":["prepare","confirm","refresh"]},"inputs":{"type":"object","maxProperties":20},"quote_id":{"type":"string","pattern":"^[a-f0-9]{32}$"},"confirmed":{"type":"boolean"}},"required":["expected_version","action"],"additionalProperties":false}' );
		$this->validator = new ContractValidator( $schemas );
	}

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/workflows',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'current' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'create' ),
				),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/workflows/(?P<id>[A-Za-z0-9_.:-]{1,128})',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'action' ),
			)
		);
	}

	public function current( \WP_REST_Request $request ) {
		return $this->respond( fn() => $this->service->list_current( $this->context(), (string) $request['conversation_id'] ) );
	}

	public function create( \WP_REST_Request $request ) {
		return $this->respond(
			function () use ( $request ): array {
				$input = $this->validator->decode( $request->get_body(), 'workflow.create' );
				return $this->service->create( $this->context(), $input->conversation_id, $input->request_id, $input->adapter_id, $input->directory_id, $input->listing_id, $input->inputs );
			}
		);
	}

	public function action( \WP_REST_Request $request ) {
		return $this->respond(
			function () use ( $request ): array {
				$input = $this->validator->decode( $request->get_body(), 'workflow.action' );
				return $this->service->action( $this->context(), (string) $request['id'], $input->expected_version, $input->action, $input->inputs ?? null, $input->quote_id ?? null, $input->confirmed ?? false );
			}
		);
	}

	private function context(): Context {
		$cookie = isset( $_COOKIE['directorist_ai_guest'] ) && is_string( $_COOKIE['directorist_ai_guest'] ) ? $_COOKIE['directorist_ai_guest'] : '';
		return Context::current( 'visitor', 'ui', $cookie );
	}

	private function respond( callable $callback ) {
		try {
			return $callback();
		} catch ( ContractException $error ) {
			$status = in_array( $error->error_code(), array( 'stale_version', 'request_conflict', 'unknown_outcome' ), true ) ? 409 : ( 'unauthorized' === $error->error_code() || 'approval_required' === $error->error_code() ? 403 : ( 'busy' === $error->error_code() ? 429 : 400 ) );
			return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => $status ) );
		} catch ( StorageException $error ) {
			return new \WP_Error( 'not_found', 'This customer conversation is unavailable.', array( 'status' => 404 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'workflow_unavailable', 'The customer request could not be verified. Refresh its status before retrying.', array( 'status' => 503 ) );
		}
	}
}
