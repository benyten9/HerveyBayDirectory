<?php
/**
 * Bounded private schema feedback without submitted values or exception prose.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Exact JSON Schema properties.

use DirectoristAIPro\Dependencies\Opis\JsonSchema\CompliantValidator;
use DirectoristAIPro\Dependencies\Opis\JsonSchema\SchemaLoader;
use DirectoristAIPro\Dependencies\Opis\JsonSchema\Errors\ValidationError;
use DirectoristAIPro\Validation\JsonDecoder;

/** Failure-only advisory projection; never changes validation or native values. */
final class BuilderFactorySchemaFeedback {
	private const LIMIT          = 1536;
	private const DISCRIMINATORS = array( 'component', 'reference', 'control_path', 'native_id' );

	/**
	 * Explain only actual Draft7 failures against an already frozen trusted schema.
	 *
	 * @param mixed  $typed_values Original strictly decoded model arguments.
	 * @param object $frozen_schema Exact server-owned parameter schema.
	 * @return array At most four detached entries, together at most 1536 JSON bytes.
	 */
	public static function explain( $typed_values, object $frozen_schema ): array {
		try {
			( new JsonDecoder( 65536, 32 ) )->check( $typed_values );
			( new JsonDecoder( 131072, 48 ) )->check( $frozen_schema );
			$schema    = json_decode( json_encode( $frozen_schema, JSON_THROW_ON_ERROR ), false, 64, JSON_THROW_ON_ERROR );
			$validator = new CompliantValidator( new SchemaLoader( null, null ), 16, false );
			$error     = $validator->dataValidation( $typed_values, $schema, null, null, null, '07' );
			if ( null === $error ) {
				return array();
			}
			$result = array();
			$budget = 2048;
			self::collect( $error, $schema, $typed_values, $result, $budget, 0 );
			return $result;
		} catch ( \Throwable $error ) {
			// Missing, malformed, recursive or over-bound contracts reveal nothing.
			return array();
		}
	}

	/**
	 * Walk actual validator errors, selecting only declared discriminator branches.
	 *
	 * @param ValidationError $error Actual private engine error.
	 * @param object          $root Detached trusted root schema.
	 * @param mixed           $values Original complete typed data.
	 * @param array           $result Bounded output entries.
	 * @param int             $budget Remaining traversal steps.
	 * @param int             $depth Current error nesting depth.
	 * @return void
	 */
	private static function collect( ValidationError $error, object $root, $values, array &$result, int &$budget, int $depth ): void {
		if ( --$budget < 0 || $depth > 64 || count( $result ) >= 4 ) {
			return;
		}
		$schema = self::resolve( $error->schema()->info()->data(), $root );
		if ( ! $schema instanceof \stdClass ) {
			return;
		}
		$keyword  = $error->keyword();
		$children = $error->subErrors();
		if ( in_array( $keyword, array( 'oneOf', 'anyOf' ), true ) && is_array( $schema->{$keyword} ?? null ) ) {
			$branches = $schema->{$keyword};
			if ( count( $children ) !== count( $branches ) ) {
				self::append(
					$result,
					array(
						'path'    => self::path( $error->data()->fullPath(), $root, $values ),
						'keyword' => $keyword,
					)
				);
				return;
			}
			$matches = self::matching( $branches, $error->data()->value(), $root );
			if ( 1 === count( $matches ) && isset( $children[ $matches[0] ] ) ) {
				self::collect( $children[ $matches[0] ], $root, $values, $result, $budget, $depth + 1 );
				return;
			}
			// Unknown discriminators get declared choices, never the submitted name.
			foreach ( self::DISCRIMINATORS as $name ) {
				$choices = array();
				foreach ( $branches as $branch ) {
					$branch   = self::resolve( $branch, $root );
					$property = self::resolve( $branch->properties->{$name} ?? null, $root );
					$choices  = array_merge( $choices, self::choices( $property ) );
				}
				if ( $choices && $error->data()->value() instanceof \stdClass && property_exists( $error->data()->value(), $name ) ) {
					$path = self::path( array_merge( $error->data()->fullPath(), array( $name ) ), $root, $values );
					self::append(
						$result,
						array(
							'path'           => $path,
							'keyword'        => 'enum',
							'allowed_values' => self::bounded( $choices ),
						)
					);
					return;
				}
			}
			self::append(
				$result,
				array(
					'path'    => self::path( $error->data()->fullPath(), $root, $values ),
					'keyword' => $keyword,
				)
			);
			return;
		}
		if ( $children ) {
			foreach ( $children as $child ) {
				self::collect( $child, $root, $values, $result, $budget, $depth + 1 );
			}
			return;
		}
		if ( ! in_array( $keyword, array( 'required', 'additionalProperties', 'type', 'enum', 'const', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'contains', 'not', 'format', 'additionalItems', 'minProperties', 'maxProperties', 'multipleOf' ), true ) ) {
			return;
		}
		if ( 'additionalProperties' === $keyword ) {
			$data     = $error->data()->value();
			$declared = $schema->properties ?? new \stdClass();
			// Opis may also mark failed-but-declared properties as unevaluated.
			// Do not present those cascading parent errors as unknown members.
			if ( ! $data instanceof \stdClass || ! $declared instanceof \stdClass || isset( $schema->patternProperties ) || ! array_diff( array_keys( get_object_vars( $data ) ), array_keys( get_object_vars( $declared ) ) ) ) {
				return;
			}
		}
		$entry = array(
			'path'    => self::path( $error->data()->fullPath(), $root, $values ),
			'keyword' => $keyword,
		);
		if ( in_array( $keyword, array( 'required', 'additionalProperties' ), true ) && ( $schema->properties ?? null ) instanceof \stdClass ) {
			$entry['allowed_properties'] = self::bounded( array_keys( get_object_vars( $schema->properties ) ) );
		}
		if ( 'required' === $keyword && is_array( $schema->required ?? null ) && $error->data()->value() instanceof \stdClass ) {
			$entry['missing_properties'] = self::bounded( array_values( array_diff( $schema->required, array_keys( get_object_vars( $error->data()->value() ) ) ) ) );
		}
		if ( 'type' === $keyword ) {
			$types                   = is_array( $schema->type ?? null ) ? $schema->type : array( $schema->type ?? null );
			$entry['expected_types'] = array_values( array_intersect( $types, array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ) ) );
		}
		if ( in_array( $keyword, array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties', 'multipleOf' ), true ) && ( is_int( $schema->{$keyword} ?? null ) || is_float( $schema->{$keyword} ?? null ) ) ) {
			// A keyword alone cannot tell a repair how many controls are allowed.
			// Copy only the exact public numeric constraint, never submitted values.
			$entry['limit'] = $schema->{$keyword};
		}
		if ( in_array( $keyword, array( 'enum', 'const' ), true ) ) {
			$entry['allowed_values'] = self::bounded( self::choices( $schema ) );
		}
		self::append( $result, $entry );
	}

	/**
	 * Resolve only bounded same-document schema pointers, without any resolver.
	 *
	 * @param mixed  $schema Trusted schema fragment.
	 * @param object $root Trusted schema root.
	 * @return mixed Resolved schema fragment, or null for an unavailable reference.
	 */
	private static function resolve( $schema, object $root ) {
		for ( $depth = 0; $depth < 32; ++$depth ) {
			if ( ! $schema instanceof \stdClass || ! isset( $schema->{'$ref'} ) ) {
				return $schema;
			}
			$ref = $schema->{'$ref'};
			if ( ! is_string( $ref ) || ! str_starts_with( $ref, '#/' ) || strlen( $ref ) > 512 ) {
				return null;
			}
			$schema = $root;
			foreach ( explode( '/', substr( $ref, 2 ) ) as $part ) {
				$part   = str_replace( array( '~1', '~0' ), array( '/', '~' ), $part );
				$schema = $schema instanceof \stdClass ? ( $schema->{$part} ?? null ) : ( is_array( $schema ) && ctype_digit( $part ) ? ( $schema[ (int) $part ] ?? null ) : null );
			}
		}
		return null;
	}

	/**
	 * Select unambiguous branches using only exact declared discriminator values.
	 *
	 * @param array  $branches Trusted union branches.
	 * @param mixed  $value Typed data at this union.
	 * @param object $root Trusted schema root.
	 * @return int[] Matching branch indices.
	 */
	private static function matching( array $branches, $value, object $root ): array {
		$matches = array();
		if ( ! $value instanceof \stdClass ) {
			return $matches;
		}
		foreach ( $branches as $index => $branch ) {
			$branch = self::resolve( $branch, $root );
			$seen   = false;
			$match  = true;
			foreach ( self::DISCRIMINATORS as $name ) {
				$choices = self::choices( self::resolve( $branch->properties->{$name} ?? null, $root ) );
				if ( $choices ) {
					$seen  = true;
					$match = $match && property_exists( $value, $name ) && in_array( $value->{$name}, $choices, true );
				}
			}
			if ( $seen && $match ) {
				$matches[] = $index;
			}
		}
		return $matches;
	}

	/**
	 * Keep data paths only while the exact current schema declares each segment.
	 *
	 * @param array  $parts Actual validator data path; never copied unchecked.
	 * @param object $root Trusted schema root.
	 * @param mixed  $values Original typed data for branch selection only.
	 * @return string Canonical JSON pointer to a known parent, or the root.
	 */
	private static function path( array $parts, object $root, $values ): string {
		$schemas = array( $root );
		$path    = '';
		$budget  = 512;
		foreach ( array_slice( $parts, 0, 32 ) as $part ) {
			$expanded = array();
			foreach ( $schemas as $schema ) {
				self::expand( $schema, $values, $root, $expanded, $budget, 0 );
			}
			$next = array();
			foreach ( $expanded as $schema ) {
				if ( is_string( $part ) && self::name( $part ) && ( $schema->properties ?? null ) instanceof \stdClass && property_exists( $schema->properties, $part ) ) {
					$next[] = $schema->properties->{$part};
				} elseif ( is_int( $part ) && $part >= 0 && $part < 200 && is_array( $values ) && array_key_exists( $part, $values ) ) {
					$item = is_array( $schema->items ?? null ) ? ( $schema->items[ $part ] ?? null ) : ( $schema->items ?? null );
					if ( $item instanceof \stdClass ) {
						$next[] = $item;
					}
				}
			}
			if ( ! $next || $budget < 0 ) {
				break;
			}
			$path   .= '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), (string) $part );
			$schemas = $next;
			$values  = is_array( $values ) ? ( $values[ $part ] ?? null ) : ( $values instanceof \stdClass ? ( $values->{$part} ?? null ) : null );
		}
		return $path;
	}

	/**
	 * Expand trusted schema unions only for canonical path membership.
	 *
	 * @param mixed  $schema Trusted fragment.
	 * @param mixed  $value Typed value for discriminator selection.
	 * @param object $root Trusted schema root.
	 * @param array  $result Current fragments.
	 * @param int    $budget Remaining traversal steps.
	 * @param int    $depth Current schema depth.
	 * @return void
	 */
	private static function expand( $schema, $value, object $root, array &$result, int &$budget, int $depth ): void {
		if ( --$budget < 0 || $depth > 32 ) {
			return;
		}
		$schema = self::resolve( $schema, $root );
		if ( ! $schema instanceof \stdClass ) {
			return;
		}
		$result[] = $schema;
		foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $keyword ) {
			$branches = $schema->{$keyword} ?? array();
			$matches  = 'allOf' === $keyword ? array() : self::matching( $branches, $value, $root );
			foreach ( $branches as $index => $branch ) {
				if ( ! $matches || in_array( $index, $matches, true ) ) {
					self::expand( $branch, $value, $root, $result, $budget, $depth + 1 );
				}
			}
		}
	}

	/**
	 * Read exact declared scalar choices without submitted values.
	 *
	 * @param mixed $schema Trusted resolved schema fragment.
	 * @return array Trusted const or enum choices.
	 */
	private static function choices( $schema ): array {
		return $schema instanceof \stdClass ? ( property_exists( $schema, 'const' ) ? array( $schema->const ) : ( is_array( $schema->enum ?? null ) ? $schema->enum : array() ) ) : array();
	}

	/**
	 * Bound trusted scalar lists without truncating strings or nested JSON.
	 *
	 * @param array $values Trusted schema choices or property names.
	 * @return array Up to six complete scalar values.
	 */
	private static function bounded( array $values ): array {
		$result = array();
		foreach ( $values as $value ) {
			if ( ( is_string( $value ) && strlen( $value ) <= 96 && ! preg_match( '/[\x00-\x1f\x7f]/', $value ) ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
				if ( ! in_array( $value, $result, true ) ) {
					$result[] = $value;
				}
			}
			if ( count( $result ) >= 6 ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Check a canonical trusted property segment.
	 *
	 * @param string $name Trusted declared property name.
	 * @return bool Whether it is safe in a compact canonical path.
	 */
	private static function name( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_$][A-Za-z0-9_.:$-]{0,95}$/D', $name );
	}

	/**
	 * Append whole unique entries only while both hard output bounds remain met.
	 *
	 * @param array $result Current detached entries.
	 * @param array $entry Fixed-key advisory entry.
	 * @return void
	 */
	private static function append( array &$result, array $entry ): void {
		if ( count( $result ) >= 4 || in_array( $entry, $result, true ) ) {
			return;
		}
		$next = array_merge( $result, array( $entry ) );
		if ( strlen( json_encode( $next, JSON_THROW_ON_ERROR ) ) <= self::LIMIT ) {
			$result = $next;
		}
	}
}
