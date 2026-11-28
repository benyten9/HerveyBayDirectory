<?php
/**
 * Frozen, integration-owned declarative builder recipes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Closed trusted declaration and JSON Schema boundary.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

/** No executable callbacks or model-supplied recipe definitions enter this registry. */
final class BuilderRecipeRegistry {
	private const MAX_RECIPES = 32;
	private array $recipes    = array();
	private bool $frozen      = false;
	private JsonCanonicalizer $canonical;

	public function __construct() {
		$this->canonical = new JsonCanonicalizer();
	}

	public function register( object $recipe ): void {
		if ( $this->frozen || count( $this->recipes ) >= self::MAX_RECIPES ) {
			throw new ContractException( 'scope_conflict', 'Builder recipe registration is closed.' );
		}
		( new JsonDecoder( 262144, 24 ) )->check( $recipe );
		$this->closed( $recipe, array( 'id', 'revision', 'provider_id', 'builder', 'native_generation', 'catalog_adapter_revision', 'graph_adapter_revision', 'document_kinds', 'intent_tags', 'parameters_schema', 'components', 'fields', 'dependencies', 'steps' ) );
		foreach ( array( 'id', 'revision', 'provider_id', 'native_generation', 'catalog_adapter_revision', 'graph_adapter_revision' ) as $field ) {
			GraphVersion::identifier( $recipe->{$field} );
		}
		if ( ! in_array( $recipe->builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! $recipe->parameters_schema instanceof \stdClass || ! is_array( $recipe->document_kinds ) || ! $recipe->document_kinds || count( $recipe->document_kinds ) > 20 || ! is_array( $recipe->intent_tags ) || ! $recipe->intent_tags || count( $recipe->intent_tags ) > 12 || ! is_array( $recipe->components ) || ! $recipe->components || count( $recipe->components ) > 12 || ! is_array( $recipe->fields ) || count( $recipe->fields ) > 12 || ! is_array( $recipe->dependencies ) || count( $recipe->dependencies ) > 12 || ! is_array( $recipe->steps ) || ! $recipe->steps || count( $recipe->steps ) > 16 ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe declaration is invalid.' );
		}
		$this->tokens( $recipe->document_kinds );
		$this->tokens( $recipe->intent_tags );
		$this->tokens( $recipe->dependencies );
		$schema = $recipe->parameters_schema;
		$this->closed( $schema, array( 'type', 'properties', 'required', 'additionalProperties' ) );
		if ( 'object' !== $schema->type || false !== $schema->additionalProperties || ! $schema->properties instanceof \stdClass || ! is_array( $schema->required ) || count( get_object_vars( $schema->properties ) ) > 32 ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe parameters must use a closed object schema.' );
		}
		foreach ( array_keys( get_object_vars( $schema->properties ) ) as $name ) {
			$this->name( $name );
		}
		foreach ( $schema->required as $name ) {
			$this->name( $name );
			if ( ! property_exists( $schema->properties, $name ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe required parameter is undeclared.' );
			}
		}
		$this->tokens( $schema->required );
		$schemas = new SchemaRegistry();
		$schemas->register( 'builder.recipe.' . substr( hash( 'sha256', $recipe->provider_id . ':' . $recipe->id ), 0, 16 ), json_encode( $schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
		$components = array();
		foreach ( $recipe->components as $component ) {
			$this->closed( $component, array( 'provider_id', 'native_id', 'operation', 'semantic_rule_refs' ) );
			GraphVersion::identifier( $component->provider_id );
			GraphVersion::identifier( $component->native_id );
			if ( ! in_array( $component->operation, array( 'insert_component', 'set_control', 'bind_field' ), true ) || ! is_array( $component->semantic_rule_refs ) || count( $component->semantic_rule_refs ) > 30 ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe component prerequisite is invalid.' );
			}
			$this->tokens( $component->semantic_rule_refs );
			$key = $component->provider_id . '|' . $component->native_id . '|' . $component->operation;
			if ( isset( $components[ $key ] ) ) {
				throw new ContractException( 'scope_conflict', 'Builder recipe component prerequisite is duplicated.' );
			}
			$components[ $key ] = true;
		}
		$fields = array();
		foreach ( $recipe->fields as $field ) {
			$this->closed( $field, array( 'parameter', 'type' ) );
			$this->name( $field->parameter );
			GraphVersion::identifier( $field->type );
			if ( isset( $fields[ $field->parameter ] ) || ! property_exists( $schema->properties, $field->parameter ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe field prerequisite is invalid.' );
			}
			$fields[ $field->parameter ] = true;
		}
		$targets         = array();
		$control_targets = array();
		foreach ( $recipe->steps as $step ) {
			if ( ! $step instanceof \stdClass || ! isset( $step->op ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe step is invalid.' );
			}
			if ( 'insert' === $step->op ) {
				$this->insert_step( $step, $components, $targets, $schema->properties );
				continue;
			}
			$this->closed( $step, array( 'op', 'target', 'provider_id', 'native_id', 'control_path', 'value_source' ) );
			$this->name( $step->target );
			GraphVersion::identifier( $step->provider_id );
			GraphVersion::identifier( $step->native_id );
			$this->path( $step->control_path );
			$this->closed( $step->value_source, array( 'kind', 'ref' ) );
			GraphVersion::identifier( $step->value_source->ref );
			if ( ! in_array( $step->op, array( 'set_control', 'bind_field' ), true ) || ! isset( $components[ $step->provider_id . '|' . $step->native_id . '|' . $step->op ] ) || ! in_array( $step->value_source->kind, array( 'parameter', 'token', 'field' ), true ) || ( 'bind_field' === $step->op ) !== ( 'field' === $step->value_source->kind ) || ( in_array( $step->value_source->kind, array( 'parameter', 'field' ), true ) && ! property_exists( $schema->properties, $step->value_source->ref ) ) || ( 'field' === $step->value_source->kind && ! isset( $fields[ $step->value_source->ref ] ) ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe step is invalid.' );
			}
			$key = $step->target . ':' . $step->control_path;
			if ( isset( $control_targets[ $key ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe control step is duplicated.' );
			}
			$control_targets[ $key ]  = true;
			$targets[ $step->target ] = true;
		}
		if ( count( $targets ) > 16 || isset( $this->recipes[ $recipe->id ] ) ) {
			throw new ContractException( 'scope_conflict', 'Builder recipe identity is duplicated.' );
		}
		$copy                         = json_decode( json_encode( $recipe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 24, JSON_THROW_ON_ERROR );
		$this->recipes[ $recipe->id ] = (object) array(
			'definition' => $copy,
			'hash'       => hash( 'sha256', $this->canonical->encode( $copy ) ),
		);
	}

	private function insert_step( object $step, array $components, array &$targets, object $parameter_properties ): void {
		$this->closed( $step, array( 'op', 'target', 'parent_provider_id', 'parent_native_id', 'position', 'subtree', 'bindings' ) );
		$this->name( $step->target );
		GraphVersion::identifier( $step->parent_provider_id );
		GraphVersion::identifier( $step->parent_native_id );
		if ( ! is_int( $step->position ) || $step->position < 0 || $step->position > 2147483647 || ! $step->subtree instanceof \stdClass || ! is_array( $step->bindings ) || count( $step->bindings ) > 30 ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe insert step is invalid.' );
		}
		$this->closed( $step->subtree, array( 'root_client_id', 'nodes', 'edges' ) );
		GraphVersion::identifier( $step->subtree->root_client_id );
		if ( ! is_array( $step->subtree->nodes ) || ! $step->subtree->nodes || count( $step->subtree->nodes ) > 30 || ! is_array( $step->subtree->edges ) || count( $step->subtree->edges ) > 60 ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe subtree is invalid.' );
		}
		$nodes = array();
		foreach ( $step->subtree->nodes as $node ) {
			$this->closed( $node, array( 'client_id', 'provider_id', 'native_id', 'attributes' ) );
			GraphVersion::identifier( $node->client_id );
			GraphVersion::identifier( $node->provider_id );
			GraphVersion::identifier( $node->native_id );
			if ( isset( $nodes[ $node->client_id ] ) || ! isset( $components[ $node->provider_id . '|' . $node->native_id . '|insert_component' ] ) || ! $node->attributes instanceof \stdClass || count( get_object_vars( $node->attributes ) ) > 30 ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe subtree node is invalid.' );
			}
			foreach ( get_object_vars( $node->attributes ) as $key => $value ) {
				$this->name( $key );
				if ( ! $this->safe_literal( $value ) ) {
					throw new ContractException( 'unsupported_contract', 'Builder recipe literal attribute is unsafe.' );
				}
			}
			$nodes[ $node->client_id ] = true;
		}
		if ( ! isset( $nodes[ $step->subtree->root_client_id ] ) ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe subtree root is absent.' );
		}
		foreach ( $step->subtree->edges as $edge ) {
			$this->closed( $edge, array( 'kind', 'source_client_id', 'target_client_id', 'order' ) );
			if ( ! in_array( $edge->kind, array( 'contains', 'context', 'field', 'style', 'dependency' ), true ) || ! isset( $nodes[ $edge->source_client_id ], $nodes[ $edge->target_client_id ] ) || ( 'contains' === $edge->kind ? ( null !== $edge->order && ( ! is_int( $edge->order ) || $edge->order < 0 ) ) : null !== $edge->order ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe subtree edge is invalid.' );
			}
		}
		$bound = array();
		foreach ( $step->bindings as $binding ) {
			$this->closed( $binding, array( 'client_id', 'attribute', 'value_source' ) );
			GraphVersion::identifier( $binding->client_id );
			$this->name( $binding->attribute );
			$this->closed( $binding->value_source, array( 'kind', 'ref' ) );
			GraphVersion::identifier( $binding->value_source->ref );
			if ( ! isset( $nodes[ $binding->client_id ] ) || ! in_array( $binding->value_source->kind, array( 'parameter', 'token' ), true ) || ( 'parameter' === $binding->value_source->kind && ! property_exists( $parameter_properties, $binding->value_source->ref ) ) || isset( $bound[ $binding->client_id . ':' . $binding->attribute ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe attribute binding is invalid.' );
			}
			$bound[ $binding->client_id . ':' . $binding->attribute ] = true;
		}
		$targets[ $step->target ] = true;
	}

	private function safe_literal( $value ): bool {
		return is_bool( $value ) || ( ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) ) || ( is_string( $value ) && strlen( $value ) <= 128 && (bool) preg_match( '/^[A-Za-z0-9 ._#%-]*$/D', $value ) );
	}

	public function freeze(): void {
		$this->frozen = true;
	}

	public function get( string $id ): ?object {
		if ( ! $this->frozen ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipes must be frozen before discovery.' );
		}
		return isset( $this->recipes[ $id ] ) ? json_decode( json_encode( $this->recipes[ $id ], JSON_THROW_ON_ERROR ) ) : null;
	}

	public function all(): array {
		if ( ! $this->frozen ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipes must be frozen before discovery.' );
		}
		$recipes = $this->recipes;
		ksort( $recipes, SORT_STRING );
		return array_map( static fn( object $item ): object => json_decode( json_encode( $item, JSON_THROW_ON_ERROR ) ), array_values( $recipes ) );
	}

	private function tokens( array $items ): void {
		$seen = array();
		foreach ( $items as $item ) {
			GraphVersion::identifier( $item );
			if ( isset( $seen[ $item ] ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder recipe token is duplicated.' );
			}
			$seen[ $item ] = true;
		}
	}

	private function name( $value ): void {
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $value ) ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe parameter name is invalid.' );
		}
	}

	private function path( $value ): void {
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$/D', $value ) ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe control path is invalid.' );
		}
	}

	private function closed( $value, array $expected ): void {
		$actual = $value instanceof \stdClass ? array_keys( get_object_vars( $value ) ) : array();
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe declaration has an unknown or missing field.' );
		}
	}
}
