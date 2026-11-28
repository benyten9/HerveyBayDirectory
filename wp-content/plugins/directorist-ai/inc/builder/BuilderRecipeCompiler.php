<?php
/**
 * Current-fact recipe selection and ordinary semantic change compilation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.PHP.YodaConditions.NotYoda -- Keep evidence fences beside compilation.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Trusted definitions choose shapes; only the P4-05 planner can create a proposal. */
final class BuilderRecipeCompiler {
	private Database $db;
	private BuilderRecipeRegistry $registry;
	private GraphSnapshotStore $graphs;
	private BuilderPlanningPolicyRegistry $policies;
	private BuilderTaskContextCatalogProvider $catalogs;
	private BuilderTaskContextFactsProvider $design;
	private RelationshipFactsProvider $relationships;
	private EffectiveCatalogContract $catalog_contract;
	private JsonCanonicalizer $canonical;

	public function __construct( Database $db, BuilderRecipeRegistry $registry, GraphSnapshotStore $graphs, BuilderPlanningPolicyRegistry $policies, BuilderTaskContextCatalogProvider $catalogs, BuilderTaskContextFactsProvider $design, RelationshipFactsProvider $relationships ) {
		$this->db               = $db;
		$this->registry         = $registry;
		$this->graphs           = $graphs;
		$this->policies         = $policies;
		$this->catalogs         = $catalogs;
		$this->design           = $design;
		$this->relationships    = $relationships;
		$this->catalog_contract = new EffectiveCatalogContract();
		$this->canonical        = new JsonCanonicalizer();
	}

	/** Discover bounded cards with current limitations; field values are checked at selection. */
	public function discover( Context $context, string $document_key, GraphVersion $version, object $scope ): array {
		$evidence = $this->evidence( $context, $document_key, $version, $scope, false );
		$result   = array();
		foreach ( $this->registry->all() as $registered ) {
			$recipe = $registered->definition;
			if ( $recipe->builder !== $evidence['catalog']->builder || ! in_array( $evidence['catalog']->document_kind, $recipe->document_kinds, true ) ) {
				continue;
			}
			if ( 'complete' !== $evidence['snapshot']->graph->completeness || 'complete' !== $evidence['snapshot']->graph->consumer_coverage ) {
				$status = 'needs_expansion';
				$reason = 'incomplete_graph';
			} else {
				try {
					$this->prerequisites( $recipe, $evidence, null );
					$status = $recipe->fields ? 'requires_parameters' : 'available';
					$reason = null;
				} catch ( ContractException $error ) {
					$status = 'unavailable';
					$reason = $error->error_code();
				}
			}
			$result[] = (object) array( 'id' => $recipe->id, 'revision' => $recipe->revision, 'definition_hash' => $registered->hash, 'intent_tags' => $recipe->intent_tags, 'parameter_names' => array_keys( get_object_vars( $recipe->parameters_schema->properties ) ), 'status' => $status, 'limitation' => $reason );
		}
		$this->fresh( $context, $document_key, $version, $scope, $evidence, false );
		return $result;
	}

	/** Compile only the registered recipe's declarative operations; no native write is available. */
	public function compile( Context $context, string $document_key, GraphVersion $version, BuilderRecipeRequest $request ): object {
		$scope    = $request->scope();
		$evidence = $this->evidence( $context, $document_key, $version, $scope, true );
		$entry    = $this->registry->get( $request->recipe_id() );
		if ( null === $entry ) {
			throw new ContractException( 'unsupported_operation', 'Builder recipe is unavailable.' );
		}
		$recipe = $entry->definition;
		if ( $recipe->builder !== $evidence['catalog']->builder || ! in_array( $evidence['catalog']->document_kind, $recipe->document_kinds, true ) ) {
			throw new ContractException( 'unsupported_operation', 'Builder recipe does not support this document.' );
		}
		$parameters = $request->parameters();
		$schemas = new SchemaRegistry();
		$schemas->register( 'builder.recipe.parameters', json_encode( $recipe->parameters_schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
		try {
			( new ContractValidator( $schemas, new JsonDecoder( 262144, 20 ) ) )->validate( $parameters, 'builder.recipe.parameters' );
		} catch ( ContractException $error ) {
			throw new ContractException( 'invalid_input', 'Builder recipe parameters fail their trusted schema.' );
		}
		$selected_provider = $this->prerequisites( $recipe, $evidence, $parameters );
		$targets = $request->targets();
		$expected_aliases = array_values( array_unique( array_map( static fn( object $step ): string => $step->target, $recipe->steps ) ) );
		sort( $expected_aliases, SORT_STRING );
		$actual_aliases = array_keys( get_object_vars( $targets ) );
		sort( $actual_aliases, SORT_STRING );
		if ( $expected_aliases !== $actual_aliases ) {
			throw new ContractException( 'invalid_input', 'Builder recipe target set differs from the declaration.' );
		}
		$overrides = array();
		foreach ( $request->token_overrides() as $override ) {
			$overrides[ $override->id ] = $override;
		}
		$tokens = array();
		foreach ( $evidence['design']->design_tokens() as $token ) {
			$tokens[ $token->id ] = $token;
		}
		$used_overrides = array();
		$token_sources = array();
		$changes = array();
		foreach ( $recipe->steps as $step ) {
			$expected_provider = 'insert' === $step->op ? $step->parent_provider_id : $step->provider_id;
			$expected_native   = 'insert' === $step->op ? $step->parent_native_id : $step->native_id;
			$this->target( $evidence['snapshot']->graph, $targets->{$step->target}, $scope, $expected_provider, $expected_native );
			if ( 'insert' === $step->op ) {
				$subtree = json_decode( json_encode( $step->subtree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
				foreach ( $step->bindings as $binding ) {
					$value = $this->source_value( $binding->value_source, $parameters, $tokens, $overrides, $used_overrides, $token_sources );
					foreach ( $subtree->nodes as $node ) {
						if ( $node->client_id === $binding->client_id ) {
							$node->attributes->{$binding->attribute} = $value;
							break;
						}
					}
				}
				foreach ( $subtree->nodes as $node ) {
					$policy = $this->policy( $node->provider_id, $node->native_id );
					$this->policies->validate_insert( $policy, $node->attributes );
				}
				$changes[] = (object) array( 'op' => 'insert', 'parent' => $targets->{$step->target}, 'position' => $step->position, 'subtree' => $subtree, 'scope' => $scope );
				continue;
			}
			$policy = $this->policy( $step->provider_id, $step->native_id );
			if ( 'bind_field' === $step->op ) {
				$field_id = $parameters->{$step->value_source->ref};
				$field = $evidence['relationships']->field( $field_id, $scope->directory_id );
				$this->policies->validate_binding( $policy, $step->control_path, $field->type, (object) array( 'field_id' => $field_id ) );
				$changes[] = (object) array( 'op' => 'bind_field', 'target' => $targets->{$step->target}, 'control_path' => $step->control_path, 'field_id' => $field_id, 'scope' => $scope );
			} else {
				$value = $this->source_value( $step->value_source, $parameters, $tokens, $overrides, $used_overrides, $token_sources );
				$this->policies->validate_control( $policy, $step->control_path, $value );
				$changes[] = (object) array( 'op' => 'set_control', 'target' => $targets->{$step->target}, 'control_path' => $step->control_path, 'value' => $value, 'scope' => $scope );
			}
		}
		if ( count( $used_overrides ) !== count( $overrides ) ) {
			throw new ContractException( 'invalid_input', 'Builder recipe token override is unused.' );
		}
		ksort( $token_sources, SORT_STRING );
		$field_requirements = array();
		foreach ( $recipe->fields as $required ) {
			$field_requirements[] = (object) array( 'id' => $parameters->{$required->parameter}, 'type' => $required->type );
		}
		usort( $field_requirements, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		$selection = new BuilderRecipeSelection(
			(object) array(
				'recipe_id'                    => $recipe->id,
				'recipe_revision'              => $recipe->revision,
				'recipe_hash'                  => $entry->hash,
				'catalog_revision'             => $evidence['catalog']->revision,
				'catalog_binding_revision'     => $evidence['catalog_snapshot']->revision(),
				'catalog_provider_fingerprint' => $selected_provider->source_fingerprint,
				'relationship_fact_revision'   => $evidence['relationships']->revision(),
				'design_fact_revision'         => $evidence['design']->revision(),
				'graph_version_hash'           => $version->hash(),
				'graph_generation'             => $evidence['snapshot']->generation,
				'graph_hash'                   => $evidence['snapshot']->graph_hash,
				'document_key'                 => $document_key,
				'scope_hash'                   => hash( 'sha256', $this->canonical->encode( $scope ) ),
				'changes_hash'                 => hash( 'sha256', $this->canonical->encode( $changes ) ),
				'token_sources'                => array_values( $token_sources ),
				'field_requirements'           => $field_requirements,
			)
		);
		$this->fresh( $context, $document_key, $version, $scope, $evidence, true );
		return (object) array( 'changes' => $changes, 'selection' => $selection, 'requires_full_server_validation' => true );
	}

	/** The planner owns every locator, relation, consumer, scope and approval check. */
	public function propose( Context $context, string $request_id, string $document_key, GraphVersion $version, BuilderRecipeRequest $request, array $consumer_bases, BuilderChangePlanner $planner ): object {
		$compiled = $this->compile( $context, $document_key, $version, $request );
		$fence = new BuilderRecipeFreshnessFence( $this, $context, $document_key, $version, $request->scope(), $compiled->selection );
		return $planner->propose( $context, $request_id, $document_key, $version, $request->scope(), $compiled->changes, $consumer_bases, $compiled->selection, $fence );
	}

	/** Recheck every recipe prerequisite before the planner activates a review. */
	public function assert_selection_current( Context $context, string $document_key, GraphVersion $version, object $scope, BuilderRecipeSelection $selection ): void {
		$selected = $selection->value();
		$evidence = $this->evidence( $context, $document_key, $version, $scope, true );
		$entry = $this->registry->get( $selected->recipe_id );
		if ( null === $entry || ! hash_equals( $selected->recipe_hash, $entry->hash ) || $selected->recipe_revision !== $entry->definition->revision || $selected->document_key !== $document_key || ! hash_equals( $selected->scope_hash, hash( 'sha256', $this->canonical->encode( $scope ) ) ) || ! hash_equals( $selected->graph_version_hash, $version->hash() ) || $selected->graph_generation !== $evidence['snapshot']->generation || ! hash_equals( $selected->graph_hash, $evidence['snapshot']->graph_hash ) || ! hash_equals( $selected->catalog_revision, $evidence['catalog']->revision ) || ! hash_equals( $selected->catalog_binding_revision, $evidence['catalog_snapshot']->revision() ) || ! hash_equals( $selected->relationship_fact_revision, $evidence['relationships']->revision() ) || ! hash_equals( $selected->design_fact_revision, $evidence['design']->revision() ) ) {
			throw new ContractException( 'evidence_stale', 'Builder recipe selection changed before review activation.' );
		}
		$provider = $this->prerequisites( $entry->definition, $evidence, null );
		if ( ! hash_equals( $selected->catalog_provider_fingerprint, $provider->source_fingerprint ) ) {
			throw new ContractException( 'evidence_stale', 'Builder recipe catalog source changed before review activation.' );
		}
		foreach ( $selected->field_requirements as $required ) {
			$field = $evidence['relationships']->field( $required->id, $scope->directory_id );
			if ( null === $field || ! $field->eligible || $field->type !== $required->type ) {
				throw new ContractException( 'evidence_stale', 'Builder recipe field changed before review activation.' );
			}
		}
	}

	private function evidence( Context $context, string $document_key, GraphVersion $version, object $scope, bool $require_complete ): array {
		$this->scope( $scope );
		$context->assert_execution_identity();
		$context->actor()->refresh();
		$context->assert_current();
		if ( $context->actor()->is_guest() || null === $context->run_id() || null === $context->conversation_id() || ( ! $context->actor()->can( 'edit_pages' ) && ! $context->actor()->can( 'edit_posts' ) && ! $context->actor()->can( 'edit_theme_options' ) ) ) {
			throw new ContractException( 'unauthorized', 'Builder recipe needs a current editing workflow.' );
		}
		GraphVersion::identifier( $document_key );
		$owner = $context->actor()->owner();
		$run_id = $context->run_id();
		$catalog_snapshot = $this->catalogs->read( $owner, $run_id );
		if ( $catalog_snapshot->site_id() !== get_current_blog_id() || $catalog_snapshot->owner_ref() !== $owner->reference() || $catalog_snapshot->run_id() !== $run_id ) {
			throw new ContractException( 'unauthorized', 'Builder recipe catalog belongs to another owner or run.' );
		}
		$catalog = $this->catalog_contract->validate( $catalog_snapshot->catalog() );
		$snapshot = $this->graphs->current( $owner, $document_key, $version, 2000, 5000, $require_complete );
		$this->design->assert_document_access( $context, $document_key, $snapshot->graph->document );
		$this->conversation_target( $context, $document_key, $snapshot->graph->document );
		$directory = null === $catalog->directory_id ? null : (string) $catalog->directory_id;
		if ( $catalog->builder !== $snapshot->graph->document->builder || $catalog->document_kind !== $snapshot->graph->document->native_kind || $catalog->environment_fingerprint !== $version->environment_hash() || $scope->directory_id !== $directory ) {
			throw new ContractException( 'stale_version', 'Builder recipe catalog differs from the current graph scope.' );
		}
		$relationship_facts = $this->relationships->read( $owner, $document_key, $version );
		$has_directory = $relationship_facts->has_directory( $directory );
		$selected_revision = $relationship_facts->directory_revision_for( $directory );
		$catalog_directory_matches = null === $directory ? null === $catalog->directory_revision : ( is_string( $catalog->directory_revision ) && null !== $selected_revision && hash_equals( $catalog->directory_revision, $selected_revision ) );
		if ( $relationship_facts->site_id() !== get_current_blog_id() || $relationship_facts->owner_ref() !== $owner->reference() || $relationship_facts->document_key() !== $document_key || $relationship_facts->graph_version_hash() !== $version->hash() || $relationship_facts->environment_hash() !== $version->environment_hash() || ! $has_directory || ! $catalog_directory_matches || $relationship_facts->directory_revision() !== $version->directory_schema_hash() ) {
			throw new ContractException( 'evidence_stale', 'Builder recipe relationship facts are stale.' );
		}
		$design = $this->design->read( $owner, $run_id, $document_key, $version, $catalog->revision );
		if ( $design->site_id() !== get_current_blog_id() || $design->owner_ref() !== $owner->reference() || $design->run_id() !== $run_id || $design->conversation_id() !== $context->conversation_id() || $design->document_key() !== $document_key || $design->graph_version_hash() !== $version->hash() || $design->catalog_revision() !== $catalog->revision ) {
			throw new ContractException( 'evidence_stale', 'Builder recipe design facts are stale.' );
		}
		if ( null !== $scope->breakpoint_id && null === $relationship_facts->breakpoint( $scope->breakpoint_id ) ) {
			throw new ContractException( 'unsupported_operation', 'Builder recipe breakpoint is unavailable.' );
		}
		return array( 'owner' => $owner, 'run_id' => $run_id, 'catalog_snapshot' => $catalog_snapshot, 'catalog' => $catalog, 'snapshot' => $snapshot, 'relationships' => $relationship_facts, 'design' => $design, 'version' => $version, 'scope' => $scope );
	}

	/**
	 * Recheck the owned conversation's explicit document binding on every read.
	 *
	 * @throws ContractException When a recipe request targets another document.
	 */
	private function conversation_target( Context $context, string $document_key, object $native_document ): void {
		$owner = $context->actor()->owner();
		$rows = $this->db->rows( 'SELECT c.context_json FROM ' . $this->db->table( 'runs' ) . ' r INNER JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.erasure_requested=0 AND r.cancel_requested=0', array( $context->run_id(), $context->conversation_id(), $owner->reference(), $owner->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Builder recipe conversation target is unavailable.' );
		}
		BuilderConversationTarget::assert_binding( $rows[0]['context_json'], $document_key, $native_document );
	}

	private function fresh( Context $context, string $document_key, GraphVersion $version, object $scope, array $before, bool $require_complete ): void {
		$owner = $before['owner'];
		$after = $this->evidence( $context, $document_key, $version, $scope, $require_complete );
		if ( $before['snapshot']->generation !== $after['snapshot']->generation || $before['snapshot']->graph_hash !== $after['snapshot']->graph_hash || $before['catalog_snapshot']->revision() !== $after['catalog_snapshot']->revision() || $before['relationships']->revision() !== $after['relationships']->revision() || $before['design']->revision() !== $after['design']->revision() || $owner->reference() !== $after['owner']->reference() ) {
			throw new ContractException( 'evidence_stale', 'Builder recipe evidence changed during compilation.' );
		}
	}

	private function prerequisites( object $recipe, array $evidence, ?object $parameters ): object {
		$catalog = $evidence['catalog'];
		$provider = null;
		$provider_count = 0;
		foreach ( $catalog->providers as $candidate ) {
			if ( $candidate->reader_id === $recipe->provider_id ) {
				$provider = $candidate;
				++$provider_count;
			}
		}
		if ( 1 !== $provider_count || null === $provider || 'available' !== $provider->status || $recipe->native_generation !== $provider->native_generation || $recipe->catalog_adapter_revision !== $provider->adapter_revision || null === $provider->source_fingerprint || $recipe->graph_adapter_revision !== $evidence['version']->adapter_revision() ) {
			throw new ContractException( 'unsupported_contract', 'Builder recipe native generation or adapter is unavailable.' );
		}
		foreach ( $recipe->components as $required ) {
			$component = null;
			$component_count = 0;
			foreach ( $catalog->components as $candidate ) {
				if ( $candidate->source_provider_id === $required->provider_id && $candidate->native_id === $required->native_id ) {
					$component = $candidate;
					++$component_count;
				}
			}
			if ( 1 !== $component_count || null === $component || 'available' !== $component->availability || ! in_array( $catalog->document_kind, $component->document_kinds, true ) || $component->adapter_revision !== $provider->adapter_revision ) {
				throw new ContractException( 'unsupported_operation', 'Builder recipe component is unavailable.' );
			}
			$features = array_filter( $component->features, static fn( object $feature ): bool => $feature->operation === $required->operation && $feature->document_kind === $catalog->document_kind && ( ! $feature->requires_editor || $evidence['version']->editor_bound() ) );
			if ( ! $features ) {
				throw new ContractException( 'unsupported_operation', 'Builder recipe component operation is unavailable.' );
			}
			$expected_rules = $required->semantic_rule_refs;
			$actual_rules = $component->semantic_rule_refs;
			sort( $expected_rules, SORT_STRING );
			sort( $actual_rules, SORT_STRING );
			if ( $expected_rules !== $actual_rules ) {
				throw new ContractException( 'relationship_invalid', 'Builder recipe native semantic rule changed.' );
			}
			foreach ( $component->dependencies as $dependency ) {
				if ( 'available' !== $dependency->status ) {
					throw new ContractException( 'unsupported_operation', 'Builder recipe component dependency is unavailable.' );
				}
			}
		}
		foreach ( $recipe->dependencies as $id ) {
			$dependency = $evidence['relationships']->dependency( $id );
			if ( null === $dependency || 'available' !== $dependency->status ) {
				throw new ContractException( 'unsupported_operation', 'Builder recipe extension is unavailable.' );
			}
		}
		if ( null === $parameters ) {
			return $provider;
		}
		foreach ( $recipe->fields as $required ) {
			$id = $parameters->{$required->parameter};
			if ( ! is_string( $id ) ) {
				throw new ContractException( 'invalid_input', 'Builder recipe field parameter is invalid.' );
			}
			$field = $evidence['relationships']->field( $id, $evidence['scope']->directory_id );
			if ( null === $field || ! $field->eligible || $field->type !== $required->type ) {
				throw new ContractException( 'unsupported_operation', 'Builder recipe field is unavailable.' );
			}
		}
		return $provider;
	}

	private function target( object $graph, object $locator, object $scope, string $provider_id, string $native_id ): void {
		$key = $this->canonical->encode( $locator );
		foreach ( $graph->nodes as $node ) {
			if ( $this->canonical->encode( $node->locator ) === $key ) {
				if ( $node->provider_id !== $provider_id || $node->native_id !== $native_id || null !== $node->opaque_ref || $this->canonical->encode( $node->scope ) !== $this->canonical->encode( $scope ) ) {
					throw new ContractException( 'unsupported_operation', 'Builder recipe target identity or scope is unavailable.' );
				}
				return;
			}
		}
		throw new ContractException( 'stale_version', 'Builder recipe target locator is stale.' );
	}

	private function policy( string $provider_id, string $native_id ): object {
		$policy = $this->policies->component( $provider_id, $native_id );
		if ( null === $policy ) {
			throw new ContractException( 'unsupported_operation', 'Builder recipe component lacks a planning policy.' );
		}
		return $policy;
	}

	private function scope( object $scope ): void {
		$keys = array_keys( get_object_vars( $scope ) );
		sort( $keys, SORT_STRING );
		if ( $keys !== array( 'breakpoint_id', 'directory_id', 'interaction', 'view' ) || ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ! in_array( $scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Builder recipe scope is invalid.' );
		}
		if ( null !== $scope->breakpoint_id ) {
			GraphVersion::identifier( $scope->breakpoint_id );
		}
	}

	private function source_value( object $source, object $parameters, array $tokens, array $overrides, array &$used_overrides, array &$token_sources ) {
		if ( 'parameter' === $source->kind ) {
			return $parameters->{$source->ref};
		}
		$token = $tokens[ $source->ref ] ?? null;
		if ( null === $token ) {
			throw new ContractException( 'unsupported_operation', 'Builder recipe design token is unavailable.' );
		}
		$override = $overrides[ $token->id ] ?? null;
		if ( null !== $override && $override->kind !== $token->kind ) {
			throw new ContractException( 'invalid_input', 'Builder recipe token kind cannot change.' );
		}
		if ( null !== $override ) {
			$used_overrides[ $token->id ] = true;
		}
		$token_sources[ $token->id ] = (object) array( 'id' => $token->id, 'kind' => $token->kind, 'scope' => $token->scope, 'source_id' => $token->source_id, 'source_revision' => $token->source_revision, 'local_override' => null !== $override );
		return null !== $override ? $override->value : $token->value;
	}
}
