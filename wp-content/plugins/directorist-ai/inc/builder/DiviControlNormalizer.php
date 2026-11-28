<?php
/**
 * Divi registrar attributes to strict effective catalog normalization.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact native normalizer.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Keeps runtime registration authoritative and private editor state out of model attributes. */
final class DiviControlNormalizer {
	public const REVISION = 'p5-15-v1';

	public function normalize( array $native, array $semantic, array $document_kinds ): ComponentDeclaration {
		$expected = array( 'attributes', 'availability', 'child_types', 'id', 'source_ref' );
		$keys     = array_keys( $native );
		sort( $keys, SORT_STRING );
		if ( $keys !== $expected || ! is_string( $native['id'] ) || ! ProviderRegistration::valid_token( $native['id'] ) || ! in_array( $native['availability'], array( 'available', 'editor_only', 'unavailable' ), true ) || ! is_array( $native['attributes'] ) || ! is_array( $native['child_types'] ) || ! is_string( $native['source_ref'] ) || ! ProviderRegistration::valid_token( $native['source_ref'] ) ) {
			throw new ContractException( 'unsupported_contract', 'Divi native module descriptor is invalid.' );
		}
		$properties  = new \stdClass();
		$defaults    = new \stdClass();
		$controls    = new \stdClass();
		$unsupported = array();
		$attributes  = $native['attributes'];
		ksort( $attributes, SORT_STRING );
		foreach ( $attributes as $name => $attribute ) {
			if ( ! is_string( $name ) || ! self::valid_attribute_id( $name ) || ! is_array( $attribute ) ) {
				throw new ContractException( 'unsupported_contract', 'Divi attribute descriptor is invalid.' );
			}
			( new JsonDecoder( 524288, 32 ) )->check( $this->json_value( $attribute ) );
			if ( self::is_private_attribute( $name ) ) {
				$unsupported[] = 'private-storage:' . $name;
				continue;
			}
			$schema              = $this->schema( $attribute );
			$properties->{$name} = $schema;
			$default_source      = array_key_exists( 'default', $attribute ) ? 'default' : ( array_key_exists( 'default_value', $attribute ) ? 'default_value' : null );
			if ( null !== $default_source ) {
				$default = $this->default_value( $attribute[ $default_source ], $schema );
				if ( $default[0] ) {
					$defaults->{$name} = $default[1];
				}
			}
			$settings = isset( $attribute['settings'] ) && is_array( $attribute['settings'] ) ? array_keys( $attribute['settings'] ) : array();
			sort( $settings, SORT_STRING );
			$controls->{$name} = (object) array(
				'type'           => $schema->type,
				'selector'       => isset( $attribute['selector'] ) && is_string( $attribute['selector'] ) ? $attribute['selector'] : null,
				'settings'       => array_slice( array_map( 'strval', $settings ), 0, 100 ),
				'default_source' => $default_source,
				'empty_policy'   => self::empty_policy( $name ),
			);
		}
		$source_provider = $semantic['source_provider_id'] ?? null;
		if ( ! is_string( $source_provider ) || ! CatalogEnvironment::valid_id( $source_provider ) ) {
			throw new ContractException( 'unsupported_contract', 'Divi source provider is invalid.' );
		}
		$child_types = ProviderRegistration::tokens( $native['child_types'] );
		$slots       = (array) ( $semantic['slots'] ?? array() );
		if ( $child_types ) {
			$slots[] = 'children';
		}
		$semantic_controls                        = (array) ( $semantic['controls'] ?? array() );
		$semantic_controls['allowed_child_types'] = $child_types;
		$semantic_controls['normalization']       = (object) array(
			'revision'                  => self::REVISION,
			'preserve_false_zero_empty' => true,
			'runtime_uuid_persistent'   => false,
		);
		$hash                                     = hash( 'sha256', json_encode( array( self::REVISION, $native, $semantic, $document_kinds, $properties, $defaults ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		return new ComponentDeclaration(
			array(
				'source_provider_id'   => $source_provider,
				'native_id'            => $native['id'],
				'availability'         => $native['availability'],
				'document_kinds'       => $document_kinds,
				'attributes_schema'    => (object) array(
					'type'                 => 'object',
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'defaults'             => $defaults,
				'controls'             => (object) array(
					'native'   => $controls,
					'kind'     => 'module',
					'semantic' => $this->json_value( $semantic_controls ),
				),
				'slots'                => array_values( array_unique( $slots ) ),
				'declared_context'     => (array) ( $semantic['declared_context'] ?? array() ),
				'semantic_rule_refs'   => (array) ( $semantic['semantic_rule_refs'] ?? array() ),
				'resolver_refs'        => (array) ( $semantic['resolver_refs'] ?? array() ),
				'dependencies'         => (array) ( $semantic['dependencies'] ?? array() ),
				'directory_fields'     => (array) ( $semantic['directory_fields'] ?? array() ),
				'source_fingerprint'   => $hash,
				'provenance'           => array(
					array(
						'kind'       => 'native_registry',
						'source_ref' => $native['source_ref'],
					),
					array(
						'kind'       => 'normalizer',
						'source_ref' => 'divi:' . self::REVISION,
					),
				),
				'features'             => array_map(
					static fn( string $kind ): array => array(
						'operation'       => 'inspect',
						'document_kind'   => $kind,
						'requires_editor' => 'editor_only' === $native['availability'],
					),
					$document_kinds
				),
				'unsupported_controls' => array_values( array_unique( array_merge( $unsupported, (array) ( $semantic['unsupported_controls'] ?? array() ) ) ) ),
			)
		);
	}

	private function schema( array $attribute ): object {
		$type = $attribute['type'] ?? null;
		if ( ! is_string( $type ) || ! in_array( $type, array( 'array', 'boolean', 'integer', 'number', 'object', 'string' ), true ) ) {
			$default = $attribute['default'] ?? $attribute['default_value'] ?? null;
			$type    = $this->type_of( $default );
		}
		return (object) array( 'type' => $type );
	}

	private function type_of( $value ): string {
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_array( $value ) ) {
			return $this->is_list( $value ) ? 'array' : 'object';
		}
		return is_object( $value ) ? 'object' : 'string';
	}

	private function default_value( $value, object $schema ): array {
		if ( null === $value ) {
			return array( false, null );
		}
		$value = $this->json_value( $value );
		if ( 'object' === $schema->type && is_array( $value ) && ! $value ) {
			$value = new \stdClass();
		}
		$valid = match ( $schema->type ) {
			'array' => is_array( $value ),
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'object' => $value instanceof \stdClass,
			'string' => is_string( $value ),
			default => false,
		};
		return array( $valid, $valid ? $value : null );
	}

	private function json_value( $value ) {
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 40, JSON_THROW_ON_ERROR );
	}

	private function is_list( array $value ): bool {
		return ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function valid_attribute_id( string $value ): bool {
		return strlen( $value ) <= 256 && 1 === preg_match( '/^_?[A-Za-z0-9][A-Za-z0-9._:\/\[\]-]*$/D', $value );
	}

	private static function is_private_attribute( string $name ): bool {
		return in_array( $name, array( '_source', '_view', 'childrenIds', 'state_by_source_view' ), true );
	}

	private static function empty_policy( string $name ): string {
		return 'module' === $name ? 'native-object-preserve' : 'preserve-false-zero-empty';
	}
}
