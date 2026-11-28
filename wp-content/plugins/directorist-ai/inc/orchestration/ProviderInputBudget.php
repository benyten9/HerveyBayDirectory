<?php
/**
 * Declaration-aware input reservations without provider or network access.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Validation\ContractException;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/** Byte-tokenized inputs reserve every serialized byte plus bounded framing. */
final class ProviderInputBudget {
	private const MAX_INPUT    = 64000;
	private const FRAME_TOKENS = 16;

	/**
	 * Numeric observations from this instance's latest complete serialization.
	 *
	 * This is local admission policy evidence, never a model context capacity,
	 * provider usage report, or authority to enlarge a reservation.
	 *
	 * @var array|null
	 */
	private ?array $last_accounting = null;

	/**
	 * Read the latest redacted accounting without serializing declarations again.
	 *
	 * Oversized input retains its measured accounting. Invalid or unprepared
	 * requests clear it, so a later failure cannot inherit an earlier observation.
	 *
	 * @return array|null Detached scalar observations, or no complete observation.
	 */
	public function last_accounting(): ?array {
		return $this->last_accounting;
	}

	/**
	 * Freeze the same declaration DTOs produced by native using_abilities.
	 *
	 * @param ModelRequest $request Bounded request with exact ability names.
	 * @return ModelRequest Detached request with transport declaration snapshots.
	 * @throws ModelException Native declaration preparation is unavailable or invalid.
	 * @throws ContractException Complete input cannot fit after history compaction.
	 * @throws \UnexpectedValueException Unavailable declaration caught at the redacted boundary.
	 */
	public function prepare( ModelRequest $request ): ModelRequest {
		$this->last_accounting           = null;
		$prepared                        = clone $request;
		$prepared->function_declarations = array();
		try {
			if ( ( null !== $request->builder_initial_factory || null !== $request->builder_initial_factory_json ) && ( ! $request->builder_initial_layout || null !== $request->visitor_plan_function || ! in_array( BuilderFactoryTransport::ABILITY_NAME, $request->ability_names, true ) ) ) {
				throw new \UnexpectedValueException( 'The admitted factory ability is unavailable.' );
			}
			if ( ( null !== $request->builder_read_targets || null !== $request->builder_read_targets_json ) && ( $request->builder_initial_layout || null !== $request->visitor_plan_function || ! in_array( BuilderReadTargetTransport::ABILITY_NAME, $request->ability_names, true ) ) ) {
				throw new \UnexpectedValueException( 'The admitted native read ability is unavailable.' );
			}
			if ( ( null !== $request->builder_style_targets || null !== $request->builder_style_targets_json ) && ( $request->builder_initial_layout || null !== $request->visitor_plan_function || 'set_control' !== $request->builder_change_operation || ! in_array( BuilderStyleTargetTransport::ABILITY_NAME, $request->ability_names, true ) || ( null === $request->builder_read_targets && null === $request->builder_read_targets_json ) ) ) { throw new \UnexpectedValueException( 'The admitted paired native style ability is unavailable.' ); }
			foreach ( $request->ability_names as $name ) {
				if ( ! class_exists( '\WP_AI_Client_Ability_Function_Resolver' ) ) {
					throw new \UnexpectedValueException( 'Native declaration APIs are unavailable.' );
				}
				$ability = wp_get_ability( $name );
				if ( ! $ability instanceof \WP_Ability ) {
					throw new \UnexpectedValueException( 'An exact allowlisted ability is unavailable.' );
				}
				// Reject recursive/non-JSON schemas before native lowering can recurse.
				$source = $ability->get_input_schema();
				json_encode( $source, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 64 );
				// Core 7.0 passes raw schemas; 7.1 uses its public native preparation API.
				$schema  = function_exists( 'wp_prepare_json_schema_for_client' ) ? wp_prepare_json_schema_for_client( $source ) : $source;
				$factory = null;
				$read_target_declaration = null;
				$style_target_declaration = null;
				$function                 = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $ability->get_name() );
				if ( BuilderReadTargetTransport::ABILITY_NAME === $ability->get_name() && ( null !== $request->builder_read_targets || null !== $request->builder_read_targets_json ) ) {
					$prepared->builder_read_targets = BuilderReadTargetTransport::prepare( $request->builder_read_targets_json ?? $request->builder_read_targets, $request->builder_read_authority_hash, $schema );
					$read_target_declaration = BuilderReadTargetTransport::declaration( $prepared->builder_read_targets );
				}
				if ( null !== $request->builder_change_operation && ! in_array( $request->builder_change_operation, array( 'insert', 'configure_saved_variants', 'set_control' ), true ) ) {
					throw new \UnexpectedValueException( 'Invalid builder declaration restriction.' ); }
				if ( ! $request->builder_initial_layout && null !== $request->builder_change_operation && BuilderFactoryTransport::ABILITY_NAME === $ability->get_name() ) {
					$schema = BuilderCreationPrompt::declaration( $schema, $request->builder_change_operation );
				}
				if ( BuilderStyleTargetTransport::ABILITY_NAME === $ability->get_name() && ( null !== $request->builder_style_targets || null !== $request->builder_style_targets_json ) ) {
					$prepared->builder_style_targets = BuilderStyleTargetTransport::prepare( $request->builder_style_targets_json ?? $request->builder_style_targets, $request->builder_read_authority_hash, $schema );
					$style_target_declaration = BuilderStyleTargetTransport::declaration( $prepared->builder_style_targets );
				}
				if ( $request->builder_initial_layout && BuilderFactoryTransport::ABILITY_NAME === $ability->get_name() ) {
					if ( null !== $request->builder_initial_factory || null !== $request->builder_initial_factory_json ) {
						$prepared->builder_initial_factory = BuilderFactoryTransport::prepare( $request->builder_initial_factory_json ?? $request->builder_initial_factory, BuilderCreationPrompt::declaration( $schema ) );
						$factory                           = BuilderFactoryTransport::declaration( $prepared->builder_initial_factory );
					} else {
						$schema = BuilderDraftTransport::declaration( BuilderCreationPrompt::declaration( $schema ) );
					}
				}
				if ( $request->builder_editor_direct && ! $request->builder_initial_layout && null === $request->visitor_plan_function
					&& null === $read_target_declaration && null === $style_target_declaration && in_array( $ability->get_name(), BuilderDraftTransport::EDITOR_ABILITIES, true ) ) {
					$schema = BuilderDraftTransport::editor_declaration( $function, $schema );
				}
				if ( null !== $request->visitor_plan_function ) {
					if ( \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $ability->get_name() ) !== $request->visitor_plan_function ) {
						continue; }
					$schema = $request->visitor_response ? VisitorReply::schema( $request->visitor_response_max_length ) : VisitorPlan::schema();
					if ( ! $request->visitor_response && $request->visitor_language_gate ) {
						$schema = \DirectoristAIPro\AI\LanguagePolicy::planner_schema( $schema );
					}
				}
				$declaration = $read_target_declaration ?? $style_target_declaration ?? $factory ?? new FunctionDeclaration(
					$function,
					null !== $request->visitor_plan_function ? ( $request->visitor_response ? 'Write the final concise answer from current native evidence using the exact response schema.' : 'Choose one visitor task using the current public directory catalog. Omit unused fields. Site topics use current native facts.' ) : $ability->get_description(),
					$schema ? $schema : null
				);
				json_encode( $declaration->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 64 );
				$prepared->function_declarations[] = FunctionDeclaration::fromArray( $this->detach( $declaration->toArray() ) );
			}
		} catch ( \Throwable $error ) {
			throw new ModelException( 'unsupported_provider_capability', 'The native WordPress ability declarations could not be prepared safely.' );
		}
		try {
			$this->input_tokens( $prepared );
		} catch ( ContractException $error ) {
			if ( 'input_context_too_large' !== $error->error_code() ) {
				throw $error;
			}
			// This local pass happens before admission or billing. Never shorten native evidence.
			$original = $prepared->prompt;
			foreach ( array( 8, 4, 1 ) as $keep ) {
				$compact = ConversationContext::compact( $original, $keep );
				if ( null === $compact ) {
					continue;
				}
				$prepared->prompt = $compact;
				try {
					$this->input_tokens( $prepared );
					return $prepared;
				} catch ( ContractException $next ) {
					if ( 'input_context_too_large' !== $next->error_code() ) {
						throw $next;
					}
					$error = $next;
				}
			}
			throw $error;
		}
		return $prepared;
	}

	/**
	 * Reserve serialized content/tool bytes plus chat and tool framing tokens.
	 *
	 * @param ModelRequest $request Prepared native request.
	 * @return int Input bound for byte-tokenized models with bounded framing.
	 * @throws ContractException Missing snapshots or an oversized bounded input.
	 * @throws \UnexpectedValueException Invalid snapshot caught at the redacted boundary.
	 */
	public function input_tokens( ModelRequest $request ): int {
		$this->last_accounting = null;
		if ( null === $request->function_declarations ) {
			throw new ContractException( 'unsupported_provider_capability', 'A native declaration snapshot is required for input reservation.' );
		}
		try {
			$tools = array();
			foreach ( $request->function_declarations as $declaration ) {
				if ( ! $declaration instanceof FunctionDeclaration ) {
					throw new \UnexpectedValueException( 'Invalid native declaration snapshot.' );
				}
				$tools[] = array(
					'type'     => 'function',
					'function' => $declaration->toArray(),
				);
			}
			$input = array(
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $request->system_instruction,
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $request->prompt,
							),
						),
					),
				),
				'max_tokens'  => $request->max_tokens,
				'temperature' => 0.1,
				'n'           => 1,
			);
			if ( $tools ) {
				$input['tools'] = $tools;
			}
			$bytes = strlen( json_encode( $input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 64 ) );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'unsupported_provider_capability', 'The native request declarations could not be serialized safely.' );
		}
		// Two input roles, one assistant prefill, and one frame per declared tool.
		$framing               = self::FRAME_TOKENS * ( 3 + count( $tools ) );
		$tokens                = $bytes + $framing;
		$limit                 = $request->context_capacity_verified ? min( 262144, max( 1, $request->input_limit ) ) : min( self::MAX_INPUT, max( 1, $request->input_limit ) );
		$this->last_accounting = array(
			'revision'                        => 'serialized-byte-reservation-v1',
			'unit'                            => 'conservative_byte_token_upper_bound',
			'prompt_bytes'                    => strlen( $request->prompt ),
			'system_bytes'                    => strlen( $request->system_instruction ),
			'declared_tool_count'             => count( $tools ),
			'serialized_request_bytes'        => $bytes,
			'framing_reservation'             => $framing,
			'input_reservation'               => $tokens,
			'input_policy_limit'              => $limit,
			'fits_input_policy'               => $tokens <= $limit,
			'model_context_capacity_verified' => $request->context_capacity_verified,
		);
		if ( $tokens > $limit ) {
			throw new ContractException( 'input_context_too_large', 'The complete declared model input exceeds the bounded request size.', array( 'declared_input_limit' ) );
		}
		return $tokens;
	}

	/**
	 * Detach native JSON values while preserving array and object identity.
	 *
	 * @param mixed $value Syntax-checked native declaration value.
	 * @return mixed Detached value with unchanged shape.
	 * @throws \UnexpectedValueException Unsupported native schema value.
	 */
	private function detach( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'detach' ), $value );
		}
		if ( $value instanceof \stdClass ) {
			$copy = new \stdClass();
			foreach ( get_object_vars( $value ) as $key => $child ) {
				$copy->{$key} = $this->detach( $child );
			}
			return $copy;
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			throw new \UnexpectedValueException( 'Unsupported native schema value.' );
		}
		return $value;
	}
}
