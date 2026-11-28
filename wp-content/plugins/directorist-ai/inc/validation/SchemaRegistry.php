<?php
/**
 * Immutable trusted schema registration with local reference expansion.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Validation;

/**
 * Holds server-owned schemas; callers select registered IDs, never schemas.
 */
final class SchemaRegistry {
	/**
	 * Registered expanded schema snapshots.
	 *
	 * @var array
	 */
	private array $schemas = array();
	/** Encoded immutable snapshot sizes, without exposing shared schema objects. */
	private array $encoded_sizes = array();
	/**
	 * Remaining expansion byte/work budget.
	 *
	 * @var int
	 */
	private int $remaining = 1048576;
	/** Exact successful expansion only; no data, capability or validation result. */
	private static array $expanded = array();
	private static int $expanded_bytes = 0;
	private static array $counts = array( 'hits' => 0, 'misses' => 0, 'evictions' => 0 );
	/** Successful syntax/expansion proofs contain only cryptographic fingerprints. */
	private static array $checked = array();
	private static int $check_hits = 0;
	private static int $check_misses = 0;

	public static function expansion_diagnostics(): array { return self::$counts + array( 'entries' => count( self::$expanded ), 'bytes' => self::$expanded_bytes, 'check_hits' => self::$check_hits, 'check_misses' => self::$check_misses, 'checked_entries' => count( self::$checked ) ); }

	/**
	 * Native policy registration only needs proof that a trusted schema expands.
	 * Reuse that pure result, never a value-validation or permission decision.
	 */
	public static function assert_valid_schema( string $json ): void {
		$key = hash( 'sha256', $json );
		$reuse = ! defined( 'DIRECTORIST_AI_SCHEMA_EXPANSION_CACHE' ) || false !== DIRECTORIST_AI_SCHEMA_EXPANSION_CACHE;
		if ( $reuse && isset( self::$checked[ $key ] ) ) { ++self::$check_hits; return; }
		++self::$check_misses;
		$registry = new self();
		$registry->register( 'trusted.schema_check', $json );
		if ( $reuse ) {
			if ( count( self::$checked ) >= 4096 ) { array_shift( self::$checked ); }
			self::$checked[ $key ] = true;
		}
	}

	/**
	 * Register a trusted, bounded Draft-07 schema exactly once.
	 *
	 * @param string $id Application schema ID.
	 * @param string $json Schema JSON supplied by trusted code.
	 * @return void
	 * @throws ContractException Invalid or duplicate schema.
	 */
	public function register( string $id, string $json ): void {
		if ( ! preg_match( '/^[a-z][a-z0-9_.-]{0,95}$/D', $id ) || array_key_exists( $id, $this->schemas ) ) {
			throw new ContractException( 'unsupported_contract', 'Invalid or duplicate schema identity.' );
		}
		$reuse = ! defined( 'DIRECTORIST_AI_SCHEMA_EXPANSION_CACHE' ) || false !== DIRECTORIST_AI_SCHEMA_EXPANSION_CACHE;
		$key = $reuse ? hash( 'sha256', $json ) : null;
		if ( null !== $key && isset( self::$expanded[ $key ] ) && self::$expanded[ $key ]['json'] === $json ) {
			++self::$counts['hits'];
			$this->schemas[ $id ] = json_decode( self::$expanded[ $key ]['schema_json'], false, 64, JSON_THROW_ON_ERROR );
			$this->encoded_sizes[ $id ] = strlen( self::$expanded[ $key ]['schema_json'] );
			return;
		}
		++self::$counts['misses'];
		$root                 = ( new JsonDecoder() )->decode( $json );
		$this->remaining      = 1048576;
		$this->schemas[ $id ] = $this->expand( $root, $root, array(), 0 );
		// Registry snapshots never escape except through get()'s detached copy.
		// Keep bounded immutable expansions across registries in this PHP request.
		if ( null !== $key ) {
			$schema_json = json_encode( $this->schemas[ $id ], JSON_THROW_ON_ERROR );
			$this->encoded_sizes[ $id ] = strlen( $schema_json );
			$cost = strlen( $json ) + strlen( $schema_json );
			if ( $cost <= 1048576 ) {
				while ( self::$expanded && ( count( self::$expanded ) >= 128 || self::$expanded_bytes + $cost > 4194304 ) ) {
					$old = array_key_first( self::$expanded );
					self::$expanded_bytes -= self::$expanded[ $old ]['bytes'];
					unset( self::$expanded[ $old ] );
					++self::$counts['evictions'];
				}
				// Retain bounded JSON strings rather than large PHP object trees.
				self::$expanded[ $key ] = array( 'json' => $json, 'schema_json' => $schema_json, 'bytes' => $cost );
				self::$expanded_bytes += $cost;
			}
		}
	}

	/**
	 * Return a detached schema so consumers cannot change the registry.
	 *
	 * @param string $id Registered schema identity.
	 * @return object|bool Schema snapshot.
	 * @throws ContractException Unknown schema.
	 */
	public function get( string $id ) {
		if ( ! array_key_exists( $id, $this->schemas ) ) {
			throw new ContractException( 'unsupported_contract', 'Unknown schema identity.' );
		}
		$json = json_encode( $this->schemas[ $id ], JSON_THROW_ON_ERROR );
		$this->encoded_sizes[ $id ] = strlen( $json );
		return json_decode( $json, false, 64, JSON_THROW_ON_ERROR );
	}

	/** Numeric accounting for bounded request-local parser reuse; no schema escapes. */
	public function encoded_size( string $id ): int {
		if ( ! array_key_exists( $id, $this->schemas ) ) {
			throw new ContractException( 'unsupported_contract', 'Unknown schema identity.' );
		}
		return $this->encoded_sizes[ $id ] ??= strlen( json_encode( $this->schemas[ $id ], JSON_THROW_ON_ERROR ) );
	}

	/**
	 * Expand local JSON pointers with bounded work and no resolver I/O.
	 *
	 * @param mixed $schema Current schema.
	 * @param mixed $root Original schema root.
	 * @param array $references Ancestor references.
	 * @param int   $depth Expansion depth.
	 * @return object|bool Expanded schema.
	 * @throws ContractException Invalid reference or unsupported extension.
	 */
	private function expand( $schema, $root, array $references, int $depth ) {
		$this->remaining -= 64;
		if ( $depth > 32 || $this->remaining < 0 ) {
			throw new ContractException( 'unsupported_contract', 'Schema expansion exceeds its limit.' );
		}
		if ( is_bool( $schema ) ) {
			return $schema;
		}
		if ( ! $schema instanceof \stdClass ) {
			throw new ContractException( 'unsupported_contract', 'Expected an object or Boolean schema.' );
		}
		if ( property_exists( $schema, '$ref' ) ) {
			$ref = $schema->{'$ref'};
			if ( ! is_string( $ref ) || 0 !== strpos( $ref, '#/' ) || in_array( $ref, $references, true ) ) {
				throw new ContractException( 'unsupported_contract', 'Only acyclic local JSON pointers are supported.' );
			}
			if ( count( get_object_vars( $schema ) ) !== 1 ) {
				throw new ContractException( 'unsupported_contract', 'Reference siblings are ambiguous in Draft-07.' );
			}
			$target = $root;
			foreach ( explode( '/', substr( $ref, 2 ) ) as $part ) {
				$part = str_replace( array( '~1', '~0' ), array( '/', '~' ), $part );
				if ( ! $target instanceof \stdClass || ! property_exists( $target, $part ) ) {
					throw new ContractException( 'unsupported_contract', 'Local schema reference does not exist.' );
				}
				$target = $target->{$part};
			}
			$references[] = $ref;
			return $this->expand( $target, $root, $references, $depth + 1 );
		}
		$maps        = array( 'properties', 'patternProperties', 'definitions' );
		$single      = array( 'additionalProperties', 'additionalItems', 'contains', 'propertyNames', 'not', 'if', 'then', 'else' );
		$lists       = array( 'allOf', 'anyOf', 'oneOf' );
		$annotations = array( 'title', 'description', '$comment', 'default', 'examples', 'readOnly', 'writeOnly' );
		$assertions  = array( 'type', 'enum', 'const', 'required', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'format', 'minItems', 'maxItems', 'uniqueItems', 'minProperties', 'maxProperties' );
		$result      = new \stdClass();
		foreach ( get_object_vars( $schema ) as $key => $value ) {
			if ( '$schema' === $key ) {
				if ( 'http://json-schema.org/draft-07/schema#' !== $value ) {
					throw new ContractException( 'unsupported_contract', 'Only Draft-07 is supported.' );
				}
				continue;
			}
			if ( '$id' === $key ) {
				throw new ContractException( 'unsupported_contract', 'Schema identities are assigned by the registry.' );
			}
			if ( in_array( $key, $maps, true ) ) {
				if ( ! $value instanceof \stdClass ) {
					throw new ContractException( 'unsupported_contract', 'Schema map must be an object.' );
				}
				$result->{$key} = new \stdClass();
				foreach ( get_object_vars( $value ) as $name => $child ) {
					$result->{$key}->{$name} = $this->expand( $child, $root, $references, $depth + 1 );
				}
			} elseif ( in_array( $key, $single, true ) ) {
				$result->{$key} = $this->expand( $value, $root, $references, $depth + 1 );
			} elseif ( in_array( $key, $lists, true ) || ( 'items' === $key && is_array( $value ) ) ) {
				if ( ! is_array( $value ) || ! $value ) {
					throw new ContractException( 'unsupported_contract', 'Expected nonempty schema list.' );
				}
				$result->{$key} = array();
				foreach ( $value as $child ) {
					$result->{$key}[] = $this->expand( $child, $root, $references, $depth + 1 );
				}
			} elseif ( 'items' === $key ) {
				$result->{$key} = $this->expand( $value, $root, $references, $depth + 1 );
			} elseif ( in_array( $key, $assertions, true ) || in_array( $key, $annotations, true ) ) {
				$this->remaining -= strlen( $key ) + strlen( json_encode( $value, JSON_THROW_ON_ERROR ) );
				if ( $this->remaining < 0 ) {
					throw new ContractException( 'unsupported_contract', 'Schema expansion exceeds its byte budget.' );
				}
				$result->{$key} = $value;
			} else {
				throw new ContractException( 'unsupported_contract', 'Unsupported schema keyword.' );
			}
		}
		return $result;
	}
}
