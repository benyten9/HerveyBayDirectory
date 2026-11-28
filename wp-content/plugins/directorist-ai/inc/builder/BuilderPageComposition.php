<?php
/**
 * Bounded composition of native factory parts and declared Core components.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Pure tree construction. Native planning, authority and serialization still apply. */
final class BuilderPageComposition {

	public const REVISION       = 'native-page-composition-v1';
	private const MAX_NEW_NODES = 64;
	private const MAX_NODES     = 400;
	private const MAX_EDGES     = 800;

	/**
	 * Describe a flat layout plan without recursive JSON Schema references.
	 *
	 * @param array $palette Trusted component aliases and exact insertion schemas.
	 * @param array $references Possible native reference aliases and identities.
	 * @return object Self-contained model-facing schema.
	 */
	public static function schema( array $palette, array $references ): object {
		self::metadata( $palette, $references );
		$key      = (object) array(
			'type'    => 'string',
			'pattern' => '^[A-Za-z][A-Za-z0-9_-]{0,47}$',
		);
		$variants = array();
		foreach ( $references as $alias => $reference ) {
			$variant              = self::closed(
				array(
					'key'       => $key,
					'reference' => (object) array( 'const' => $alias ),
				)
			);
			$variant->description = 'Existing native ' . $reference->provider_id . '|' . $reference->native_id . ( $reference->container ? ' container. Its children may be rearranged.' : ' opaque part. Its content, controls and descendants stay unchanged; do not add children.' );
			$variants[]           = $variant;
		}
		foreach ( $palette as $alias => $component ) {
			$registry = new SchemaRegistry();
			$registry->register( 'composition.attributes', self::json( $component->attributes_schema ) );
			$variant              = self::closed(
				array(
					'key'        => $key,
					'component'  => (object) array( 'const' => $alias ),
					'attributes' => $registry->get( 'composition.attributes' ),
				)
			);
			$variant->description = 'New native ' . $component->provider_id . '|' . $component->native_id . ( $component->container ? ' container.' : ' leaf; it cannot receive children.' );
			if ( is_string( $component->description ?? null ) ) {
				$variant->description .= ' ' . substr( $component->description, 0, 512 );
			}
			$variants[] = $variant;
		}
		$schema   = self::closed(
			array(
				'nodes' => (object) array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_NEW_NODES + count( $references ),
					'items'       => (object) array( 'oneOf' => $variants ),
					'description' => 'Arrange every generated native reference exactly once, with optional declared Core components. Reference attributes and opaque listing subtrees stay unchanged. Intro references exist only when the native intro is generated.',
				),
				'edges' => (object) array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_NEW_NODES + count( $references ),
					'items'       => self::closed(
						array(
							'parent' => (object) array(
								'type'  => 'string',
								'anyOf' => array( (object) array( 'const' => '$root' ), $key ),
							),
							'child'  => $key,
							'order'  => (object) array(
								'type'    => 'integer',
								'minimum' => 0,
								'maximum' => 99,
							),
						)
					),
					'description' => 'Each node has one parent. Use $root for the unchanged native root. Only declared containers accept children. Respect the builder native parent/child rules; no cycles or repeated sibling positions.',
				),
			)
		);
		$required = array();
		foreach ( $references as $alias => $reference ) {
			if ( true !== ( $reference->optional ?? false ) ) {
				$required[] = (object) array(
					'contains' => (object) array(
						'type'       => 'object',
						'properties' => (object) array( 'reference' => (object) array( 'const' => $alias ) ),
						'required'   => array( 'reference' ),
					),
				);
			}
		}
		if ( $required ) {
			$schema->properties->nodes->allOf = $required;
		}
		return $schema;
	}

	/**
	 * Arrange native references without replacing their attributes or descendants.
	 *
	 * @param object $tree Trusted expanded native factory subtree.
	 * @param object $composition Model plan, always revalidated here.
	 * @param array  $palette Trusted public Core insertion definitions.
	 * @param array  $anchors Actual generated references with client_id bindings.
	 * @return object Detached semantic subtree for the normal native planner.
	 * @throws ContractException Invalid native composition or changed references.
	 */
	public static function apply( object $tree, object $composition, array $palette, array $anchors ): object {
		$references = array();
		foreach ( $anchors as $alias => $anchor ) {
			if ( ! $anchor instanceof \stdClass || ! is_string( $anchor->client_id ?? null ) ) {
				self::reject( 'Native composition anchors are unavailable.' );
			}
			$references[ $alias ] = (object) array(
				'provider_id' => $anchor->provider_id ?? null,
				'native_id'   => $anchor->native_id ?? null,
				'container'   => $anchor->container ?? null,
			);
		}
		$registry = new SchemaRegistry();
		$registry->register( 'composition.plan', self::json( self::schema( $palette, $references ) ) );
		try {
			( new ContractValidator( $registry, new JsonDecoder( 65536, 32 ) ) )->validate( $composition, 'composition.plan' );
		} catch ( ContractException $error ) {
			if ( 'invalid_input' !== $error->error_code() ) {
				throw $error;
			}
			self::reject( 'The page composition does not match the declared native components and references.' );
		}
		$composition = json_decode( self::json( $composition ), false, 48, JSON_THROW_ON_ERROR );
		$copy        = json_decode( self::json( $tree ), false, 48, JSON_THROW_ON_ERROR );
		( new JsonDecoder( 1048576, 48 ) )->check( $copy );
		if ( ! is_string( $copy->root_client_id ?? null ) || ! is_array( $copy->nodes ?? null ) || ! is_array( $copy->edges ?? null ) || count( $copy->nodes ) > self::MAX_NODES || count( $copy->edges ) > self::MAX_EDGES ) {
			self::reject( 'The native composition source exceeds its bounds.' );
		}
		$source = array();
		foreach ( $copy->nodes as $node ) {
			if ( ! $node instanceof \stdClass || ! is_string( $node->client_id ?? null ) || isset( $source[ $node->client_id ] ) || ! ( $node->attributes ?? null ) instanceof \stdClass ) {
				self::reject( 'The native composition source has ambiguous components.' );
			}
			$source[ $node->client_id ] = $node;
		}
		if ( ! isset( $source[ $copy->root_client_id ] ) ) {
			self::reject( 'The native composition root is unavailable.' );
		}
		$children       = array();
		$source_parents = array();
		foreach ( $copy->edges as $edge ) {
			if ( 'contains' !== ( $edge->kind ?? null ) ) {
				continue;
			}
			$from = $edge->source_client_id ?? '';
			$to   = $edge->target_client_id ?? '';
			if ( ! isset( $source[ $from ], $source[ $to ] ) || isset( $source_parents[ $to ] ) || $to === $copy->root_client_id ) {
				self::reject( 'The native composition source has invalid containment.' );
			}
			$children[ $from ][]   = $to;
			$source_parents[ $to ] = $from;
		}
		$visible = self::descendants( $copy->root_client_id, $children );
		if ( count( $visible ) !== count( $source ) ) {
			self::reject( 'The native composition source must be one complete connected tree.' );
		}
		$anchor_ids = array();
		$opaque     = array();
		foreach ( $anchors as $alias => $anchor ) {
			$id   = $anchor->client_id;
			$node = $source[ $id ] ?? null;
			if ( null === $node || ! isset( $visible[ $id ] ) || $id === $copy->root_client_id || isset( $anchor_ids[ $id ] ) || ( $node->provider_id ?? null ) !== $anchor->provider_id || ( $node->native_id ?? null ) !== $anchor->native_id ) {
				self::reject( 'A native composition reference changed or is ambiguous.' );
			}
			$anchor_ids[ $id ] = $alias;
			if ( ! $anchor->container ) {
				$opaque += self::descendants( $id, $children );
			}
		}
		foreach ( $anchors as $anchor ) {
			if ( isset( $opaque[ $anchor->client_id ] ) && $anchor->container ) {
				self::reject( 'A native composition container cannot be inside an opaque reference.' );
			}
			if ( ! $anchor->container ) {
				foreach ( self::descendants( $anchor->client_id, $children ) as $id => $unused ) {
					if ( $id !== $anchor->client_id && isset( $anchor_ids[ $id ] ) ) {
						self::reject( 'Native composition references overlap.' );
					}
				}
			}
		}
		foreach ( $visible as $id => $unused ) {
			if ( $id !== $copy->root_client_id && ! isset( $anchor_ids[ $id ] ) && ! isset( $opaque[ $id ] ) ) {
				self::reject( 'The page composition cannot discard an existing native part.' );
			}
		}
		$mapped     = array();
		$containers = array();
		$used       = array();
		$added      = array();
		foreach ( $composition->nodes as $node ) {
			$key = $node->key;
			if ( isset( $mapped[ $key ] ) || in_array( $key, array( 'constructor', 'prototype', '__proto__' ), true ) ) {
				self::reject( 'Page composition keys must be unique.' );
			}
			if ( isset( $node->reference ) ) {
				if ( isset( $used[ $node->reference ] ) ) {
					self::reject( 'Each native composition reference must occur exactly once.' );
				}
				$anchor                   = $anchors[ $node->reference ];
				$mapped[ $key ]           = $anchor->client_id;
				$containers[ $key ]       = $anchor->container;
				$used[ $node->reference ] = true;
			} else {
				$component = $palette[ $node->component ];
				$id        = 'composition-' . substr( hash( 'sha256', $copy->root_client_id . "\0" . $key ), 0, 32 );
				if ( isset( $source[ $id ] ) || count( $added ) >= self::MAX_NEW_NODES ) {
					self::reject( 'The page composition adds too many components or repeats a native identity.' );
				}
				$mapped[ $key ]     = $id;
				$containers[ $key ] = $component->container;
				$added[]            = (object) array(
					'client_id'   => $id,
					'provider_id' => $component->provider_id,
					'native_id'   => $component->native_id,
					'attributes'  => $node->attributes,
				);
			}
		}
		if ( count( $used ) !== count( $anchors ) ) {
			self::reject( 'The page composition must keep every generated native reference.' );
		}
		$parents          = array();
		$positions        = array();
		$planned_children = array();
		$planned_edges    = array();
		foreach ( $composition->edges as $edge ) {
			$from = $edge->parent;
			$to   = $edge->child;
			if ( ! isset( $mapped[ $to ] ) || ( '$root' !== $from && ( ! isset( $mapped[ $from ] ) || ! $containers[ $from ] ) ) || isset( $parents[ $to ] ) || isset( $positions[ $from . ':' . $edge->order ] ) ) {
				self::reject( 'The page composition has an invalid parent, repeated child or sibling position.' );
			}
			$parents[ $to ]                          = $from;
			$positions[ $from . ':' . $edge->order ] = true;
			$planned_children[ $from ][]             = $to;
			$planned_edges[]                         = (object) array(
				'kind'             => 'contains',
				'source_client_id' => '$root' === $from ? $copy->root_client_id : $mapped[ $from ],
				'target_client_id' => $mapped[ $to ],
				'order'            => $edge->order,
			);
		}
		$reached = self::descendants( '$root', $planned_children );
		if ( count( $parents ) !== count( $mapped ) || count( $reached ) !== count( $mapped ) + 1 ) {
			self::reject( 'The page composition must be connected to the native root without cycles.' );
		}
		$replace = array( $copy->root_client_id => true );
		foreach ( $anchors as $anchor ) {
			if ( $anchor->container ) {
				$replace[ $anchor->client_id ] = true;
			}
		}
		$copy->nodes = array_merge( $copy->nodes, $added );
		$copy->edges = array_merge( array_values( array_filter( $copy->edges, static fn( $edge ): bool => 'contains' !== ( $edge->kind ?? null ) || ! isset( $replace[ $edge->source_client_id ] ) ) ), $planned_edges );
		if ( count( $copy->nodes ) > self::MAX_NODES || count( $copy->edges ) > self::MAX_EDGES ) {
			self::reject( 'The composed native subtree exceeds its planning bounds.' );
		}
		return $copy;
	}

	/**
	 * Validate installed metadata without accepting model-provided identities.
	 *
	 * @param array $palette Server-owned insertion definitions.
	 * @param array $references Server-owned native references.
	 * @return void
	 */
	private static function metadata( array $palette, array $references ): void {
		if ( ! $references || count( $references ) > 16 || count( $palette ) > 12 ) {
			self::reject( 'The native page composition contract exceeds its metadata bounds.' );
		}
		foreach ( array( $palette, $references ) as $index => $rows ) {
			foreach ( $rows as $alias => $row ) {
				if ( ! is_string( $alias ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,47}$/D', $alias ) || ! $row instanceof \stdClass || ! is_string( $row->provider_id ?? null ) || ! is_string( $row->native_id ?? null ) || ! is_bool( $row->container ?? null ) || ( 0 === $index && ! ( $row->attributes_schema ?? null ) instanceof \stdClass ) ) {
					self::reject( 'The native page composition metadata is incomplete.' );
				}
			}
		}
	}

	/**
	 * Walk bounded containment and reject a cycle or repeated child.
	 *
	 * @param string $root Root identifier.
	 * @param array  $children Child adjacency list.
	 * @return array Visited identities.
	 */
	private static function descendants( string $root, array $children ): array {
		$seen  = array();
		$stack = array( $root );
		while ( $stack ) {
			$id = array_pop( $stack );
			if ( isset( $seen[ $id ] ) || count( $seen ) > self::MAX_NODES ) {
				self::reject( 'Native page composition containment must be acyclic and bounded.' );
			}
			$seen[ $id ] = true;
			foreach ( $children[ $id ] ?? array() as $child ) {
				$stack[] = $child;
			}
		}
		return $seen;
	}

	/**
	 * Make a closed JSON object schema.
	 *
	 * @param array $properties Required object members.
	 * @return object Closed schema.
	 */
	private static function closed( array $properties ): object {
		return (object) array(
			'type'                 => 'object',
			'properties'           => (object) $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Encode trusted JSON without coercion.
	 *
	 * @param mixed $value JSON value.
	 * @return string Encoded bytes.
	 */
	private static function json( $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Reject a model composition without changing native source or permissions.
	 *
	 * @param string $message Fixed diagnostic, never a submitted value.
	 * @return void
	 * @throws ContractException Invalid composition.
	 */
	private static function reject( string $message ): void {
		throw new ContractException( 'invalid_input', $message, array( 'native_page_composition_invalid' ) );
	}
}
