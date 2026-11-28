<?php
/**
 * Managed result adapter preserving native tool validation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;
use DirectoristAIPro\AI\ManagedService;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed native transport adapter.
/** Maintains the closed server-owned boundary. */
final class ManagedModelClient implements ModelClient {
	/** Build the exact admitted POST before persisting its private recovery envelope. */
	public static function submission_body( ModelRequest $request ): array {
		$tools = array();
		$names = array();
		if ( null !== $request->builder_style_targets || null !== $request->builder_style_targets_json ) {
			try { if ( null === BuilderStyleTargetTransport::for_request( $request ) ) { throw new \UnexpectedValueException( 'No paired current style targets.' ); } }
			catch ( \Throwable $error ) { throw new ModelException( 'invalid_input', 'The current native style declaration is unavailable.' ); }
		}
		if ( null !== $request->builder_read_targets || null !== $request->builder_read_targets_json ) {
			try {
				if ( null === BuilderReadTargetTransport::for_request( $request ) ) { throw new \UnexpectedValueException( 'No admitted native read targets.' ); }
			} catch ( \Throwable $error ) {
				throw new ModelException( 'invalid_input', 'The current native read declaration is unavailable.' );
			}
		}
		if ( null !== $request->builder_initial_factory || null !== $request->builder_initial_factory_json ) {
			try {
				if ( null === BuilderFactoryTransport::for_request( $request ) ) {
					throw new \UnexpectedValueException( 'An exact prepared factory is required.' );
				}
			} catch ( \Throwable $error ) {
				throw new ModelException( 'invalid_input', 'The exact initial layout declaration is unavailable.' );
			}
		}
		if ( null === $request->function_declarations ) {
			throw new ModelException( 'invalid_input', 'Managed tool calls require the exact native declaration snapshot.' ); }
		foreach ( $request->function_declarations as $declaration ) {
			$f       = $declaration->toArray();
			$names[] = $f['name'];
			$tools[] = array(
				'type'     => 'function',
				'function' => $f,
			);
		}
		$feature                  = $request->ai_route['feature'] ?? 'admin';
		$body                     = array(
			'schema_version'    => 1,
			'request_id'        => $request->managed_request_id,
			'operation_id'      => $request->managed_operation_id,
			'type'              => 'vision' === $request->task ? 'inspection' : ( 'builder' === $feature ? 'layout' : ( 'listing' === $feature ? 'listing' : 'chat' ) ),
			'messages'          => array(
				array(
					'role'    => 'system',
					'content' => $request->system_instruction,
				),
				array(
					'role'    => 'user',
					'content' => $request->prompt,
				),
			),
			'max_output_tokens' => $request->max_tokens,
			'tools'             => $tools,
		);
		return $body;
	}
	public function generate( ModelRequest $request ): ModelResponse {
		if ( $request->managed_resubmit && ( ! $request->managed_poll || null === $request->managed_submission ) ) {
			throw new ModelException( 'managed_submission_invalid', 'Managed recovery requires the exact previously admitted request.' );
		}
		$body = self::submission_body( $request );
		$names = array_column( array_column( $body['tools'], 'function' ), 'name' );
		if ( null !== $request->managed_submission ) {
			$body = ManagedSubmission::body( $request->managed_submission, $request->ai_route ?? array(), $request->managed_request_id, $request->managed_operation_id );
		}
		$native_object_schemas    = $this->native_envelope_schemas( $body );
		$r                        = ManagedService::inference( $body, $request->ai_route ?? array(), $request->managed_poll && ! $request->managed_resubmit, $request->managed_submission->body_json ?? null );
		$telemetry                = ManagedService::receipt( $r );
		$validation               = 'usage';
		$factory_repair_candidate = null;
		$read_repair_feedback = null;
		try {
			$receipt = $r['receipt'];
			$usage   = $receipt['usage'] ?? array();
			if ( ! is_array( $usage ) ) {
				throw new \UnexpectedValueException( 'Invalid token evidence container.' );
			}
			$reported   = 'completed' === ( $receipt['state'] ?? null ) && isset( $usage['input_tokens'], $usage['output_tokens'] );
			$input      = self::tokens( $usage['input_tokens'] ?? null );
			$output     = self::tokens( $usage['output_tokens'] ?? null );
			$telemetry  = array_merge(
				$telemetry,
				array(
					'usage_reported' => $reported,
					'input_tokens'   => $reported ? $input : null,
					'output_tokens'  => $reported ? $output : null,
				)
			);
			$validation = 'tool_binding';
			$result     = $r['result'] ?? array();
			if ( 'length' === ( $result['finish_reason'] ?? null ) ) {
				throw new ModelException( 'output_truncated', 'The AI response reached its output limit. No partial action was applied.', false, $telemetry );
			}
			if ( isset( $result['finish_reason'] ) && ! in_array( $result['finish_reason'], array( 'stop', 'tool_calls', 'function_call' ), true ) ) {
				throw new ModelException( 'invalid_output', 'The AI response did not complete successfully. No returned action was applied.', false, $telemetry );
			}
			$calls = array();
			foreach ( $result['tool_calls'] ?? array() as $call ) {
				$validation = 'tool_binding';
				if ( ! in_array( $call['name'] ?? null, $names, true ) ) {
					throw new \UnexpectedValueException( 'Unbound function call.' ); }
				$validation = 'tool_arguments';
				if ( ! is_string( $call['arguments'] ?? null ) || strlen( $call['arguments'] ) > 65536 ) {
					throw new \UnexpectedValueException( 'Invalid bounded function arguments.' ); }
				if ( BuilderReadTargetTransport::FUNCTION_NAME === $call['name'] ) {
					try { $calls[] = BuilderReadTargetTransport::managed_call( $request, $call['id'], $call['arguments'] ); }
					catch ( \Throwable $error ) { $read_repair_feedback = BuilderReadTargetTransport::validation_feedback( $request, $call['arguments'] ); throw $error; }
					continue;
				}
				if ( BuilderStyleTargetTransport::FUNCTION_NAME === $call['name'] ) { $calls[] = BuilderStyleTargetTransport::managed_call( $request, $call['id'], $call['arguments'] ); continue; }
				if ( BuilderFactoryTransport::FUNCTION_NAME === $call['name'] ) {
					try {
						$calls[] = BuilderFactoryTransport::managed_call( $request, $call['id'], $call['arguments'] );
					} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
						$telemetry['builder_factory_repair_codes'] = $error->repair_codes();
						try {
							$snapshot                                     = BuilderFactoryTransport::for_request( $request );
							$values                                       = ( new \DirectoristAIPro\Validation\JsonDecoder( 65536, 32 ) )->decode( $call['arguments'] );
							$telemetry['builder_factory_schema_feedback'] = null === $snapshot ? array() : BuilderFactorySchemaFeedback::explain( $values, $snapshot->parameters );
							$factory_repair_candidate                     = null === $snapshot ? null : BuilderFactoryRepairCandidate::capture( $call['arguments'], $snapshot );
						} catch ( \Throwable $diagnostic_error ) {
							// Diagnostics never replace the original strict rejection.
							$telemetry['builder_factory_schema_feedback'] = array();
						}
						throw $error;
					}
					continue;
				}
				$direct_only   = BuilderDraftTransport::strict_direct( $request, $call['name'] );
				$direct_schema = BuilderDraftTransport::schema( $request, $call['name'] );
				if ( null !== $direct_schema ) {
					$args = BuilderDraftTransport::managed_arguments( $call['arguments'], $direct_schema, $direct_only );
				} elseif ( $call['name'] === $request->visitor_plan_function ) {
					// Keep original bytes so native validation can reject duplicate members and repair safely.
					$native = json_decode( $call['arguments'] );
					$args   = $native instanceof \stdClass && array_keys( get_object_vars( $native ) ) === array( 'input_json' ) && is_string( $native->input_json ) ? $native : (object) array( 'input_json' => $call['arguments'] );
				} else {
					$args = json_decode( $call['arguments'], false, 32, JSON_THROW_ON_ERROR );
					if ( $args instanceof \stdClass && array( 'input_json' ) === array_keys( get_object_vars( $args ) ) && ! is_string( $args->input_json ) ) {
						$validation = 'native_envelope';
						$args       = $this->native_object_envelope( $call['arguments'], $native_object_schemas[ $call['name'] ] ?? null );
					}
				}
				$validation = 'native_envelope';
				if ( ! $args instanceof \stdClass || array_keys( get_object_vars( $args ) ) !== array( 'input_json' ) || ! is_string( $args->input_json ) ) {
					throw new \UnexpectedValueException( 'Invalid native envelope.' ); }
				$validation = 'tool_binding';
				$calls[]    = new ModelToolCall( $call['id'], $call['name'], $args );
			}
			$validation          = null;
			$response            = new ModelResponse( $result['text'] ?? null, $calls, $input, $output, hash( 'sha256', $receipt['id'] ) );
			$response->telemetry = array_merge(
				$telemetry,
				array(
					'usage_reported' => $reported,
					'input_tokens'   => $reported ? $input : null,
					'output_tokens'  => $reported ? $output : null,
					'reported_model' => null,
				)
			);
			return $response;
		} catch ( \Throwable $e ) {
			if ( $e instanceof ModelException && 'output_truncated' === $e->error_code() ) {
				throw $e; }
			if ( null !== $validation ) {
				$telemetry['managed_output_validation'] = $validation;
			}
			if ( 'usage' === $validation ) {
				$telemetry['usage_reported'] = false;
				$telemetry['input_tokens']   = null;
				$telemetry['output_tokens']  = null;
			}
			$error = new ModelException( 'invalid_output', 'The managed provider returned invalid content. Its service receipt is retained. No returned action was executed.', false, $telemetry );
			if ( in_array( $validation, array( 'tool_arguments', 'native_envelope' ), true ) && is_string( $call['name'] ?? null ) && is_string( $call['arguments'] ?? null ) ) {
				$error->set_builder_rejected_output( $call['name'], $call['arguments'] );
			}
			$error->set_builder_factory_repair_candidate( $factory_repair_candidate );
			$error->set_builder_read_feedback( $read_repair_feedback );
			throw $error; }
	}

	/** Capture only known native wrapper contracts from the exact admitted body. */
	private function native_envelope_schemas( array $body ): array {
		$vars    = static fn( $value ): ?array => is_array( $value ) ? $value : ( $value instanceof \stdClass ? get_object_vars( $value ) : null );
		$matches = array();
		foreach ( $body['tools'] ?? array() as $tool ) {
			$tool     = $vars( $tool );
			$function = $vars( $tool['function'] ?? null );
			$name     = $function['name'] ?? null;
			if ( 'function' === ( $tool['type'] ?? null ) && is_string( $name ) && str_starts_with( $name, 'wpab__directorist-ai__' ) ) {
				$matches[ $name ][] = $function['parameters'] ?? null; }
		}
		$schemas = array();
		$prefix  = 'JSON encoded operation input. Authoritative schema: ';
		foreach ( $matches as $name => $declarations ) {
			if ( 1 !== count( $declarations ) ) {
				continue; }
			$schema     = $vars( $declarations[0] );
			$properties = $vars( $schema['properties'] ?? null );
			$input      = $vars( $properties['input_json'] ?? null );
			if ( null === $schema || 'object' !== ( $schema['type'] ?? null ) || null === $properties || array( 'input_json' ) !== array_keys( $properties ) || array( 'input_json' ) !== ( $schema['required'] ?? null ) || false !== ( $schema['additionalProperties'] ?? null ) || null === $input || 'string' !== ( $input['type'] ?? null ) || array_diff( array_keys( $schema ), array( 'type', 'properties', 'required', 'additionalProperties' ) ) || array_diff( array_keys( $input ), array( 'type', 'maxLength', 'description' ) ) ) {
				continue; }
			$description = $input['description'] ?? null;
			$max_bytes   = $input['maxLength'] ?? null;
			if ( ! is_string( $description ) || ! str_starts_with( $description, $prefix ) || ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > 1048576 ) {
				continue; }
			$schemas[ $name ] = array(
				'json'      => substr( $description, strlen( $prefix ) ),
				'max_bytes' => $max_bytes,
			);
		}
		return $schemas;
	}

	/**
	 * Normalize a detached object only after original bytes pass strict duplicate checks.
	 *
	 * @param string $raw    Original provider argument bytes.
	 * @param ?array $schema Admitted native argument schema and limits.
	 * @return \stdClass Native string envelope.
	 * @throws \UnexpectedValueException The exact admitted wrapper is missing or invalid.
	 */
	private function native_object_envelope( string $raw, ?array $schema ): \stdClass {
		if ( null === $schema ) {
			throw new \UnexpectedValueException( 'No exact admitted native wrapper.' ); }
		$outer = ( new \DirectoristAIPro\Validation\JsonDecoder( 65536, 32 ) )->decode( $raw );
		if ( ! $outer instanceof \stdClass || array( 'input_json' ) !== array_keys( get_object_vars( $outer ) ) || ! $outer->input_json instanceof \stdClass ) {
			throw new \UnexpectedValueException( 'Expected declared native operation object.' ); }
		$decoder  = new \DirectoristAIPro\Validation\JsonDecoder( $schema['max_bytes'], 32 );
		$registry = new \DirectoristAIPro\Validation\SchemaRegistry();
		$registry->register( 'managed.operation', $schema['json'] );
		( new \DirectoristAIPro\Validation\ContractValidator( $registry, $decoder ) )->validate( $outer->input_json, 'managed.operation' );
		return (object) array( 'input_json' => json_encode( $outer->input_json, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 33 ) );
	}

	public static function tokens( $value ): int {
		if ( null === $value ) {
			return 0; }
		if ( ! is_string( $value ) || ! preg_match( '/^(0|[1-9][0-9]{0,8})$/D', $value ) ) {
			throw new \UnexpectedValueException( 'Invalid bounded token evidence.' ); }
		return (int) $value;
	}
}
