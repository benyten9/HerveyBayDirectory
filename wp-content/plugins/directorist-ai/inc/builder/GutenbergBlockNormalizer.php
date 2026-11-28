<?php
/**
 * WordPress block registration to strict catalog normalization.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Native normalization invariants are documented inline.

use DirectoristAIPro\Validation\ContractException;

/** Keeps WordPress parsing details in provenance while producing bounded JSON Schema. */
final class GutenbergBlockNormalizer {
	public const REVISION = 'p5-01-v2';

	/** Normalize one selected live block descriptor and integration-owned semantic mapping. */
	public function normalize( array $native, array $semantic, array $document_kinds ): ComponentDeclaration {
		$expected = array( 'allowed_blocks', 'ancestor', 'attributes', 'availability', 'name', 'parent', 'provides_context', 'render_mode', 'selectors', 'source_ref', 'styles', 'supports', 'uses_context' );
		$keys     = array_keys( $native );
		sort( $keys, SORT_STRING );
		if ( $keys !== $expected || ! is_string( $native['name'] ) || ! ProviderRegistration::valid_token( $native['name'] ) || ! in_array( $native['availability'], array( 'available', 'editor_only', 'unavailable' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Gutenberg block descriptor is invalid.' );
		}
		foreach ( array( 'attributes', 'provides_context', 'selectors', 'styles', 'supports' ) as $map ) {
			if ( ! is_array( $native[ $map ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Gutenberg block map is invalid.' );
			}
		}
		$properties  = new \stdClass();
		$defaults    = new \stdClass();
		$controls    = new \stdClass();
		$unsupported = array();
		$attributes  = $native['attributes'];
		ksort( $attributes, SORT_STRING );
		foreach ( $attributes as $name => $attribute ) {
			if ( ! is_string( $name ) || ! ProviderRegistration::valid_token( $name ) || ! is_array( $attribute ) ) {
				throw new ContractException( 'unsupported_contract', 'Gutenberg block attribute is invalid.' );
			}
			$schema = $this->attribute_schema( $attribute );
			if ( null === $schema ) {
				$unsupported[] = 'attribute:' . $name;
				continue;
			}
			$properties->{$name} = $schema;
			if ( array_key_exists( 'default', $attribute ) ) {
				$defaults->{$name} = $this->default_value( $attribute['default'], $schema );
			}
			$controls->{$name} = (object) array(
				'native_role'     => is_string( $attribute['role'] ?? null ) ? $attribute['role'] : null,
				'native_selector' => is_string( $attribute['selector'] ?? null ) ? $attribute['selector'] : null,
				'native_source'   => is_string( $attribute['source'] ?? null ) ? $attribute['source'] : null,
				'type'            => $this->control_type( $schema ),
			);
		}
		$declared_context = array();
		foreach ( array(
			'parent'   => $native['parent'],
			'ancestor' => $native['ancestor'],
			'uses'     => $native['uses_context'],
			'allowed'  => $native['allowed_blocks'],
		) as $prefix => $values ) {
			foreach ( is_array( $values ) ? $values : array() as $value ) {
				if ( is_string( $value ) && ProviderRegistration::valid_token( $value ) ) {
					$declared_context[] = $prefix . ':' . $value;
				}
			}
		}
		foreach ( $native['provides_context'] as $key => $attribute ) {
			if ( is_string( $key ) && is_string( $attribute ) && ProviderRegistration::valid_token( $key ) && ProviderRegistration::valid_token( $attribute ) ) {
				$declared_context[] = 'provides:' . $key . ':' . $attribute;
			}
		}
		$source_provider = $semantic['source_provider_id'] ?? null;
		if ( ! is_string( $source_provider ) ) {
			throw new ContractException( 'unsupported_contract', 'Gutenberg source provider is invalid.' );
		}
		$slots = array_values( array_unique( array_merge( (array) ( $semantic['slots'] ?? array() ), $native['allowed_blocks'] ? array( 'innerBlocks' ) : array() ) ) );
		$hash  = hash(
			'sha256',
			json_encode(
				array(
					'normalizer'     => self::REVISION,
					'native'         => $native,
					'semantic'       => $semantic,
					'document_kinds' => $document_kinds,
					'schema'         => $properties,
					'defaults'       => $defaults,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
			)
		);
		return new ComponentDeclaration(
			array(
				'source_provider_id'   => $source_provider,
				'native_id'            => $native['name'],
				'availability'         => $native['availability'],
				'document_kinds'       => $document_kinds,
				'attributes_schema'    => (object) array(
					'type'                 => 'object',
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'defaults'             => $defaults,
				'controls'             => (object) array(
					'attributes'  => $controls,
					'supports'    => $this->json_object( $native['supports'] ),
					'render_mode' => $native['render_mode'],
					'semantic'    => $this->json_object( (array) ( $semantic['controls'] ?? array() ) ),
				),
				'slots'                => $slots,
				'declared_context'     => $declared_context,
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
						'source_ref' => 'gutenberg:' . self::REVISION,
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

	/** Translate WordPress attribute metadata to a closed Draft-07 subset. */
	private function attribute_schema( array $attribute ): ?object {
		$type = $attribute['type'] ?? null;
		// WordPress rich-text values serialize as strings; native sourcing is retained in controls.
		// This describes stored data only. Integration policies still bound writable markup.
		if ( 'rich-text' === $type && in_array( $attribute['source'] ?? null, array( 'rich-text', 'html' ), true ) ) {
			$type = 'string';
		}
		if ( is_array( $type ) ) {
			$types = array_values( array_unique( array_filter( $type, static fn( $item ): bool => is_string( $item ) && in_array( $item, array( 'array', 'boolean', 'integer', 'null', 'number', 'object', 'string' ), true ) ) ) );
			if ( count( $types ) !== count( $type ) || ! $types ) {
				return null;
			}
			$schema = (object) array( 'type' => $types );
		} elseif ( is_string( $type ) && in_array( $type, array( 'array', 'boolean', 'integer', 'null', 'number', 'object', 'string' ), true ) ) {
			$schema = (object) array( 'type' => $type );
		} elseif ( isset( $attribute['enum'] ) && is_array( $attribute['enum'] ) && $attribute['enum'] ) {
			$schema = (object) array( 'enum' => array_values( $attribute['enum'] ) );
		} else {
			return null;
		}
		if ( isset( $attribute['enum'] ) && is_array( $attribute['enum'] ) && $attribute['enum'] ) {
			$schema->enum = array_values( $attribute['enum'] );
		}
		if ( isset( $attribute['description'] ) && is_string( $attribute['description'] ) && '' !== $attribute['description'] ) {
			$schema->description = wp_strip_all_tags( $attribute['description'] );
		}
		if ( ( 'array' === $type || ( is_array( $type ) && in_array( 'array', $type, true ) ) ) && isset( $attribute['items'] ) && is_array( $attribute['items'] ) ) {
			$items = $this->attribute_schema( $attribute['items'] );
			if ( null !== $items ) {
				$schema->items = $items;
			}
		}
		return $schema;
	}

	private function default_value( $value, object $schema ) {
		$type = $schema->type ?? null;
		if ( 'object' === $type && is_array( $value ) && ! $value ) {
			return new \stdClass();
		}
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 24, JSON_THROW_ON_ERROR );
	}

	private function control_type( object $schema ): string {
		if ( isset( $schema->enum ) ) {
			return 'select';
		}
		$type = $schema->type ?? null;
		return is_string( $type ) && in_array( $type, array( 'array', 'boolean', 'integer', 'number', 'object', 'string' ), true ) ? $type : 'mixed';
	}

	private function json_object( array $value ): object {
		if ( ! $value ) {
			return new \stdClass();
		}
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
	}
}
