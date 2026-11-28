<?php
/**
 * Explicit provider schema lowering without weakening server validation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Validation;

/**
 * Produces detached tool schemas plus a record of server-only constraints.
 */
final class SchemaPublisher {
	/**
	 * Immutable source schemas.
	 *
	 * @var SchemaRegistry
	 */
	private SchemaRegistry $registry;

	/**
	 * Bind the trusted registry.
	 *
	 * @param SchemaRegistry $registry Trusted schemas.
	 */
	public function __construct( SchemaRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Lower to explicitly supported provider keywords.
	 *
	 * @param string $schema_id Registered schema ID.
	 * @param array  $keywords Provider-supported keywords from trusted configuration.
	 * @return array Schema and explicit omitted constraint paths.
	 * @throws ContractException Unsupported structural capability.
	 */
	public function publish( string $schema_id, array $keywords ): array {
		$omitted = array();
		$schema  = $this->lower( $this->registry->get( $schema_id ), $keywords, '', $omitted );
		return array(
			'schema'      => $schema,
			'server_only' => $omitted,
		);
	}

	/**
	 * Lower only schema positions; enum/const/default values remain data.
	 *
	 * @param mixed  $schema Current schema.
	 * @param array  $keywords Supported keywords.
	 * @param string $path Diagnostic path.
	 * @param array  $omitted Accumulated omitted constraints.
	 * @return mixed Lowered schema.
	 * @throws ContractException A required structural keyword is unsupported.
	 */
	private function lower( $schema, array $keywords, string $path, array &$omitted ) {
		if ( is_bool( $schema ) ) {
			if ( ! in_array( 'boolean_schema', $keywords, true ) ) {
				throw new ContractException( 'unsupported_provider_capability', 'Provider cannot represent Boolean schemas.' );
			}
			return $schema;
		}
		$out = new \stdClass();
		foreach ( get_object_vars( $schema ) as $key => $value ) {
			$location = $path . '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), $key );
			if ( 'definitions' === $key ) {
				continue; // All references were expanded by the trusted registry.
			}
			if ( 'const' === $key && ! in_array( 'const', $keywords, true ) && in_array( 'enum', $keywords, true ) && ! property_exists( $schema, 'enum' ) ) {
				$out->enum = array( $value );
				continue;
			}
			if ( ! in_array( $key, $keywords, true ) ) {
				if ( in_array( $key, array( 'type', 'properties', 'items', 'required', 'additionalProperties' ), true ) ) {
					throw new ContractException( 'unsupported_provider_capability', 'Provider lacks required structural schema support.' );
				}
				$omitted[] = $location;
				continue;
			}
			if ( in_array( $key, array( 'properties', 'patternProperties' ), true ) ) {
				$out->{$key} = new \stdClass();
				foreach ( get_object_vars( $value ) as $name => $child ) {
					$out->{$key}->{$name} = $this->lower( $child, $keywords, $location . '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), $name ), $omitted );
				}
			} elseif ( in_array( $key, array( 'allOf', 'anyOf', 'oneOf' ), true ) || ( 'items' === $key && is_array( $value ) ) ) {
				$out->{$key} = array();
				foreach ( $value as $index => $child ) {
					$out->{$key}[] = $this->lower( $child, $keywords, $location . '/' . $index, $omitted );
				}
			} elseif ( in_array( $key, array( 'items', 'contains', 'propertyNames', 'not', 'if', 'then', 'else', 'additionalProperties', 'additionalItems' ), true ) && ! is_bool( $value ) ) {
				$out->{$key} = $this->lower( $value, $keywords, $location, $omitted );
			} else {
				$out->{$key} = $value;
			}
		}
		return $out;
	}
}
