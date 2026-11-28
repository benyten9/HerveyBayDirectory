<?php
/**
 * Lossless local references for repeated native schemas in model context.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** This never changes the stored schema or the server's validation authority. */
final class BuilderPromptSchema {

	/**
	 * Show a few exact relative value paths without inventing a native value.
	 *
	 * These examples supplement the complete public schema. They never expose
	 * defaults, private values or new control paths, or change validation.
	 *
	 * @param object $schema Original public value schema.
	 * @return array Bounded declared property paths, possibly incomplete.
	 */
	public function value_path_examples( object $schema ): array {
		$paths = array();
		$visit = function ( object $node, array $parts, int $depth, array $references = array() ) use ( &$visit, &$paths, $schema ): void {
			if ( $depth > 10 || count( $paths ) >= 12 ) {
				return;
			}
			if ( isset( $node->{'$ref'} ) ) {
				$reference = $node->{'$ref'};
				if ( 1 !== count( get_object_vars( $node ) ) || ! is_string( $reference ) || ! preg_match( '/^#\/(definitions|\$defs)\/([A-Za-z_][A-Za-z0-9_-]{0,127})$/D', $reference, $match ) || isset( $references[ $reference ] ) ) {
					return;
				}
				$resolved = $schema->{$match[1]}->{$match[2]} ?? null;
				if ( ! $resolved instanceof \stdClass ) {
					return;
				}
				$references[ $reference ] = true;
				$visit( $resolved, $parts, $depth + 1, $references );
				return;
			}
			if ( ( $node->properties ?? null ) instanceof \stdClass ) {
				$properties = get_object_vars( $node->properties );
				// Ordinary desktop values are the common edit destination. Keep their
				// real native nesting visible before optional device/state overrides.
				$ordered = array();
				foreach ( array( 'desktop', 'value', 'color', 'size', 'weight' ) as $preferred ) {
					if ( array_key_exists( $preferred, $properties ) ) {
						$ordered[ $preferred ] = $properties[ $preferred ];
					}
				}
				foreach ( $ordered + $properties as $key => $child ) {
					if ( ! $child instanceof \stdClass || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D', (string) $key ) || true === ( $child->{'readOnly'} ?? false ) ) {
						continue;
					}
					$visit( $child, array_merge( $parts, array( $key ) ), $depth + 1, $references );
				}
				return;
			}
			if ( isset( $node->{'$ref'} ) || isset( $node->{'oneOf'} ) || isset( $node->{'anyOf'} ) || isset( $node->{'allOf'} ) || ! $parts ) {
				return;
			}
			$path = implode( '.', $parts );
			if ( strlen( $path ) <= 160 && strlen( json_encode( array_merge( $paths, array( $path ) ), JSON_THROW_ON_ERROR ) ) <= 384 ) {
				$paths[] = $path;
			}
		};
		$visit( $schema, array(), 0 );
		return $paths;
	}

	/**
	 * Share identical schema branches, preserving every constraint and annotation.
	 *
	 * @param object $schema Original public control value schema.
	 * @return object Original schema, or a smaller detached equivalent.
	 */
	public function compact( object $schema ): object {
		$encoded = json_encode( $schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		// Existing pointer targets and resource scopes must retain their positions.
		if ( preg_match( '/"\$(?:ref|id|anchor|dynamicRef|dynamicAnchor|recursiveRef|recursiveAnchor)"\s*:/', $encoded ) ) {
			return $schema;
		}
		$counts = array();
		$visit  = function ( object $node ) use ( &$counts ): object {
			$key = json_encode( $node, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
			if ( strlen( $key ) >= 160 ) {
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
			return $node;
		};
		$this->walk( $schema, $visit, false );
		$candidates = array();
		foreach ( $counts as $key => $count ) {
			if ( $count >= 2 && ( $count - 1 ) * strlen( $key ) > 48 * $count + 32 ) {
				$candidates[ $key ] = true;
			}
		}
		if ( ! $candidates ) {
			return $schema;
		}
		$definitions         = clone ( $schema->definitions ?? new \stdClass() );
		$references          = array();
		$next                = 0;
		$replace             = function ( object $node ) use ( &$definitions, &$references, &$next, $candidates ): object {
			$key = json_encode( $node, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
			if ( ! isset( $candidates[ $key ] ) ) {
				return $node;
			}
			if ( ! isset( $references[ $key ] ) ) {
				do {
					$name = 'native_shared_' . ++$next;
				} while ( property_exists( $definitions, $name ) );
				$definitions->{$name} = $node;
				$references[ $key ]   = '#/definitions/' . $name;
			}
			return (object) array( '$ref' => $references[ $key ] );
		};
		$result              = $this->walk( $schema, $replace, true );
		$result->definitions = $definitions;
		return strlen( json_encode( $result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) < strlen( $encoded ) ? $result : $schema;
	}

	/**
	 * Visit schema positions only; enum/default/example data are never rewritten.
	 *
	 * @param object   $schema Current schema object.
	 * @param callable $visit Branch transformation.
	 * @param bool     $replace Whether replacements stop branch traversal.
	 * @return object Detached schema with equivalent child schemas.
	 */
	private function walk( object $schema, callable $visit, bool $replace ): object {
		$result = clone $schema;
		foreach ( array( 'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas' ) as $keyword ) {
			if ( ! ( $schema->{$keyword} ?? null ) instanceof \stdClass ) {
				continue; }
			$result->{$keyword} = clone $schema->{$keyword};
			foreach ( $schema->{$keyword} as $name => $child ) {
				if ( $child instanceof \stdClass ) {
					$result->{$keyword}->{$name} = $this->branch( $child, $visit, $replace ); }
			}
		}
		foreach ( array( 'allOf', 'anyOf', 'oneOf', 'prefixItems' ) as $keyword ) {
			if ( ! is_array( $schema->{$keyword} ?? null ) ) {
				continue; }
			$result->{$keyword} = array_map( fn( mixed $child ): mixed => $child instanceof \stdClass ? $this->branch( $child, $visit, $replace ) : $child, $schema->{$keyword} );
		}
		foreach ( array( 'items', 'additionalItems', 'additionalProperties', 'unevaluatedItems', 'unevaluatedProperties', 'contains', 'propertyNames', 'not', 'if', 'then', 'else' ) as $keyword ) {
			$child = $schema->{$keyword} ?? null;
			if ( $child instanceof \stdClass ) {
				$result->{$keyword} = $this->branch( $child, $visit, $replace );
			} elseif ( 'items' === $keyword && is_array( $child ) ) {
				$result->{$keyword} = array_map( fn( mixed $item ): mixed => $item instanceof \stdClass ? $this->branch( $item, $visit, $replace ) : $item, $child );
			}
		}
		return $result;
	}

	/**
	 * Visit one nonroot schema before its children.
	 *
	 * @param object   $schema Current child schema.
	 * @param callable $visit Branch transformation.
	 * @param bool     $replace Whether replacements stop branch traversal.
	 * @return object Equivalent child schema.
	 */
	private function branch( object $schema, callable $visit, bool $replace ): object {
		$result = $visit( $schema );
		return $replace && $result !== $schema ? $result : $this->walk( $schema, $visit, $replace );
	}
}
