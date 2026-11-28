<?php
/**
 * Model-only declared argument formats for native builder tools.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/** Native ability input and gateway validation retain their original envelope. */
final class BuilderDraftTransport {
	public const FUNCTION_NAME      = 'wpab__directorist-ai__builder-draft-propose';
	private const REVISION          = 'directorist-ai-initial-draft-direct-v1:';
	private const COMPAT            = 'directorist-ai-initial-draft-compat-v2:';
	private const DIRECT            = 'directorist-ai-initial-draft-direct-v3:';
	private const EDITOR            = 'directorist-ai-gutenberg-editor-direct-v1:';
	private const PREFIX            = 'JSON encoded operation input. Authoritative schema: ';
	public const EDITOR_ABILITIES   = array( 'directorist-ai/builder-inspect', 'directorist-ai/builder-draft-propose' );
	public const EDITOR_INSTRUCTION = ' For this Gutenberg editor, use direct object arguments for the declared inspect and draft tools. Follow their actual properties. Do not send an input_json envelope to either tool. A locator is the entire object returned by inspection, including snapshot_hash and both nullable fields native_id and editor_epoch. A component type is different from a locator. Other tools use their own declared arguments.';

	/**
	 * Expose the native schema itself for saved Gutenberg editor reads and proposals.
	 *
	 * @param string $function_name Declared native editor function.
	 * @param array  $envelope      Original ability input envelope.
	 * @return array Direct provider declaration.
	 * @throws \UnexpectedValueException The native editor schema is missing or invalid.
	 */
	public static function editor_declaration( string $function_name, array $envelope ): array {
		$text = $envelope['properties']['input_json']['description'] ?? null;
		if ( ! is_string( $text ) || ! str_starts_with( $text, self::PREFIX ) ) {
			throw new \UnexpectedValueException( 'The native editor argument envelope changed.' );
		}
		$schema = ( new JsonDecoder() )->decode( substr( $text, strlen( self::PREFIX ) ) );
		if ( ! self::editor_schema_shape( $schema, $function_name ) ) {
			throw new \UnexpectedValueException( 'The native editor tool schema is unavailable.' );
		}
		$schema->{'$comment'} = self::editor_marker( $function_name, $schema );
		return (array) $schema;
	}

	/**
	 * Check the root shape of an admitted native editor schema.
	 *
	 * @param mixed  $schema        Schema decoded from the native declaration.
	 * @param string $function_name Declared native editor function.
	 * @return bool Whether the schema matches the supported shape.
	 */
	private static function editor_schema_shape( $schema, string $function_name ): bool {
		$member = self::FUNCTION_NAME === $function_name ? 'changes' : 'selection';
		return $schema instanceof \stdClass && 'object' === ( $schema->type ?? null )
			&& false === ( $schema->{'additionalProperties'} ?? null ) && ! property_exists( $schema, '$comment' )
			&& ! isset( $schema->properties->input_json ) && 'array' === ( $schema->properties->$member->type ?? null )
			&& is_array( $schema->required ?? null )
			&& ! array_diff( array( 'document', 'scope', 'directory_id', $member ), $schema->required );
	}

	/**
	 * Bind the native editor declaration to its exact schema.
	 *
	 * @param string $function_name Declared native editor function.
	 * @param object $schema        Unmarked native schema.
	 * @return string Declaration integrity marker.
	 */
	private static function editor_marker( string $function_name, object $schema ): string {
		return self::EDITOR . hash( 'sha256', $function_name . "\n" . self::json( $schema ) );
	}

	/**
	 * Verify a direct schema from the immutable declared request.
	 *
	 * @param ModelRequest $request       Admitted model request.
	 * @param string       $function_name Declared native editor function.
	 * @return object|null Verified direct schema when applicable.
	 * @throws \UnexpectedValueException The frozen editor declaration differs.
	 */
	private static function editor_schema( ModelRequest $request, string $function_name ): ?object {
		if ( ! $request->builder_editor_direct || $request->builder_initial_layout || ! in_array( $function_name, array( self::FUNCTION_NAME, BuilderReadTargetTransport::NATIVE_FUNCTION ), true ) ) {
			return null;
		}
		$matches = array_filter( $request->function_declarations ?? array(), static fn( $declaration ): bool => $declaration instanceof FunctionDeclaration && $declaration->getName() === $function_name );
		if ( 1 === count( $matches ) ) {
			$schema = json_decode( self::json( reset( $matches )->getParameters() ) );
			$marker = $schema->{'$comment'} ?? null;
			if ( $schema instanceof \stdClass ) {
				unset( $schema->{'$comment'} );
			}
			if ( self::editor_schema_shape( $schema, $function_name ) && is_string( $marker ) && hash_equals( self::editor_marker( $function_name, $schema ), $marker ) ) {
				return $schema;
			}
		}
		throw new \UnexpectedValueException( 'The frozen native editor declaration changed.' );
	}

	/**
	 * Decode only the known, already insertion-only native declaration.
	 *
	 * @param array $envelope Server-owned native argument envelope.
	 * @return array Detached direct schema, or unchanged legacy envelope.
	 */
	public static function declaration( array $envelope ): array {
		$text = $envelope['properties']['input_json']['description'] ?? null;
		if ( ! is_string( $text ) || ! str_starts_with( $text, self::PREFIX ) ) {
			return $envelope;
		}
		$schema = ( new JsonDecoder() )->decode( substr( $text, strlen( self::PREFIX ) ) );
		if ( ! $schema instanceof \stdClass || ! self::initial_schema( $schema ) || property_exists( $schema, '$comment' ) ) {
			return $envelope;
		}
		$schema->{'$comment'} = self::DIRECT . hash( 'sha256', self::json( $schema ) );
		return (array) $schema;
	}

	/**
	 * An initial flag alone cannot change a legacy pending declaration.
	 *
	 * @param ModelRequest $request Frozen native request.
	 * @param string       $function_name Exact returned function identity.
	 * @return object|null Exact direct schema, or no normalization.
	 */
	public static function schema( ModelRequest $request, string $function_name ): ?object {
		$editor_schema = self::editor_schema( $request, $function_name );
		if ( null !== $editor_schema ) {
			return $editor_schema;
		}
		if ( ! $request->builder_initial_layout || self::FUNCTION_NAME !== $function_name || null === $request->function_declarations ) {
			return null;
		}
		$matches = array();
		foreach ( $request->function_declarations as $declaration ) {
			if ( ! $declaration instanceof FunctionDeclaration ) {
				return null;
			}
			if ( $declaration->getName() === $function_name ) {
				$matches[] = $declaration;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return null;
		}
		$schema = json_decode( self::json( $matches[0]->getParameters() ) );
		if ( ! $schema instanceof \stdClass || ! is_string( $schema->{'$comment'} ?? null ) ) {
			return null;
		}
		$marker = $schema->{'$comment'};
		unset( $schema->{'$comment'} );
		$revision = self::compat_schema( $schema ) ? self::COMPAT : ( self::initial_schema( $schema ) ? ( str_starts_with( $marker, self::DIRECT ) ? self::DIRECT : self::REVISION ) : null );
		return null !== $revision && hash_equals( $revision . hash( 'sha256', self::json( $schema ) ), $marker ) ? $schema : null;
	}


	/**
	 * Select strict direct roots only from the exact frozen v3 declaration.
	 *
	 * Historical v1/v2 and unmarked native envelopes retain their old parsing.
	 * Unknown or changed format markers never fall through to legacy parsing.
	 *
	 * @param ModelRequest $request Frozen native request.
	 * @param string       $function_name Exact returned function identity.
	 * @return bool Whether this is the validated fresh direct-only revision.
	 * @throws \UnexpectedValueException Marked declaration is unknown or changed.
	 */
	public static function strict_direct( ModelRequest $request, string $function_name ): bool {
		if ( null !== self::editor_schema( $request, $function_name ) ) {
			return true;
		}
		if ( ! $request->builder_initial_layout || self::FUNCTION_NAME !== $function_name || null === $request->function_declarations ) {
			return false;
		}
		$markers = array();
		foreach ( $request->function_declarations as $declaration ) {
			if ( $declaration instanceof FunctionDeclaration && $declaration->getName() === $function_name ) {
				$parameters = $declaration->getParameters();
				$marker     = $parameters['$comment'] ?? null;
				$shape      = json_decode( self::json( $parameters ) );
				if ( $shape instanceof \stdClass ) {
					unset( $shape->{'$comment'} );
				}
				if ( ( is_string( $marker ) && str_starts_with( $marker, 'directorist-ai-initial-draft-' ) ) ||
					( $shape instanceof \stdClass && ( self::initial_schema( $shape ) || self::compat_schema( $shape ) ) ) ) {
					$markers[] = $marker;
				}
			}
		}
		if ( ! $markers ) {
			return false;
		}
		if ( 1 !== count( $markers ) || null === self::schema( $request, $function_name ) ) {
			throw new \UnexpectedValueException( 'Unknown or changed initial draft declaration.' );
		}
		return str_starts_with( $markers[0], self::DIRECT );
	}

	/**
	 * Preserve managed bytes, including duplicate members, for the native decoder.
	 *
	 * @param string      $raw Original managed argument bytes.
	 * @param object|null $schema Exact frozen declared format, when present.
	 * @param bool        $direct_only Exact validated v3 snapshot; never provider input.
	 * @return object Unchanged bytes in the native envelope.
	 * @throws \UnexpectedValueException Output exceeds its existing bound.
	 */
	public static function managed_arguments( string $raw, ?object $schema = null, bool $direct_only = false ): object {
		if ( strlen( $raw ) > 65536 ) {
			throw new \UnexpectedValueException( 'Invalid bounded direct function arguments.' );
		}
		if ( $direct_only ) {
			$root = json_decode( $raw, false, 33, JSON_THROW_ON_ERROR );
			if ( ! $root instanceof \stdClass || property_exists( $root, 'input_json' ) ) {
				throw new \UnexpectedValueException( 'Expected the declared direct builder arguments.' );
			}
		}
		if ( null !== $schema && self::compat_schema( $schema ) ) {
			$outer = json_decode( $raw );
			if ( $outer instanceof \stdClass && property_exists( $outer, 'input_json' ) ) {
				// Parse the original outer bytes strictly; never let decoding erase duplicates.
				$outer = ( new JsonDecoder( 65536, 32 ) )->decode( $raw );
				if ( array_keys( get_object_vars( $outer ) ) !== array( 'input_json' ) || ! is_string( $outer->input_json ) || '' === trim( $outer->input_json ) ) {
					throw new \UnexpectedValueException( 'Invalid declared legacy argument branch.' );
				}
				// Inner bytes retain duplicate members, malformed JSON and native values.
				return (object) array( 'input_json' => $outer->input_json );
			}
		}
		return (object) array( 'input_json' => $raw );
	}

	/**
	 * Native connectors already decoded duplicate members before this boundary.
	 * Only declared object positions can recover an empty object from SDK arrays.
	 *
	 * @param mixed  $arguments Already-decoded native SDK arguments.
	 * @param object $schema Exact frozen direct schema.
	 * @param bool   $direct_only Exact validated v3 snapshot; never provider input.
	 * @return object Native bounded argument envelope.
	 * @throws \UnexpectedValueException Unsupported argument root or depth.
	 */
	public static function wordpress_arguments( $arguments, object $schema, bool $direct_only = false ): object {
		if ( $direct_only ) {
			$root = $arguments instanceof \stdClass ? get_object_vars( $arguments ) : $arguments;
			if ( ! is_array( $root ) || array_key_exists( 'input_json', $root ) ) {
				throw new \UnexpectedValueException( 'Expected the declared direct builder arguments.' );
			}
		}
		$native_schema = $schema;
		if ( self::compat_schema( $schema ) ) {
			$root          = $arguments instanceof \stdClass ? get_object_vars( $arguments ) : $arguments;
			$native_schema = is_array( $root ) && array_key_exists( 'input_json', $root ) ? self::legacy_schema() : clone $schema->{'oneOf'}[0];
			if ( isset( $schema->definitions ) ) {
				$native_schema->definitions = $schema->definitions;
			}
		}
		$registry = new SchemaRegistry();
		$registry->register( 'initial.transport', self::json( $native_schema ) );
		$value = self::native_value( $arguments, $registry->get( 'initial.transport' ), 0 );
		if ( ! $value instanceof \stdClass ) {
			throw new \UnexpectedValueException( 'Expected direct function object.' );
		}
		( new JsonDecoder( 65536, 32 ) )->check( $value );
		return self::managed_arguments( self::json( $value ), $schema, $direct_only );
	}

	/**
	 * The explicitly declared compatibility branch contains only native JSON bytes.
	 *
	 * @return object Closed alternate argument schema.
	 */
	private static function legacy_schema(): object {
		return (object) array(
			'type'                 => 'object',
			'properties'           => (object) array(
				'input_json' => (object) array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 65536,
					'pattern'     => '\\S',
					'description' => 'Exact native operation JSON. The native decoder rejects malformed JSON, duplicate members and invalid operation input. No extra envelope members.',
				),
			),
			'required'             => array( 'input_json' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Recognize only the closed two-format schema emitted by this revision.
	 *
	 * @param object $schema Exact frozen schema without its digest marker.
	 * @return bool Whether both declared argument branches remain unchanged.
	 */
	private static function compat_schema( object $schema ): bool {
		$keys = array_keys( get_object_vars( $schema ) );
		sort( $keys );
		$expected = isset( $schema->definitions ) ? array( 'definitions', 'oneOf', 'type' ) : array( 'oneOf', 'type' );
		$branches = $schema->{'oneOf'} ?? null;
		return $keys === $expected && 'object' === ( $schema->type ?? null ) && is_array( $branches ) && 2 === count( $branches )
			&& $branches[0] instanceof \stdClass && self::initial_schema( $branches[0] ) && ! isset( $branches[0]->definitions )
			&& self::json( $branches[1] ) === self::json( self::legacy_schema() )
			&& ( ! isset( $schema->definitions ) || $schema->definitions instanceof \stdClass );
	}

	/**
	 * Admit exactly the existing closed operation with one insert variant.
	 *
	 * @param object $schema Detached server-owned schema.
	 * @return bool Whether the initial syntax restriction is present.
	 */
	private static function initial_schema( object $schema ): bool {
		$properties = array_keys( get_object_vars( $schema->properties ?? new \stdClass() ) );
		$required   = $schema->required ?? array();
		sort( $properties );
		if ( ! is_array( $required ) ) {
			return false;
		}
		sort( $required );
		$changes = $schema->properties->changes ?? null;
		$choices = $changes->items->oneOf ?? null;
		return 'object' === ( $schema->type ?? null ) && false === ( $schema->{'additionalProperties'} ?? null )
			&& array( 'changes', 'directory_id', 'directory_query', 'document', 'intent', 'request_id', 'scope' ) === $properties
			&& array( 'changes', 'directory_id', 'document', 'intent', 'request_id', 'scope' ) === $required
			&& 'array' === ( $changes->type ?? null ) && 1 === ( $changes->{'minItems'} ?? null ) && 1 === ( $changes->{'maxItems'} ?? null )
			&& is_array( $choices ) && 1 === count( $choices ) && 'insert' === ( $choices[0]->properties->op->const ?? null );
	}

	/**
	 * Preserve lists and scalars; recover objects only from declared SDK positions.
	 *
	 * @param mixed  $value Native decoded value.
	 * @param object $schema Declared schema at this exact position.
	 * @param int    $depth Current bounded depth.
	 * @return mixed Native JSON value with object/list types retained.
	 * @throws \UnexpectedValueException Output nesting exceeds its existing bound.
	 */
	private static function native_value( $value, object $schema, int $depth ) {
		if ( $depth >= 32 ) {
			throw new \UnexpectedValueException( 'Direct function nesting exceeds its bound.' );
		}
		if ( isset( $schema->{'oneOf'} ) && 1 === count( $schema->{'oneOf'} ) ) {
			$schema = $schema->{'oneOf'}[0];
		} elseif ( isset( $schema->{'oneOf'} ) && is_string( ( (array) $value )['op'] ?? null ) ) {
			$operation = ( (array) $value )['op'];
			foreach ( $schema->{'oneOf'} as $choice ) {
				if ( ( $choice->properties->op->const ?? null ) === $operation ) {
					$schema = $choice;
					break;
				}
			}
		}
		if ( is_array( $value ) && ( ( ! $value && 'object' === ( $schema->type ?? null ) ) || ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) ) {
			$value = (object) $value;
		}
		if ( $value instanceof \stdClass ) {
			$copy = new \stdClass();
			foreach ( get_object_vars( $value ) as $key => $child ) {
				$child_schema = $schema->properties->{$key} ?? new \stdClass();
				$copy->{$key} = self::native_value( $child, is_object( $child_schema ) ? $child_schema : new \stdClass(), $depth + 1 );
			}
			return $copy;
		}
		if ( is_array( $value ) ) {
			$child_schema = $schema->items ?? new \stdClass();
			return array_map( static fn( $child ) => self::native_value( $child, is_object( $child_schema ) ? $child_schema : new \stdClass(), $depth + 1 ), $value );
		}
		return $value;
	}

	/**
	 * Stable bytes bind declaration snapshots without object/list coercion.
	 *
	 * @param mixed $value Bounded native JSON value.
	 * @return string Exact JSON bytes.
	 */
	private static function json( $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 64 );
	}
}
