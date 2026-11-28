<?php
/**
 * Authenticated directory capture and delivery through the existing chat runtime.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Directory;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Runtime\RunStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Authenticated native adapter.
/** Native Directory Builder AI integration. */
final class DirectoryApi {
	public function __construct( private Database $db, private DirectoryService $service ) {}

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/directory/forms/recover',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'recover' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/directory/forms',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'capture' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/directory/form-runs/(?P<id>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'result' ),
			)
		);
	}

	public function permission( \WP_REST_Request $request ): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' ) && wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && ! get_option( 'directorist_ai_pro_emergency_stop', false );
	}

	public function capture( \WP_REST_Request $request ) {
		try {
			$input = json_decode( $request->get_body() );
			if ( ! $input instanceof \stdClass || strlen( $request->get_body() ) > 262144 || array_diff( array_keys( get_object_vars( $input ) ), array( 'request_id', 'directory_id', 'values', 'area' ) ) || ! is_string( $input->request_id ?? null ) ) {
				throw new ContractException( 'invalid_input', 'The directory capture is invalid.' ); }
			$context = Context::current( 'admin', 'ui' );
			$form    = $this->service->bind( $context, $input );
			$row     = ( new ConversationStore( $this->db ) )->create(
				$context->actor()->owner(),
				'directory-' . substr( hash( 'sha256', $input->request_id ), 0, 40 ),
				'admin',
				(object) array(
					'target'            => 'directory-config:' . $form->directory_id,
					'directory_builder' => $form,
				),
				\DirectoristAIPro\Chat\Operations::settings()['user_retention_days'] * DAY_IN_SECONDS
			);
			return new \WP_REST_Response(
				array(
					'id'          => $row['public_id'],
					'profile'     => 'admin',
					'editor_hash' => $form->editor_hash,
				),
				201
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error ); }
	}

	public function result( \WP_REST_Request $request ) {
		try {
			$context = Context::current( 'admin', 'ui' );
			$run     = ( new RunStore( $this->db ) )->get( $context->actor()->owner(), $request['id'] );
			$rows    = $this->db->rows( 'SELECT public_id,context_json,profile FROM ' . $this->db->table( 'conversations' ) . ' WHERE id=%d AND owner_ref=%s', array( $run['conversation_id'], $context->actor()->owner()->reference() ) );
			$form    = $rows ? json_decode( $rows[0]['context_json'] )->directory_builder ?? null : null;
			if ( ! $form || 'admin' !== $rows[0]['profile'] ) {
				throw new ContractException( 'unauthorized', 'This is not a directory assistance run.' ); }
			$this->service->assert_form( $context, $form );
			$checkpoint = json_decode( $run['checkpoint_json'] );
			$data       = array(
				'state'           => $run['state'],
				'state_version'   => (int) $run['state_version'],
				'conversation_id' => $rows[0]['public_id'],
			);
			if ( in_array( $run['state'], array( 'failed', 'partial', 'unknown' ), true ) ) {
				$route          = json_decode( wp_json_encode( $checkpoint->ai_route ?? array() ), true );
				$source         = \DirectoristAIPro\AI\Settings::for_task( $route, 'text' )['source'] ?? 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
				$data['notice'] = \DirectoristAIPro\AI\FailureNotice::message( $checkpoint->error ?? '', $source, 'text', (array) ( $checkpoint->wordpress_error ?? array() ) ) ?? 'The directory configuration could not be confirmed. Reopen the builder to check its current state before another request.';
				$targets        = isset( $checkpoint->receipt->targets ) ? array_values( get_object_vars( $checkpoint->receipt->targets ) ) : array();
				if ( 'directory_name_exists' === ( $targets[0]->diagnostic ?? '' ) ) {
					$data['notice'] = 'A directory with this name already exists. Open that directory to edit it, or choose another name.'; }
			}
			if ( 'waiting_approval' === $run['state'] && in_array( $checkpoint->review->capability ?? '', array( 'directory.create', 'directory.editor_fill' ), true ) ) {
				$data['review'] = ( new ApprovalService( $this->db ) )->get( $context->actor(), $checkpoint->review->id );
			}
			if ( 'succeeded' === $run['state'] && in_array( $checkpoint->review->capability ?? '', array( 'directory.create', 'directory.editor_fill' ), true ) ) {
				$data['effects'] = array_values( get_object_vars( $checkpoint->receipt->targets ) )[0]->effects ?? (object) array();
			}
			$response = new \WP_REST_Response( $data );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error ); }
	}

	/** Read retained work against a fresh native capture; never admit or apply work. */
	public function recover( \WP_REST_Request $request ) {
		try {
			$input = json_decode( $request->get_body() );
			if ( ! $input instanceof \stdClass || strlen( $request->get_body() ) > 262144 || array_diff( array_keys( get_object_vars( $input ) ), array( 'directory_id', 'values', 'area', 'capture_request_id', 'turn_request_id', 'conversation_id', 'run_id' ) ) ) {
				throw new ContractException( 'invalid_input', 'The directory recovery capture is invalid.' ); }
			foreach ( array( 'capture_request_id', 'turn_request_id' ) as $key ) {
				if ( ! is_string( $input->$key ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $input->$key ) ) {
					throw new ContractException( 'invalid_input', 'The original directory request identity is required.' ); }
			}
			foreach ( array( 'conversation_id', 'run_id' ) as $key ) {
				if ( isset( $input->$key ) && ( ! is_string( $input->$key ) || ! preg_match( '/^[a-f0-9]{32}$/D', $input->$key ) ) ) {
					throw new ContractException( 'invalid_input', 'The retained directory identity is invalid.' ); }
			}
			$context       = Context::current( 'admin', 'ui' );
			$now           = $this->service->bind( $context, $input );
			$conversations = $this->db->table( 'conversations' );
			$rows          = $this->db->rows( "SELECT * FROM $conversations WHERE owner_ref=%s AND request_id=%s AND profile='admin' AND erasure_requested=0 AND (expires_at>UTC_TIMESTAMP() OR active_run_id IS NOT NULL)", array( $context->actor()->owner()->reference(), 'directory-' . substr( hash( 'sha256', $input->capture_request_id ), 0, 40 ) ) );
			if ( ! $rows || ( isset( $input->conversation_id ) && $input->conversation_id !== $rows[0]['public_id'] ) ) {
				throw new ContractException( 'directory_recovery_unavailable', 'The original directory request could not be confirmed. Check its status before sending another instruction.' ); }
			$conversation = $rows[0];
			$form         = json_decode( $conversation['context_json'] )->directory_builder ?? null;
			if ( ! $form instanceof \stdClass || (int) $form->directory_id !== $now->directory_id || $form->area !== $now->area ) {
				throw new ContractException( 'scope_conflict', 'Open the original directory and builder section to check this request.' ); }
			$runs  = $this->db->table( 'runs' );
			$turns = $this->db->table( 'turns' );
			$rows  = $this->db->rows( "SELECT r.public_id,r.state,r.state_version FROM $runs r INNER JOIN $turns t ON t.run_id=r.id AND t.conversation_id=r.conversation_id WHERE r.conversation_id=%d AND r.owner_ref=%s AND t.request_id=%s", array( (int) $conversation['id'], $context->actor()->owner()->reference(), $input->turn_request_id ) );
			$data  = array( 'conversation_id' => $conversation['public_id'], 'current' => false, 'state' => 'admission_unknown' );
			if ( $rows ) {
				$run = $rows[0];
				if ( isset( $input->run_id ) && $input->run_id !== $run['public_id'] ) {
					throw new ContractException( 'scope_conflict', 'The retained run does not match this directory request.' ); }
				$data['run_id']        = $run['public_id'];
				$data['state']         = $run['state'];
				$data['state_version'] = (int) $run['state_version'];
				$data['current']       = hash_equals( $form->editor_hash, $now->editor_hash ) && hash_equals( $form->source_hash, $now->source_hash ) && hash_equals( $form->schema_hash, $now->schema_hash );
				if ( $data['current'] ) {
					$original       = new \WP_REST_Request( 'GET' );
					$original['id'] = $run['public_id'];
					$result         = $this->result( $original );
					if ( is_wp_error( $result ) ) {
						return $result; }
					$data = array_merge( $data, $result->get_data() );
				}
			}
			$response = new \WP_REST_Response( $data );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error ); }
	}

	private function error( \Throwable $error ): \WP_Error {
		return new \WP_Error( $error instanceof ContractException ? $error->error_code() : 'directory_unavailable', $error instanceof ContractException ? $error->getMessage() : 'Directory assistance is unavailable.', array( 'status' => 409 ) );
	}
}
