<?php
/** Durable, owned mapping proposals through the existing accounted model worker.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Runtime\ActionSchedulerQueue;
use DirectoristAIPro\Runtime\Dispatcher;
use DirectoristAIPro\Runtime\GenerationResponseDispatch;
use DirectoristAIPro\Runtime\RunStore;
use DirectoristAIPro\Runtime\WakeQueue;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageConflictException;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Narrow owned runtime adapter; contracts documented with its guarded suite.
// phpcs:disable WordPress.WP.CapitalPDangit -- Internal normalized AI source identifiers are not display text.

/** No import row is validated, approved or written by a mapping model response. */
final class ImportModelBridge {
	public const OPERATION = 'import.mapping_propose';
	private const INTENT   = 'Suggest a mapping from this private source into the selected existing directory. Preserve source facts and leave ambiguity for manual review. Do not validate, approve or create listings.';
	private ImportService $service;
	private ImportStore $store;
	private Dispatcher $dispatcher;
	private GenerationResponseDispatch $generation_dispatch;

	public function __construct( private Database $db, ?WakeQueue $queue = null, ?GenerationResponseDispatch $generation_dispatch = null ) {
		$this->service             = new ImportService( $db );
		$this->store               = new ImportStore( $db );
		$this->dispatcher          = new Dispatcher( $db, $queue ?? new ActionSchedulerQueue() );
		$this->generation_dispatch = $generation_dispatch ?? new GenerationResponseDispatch();
	}

	public function hooks(): void {
		add_action( 'directorist_ai_pro_register_operations', array( $this, 'register' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'api' ) );
	}

	/** Only route-selected identity and client retry identity enter this bridge. */
	public function suggest_rest( Context $context, array $request ) {
		$context->assert_current();
		if ( ! is_string( $request['job_id'] ?? null ) || ! is_string( $request['request_id'] ?? null ) ) {
			throw new ContractException( 'invalid_input', 'Select an owned import and a stable mapping request ID.' );
		}
		$lock = 'dai-import-map-' . substr( hash( 'sha256', get_current_blog_id() . "\0" . $context->actor()->owner()->reference() . "\0" . $request['job_id'] . "\0" . $request['request_id'] ), 0, 48 );
		if ( '1' !== (string) $this->db->scalar( 'SELECT GET_LOCK(%s,0)', array( $lock ) ) ) {
			return new \WP_Error(
				'mapping_admission_busy',
				'This suggestion request is still being accepted. Check it using the same request identity.',
				array(
					'status'     => 409,
					'acceptance' => 'uncertain',
				)
			);
		}
		$admission = (object) array( 'rejected_before_acceptance' => false );
		try {
			return $this->suggest( $context, $request, $admission );
		} catch ( ContractException $error ) {
			if ( ! $admission->rejected_before_acceptance ) {
				throw $error;
			}
			return new \WP_Error(
				$error->error_code(),
				$error->getMessage(),
				array(
					'status'     => 'unauthorized' === $error->error_code() ? 403 : 409,
					'acceptance' => 'rejected_before_acceptance',
				)
			);
		} finally {
			$this->db->scalar( 'SELECT RELEASE_LOCK(%s)', array( $lock ) );
		}
	}

	public function suggest( Context $context, array $request, ?object $admission = null ): array {
		$this->authorize( $context );
		if ( count( $request ) !== 2 || array_diff( array_keys( $request ), array( 'job_id', 'request_id' ) ) || ! is_string( $request['job_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $request['job_id'] ) || ! is_string( $request['request_id'] ?? null ) ) {
			throw new ContractException( 'invalid_input', 'Select an owned import and a stable mapping request ID.' );
		}
		ConversationStore::identifier( $request['request_id'] );
		$key           = 'import-map-' . hash( 'sha256', get_current_blog_id() . "\0" . $context->actor()->owner()->reference() . "\0" . $request['job_id'] . "\0" . $request['request_id'] );
		$conversations = $this->db->table( 'conversations' );
		$existing      = $this->db->rows( "SELECT public_id,context_json FROM $conversations WHERE owner_ref=%s AND request_id=%s", array( $context->actor()->owner()->reference(), $key ) );
		if ( $existing ) {
			$conversation = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $existing[0]['public_id'] );
			$scope        = json_decode( $conversation['context_json'] );
			if ( ! self::capture_valid( $scope->listing_import ?? null ) || $scope->listing_import->job_id !== $request['job_id'] ) {
				throw new ContractException( 'scope_conflict', 'Mapping retry identity has a different captured import.' );
			}
		} else {
			// Deduplication precedes current job version/state checks, including retries after mapping or launch.
			if ( $admission ) {
				$admission->rejected_before_acceptance = true; }
			$envelope = $this->as_actor( $context, fn(): array => $this->service->mapping_request( $context, $request['job_id'] ) );
			$scope    = json_decode( wp_json_encode( $envelope['context'] ), false, 32, JSON_THROW_ON_ERROR );
			if ( $admission ) {
				$admission->rejected_before_acceptance = false; }
			try {
				$conversation = ( new ConversationStore( $this->db ) )->create( $context->actor()->owner(), $key, 'admin', $scope, DAY_IN_SECONDS );
			} catch ( StorageConflictException $error ) {
				// Another identical request may have committed and applied its mapping during our capture.
				$existing = $this->db->rows( "SELECT public_id FROM $conversations WHERE owner_ref=%s AND request_id=%s", array( $context->actor()->owner()->reference(), $key ) );
				if ( ! $existing ) {
					throw $error;
				}
				$conversation = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $existing[0]['public_id'] );
				$scope        = json_decode( $conversation['context_json'] );
				if ( ! self::capture_valid( $scope->listing_import ?? null ) || $scope->listing_import->job_id !== $request['job_id'] ) {
					throw new ContractException( 'scope_conflict', 'Mapping allocation conflicts with its earlier captured import.' );
				}
			}
		}
		$accepted = ( new RunStore( $this->db ) )->accept(
			$context,
			$conversation['public_id'],
			$key,
			wp_json_encode(
				array(
					'text'                 => self::INTENT,
					'artifact_refs'        => array(),
					'reply_to_run_id'      => null,
					'expected_run_version' => null,
				)
			)
		);
		$run      = $accepted['run'];
		$this->advance( $context, $run['public_id'] );
		return array(
			'run_id'          => $run['public_id'],
			'conversation_id' => $conversation['public_id'],
			'poll_path'       => '/directorist-ai/v1/import/mapping-runs/' . $run['public_id'],
			'advance_path'    => '/directorist-ai/v1/import/mapping-runs/' . $run['public_id'] . '/advance',
		);
	}

	public static function capture_valid( $capture ): bool {
		return $capture instanceof \stdClass && array_diff( array_keys( get_object_vars( $capture ) ), array( 'mode', 'job_id', 'directory_id', 'schema_hash', 'source_hash', 'job_version' ) ) === array() && 'mapping' === ( $capture->mode ?? null ) && is_string( $capture->job_id ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $capture->job_id ) && is_int( $capture->directory_id ?? null ) && $capture->directory_id > 0 && is_int( $capture->job_version ?? null ) && $capture->job_version > 0 && is_string( $capture->schema_hash ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $capture->schema_hash ) && is_string( $capture->source_hash ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $capture->source_hash );
	}

	/** Current paired worker binding; replay is allowed only for its exact persisted marker. */
	public function bound( Context $context ): array {
		$this->authorize( $context );
		if ( 'worker' !== $context->transport() || ! $context->conversation_id() || ! $context->run_id() ) {
			throw new ContractException( 'unauthorized', 'Mapping proposals require their paired owned worker.' );
		}
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$owner         = $context->actor()->owner()->reference();
		$rows          = $this->db->rows( "SELECT c.context_json FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.profile='admin' AND c.erasure_requested=0 AND c.expires_at>UTC_TIMESTAMP() AND c.active_run_id=r.id AND r.state='running' AND r.cancel_requested=0 AND r.lease_until>=UTC_TIMESTAMP()", array( $context->run_id(), $context->conversation_id(), $owner, $owner ) );
		$scope         = $rows ? json_decode( $rows[0]['context_json'] ) : null;
		if ( ! self::capture_valid( $scope->listing_import ?? null ) || array_keys( get_object_vars( $scope ) ) !== array( 'listing_import' ) ) {
			throw new ContractException( 'scope_conflict', 'This run is not the active owned mapping conversation.' );
		}
		$capture = $scope->listing_import;
		$job     = $this->store->get( $context, $capture->job_id );
		if ( strtotime( $job['expires_at'] . ' UTC' ) <= time() || (int) $job['cancel_requested'] || (int) $job['directory_id'] !== $capture->directory_id || ! hash_equals( $job['schema_hash'], $capture->schema_hash ) || ! hash_equals( $job['source_hash'], $capture->source_hash ) ) {
			throw new ContractException( 'stale_version', 'The captured import source or directory is no longer current.' );
		}
		$applied = ( $job['manifest']['mapping_run_id'] ?? null ) === $context->run_id() && is_array( $job['manifest']['mapping'] ?? null );
		if ( ! $applied && ( (int) $job['version'] !== $capture->job_version || ! in_array( $job['state'], array( 'parsed', 'mapped', 'validating', 'validated', 'reviewed' ), true ) ) ) {
			throw new ContractException( 'stale_version', 'The import mapping review changed. Request a new suggestion or edit it manually.' );
		}
		return array(
			'capture' => $capture,
			'job'     => $job,
			'applied' => $applied,
		);
	}

	public function prepare( Context $context ): array {
		$bound = $this->bound( $context );
		if ( $bound['applied'] ) {
			// An exact marker avoids provider replay, but is never evidence that the native directory stayed unchanged.
			$this->fresh_schema( $context, $bound['job'] );
			return $bound;
		}
		$envelope = $this->as_actor( $context, fn(): array => $this->service->mapping_request( $context, $bound['capture']->job_id ) );
		$captured = json_decode( wp_json_encode( $envelope['context']['listing_import'] ) );
		foreach ( get_object_vars( $bound['capture'] ) as $key => $value ) {
			if ( ( $captured->$key ?? null ) !== $value ) {
				throw new ContractException( 'stale_version', 'The complete mapping capture changed before the model request.' );
			}
		}
		$bound['input'] = $envelope['input'];
		return $bound;
	}

	public function prompt( Context $context ): object {
		$bound = $this->prepare( $context );
		if ( $bound['applied'] ) {
			throw new ContractException( 'mapping_applied', 'This run already persisted its mapping.' );
		}
		return json_decode( wp_json_encode( $bound['input'] ), false, 32, JSON_THROW_ON_ERROR );
	}

	public function propose( Context $context, object $input ): object {
		$bound = $this->prepare( $context );
		if ( ! $bound['applied'] ) {
			// Only fully disclosed source paths can be selected by AI. Raw private records remain manual-only.
			$paths = $bound['input']['source_paths'] ?? array();
			foreach ( $input->fields ?? array() as $entry ) {
				foreach ( CompositeFields::paths( json_decode( wp_json_encode( $entry ), true ) ) as $path ) {
					if ( ! is_string( $path ) || ! in_array( $path, $paths, true ) ) { throw new ContractException( 'mapping_invalid', 'The proposal selected a source path outside its permitted sample.' ); }
				}
			}
			$policy = $bound['job']['manifest']['policy'] ?? null;
			if ( ! is_array( $policy ) ) {
				$view   = $this->as_actor( $context, fn(): array => $this->service->view( $context, $bound['capture']->job_id, (int) $bound['job']['record_count'] ) );
				$policy = $view['policy_defaults'];
			}
			$this->as_actor( $context, fn(): array => $this->service->mapping( $context, $bound['capture']->job_id, $bound['capture']->job_version, json_decode( wp_json_encode( $input ), true, 32, JSON_THROW_ON_ERROR ), $policy ) );
		}
		return (object) array(
			'job_id'          => $bound['capture']->job_id,
			'mapping_applied' => true,
			'run_id'          => $context->run_id(),
		);
	}

	public static function mapping_schema(): array {
		$id = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 128,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'fields'         => array(
					'type'     => 'array',
					'maxItems' => Limits::COLUMNS,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'source_path' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 2048,
							),
							'field_id'    => $id,
							'transform'   => array( 'enum' => Mapping::TRANSFORMS ),
							'components'  => array( 'type' => 'object', 'properties' => array_fill_keys( array( 'type', 'range', 'longitude', 'hide_map' ), array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2048 ) ), 'maxProperties' => 2, 'additionalProperties' => false ),
						),
						'required'             => array( 'source_path', 'field_id', 'transform' ),
						'additionalProperties' => false,
					),
				),
				'omit_fields'    => array(
					'type'        => 'array',
					'maxItems'    => Limits::COLUMNS,
					'uniqueItems' => true,
					'items'       => $id,
				),
				'default_fields' => array(
					'type'        => 'array',
					'maxItems'    => Limits::COLUMNS,
					'uniqueItems' => true,
					'items'       => $id,
				),
			),
			'required'             => array( 'fields', 'omit_fields', 'default_fields' ),
			'additionalProperties' => false,
		);
	}

	public function register( Registry $registry, SchemaRegistry $schemas ): void {
		$schemas->register( self::OPERATION . '.input', wp_json_encode( self::mapping_schema() ) );
		$schemas->register( self::OPERATION . '.output', '{"type":"object","properties":{"job_id":{"type":"string","pattern":"^[a-f0-9]{32}$"},"mapping_applied":{"type":"boolean","const":true},"run_id":{"type":"string","pattern":"^[a-f0-9]{32}$"}},"required":["job_id","mapping_applied","run_id"],"additionalProperties":false}' );
		$permitted = function ( Context $context ): bool {
			try {
				$this->bound( $context );
				return true;
			} catch ( \Throwable $error ) {
				return false; }
		};
		$registry->register(
			new Operation(
				array(
					'id'            => self::OPERATION,
					'revision'      => '1',
					'description'   => 'Propose one source-to-field mapping for this exact administrator import. Use existing field IDs, source JSON pointers and allowed transforms. This saves mapping only; records still require native validation and a separate exact human import review.',
					'input_schema'  => self::OPERATION . '.input',
					'output_schema' => self::OPERATION . '.output',
					'effect'        => 'private',
					'profiles'      => array( 'admin' ),
					'allow_guest'   => false,
				),
				static fn( object $input, Context $context ): bool => $permitted( $context ),
				$permitted,
				static fn(): bool => true,
				fn( object $input, Context $context ): object => $this->propose( $context, $input )
			)
		);
	}

	public function api(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/import/mapping-runs/(?P<id>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'callback'            => function ( \WP_REST_Request $request ) {
					try {
						return $this->poll( Context::current( 'admin', 'ui' ), $request['id'] );
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'mapping_unavailable', 'This owned mapping request is unavailable. Reload the private import.', array( 'status' => 403 ) ); }
				},
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/import/mapping-runs/(?P<id>[a-f0-9]{32})/advance',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'callback'            => array( $this, 'advance_rest' ),
			)
		);
	}

	/** One explicit authenticated wake; never called by history or status GETs. */
	public function advance_rest( \WP_REST_Request $request ) {
		try {
			if ( 'POST' !== $request->get_method() || ! wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ) {
				throw new ContractException( 'unauthorized', 'Refresh the private import before continuing its mapping request.' );
			}
			$body = ( new JsonDecoder( 4096, 4 ) )->decode( '' === $request->get_body() ? '{}' : $request->get_body() );
			if ( ! $body instanceof \stdClass || get_object_vars( $body ) ) {
				throw new ContractException( 'invalid_input', 'Mapping continuation accepts an empty request only.' );
			}
			$response = new \WP_REST_Response( $this->advance( Context::current( 'admin', 'ui' ), (string) $request['id'] ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( ContractException $error ) {
			return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 'unauthorized' === $error->error_code() ? 403 : 409 ) );
		}
	}

	/** Reuse current native capture checks before registering post-response work. */
	public function advance( Context $context, string $id ): array {
		$this->authorize( $context );
		$progress = $this->poll( $context, $id );
		if ( $progress['advance_due'] ) {
			$this->generation_dispatch->defer( $this->dispatcher, $id );
		}
		return $progress;
	}

	public function poll( Context $context, string $id ): array {
		$context->assert_current();
		if ( 'admin' !== $context->profile() || ! $context->actor()->can( 'manage_options' ) ) {
			throw new ContractException( 'unauthorized', 'Mapping progress requires its administrator owner.' );
		}
		$run          = ( new RunStore( $this->db ) )->get( $context->actor()->owner(), $id );
		$conversation = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $run['conversation_public_id'] );
		$scope        = json_decode( $conversation['context_json'] );
		if ( 'admin' !== $conversation['profile'] || ! $scope instanceof \stdClass || array_keys( get_object_vars( $scope ) ) !== array( 'listing_import' ) || ! self::capture_valid( $scope->listing_import ?? null ) ) {
			throw new ContractException( 'scope_conflict', 'This owned run is not an import mapping request.' );
		}
		$capture = $scope->listing_import;
		$job     = $this->store->get( $context, $capture->job_id );
		$matches = (int) $job['directory_id'] === $capture->directory_id && hash_equals( $job['source_hash'], $capture->source_hash ) && hash_equals( $job['schema_hash'], $capture->schema_hash );
		$applied = $matches && ( $job['manifest']['mapping_run_id'] ?? null ) === $id && is_array( $job['manifest']['mapping'] ?? null );
		$stale   = ! $matches || ( ! $applied && ( (int) $job['version'] !== $capture->job_version || ! in_array( $job['state'], array( 'parsed', 'mapped', 'validating', 'validated', 'reviewed' ), true ) ) );
		if ( ! $stale ) {
			try {
				$this->fresh_schema( $context, $job );
			} catch ( \Throwable $error ) {
				$applied = false;
				$stale   = true;
			}
		}
		$stale       = $stale || ( 'succeeded' === $run['state'] && ! $applied );
		$expired     = 'running' === $run['state'] && null !== $run['lease_until'] && strtotime( $run['lease_until'] . ' UTC' ) < time();
		$advance_due = ! $stale && ! $applied && ! (int) $job['cancel_requested'] && ! (int) $run['cancel_requested'] && (int) $conversation['active_run_id'] === (int) $run['id'] && ( 'queued' === $run['state'] || $expired ) && strtotime( $run['available_at'] . ' UTC' ) <= time();
		$checkpoint  = json_decode( $run['checkpoint_json'] );
		$source      = $checkpoint->ai_route->task_routes->text->source ?? $checkpoint->ai_route->source ?? null;
		$source      = in_array( $source, array( 'wordpress', 'managed' ), true ) ? $source : null;
		$failure     = array(
			'failure_code' => null,
			'recovery'     => null,
			'error'        => null,
		);
		if ( $stale || in_array( $run['state'], array( 'failed', 'cancelled', 'waiting_input' ), true ) ) {
			$code = $stale ? 'mapping_stale' : ( 'cancelled' === $run['state'] ? 'mapping_cancelled' : ( $checkpoint->error ?? null ) );
			if ( null !== $code || 'failed' === $run['state'] ) {
				$native = null;
				if ( 'provider_unavailable' === $code && 'wordpress' === $source ) {
					$steps  = $this->db->rows( 'SELECT data_json FROM ' . $this->db->table( 'steps' ) . " WHERE run_id=%d AND kind='model' AND state='failed' ORDER BY sequence DESC LIMIT 1", array( (int) $run['id'] ) );
					$data   = $steps ? json_decode( $steps[0]['data_json'] ) : null;
					$native = $data->wordpress_error ?? null;
				}
				$failure = MappingFailure::describe( $code, $source, $native );
			}
		}
		$question = null;
		foreach ( array_reverse( (array) ( $checkpoint->tool_results ?? array() ) ) as $result ) {
			if ( 'conversation.clarify' === ( $result->response->operation ?? null ) && true === ( $result->response->ok ?? false ) ) {
				$question = $result->response->data->question ?? null;
				break; }
		}
		return array(
			'state'           => $stale ? 'waiting_input' : $run['state'],
			'version'         => (int) $run['state_version'],
			'question'        => ! $stale && is_string( $question ) ? $question : null,
			'error'           => $failure['error'],
			'failure_code'    => $failure['failure_code'],
			'ai_source'       => $source,
			'recovery'        => $failure['recovery'],
			'mapping_applied' => $applied,
			'job_id'          => $scope->listing_import->job_id,
			'advance_due'     => $advance_due,
		);
	}

	private function authorize( Context $context ): void {
		$context->actor()->refresh();
		$context->assert_current();
		$this->db->readiness()->assert_ready();
		if ( 'admin' !== $context->profile() || ! $context->actor()->can( 'manage_options' ) || get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			throw new ContractException( 'unauthorized', 'Import mapping is unavailable under the current administrator/site policy.' );
		}
	}

	private function fresh_schema( Context $context, array $job ): void {
		$schema = $this->as_actor( $context, fn(): array => ( new ImportSchema( new DirectoristInspector() ) )->capture( $context, (int) $job['directory_id'] ) );
		if ( strtotime( $job['expires_at'] . ' UTC' ) <= time() || (int) $job['cancel_requested'] || ! hash_equals( $job['schema_hash'], $schema['revision'] ) || ! hash_equals( $job['environment_hash'], $schema['environment_hash'] ) ) {
			throw new ContractException( 'schema_changed', 'The captured native directory or import lifecycle changed.' );
		}
	}

	private function as_actor( Context $context, callable $read ) {
		$previous = get_current_user_id();
		try {
			wp_set_current_user( $context->actor()->user_id() );
			$context->assert_execution_identity();
			return $read();
		} finally {
			wp_set_current_user( $previous ); }
	}
}
