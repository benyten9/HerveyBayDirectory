<?php
/**
 * Lossless model-facing shapes for the existing semantic change planner.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed schemas remain beside their operation variants.

/** Structural validation does not replace current native policy and relationship checks. */
final class BuilderChangeSchema {

	public static function schema( array $locator, array $scope, ?array $operations = null ): array {
		$identifier = array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$' );
		$position   = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 );
		$path       = array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+(?:\\.[A-Za-z0-9_-]+){0,15}$' );
		$node       = self::closed( array( 'client_id' => $identifier, 'provider_id' => $identifier, 'native_id' => $identifier, 'attributes' => array( 'type' => 'object' ) ) );
		$edge       = self::closed(
			array(
				'kind'             => array( 'enum' => array( 'contains', 'context', 'field', 'style', 'dependency' ) ),
				'source_client_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'An inserted client_id; only a context edge may use $parent for the authorized insertion parent.' ),
				'target_client_id' => $identifier,
				'order'            => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0, 'maximum' => 2147483647 ),
			)
		);
		$subtree    = self::closed(
			array(
				'root_client_id' => $identifier,
				'nodes'          => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 400, 'items' => $node ),
				'edges'          => array( 'type' => 'array', 'maxItems' => 800, 'items' => $edge ),
			)
		);
		$variants   = array(
			self::change( 'insert', array( 'parent' => $locator, 'position' => $position, 'subtree' => $subtree ), $scope ),
			self::change( 'move', array( 'target' => $locator, 'parent' => $locator, 'position' => $position ), $scope ),
			self::change( 'remove', array( 'target' => $locator ), $scope ),
			self::change( 'set_control', array( 'target' => $locator, 'control_path' => $path, 'value' => (object) array() ), $scope ),
			self::change( 'bind_field', array( 'target' => $locator, 'control_path' => $path, 'field_id' => $identifier ), $scope ),
			self::change( 'set_variant', array( 'target' => $locator, 'variant_id' => $identifier, 'mode' => array( 'enum' => array( 'select_existing', 'create' ) ), 'plan' => array( 'type' => 'object' ) ), $scope ),
		);
		if ( null !== $operations && in_array( 'configure_directory_query', $operations, true ) ) {
			$variants[] = self::change( 'configure_directory_query', array( 'target' => $locator, 'before' => BuilderDirectoryQueryService::configuration_schema( true ), 'after' => BuilderDirectoryQueryService::configuration_schema( true ) ), $scope );
		}
		if ( null !== $operations && in_array( 'configure_directory_binding', $operations, true ) ) {
			$variants[] = self::change( 'configure_directory_binding', array( 'target' => $locator, 'before' => BuilderDirectoryBindingService::configuration_schema( true ), 'after' => BuilderDirectoryBindingService::configuration_schema() ), $scope );
		}
		if ( null !== $operations && in_array( 'configure_saved_variants', $operations, true ) ) {
			$variants[] = self::change( 'configure_saved_variants', array( 'target' => $locator, 'batch' => BuilderSavedVariantControlsService::batch_schema( $scope ) ), $scope );
		}
		if ( null !== $operations ) {
			$variants = array_values( array_filter( $variants, static fn( array $variant ): bool => in_array( $variant['properties']['op']['const'], $operations, true ) ) );
		}
		if ( ! $variants ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Native compilers declare no supported semantic draft change.' );
		}
		return array( 'oneOf' => $variants );
	}

	private static function change( string $operation, array $properties, array $scope ): array {
		return self::closed( array_merge( array( 'op' => array( 'const' => $operation ) ), $properties, array( 'scope' => $scope ) ) );
	}

	private static function closed( array $properties ): array {
		return array( 'type' => 'object', 'properties' => $properties, 'required' => array_keys( $properties ), 'additionalProperties' => false );
	}
}
