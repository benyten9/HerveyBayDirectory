<?php
/**
 * Grounded handoff contracts composed from registered retrieval contracts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,Squiz.Commenting.FunctionComment -- Closed contracts remain together for review.

use DirectoristAIPro\Validation\SchemaRegistry;

/** Model input selects a plan; only the server can produce facts or provenance. */
final class GroundedResponseContracts {
	/**
	 * Describe shared search schemas once within the native model envelope.
	 *
	 * The gateway still validates the expanded registered contract. Local refs
	 * preserve the identical constraints without exceeding managed tool limits.
	 *
	 * @param object $schema Detached expanded input schema.
	 * @return object Lossless Draft-07 description.
	 */
	public static function model_description( object $schema ): object {
		$description              = json_decode( wp_json_encode( $schema ) );
		$description->definitions = (object) array(
			'directory_search' => $description->properties->searches->items,
			'listing_comparison' => $description->properties->comparisons->items,
		);
		foreach ( array( 'search' => 'directory_search', 'compare' => 'listing_comparison' ) as $key => $definition ) {
			$reference                                = (object) array( '$ref' => '#/definitions/' . $definition );
			$description->properties->{$key}          = (object) array( 'anyOf' => array( $reference, (object) array( 'type' => 'null' ) ) );
			$batch                                    = 'search' === $key ? 'searches' : 'comparisons';
			$description->properties->{$batch}->items = $reference;
		}
		return $description;
	}

	public static function register( SchemaRegistry $schemas ): void {
		$nullable = static function ( object $schema ): object {
			$schema->type = array( 'object', 'null' );
			return $schema;
		};
		$schemas->register(
			'conversation.respond.input',
			wp_json_encode(
				self::closed(
					array(
						'question' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ),
						'resolution' => array( 'enum' => array( 'resolved', 'ambiguous', 'unsupported' ) ),
						'intent' => array( 'enum' => array( 'search', 'compare', 'knowledge', 'action', 'follow_up' ) ),
						'result_limit' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1, 'maximum' => 20 ),
						'context_token' => array( 'type' => array( 'string', 'null' ), 'maxLength' => 32768 ),
						'searches' => array( 'type' => array( 'array', 'null' ), 'maxItems' => 100, 'items' => $schemas->get( 'listing.search.input' ) ),
						'comparisons' => array( 'type' => array( 'array', 'null' ), 'maxItems' => 4, 'items' => $schemas->get( 'listing.compare.input' ) ),
						'search' => $nullable( $schemas->get( 'listing.search.input' ) ),
						'compare' => $nullable( $schemas->get( 'listing.compare.input' ) ),
						'knowledge' => $nullable( $schemas->get( 'knowledge.search.input' ) ),
						'action' => $nullable( $schemas->get( 'listing.actions.input' ) ),
					),
					array( 'action', 'searches', 'comparisons', 'result_limit' )
				)
			)
		);
		$comparison = $nullable( $schemas->get( 'listing.compare.output' ) );
		$source     = $comparison->properties->rows->items->properties->cells->items->properties->source;
		$knowledge  = $schemas->get( 'knowledge.search.output' );
		$hash       = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		$schemas->register(
			'conversation.respond.output',
			wp_json_encode(
				self::closed(
					array(
						'contract' => array( 'const' => 'directorist-ai/grounded-response-v1' ),
						'reply_origin' => array( 'enum' => array( 'native', 'llm' ) ),
						'status' => array( 'enum' => array( 'results', 'unknown', 'missing', 'conflict', 'unsupported', 'needs_input' ) ),
						'message' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ),
						'task' => self::closed( array( 'kind' => array( 'enum' => array( 'search', 'compare', 'knowledge', 'action', 'ambiguous', 'unsupported' ) ), 'family' => array( 'enum' => array( 'listing', 'knowledge' ) ), 'directory_id' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ), 'dataset' => array( 'enum' => array( 'public', 'own', 'management', null ) ) ) ),
						'count' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0 ),
						'cards' => array( 'type' => 'array', 'maxItems' => 50, 'items' => self::closed( array( 'listing_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'directory_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'version' => $hash, 'facts' => array( 'type' => 'array', 'items' => self::closed( array( 'field_id' => array( 'type' => 'string' ), 'state' => array( 'enum' => array( 'known', 'unknown' ) ), 'value' => true, 'source' => $source ) ) ) ) ) ),
						'comparison' => $comparison,
						'comparisons' => array( 'type' => 'array', 'maxItems' => 4, 'items' => $schemas->get( 'listing.compare.output' ) ),
						'native_actions' => $schemas->get( 'listing.actions.output' ),
						'evidence' => $knowledge->properties->items,
						'citations' => array( 'type' => 'array', 'maxItems' => 10, 'items' => $knowledge->properties->items->items->properties->citation ),
						'assertions' => array( 'type' => 'array', 'items' => self::closed( array( 'subject' => array( 'type' => 'string' ), 'predicate' => array( 'type' => 'string' ), 'value' => true, 'source' => $source, 'version' => $hash ) ) ),
						'limitations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'context_token' => array( 'type' => array( 'string', 'null' ), 'maxLength' => 32768 ),
						'trace' => self::closed( array( 'question_hash' => $hash, 'plan_hash' => $hash, 'initial_hash' => $hash, 'delivered_hash' => $hash, 'refreshed' => array( 'type' => 'boolean' ) ) ),
						'evidence_policy' => self::closed( array( 'content_role' => array( 'const' => 'untrusted_evidence' ), 'claims_from_model' => array( 'const' => false ), 'can_grant_tools' => array( 'const' => false ), 'can_grant_approval' => array( 'const' => false ) ) ),
					),
					array( 'count', 'native_actions', 'comparisons', 'reply_origin' )
				)
			)
		);
	}

	private static function closed( array $properties, array $optional = array() ): array {
		return array( 'type' => 'object', 'properties' => $properties, 'required' => array_values( array_diff( array_keys( $properties ), $optional ) ), 'additionalProperties' => false );
	}
}
