<?php
/**
 * Private typed handoff descriptors for exact native one-node initial factories.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Exact native JSON Schema keywords.

use DirectoristAIPro\Validation\{ContractValidator, JsonCanonicalizer, JsonDecoder, SchemaRegistry};

/** Descriptor preparation performs no model, storage, permission or native write. */
final class BuilderOneNodeFactoryBinding {
	/**
	 * Describe only an independently verified current native page factory.
	 *
	 * @param array $fresh_prefetched Fresh server-owned native receipts.
	 * @return object|null Private binding plus exact public attribute schemas.
	 */
	public static function describe( array $fresh_prefetched ): ?object {
		try {
			( new JsonDecoder( 2097152, 48 ) )->check( $fresh_prefetched );
			if ( ! BuilderCreationPrompt::has_initial_factory( $fresh_prefetched ) ) {
				return null; }
			$canonical  = new JsonCanonicalizer();
			$candidates = array();
			foreach ( $fresh_prefetched as $entry ) {
				$input = json_decode( $entry->input_json ?? 'null' );
				$a     = $entry->response->data->authoring ?? null;
				$f     = $a->draft->first_loop ?? null;
				if ( true === ( $entry->response->ok ?? null ) && 'builder.inspect' === ( $entry->response->operation ?? null ) && 'complete_for_selection' === ( $a->state ?? null ) && true === ( $a->can_propose_private_draft ?? null ) && true === ( $a->draft_readiness->ready ?? null ) && empty( $input->selection ) && empty( $input->control_paths ) && ( $f->carrier_attributes_schema ?? null ) instanceof \stdClass ) {
					$candidates[] = $entry; }
			}
			if ( count( $candidates ) !== 1 ) {
				return null; }
			$entry    = $candidates[0];
			$data     = $entry->response->data;
			$a        = $data->authoring;
			$f        = $a->draft->first_loop;
			$input    = json_decode( $entry->input_json );
			$graph    = $data->graph;
			$document = $graph->document;
			$builder  = $document->builder ?? null;
			if ( 'page' !== ( $document->native_kind ?? null ) || ! in_array( $builder, array( 'gutenberg', 'divi' ), true ) || ! is_int( $input->directory_id ?? null ) || $input->directory_id < 1 || ( $input->scope->directory_id ?? null ) !== (string) $input->directory_id || $canonical->encode( $document ) !== $canonical->encode( $f->parent->document ?? null ) || ( isset( $a->draft->intent ) && 'private_page_draft' !== $a->draft->intent ) ) {
				return null; }
			$identity = self::identity( $entry );
			if ( null === $identity ) {
				return null; }
			foreach ( $fresh_prefetched as $other ) {
				if ( 'builder.inspect' !== ( $other->response->operation ?? null ) || true !== ( $other->response->ok ?? null ) ) {
					continue; }
				$i = json_decode( $other->input_json ?? 'null' );
				if ( ! is_array( $i->selection ?? null ) || $i->selection || self::identity( $other ) !== $identity ) {
					return null; }
			}
			$tree = $f->subtree;
			$node = $tree->nodes[0] ?? null;
			if ( ! $node instanceof \stdClass || count( $tree->nodes ) !== 1 || array() !== $tree->edges || ! is_string( $node->client_id ?? null ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $node->client_id ) || in_array( $node->client_id, array( 'constructor', 'prototype', '__proto__' ), true ) || ( $tree->root_client_id ?? null ) !== $node->client_id || ! $node->attributes instanceof \stdClass || array_diff( array_keys( get_object_vars( $node ) ), array( 'client_id', 'provider_id', 'native_id', 'attributes' ) ) ) {
				return null; }
			$expected = 'gutenberg' === $builder ? array( 'wordpress/core', 'core/group' ) : array( 'divi/core', 'divi/section' );
			if ( array( $node->provider_id ?? null, $node->native_id ?? null ) !== $expected || array( 'native_loop_recipe' ) !== array_keys( get_object_vars( $node->attributes ) ) ) {
				return null; }
			$schema = $f->carrier_attributes_schema;
			if ( 'object' !== ( $schema->type ?? null ) || false !== ( $schema->additionalProperties ?? null ) || ! in_array( 'native_loop_recipe', $schema->required ?? array(), true ) || null === self::embed_schema( $schema, 'factory' ) ) {
				return null; }
			$schemas = new SchemaRegistry();
			$schemas->register( 'factory-attributes', self::json( $schema ) );
			( new ContractValidator( $schemas ) )->validate( $node->attributes, 'factory-attributes' );
			$authority = (object) array(
				'document_key'               => $data->document_key,
				'graph_hash'                 => $data->graph_hash,
				'fact_revision'              => $data->fact_revision,
				'catalog_revision'           => $a->catalog_revision,
				'semantic_rule_set_revision' => $a->semantic_rule_set_revision,
				'source_hash'                => $graph->version->source_hash,
				'graph_version'              => self::copy( $graph->version ),
				'first_loop_sha256'          => hash( 'sha256', $canonical->encode( $f ) ),
				'owner_ref'                  => self::metadata( $entry, 'owner_ref' ),
				'site_id'                    => self::metadata( $entry, 'site_id' ),
				'capture_id'                 => self::metadata( $entry, 'capture_id' ),
				'root'                       => self::copy( $f->parent ),
			);
			$out       = (object) array(
				'template'  => (object) array(
					'document'     => self::copy( $document ),
					'directory_id' => $input->directory_id,
					'scope'        => self::copy( $input->scope ),
					'intent'       => 'private_page_draft',
					'first_loop'   => (object) array(
						'parent'   => self::copy( $f->parent ),
						'scope'    => self::copy( $f->scope ),
						'position' => $f->position,
						'subtree'  => self::copy( $tree ),
					),
				),
				'slots'     => array(
					(object) array(
						'key'               => $node->client_id,
						'client_id'         => $node->client_id,
						'attributes_schema' => self::copy( $schema ),
					),
				),
				'authority' => $authority,
			);
			if ( property_exists( $f, 'defaults' ) ) {
				$out->defaults = self::copy( $f->defaults ); }
			( new JsonDecoder( 65536, 32 ) )->check( $out );
			return $out;
		} catch ( \Throwable $error ) {
			return null; }
	}

	/**
	 * Hoist standalone local definitions into one caller-selected namespace.
	 *
	 * Only schema positions change; literal defaults/enum/examples remain exact.
	 * Callers must merge definitions without collisions before validating values.
	 *
	 * @param object $schema Exact standalone native attribute or query schema.
	 * @param string $prefix Trusted unique property/slot namespace.
	 * @return object|null Embedded schema plus root definitions, or safe fallback.
	 */
	public static function embed_schema( object $schema, string $prefix ): ?object {
		try {
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $prefix ) ) {
				return null; }
			( new JsonDecoder( 65536, 32 ) )->check( $schema );
			$root        = self::copy( $schema );
			$map         = array();
			$definitions = new \stdClass();
			$originals   = array();
			foreach ( array( 'definitions', '$defs' ) as $kind ) {
				if ( ! property_exists( $root, $kind ) ) {
					continue; }
				if ( ! $root->{$kind} instanceof \stdClass ) {
					return null; }
				foreach ( $root->{$kind} as $name => $value ) {
					if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', (string) $name ) || ( ! $value instanceof \stdClass && ! is_bool( $value ) ) ) {
						return null; }
					$ref = '#/' . $kind . '/' . $name;
					$new = $prefix . '_' . ( 'definitions' === $kind ? 'd_' : 'f_' ) . $name;
					if ( isset( $originals[ $new ] ) ) {
						return null; }
					$map[ $ref ]       = '#/definitions/' . $new;
					$originals[ $new ] = $value;
				}
				unset( $root->{$kind} );
			}
			if ( count( $originals ) > 512 ) {
				return null; }
			$references = array();
			$visit      = static function ( $value, string $origin, int $depth = 0 ) use ( &$visit, &$references, $map ) {
				if ( is_bool( $value ) ) {
					return $value; }
				if ( ! $value instanceof \stdClass || $depth > 32 ) {
					throw new \UnexpectedValueException( 'Unsupported schema position.' ); }
				foreach ( array( '$id', '$schema', '$anchor', '$dynamicAnchor', '$recursiveAnchor', '$dynamicRef', '$recursiveRef', '$vocabulary', 'unevaluatedProperties', 'unevaluatedItems', 'prefixItems', 'dependentSchemas', 'dependentRequired' ) as $keyword ) {
					if ( property_exists( $value, $keyword ) ) {
						throw new \UnexpectedValueException( 'Unsupported schema base/dialect.' ); }
				}
				$out = clone $value;
				if ( property_exists( $out, '$ref' ) ) {
					$ref = $out->{'$ref'};
					if ( count( get_object_vars( $out ) ) !== 1 || ! is_string( $ref ) || ! isset( $map[ $ref ] ) ) {
						throw new \UnexpectedValueException( 'Unresolved or ambiguous native reference.' ); }
					$target                  = substr( $map[ $ref ], strlen( '#/definitions/' ) );
					$references[ $origin ][] = $target;
					$out->{'$ref'}           = $map[ $ref ];
					return $out;
				}
				foreach ( array( 'properties', 'patternProperties', 'definitions', '$defs' ) as $keyword ) {
					if ( ! property_exists( $out, $keyword ) ) {
						continue; }
					if ( ! $out->{$keyword} instanceof \stdClass ) {
						throw new \UnexpectedValueException( 'Malformed schema map.' ); }
					$children = new \stdClass();
					foreach ( $out->{$keyword} as $key => $child ) {
						$children->{$key} = $visit( $child, $origin, $depth + 1 );
					} $out->{$keyword} = $children;
				}
				foreach ( array( 'allOf', 'anyOf', 'oneOf' ) as $keyword ) {
					if ( ! property_exists( $out, $keyword ) ) {
						continue; }
					if ( ! is_array( $out->{$keyword} ) ) {
						throw new \UnexpectedValueException( 'Malformed schema variants.' ); }
					$out->{$keyword} = array_map( static fn( $child ) => $visit( $child, $origin, $depth + 1 ), $out->{$keyword} );
				}
				foreach ( array( 'items', 'additionalItems', 'additionalProperties', 'propertyNames', 'contains', 'if', 'then', 'else', 'not' ) as $keyword ) {
					if ( ! property_exists( $out, $keyword ) ) {
						continue; }
					$out->{$keyword} = is_array( $out->{$keyword} ) && 'items' === $keyword ? array_map( static fn( $child ) => $visit( $child, $origin, $depth + 1 ), $out->{$keyword} ) : $visit( $out->{$keyword}, $origin, $depth + 1 );
				}
				if ( property_exists( $out, 'dependencies' ) ) {
					if ( ! $out->dependencies instanceof \stdClass ) {
						throw new \UnexpectedValueException( 'Malformed schema dependencies.' ); }
					$deps = new \stdClass();
					foreach ( $out->dependencies as $key => $dependency ) {
						$deps->{$key} = is_array( $dependency ) ? $dependency : $visit( $dependency, $origin, $depth + 1 );
					} $out->dependencies = $deps;
				}
				return $out;
			};
			$root       = $visit( $root, 'root' );
			foreach ( $originals as $name => $definition ) {
				$definitions->{$name} = $visit( $definition, $name ); }
			$seen    = array();
			$active  = array();
			$acyclic = static function ( string $name ) use ( &$acyclic, &$seen, &$active, $references ): bool {
				if ( isset( $active[ $name ] ) ) {
					return false;
				} if ( isset( $seen[ $name ] ) ) {
					return true; }
				$active[ $name ] = true;
				foreach ( $references[ $name ] ?? array() as $next ) {
					if ( ! $acyclic( $next ) ) {
						return false;
					}
				} unset( $active[ $name ] );
				$seen[ $name ] = true;
				return true;
			};
			foreach ( array_keys( $originals ) as $name ) {
				if ( ! $acyclic( $name ) ) {
					return null; }
			}
			$out = (object) array(
				'schema'      => $root,
				'definitions' => $definitions,
			);
			( new JsonDecoder( 65536, 32 ) )->check( $out );
			return $out;
		} catch ( \Throwable $error ) {
			return null; }
	}

	/**
	 * Encode the complete existing native receipt identity without caching it.
	 *
	 * @param object $entry Current trusted receipt.
	 * @return string|null Complete identity or no supported authority.
	 */
	private static function identity( object $entry ): ?string {
		$d = $entry->response->data ?? null;
		$a = $d->authoring ?? null;
		$g = $d->graph ?? null;
		$i = json_decode( $entry->input_json ?? 'null' );
		if ( ! $i instanceof \stdClass || ! $g instanceof \stdClass || true !== ( $d->semantic_report->accepted ?? null ) || 'complete' !== ( $g->completeness ?? null ) || 'complete' !== ( $g->consumer_coverage ?? null ) || true !== ( $i->include_authoring ?? null ) || ! property_exists( $i, 'editor_binding_id' ) || null !== $i->editor_binding_id ) {
			return null; }
		$hashes = array( $d->graph_hash ?? null, $d->fact_revision ?? null, $a->catalog_revision ?? null, $a->semantic_rule_set_revision ?? null, $g->version->source_hash ?? null );
		foreach ( $hashes as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				return null; }
		}
		return ( new JsonCanonicalizer() )->encode( array( $d->document_key, $g, $hashes, $i->document, $i->directory_id, $i->scope, $i->editor_binding_id, self::metadata( $entry, 'owner_ref' ), self::metadata( $entry, 'site_id' ), self::metadata( $entry, 'capture_id' ) ) );
	}

	/**
	 * Preserve only explicit trusted binding metadata, never process globals.
	 *
	 * @param object $entry Current native receipt.
	 * @param string $key Supported metadata member.
	 * @return mixed Exact current scalar or null.
	 * @throws \UnexpectedValueException Conflicting or invalid metadata.
	 */
	private static function metadata( object $entry, string $key ) {
		$d = $entry->response->data;
		if ( property_exists( $entry, $key ) && property_exists( $d, $key ) && $entry->{$key} !== $d->{$key} ) {
			throw new \UnexpectedValueException( 'Conflicting trusted binding metadata.' );
		} $value = $entry->{$key} ?? $d->{$key} ?? null;
		if ( null !== $value && ( 'site_id' === $key ? ( ! is_int( $value ) || $value < 1 ) : ( ! is_string( $value ) || '' === $value || strlen( $value ) > 128 ) ) ) {
			throw new \UnexpectedValueException( 'Invalid trusted binding metadata.' );
		} return $value;
	}

	/**
	 * Encode exact JSON values, including native float defaults.
	 *
	 * @param mixed $value Bounded native value.
	 * @return string Exact JSON encoding.
	 */
	private static function json( $value ): string {
		return wp_json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ); }
	/**
	 * Detach native metadata without altering value types.
	 *
	 * @param mixed $value Bounded native value.
	 * @return mixed Detached exact value.
	 */
	private static function copy( $value ) {
		return json_decode( self::json( $value ), false, 48, JSON_THROW_ON_ERROR ); }
}
