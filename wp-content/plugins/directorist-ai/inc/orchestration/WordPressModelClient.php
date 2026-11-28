<?php
/**
 * WordPress 7 AI Client adapter with no plugin-owned credential storage.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Tools\DTO\FunctionCall;

/** Provider and model selection remain in WordPress Connectors. */
final class WordPressModelClient implements ModelClient {
	/**
	 * Generate through the site-configured WordPress provider.
	 *
	 * @param ModelRequest $request Closed provider request.
	 * @return ModelResponse Visible content, calls and usage.
	 * @throws ModelException Provider is missing, unsupported or malformed.
	 * @throws \UnexpectedValueException Caught internally and normalized at the provider boundary.
	 */
	public function generate( ModelRequest $request ): ModelResponse {
		if ( null !== $request->ai_route ) {
			$request->ai_route = \DirectoristAIPro\AI\Settings::for_task( $request->ai_route, $request->task );
		}
		if ( 'managed' === ( $request->ai_route['source'] ?? '' ) ) {
			return ( new ManagedModelClient() )->generate( $request ); }
		$factory_names = null;
		$read_repair_feedback = null;
		if ( null !== $request->builder_style_targets || null !== $request->builder_style_targets_json ) {
			try { if ( null === BuilderStyleTargetTransport::for_request( $request ) ) { throw new \UnexpectedValueException( 'No paired current style targets.' ); } $factory_names = array_map( static fn( $d ): string => $d->getName(), $request->function_declarations ); }
			catch ( \Throwable $error ) { throw new ModelException( 'invalid_input', 'The current native style declaration is unavailable.' ); }
		}
		if ( null !== $request->builder_read_targets || null !== $request->builder_read_targets_json ) {
			try {
				if ( null === BuilderReadTargetTransport::for_request( $request ) ) { throw new \UnexpectedValueException( 'No admitted native read targets.' ); }
				$factory_names = array_map( static fn( $declaration ): string => $declaration->getName(), $request->function_declarations );
			} catch ( \Throwable $error ) {
				throw new ModelException( 'invalid_input', 'The current native read declaration is unavailable.' );
			}
		}
		if ( null !== $request->builder_initial_factory || null !== $request->builder_initial_factory_json ) {
			try {
				if ( null === BuilderFactoryTransport::for_request( $request ) ) {
					throw new \UnexpectedValueException( 'An exact prepared factory is required.' );
				}
				$factory_names = array_map( static fn( $declaration ): string => $declaration->getName(), $request->function_declarations );
			} catch ( \Throwable $error ) {
				throw new ModelException( 'invalid_input', 'The exact initial layout declaration is unavailable.' );
			}
		}
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! wp_supports_ai() ) {
			throw new ModelException( 'provider_unavailable', 'WordPress AI support is disabled or unavailable.' );
		}
		$builder = wp_ai_client_prompt( $request->prompt )
			->using_system_instruction( $request->system_instruction )
			->using_max_tokens( $request->max_tokens )
			->using_temperature( 0.1 )
			->using_candidate_count( 1 )
			->using_request_options(
				RequestOptions::fromArray(
					array(
						RequestOptions::KEY_TIMEOUT => $request->timeout,
					)
				)
			);
		$builder = \DirectoristAIPro\AI\Routing::bind( $builder, $request->ai_route ?? \DirectoristAIPro\AI\Settings::snapshot( 'admin' ), $request->task );
		if ( null !== $request->function_declarations && $request->function_declarations ) {
			$builder->using_function_declarations( ...$request->function_declarations );
		} elseif ( null === $request->function_declarations && $request->ability_names ) {
			$builder->using_abilities( ...$request->ability_names );
		}
		try {
			$supported = $builder->is_supported_for_text_generation();
		} catch ( \Throwable $error ) {
			throw new ModelException( 'provider_capability_unknown', \DirectoristAIPro\AI\FailureNotice::message( 'provider_capability_unknown', 'wordpress', $request->task ) ); // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
		}
		// Some native wrapper errors return the builder; only an explicit true admits generation.
		if ( true !== $supported ) {
			$code = false === $supported ? 'unsupported_provider_capability' : 'provider_capability_unknown';
			throw new ModelException( $code, \DirectoristAIPro\AI\FailureNotice::message( $code, 'wordpress', $request->task ) ); // phpcs:ignore WordPress.WP.CapitalPDangit -- Internal source identifier.
		}
		$selected_route   = array();
		$capture_route    = function ( $event ) use ( &$selected_route ): void {
			if ( ! $event instanceof \WordPress\AiClient\Events\BeforeGenerateResultEvent ) {
				return;
			}
			try {
				$model = $event->getModel();
				$route = $this->valid_route_identity( $model->providerMetadata()->getId(), $model->metadata()->getId() );
				if ( null !== $route[0] ) {
					$selected_route = array(
						'provider_id' => $route[0],
						'model_id'    => $route[1],
					);
				} else {
					$selected_route = array();
				}
			} catch ( \Throwable $error ) {
				// Optional route diagnostics cannot change provider execution.
				$selected_route = array();
			}
		};
		$argument_schemas = $this->argument_schemas( $request );
		add_action( 'wp_ai_client_before_generate_result', $capture_route, PHP_INT_MAX );
		try {
			$result = $builder->generate_text_result();
		} catch ( ModelException $error ) {
			throw $error;
		} catch ( \Throwable $error ) {
			// Core normally returns WP_Error. Connector implementations may throw instead.
			$result = $this->native_exception( $error );
		} finally {
			remove_action( 'wp_ai_client_before_generate_result', $capture_route, PHP_INT_MAX );
		}
		if ( is_wp_error( $result ) ) {
			throw new ModelException( 'provider_unavailable', 'The configured WordPress AI provider could not complete the request.', $this->retryable_error( $result ), array_merge( $this->error_telemetry( $result ), $selected_route ) );
		}
		$candidates = $result->getCandidates();
		if ( 1 !== count( $candidates ) ) {
			throw new ModelException( 'invalid_output', 'The provider returned an unexpected candidate count.' );
		}
		if ( $candidates[0]->getFinishReason()->isLength() ) {
			throw new ModelException( 'output_truncated', 'The configured WordPress AI provider reached the request token limit.' );
		}
		try {
			$text  = array();
			$calls = array();
			foreach ( $candidates[0]->getMessage()->getParts() as $part ) {
				if ( ! $part->getChannel()->isContent() ) {
					continue;
				}
				if ( null !== $part->getText() ) {
					$text[] = $part->getText();
				}
				$call = $part->getFunctionCall();
				if ( $call instanceof FunctionCall ) {
					if ( null !== $factory_names && ! in_array( $call->getName(), $factory_names, true ) ) {
						throw new \UnexpectedValueException( 'The model returned an undeclared initial layout function.' );
					}
					if ( BuilderFactoryTransport::FUNCTION_NAME === $call->getName() ) {
						$calls[] = BuilderFactoryTransport::wordpress_call( $request, $call->getId() ?? '', $call->getArgs() );
						continue;
					}
					if ( BuilderReadTargetTransport::FUNCTION_NAME === $call->getName() ) {
						try { $calls[] = BuilderReadTargetTransport::wordpress_call( $request, $call->getId() ?? '', $call->getArgs() ); }
						catch ( \Throwable $error ) { $read_repair_feedback = BuilderReadTargetTransport::validation_feedback( $request, json_encode( $call->getArgs(), JSON_THROW_ON_ERROR ) ); throw $error; }
						continue;
					}
					if ( BuilderStyleTargetTransport::FUNCTION_NAME === $call->getName() ) { $calls[] = BuilderStyleTargetTransport::wordpress_call( $request, $call->getId() ?? '', $call->getArgs() ); continue; }
					$direct_only   = BuilderDraftTransport::strict_direct( $request, $call->getName() ?? '' );
					$direct_schema = BuilderDraftTransport::schema( $request, $call->getName() ?? '' );
					$arguments     = null !== $direct_schema ? BuilderDraftTransport::wordpress_arguments( $call->getArgs(), $direct_schema, $direct_only ) : $this->call_arguments( $call->getArgs(), $argument_schemas[ $call->getName() ] ?? null );
					$calls[]       = new ModelToolCall( $call->getId() ?? '', $call->getName() ?? '', $arguments );
				}
			}
			$usage               = $result->getTokenUsage();
			$route               = $this->native_route_identity( $result );
			$response            = new ModelResponse(
				$text ? implode( "\n", $text ) : null,
				$calls,
				$usage->getPromptTokens(),
				$usage->getCompletionTokens(),
				hash( 'sha256', $result->getId() ),
				$route[0],
				$route[1]
			);
			$response->telemetry = \DirectoristAIPro\AI\Telemetry::result( $result );
			return $response;
		} catch ( \Throwable $error ) {
			$failure = new ModelException( 'invalid_output', 'The provider returned a malformed bounded response.' );
			$failure->set_builder_read_feedback( $read_repair_feedback );
			throw $failure;
		}
	}

	/**
	 * Read an optional native route without making telemetry generation-critical.
	 *
	 * The pair is retained only when both exact native IDs satisfy the durable
	 * redacted step contract. IDs are never repaired, truncated or inferred.
	 *
	 * @param object $result Native WordPress AI result.
	 * @return array{0:?string,1:?string} Valid provider/model pair or two nulls.
	 */
	private function native_route_identity( object $result ): array {
		try {
			$provider_id = $result->getProviderMetadata()->getId();
			$model_id    = $result->getModelMetadata()->getId();
		} catch ( \Throwable $error ) {
			return array( null, null );
		}
		return $this->valid_route_identity( $provider_id, $model_id );
	}

	/**
	 * Admit only the complete bounded native provider/model identity pair.
	 *
	 * @param mixed $provider_id Native provider identity.
	 * @param mixed $model_id Native model identity.
	 * @return array{0:?string,1:?string} Safe pair or two nulls.
	 */
	private function valid_route_identity( $provider_id, $model_id ): array {
		if ( ! is_string( $provider_id ) || ! preg_match( '/^[a-z0-9_-]{1,128}$/D', $provider_id ) || ! is_string( $model_id ) || ! preg_match( '/^[A-Za-z0-9._:\\/@+-]{1,191}$/D', $model_id ) ) {
			return array( null, null );
		}
		return array( $provider_id, $model_id );
	}

	/**
	 * Retry only recognized transport failures and native transient HTTP errors.
	 *
	 * Core's exception mapper supplies integer statuses. Neither unknown codes
	 * nor malformed status data can turn a permanent failure into a retry.
	 *
	 * @param \WP_Error $error Native provider error; its message stays private.
	 * @return bool Whether the bounded worker retry policy may apply.
	 */
	private function retryable_error( \WP_Error $error ): bool {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'network_error', 'server_error', 'request_timeout', 'http_request_failed' ), true ) ) {
			return true;
		}
		$data   = $error->get_error_data( $code );
		$status = is_array( $data ) ? ( $data['status'] ?? null ) : null;
		if ( ! is_int( $status ) ) {
			return false;
		}
		return ( 'prompt_network_error' === $code && 503 === $status )
			|| ( 'prompt_client_error' === $code && 429 === $status )
			|| ( 'prompt_upstream_server_error' === $code && $status >= 500 && $status <= 599 );
	}

	/**
	 * Retain only a closed native code, integer HTTP status and fixed reason.
	 *
	 * Neither provider messages, response bodies nor exception classes are copied.
	 * The broad durable failure code remains compatible with existing consumers.
	 *
	 * @param \WP_Error $error Native boundary failure.
	 * @return array Safe metadata for the owned provider step.
	 */
	private function error_telemetry( \WP_Error $error ): array {
		$code       = $error->get_error_code();
		$data       = $error->get_error_data( $code );
		$status     = is_array( $data ) ? ( $data['status'] ?? null ) : null;
		$diagnostic = \DirectoristAIPro\AI\FailureNotice::wordpress_diagnostic(
			array(
				'code'        => $code,
				'http_status' => $status,
			)
		);
		// Classify only a bounded known model-unavailable message. The original
		// message, response body and any credentials never leave this boundary.
		if ( 'prompt_client_error' === $diagnostic['code'] && 404 === $diagnostic['http_status'] && preg_match( '/\bmodels?\b[^\r\n]{0,300}\b(?:is no longer available|is not available|was not found|does not exist|is not found)\b/i', substr( $error->get_error_message( $code ), 0, 4096 ) ) ) {
			$diagnostic['reason'] = 'model_unavailable';
		}
		return array( 'wordpress_error' => $diagnostic );
	}

	/**
	 * Match installed core's exception families without retaining their messages.
	 *
	 * @param \Throwable $error Connector exception.
	 * @return \WP_Error Redacted primary failure for the same bounded retry policy.
	 */
	private function native_exception( \Throwable $error ): \WP_Error {
		$code   = 'prompt_builder_error';
		$status = 500;
		if ( $error instanceof \WordPress\AiClient\Providers\Http\Exception\NetworkException ) {
			$code   = 'prompt_network_error';
			$status = 503;
		} elseif ( $error instanceof \WordPress\AiClient\Providers\Http\Exception\ClientException ) {
			$code   = 'prompt_client_error';
			$status = $error->getCode() ? $error->getCode() : 400;
		} elseif ( $error instanceof \WordPress\AiClient\Providers\Http\Exception\ServerException ) {
			$code   = 'prompt_upstream_server_error';
			$status = $error->getCode() ? $error->getCode() : 500;
		} elseif ( $error instanceof \WordPress\AiClient\Common\Exception\TokenLimitReachedException ) {
			$code   = 'prompt_token_limit_reached';
			$status = 400;
		} elseif ( $error instanceof \WordPress\AiClient\Common\Exception\InvalidArgumentException ) {
			$code   = 'prompt_invalid_argument';
			$status = 400;
		}
		return new \WP_Error( $code, 'The WordPress provider request failed.', array( 'status' => $status ) );
	}

	/**
	 * Freeze eligible envelopes before the provider call, not from returned data.
	 * Existing direct builder transports remain separate and unchanged.
	 *
	 * @param ModelRequest $request Admitted request and declared functions.
	 * @return array Native envelopes keyed by provider function.
	 */
	private function argument_schemas( ModelRequest $request ): array {
		$allowed = array();
		foreach ( $request->ability_names as $name ) {
			if ( str_starts_with( $name, 'directorist-ai/' ) ) {
				$allowed[ \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $name ) ] = $name;
			}
		}
		$declarations = array();
		if ( null !== $request->function_declarations ) {
			foreach ( $request->function_declarations as $declaration ) {
				if ( $declaration instanceof \WordPress\AiClient\Tools\DTO\FunctionDeclaration && isset( $allowed[ $declaration->getName() ] ) ) {
					$declarations[ $declaration->getName() ][] = $declaration->getParameters();
				}
			}
		} else {
			foreach ( $allowed as $function => $name ) {
				$ability = wp_get_ability( $name );
				if ( $ability instanceof \WP_Ability ) {
					$declarations[ $function ][] = $ability->get_input_schema(); }
			}
		}
		$schemas = array();
		$prefix  = 'JSON encoded operation input. Authoritative schema: ';
		foreach ( $declarations as $function => $matches ) {
			if ( 1 !== count( $matches ) ) {
				continue; }
			$schema     = $matches[0];
			$properties = $schema['properties'] ?? null;
			$input      = is_array( $properties ) ? ( $properties['input_json'] ?? null ) : null;
			if ( 'object' !== ( $schema['type'] ?? null ) || ! is_array( $properties ) || array( 'input_json' ) !== array_keys( $properties ) || array( 'input_json' ) !== ( $schema['required'] ?? null ) || false !== ( $schema['additionalProperties'] ?? null ) || ! is_array( $input ) || 'string' !== ( $input['type'] ?? null ) ) {
				continue; }
			// Only the known native wrapper is translated. Unknown transport
			// assertions cannot be discarded to make a returned object pass.
			if ( array_diff( array_keys( $schema ), array( 'type', 'properties', 'required', 'additionalProperties' ) ) || array_diff( array_keys( $input ), array( 'type', 'maxLength', 'description' ) ) ) {
				continue; }
			$description = $input['description'] ?? null;
			$max_bytes   = $input['maxLength'] ?? 1048576;
			if ( ! is_string( $description ) || ! str_starts_with( $description, $prefix ) || ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > 1048576 ) {
				continue; }
			$schemas[ $function ] = array(
				'json'      => substr( $description, strlen( $prefix ) ),
				'max_bytes' => $max_bytes,
			);
		}
		return $schemas;
	}

	/**
	 * Preserve SDK lists and scalar types without invoking arbitrary serializers.
	 * Raw duplicate members have already been decoded by the native SDK. Original
	 * string envelopes stay byte-exact for the gateway's duplicate-member checks.
	 *
	 * @param mixed $value SDK argument value.
	 * @param int   $depth Current traversal depth.
	 * @return mixed Plain native argument value.
	 * @throws \UnexpectedValueException The value is unsupported or too deeply nested.
	 */
	private function structured_value( $value, int $depth = 0 ) {
		if ( is_array( $value ) || $value instanceof \stdClass ) {
			if ( $depth >= 32 ) {
				throw new \UnexpectedValueException( 'Native arguments exceed the existing depth limit.' ); }
			$object = $value instanceof \stdClass || ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
			$result = $object ? new \stdClass() : array();
			foreach ( $value as $key => $child ) {
				$child = $this->structured_value( $child, $depth + 1 );
				if ( $object ) {
					$result->{ (string) $key} = $child;
				} else {
					$result[] = $child; }
			}
			return $result;
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			throw new \UnexpectedValueException( 'Invalid native JSON value.' ); }
		return $value;
	}

	/**
	 * Normalize only the native SDK's closed ability argument envelope.
	 *
	 * @param mixed      $arguments Native function arguments.
	 * @param array|null $schema Exact request-owned envelope contract, if eligible.
	 * @return \stdClass Native input_json string for unchanged strict gateway validation.
	 * @throws \UnexpectedValueException Unsupported function argument shape.
	 */
	private function call_arguments( $arguments, ?array $schema = null ): \stdClass {
		$envelope = $arguments instanceof \stdClass ? get_object_vars( $arguments ) : $arguments;
		if ( ! is_array( $envelope ) || array_keys( $envelope ) !== array( 'input_json' ) ) {
			throw new \UnexpectedValueException( 'Invalid native function argument envelope.' );
		}
		if ( is_string( $envelope['input_json'] ) ) {
			return $arguments instanceof \stdClass ? $arguments : (object) $envelope;
		}
		if ( null === $schema ) {
			throw new \UnexpectedValueException( 'Undeclared structured native argument envelope.' ); }
		$value = $this->structured_value( $envelope['input_json'] );
		if ( ! $value instanceof \stdClass ) {
			throw new \UnexpectedValueException( 'Expected native operation object.' ); }
		$decoder = new \DirectoristAIPro\Validation\JsonDecoder( $schema['max_bytes'], 32 );
		$decoder->check( $value );
		$registry = new \DirectoristAIPro\Validation\SchemaRegistry();
		$registry->register( 'wordpress.operation', $schema['json'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Internal schema identifier.
		( new \DirectoristAIPro\Validation\ContractValidator( $registry, $decoder ) )->validate( $value, 'wordpress.operation' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Internal schema identifier.
		return (object) array( 'input_json' => json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 33 ) );
	}
}
