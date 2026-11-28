<?php
/**
 * Bounded native design vocabulary supplied by installed builder adapters.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\JsonDecoder;

/** Design tokens inform model choices; native authoring contracts govern effects. */
final class BuilderDesignContext {
	/** Creative future values are distinct from claims about hidden saved values. */
	public const INSTRUCTION = ' You have creative discretion over requested visual design within the returned native controls. Choose suitable colors, typography, spacing, borders or shadows only to the breadth requested. Warm, elegant, welcoming, professional or softer is sufficient direction: choose values without asking the user for a HEX/RGB value. A background-only request changes only its background. Proposed values are design choices, never claims about hidden saved values. Preserve existing facts, bindings, inherited/unrelated properties and exact device/view scopes; obey schemas and activation dependencies. Prefer appropriate current theme/Kit/preset tokens or generate valid native custom values. For an explicitly named token use its exact value. For a requested live global link use the exact token.reference only when reference_supported:true and the selected schema accepts it; an equal literal is not a linked preset. reference_supported:false permits a resolved value copy only, without claiming future synchronization. Global tokens remain read-only unless an explicitly requested supported global operation exists. A terse warmer/subtler refinement continues the current goal. Respect explain-only, options-only, ask-first and all-or-nothing instructions. Ask only for an essential unresolved target, unsupported requirement or explicitly required exact brand value. Briefly explain the chosen design when a completion summary is supported.';

	/**
	 * Capture an optional adapter-owned read without expanding effect authority.
	 *
	 * @param BuilderDraftCandidateCompiler $compiler Installed native compiler.
	 * @param object                        $graph Fresh authorized native graph.
	 * @return object|null Bounded detached native vocabulary.
	 */
	public static function capture( BuilderDraftCandidateCompiler $compiler, object $graph ): ?object {
		$method = is_callable( array( $compiler, 'global_design_context_for_graph' ) ) ? 'global_design_context_for_graph' : 'design_context_for_graph';
		if ( ! is_callable( array( $compiler, $method ) ) ) {
			return null;
		}
		$value = $compiler->$method( $graph );
		if ( ! $value instanceof \stdClass || ( $value->builder ?? null ) !== ( $graph->document->builder ?? null ) || ! is_string( $value->revision ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value->revision ) ) {
			return null;
		}
		try {
			( new JsonDecoder( 16384, 24 ) )->check( $value );
			return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 25, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Keep one compact vocabulary beside projected source-bound model receipts.
	 *
	 * @param array  $projected Model-only receipt projections.
	 * @param array  $original Complete freshly guarded native receipts.
	 * @param string $intent Current client style request.
	 * @return array Detached projections with advisory design context.
	 */
	public static function project( array $projected, array $original, string $intent = '' ): array {
		$contexts = array();
		foreach ( $original as $entry ) {
			$value = $entry->response->data->authoring->design_context ?? null;
			if ( true === ( $entry->response->ok ?? null ) && 'builder.inspect' === ( $entry->response->operation ?? null ) && $value instanceof \stdClass && is_string( $entry->call_id ?? null ) ) {
				$contexts[ $entry->call_id ] = $value;
			}
		}
		$seen = array();
		foreach ( $projected as &$entry ) {
			$context = $contexts[ $entry->call_id ?? '' ] ?? null;
			if ( ! $context instanceof \stdClass || ! ( $entry->response->data ?? null ) instanceof \stdClass ) {
				continue;
			}
			$entry = json_decode( json_encode( $entry, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 64, JSON_THROW_ON_ERROR );
			unset( $entry->response->data->authoring->design_context );
			$requested = self::requested_reference( $context, $intent );
			if ( null !== $requested ) {
				$entry->response->data->requested_design_reference = $requested;
			}
			$resolved = self::requested_resolved_value( $context, $intent );
			if ( null !== $resolved ) {
				$entry->response->data->requested_design_value = $resolved;
			}
			$key = $context->builder . ':' . $context->revision;
			if ( isset( $seen[ $key ] ) ) {
				$entry->response->data->design_context_reference = (object) array(
					'call_id'  => $seen[ $key ],
					'revision' => $context->revision,
				);
				continue;
			}
			$copy = clone $context;
			// Omit unrelated advisory vocabulary for an explicit visual request.
			// Native schemas, current values and the full server read remain exact.
			if ( preg_match( '/\b(?:backgrounds?|colou?rs?|padding|margins?|spacing|borders?|radius|rounded|fonts?|typography|shadows?|gradients?)\b/i', $intent ) ) {
				foreach ( array( 'typography' => '/\b(?:fonts?|typography|text|headings?|typeface|letter|line.height)\b/i', 'gradients' => '/\bgradients?\b/i', 'shadows' => '/\bshadows?\b/i', 'block_styles' => '/\b(?:block.style|presets?)\b/i' ) as $family => $pattern ) {
					if ( ! preg_match( $pattern, $intent ) ) { unset( $copy->$family ); }
				}
			}

			// Component schemas are supplied by the exact selected authoring
			// contracts. Global vocabulary needs no duplicate component inventory.
			if ( ( $copy->bindings ?? null ) instanceof \stdClass ) {
				$copy->bindings = clone $copy->bindings;
				unset( $copy->bindings->components, $copy->bindings->native_components );
			}
			foreach ( array(
				'palette'      => 16,
				'typography'   => 8,
				'spacing'      => 12,
				'gradients'    => 6,
				'shadows'      => 6,
				'block_styles' => 8,
			) as $field => $limit ) {
				if ( is_array( $copy->$field ?? null ) && count( $copy->$field ) > $limit ) {
					$copy->$field    = array_slice( $copy->$field, 0, $limit );
					$copy->truncated = true;
				}
			}
			// Native schemas already describe component properties and conditions.
			// Keep their declared token/binding guidance once rather than repeating
			// every component's full control definitions in the prompt again.
			if ( strlen( json_encode( $copy, JSON_THROW_ON_ERROR ) ) > 8192 ) {
				unset( $copy->properties, $copy->components, $copy->native_components, $copy->native_controls, $copy->modes );
				$copy->truncated = true;
			}
			if ( strlen( json_encode( $copy, JSON_THROW_ON_ERROR ) ) > 8192 ) {
				$copy = (object) array(
					'builder'     => $context->builder,
					'revision'    => $context->revision,
					'palette'     => array_slice( $context->palette ?? array(), 0, 8 ),
					'typography'  => array_slice( $context->typography ?? array(), 0, 4 ),
					'bindings'    => $copy->bindings ?? new \stdClass(),
					'limitations' => $context->limitations ?? array(),
					'truncated'   => true,
				);
			}
			if ( strlen( json_encode( $copy, JSON_THROW_ON_ERROR ) ) > 8192 ) {
				unset( $copy->typography );
				$copy->truncated = true;
			}
			if ( strlen( json_encode( $copy, JSON_THROW_ON_ERROR ) ) <= 8192 ) {
				$entry->response->data->design_context = $copy;
				$seen[ $key ]                          = $entry->call_id;
			}
		}
		unset( $entry );
		return $projected;
	}

	/**
	 * Keep an explicitly named resolved palette value beside native controls.
	 *
	 * @param object $context Current native palette and binding capabilities.
	 * @param string $intent Current client request.
	 * @return object|null Advisory exact value, never native authority.
	 */
	private static function requested_resolved_value( object $context, string $intent ): ?object {
		$intent = self::design_request_text( $intent );
		if ( ! preg_match( '/\b(?:site|kit|theme|palette|preset|global)\b/i', $intent ) ) {
			return null;
		}
		$text    = ' ' . strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', $intent ) ) ) . ' ';
		$matches = array();
		foreach ( $context->palette ?? array() as $token ) {
			if ( false !== ( $token->reference_supported ?? null ) || ! is_string( $token->name ?? null ) || ! is_string( $token->value ?? null ) || strlen( $token->value ) > 256 ) {
				continue;
			}
			$name = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', $token->name ) ) );
			if ( '' !== $name && str_contains( $text, ' ' . $name . ' ' ) ) {
				$matches[ $token->name . ':' . $token->value ] = $token;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return null;
		}
		$token = reset( $matches );
		return (object) array(
			'name'           => $token->name,
			'resolved_value' => $token->value,
			'instruction'    => 'Use this exact resolved value for the explicitly named palette color. A different similar color does not satisfy the request. This is a local value copy, not a live global binding. Preserve global settings and follow the selected native schema and scope. This note grants no permission or approval.',
		);
	}

	/**
	 * Separate requested design choices from explicit preservation clauses.
	 *
	 * @param string $intent Current client request.
	 * @return string Requested design clauses only.
	 */
	private static function design_request_text( string $intent ): string {
		$intent  = BuilderStyleIntent::user_text( $intent );
		$clauses = preg_split( '/[.!?;\n]+|(?:\band|,)\s+(?=(?:keep|preserve|retain|leave|change|make|set|give|apply|use|switch|select|choose)\b)/i', $intent );
		return implode( ' ', array_filter( $clauses, static fn( string $clause ): bool => ! preg_match( '/^\s*(?:keep|preserve|retain|leave)\b/i', $clause ) ) );
	}
	/**
	 * Keep a named live-token request explicit beside the selected native schema.
	 *
	 * @param object $context Fresh native global vocabulary.
	 * @param string $intent Current client request.
	 * @return object|null Advisory request, never permission or a rewritten effect.
	 */
	private static function requested_reference( object $context, string $intent ): ?object {
		$intent = self::design_request_text( $intent );
		if ( ! preg_match( '/\b(?:preset|global|shared|follow|linked|sync)\b/i', $intent ) ) {
			return null;
		}
		$text    = ' ' . strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', $intent ) ) ) . ' ';
		$matches = array();
		foreach ( $context->palette ?? array() as $token ) {
			if ( true !== ( $token->reference_supported ?? null ) || ! is_string( $token->name ?? null ) || ! is_string( $token->reference ?? null ) ) {
				continue;
			}
			$name = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', $token->name ) ) );
			if ( '' !== $name && str_contains( $text, ' ' . $name . ' ' ) ) {
				$matches[ $token->reference ] = $token;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return null;
		}
		$token = reset( $matches );
		return (object) array(
			'name'           => $token->name,
			'reference'      => $token->reference,
			'resolved_value' => $token->value ?? null,
			'instruction'    => 'Use this exact reference where the selected native control schema supports it. A matching literal does not follow shared preset changes. Global values stay read-only; supported node reference assignment is allowed. This note grants no write or approval.',
		);
	}
}
