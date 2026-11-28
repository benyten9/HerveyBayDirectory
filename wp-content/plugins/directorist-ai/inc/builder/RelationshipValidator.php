<?php
/**
 * Deterministic semantic validation for builder graph relationships and scopes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Rule checks remain adjacent to diagnostics.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Storage\Owner;

/** Pure validator: it has no native mutation handler and executes no supplied code. */
final class RelationshipValidator {
	private SemanticRuleRegistry $rules;
	private JsonCanonicalizer $canonical;
	private JsonDecoder $decoder;

	public function __construct( SemanticRuleRegistry $rules ) {
		$this->rules     = $rules;
		$this->canonical = new JsonCanonicalizer();
		$this->decoder   = new JsonDecoder( 5242880, 32 );
	}

	/** Whether the frozen trusted registry declares this exact component tuple. */
	public function supports_component( string $provider_id, string $native_id ): bool {
		return null !== $this->rules->component_for( $provider_id, $native_id );
	}

	/** Return one frozen declaration for safe context selection. */
	public function component_rule( string $provider_id, string $native_id ): ?object {
		return $this->rules->component_for( $provider_id, $native_id );
	}

	/** Read-only dependency identities and provenance from frozen native declarations. */
	public function dependency_targets( string $provider_id, string $native_id ): array {
		return $this->rules->dependency_targets( $provider_id, $native_id );
	}

	/** Bind task-context packets to the exact frozen semantic declarations. */
	public function rule_set_revision(): string {
		return $this->rules->revision();
	}

	/** Whether frozen trusted rules permit one exact semantic edge tuple. */
	public function supports_relationship( string $kind, string $source_provider, string $source_native, string $target_provider, string $target_native ): bool {
		return null !== $this->relationship_rule( $kind, $source_provider, $source_native, $target_provider, $target_native );
	}

	/** Return the exact frozen relationship declaration for candidate materialization. */
	public function relationship_rule( string $kind, string $source_provider, string $source_native, string $target_provider, string $target_native ): ?object {
		$source = $this->rules->component_for( $source_provider, $source_native );
		$target = $this->rules->component_for( $target_provider, $target_native );
		return null === $source || null === $target ? null : $this->rules->matching_relationship( $kind, $source->id, $target->id );
	}

	/** Return all frozen variants for safe read-only structural guidance. */
	public function relationship_rules( string $kind, string $source_provider, string $source_native, string $target_provider, string $target_native ): array {
		$source = $this->rules->component_for( $source_provider, $source_native );
		$target = $this->rules->component_for( $target_provider, $target_native );
		return null === $source || null === $target ? array() : $this->rules->matching_relationships( $kind, $source->id, $target->id );
	}

	/** Validate a complete current owner-authorized snapshot and re-fence it after semantic work. */
	public function validate_current( GraphSnapshotStore $graphs, Owner $owner, string $document_key, GraphVersion $expected, RelationshipFactsProvider $facts_provider, ?BuilderPrivateCopyCoverage $copy = null ): object {
		return $this->validate_current_bundle( $graphs, $owner, $document_key, $expected, $facts_provider, $copy )->report;
	}

	/** Return the exact facts and snapshot checked by the complete current validation. */
	public function validate_current_bundle( GraphSnapshotStore $graphs, Owner $owner, string $document_key, GraphVersion $expected, RelationshipFactsProvider $facts_provider, ?BuilderPrivateCopyCoverage $copy = null ): object {
		$before = $graphs->current( $owner, $document_key, $expected, 2000, 5000, null === $copy );
		$facts  = $facts_provider->read( $owner, $document_key, $expected );
		if ( ! $facts instanceof RelationshipFacts ) {
			throw new ContractException( 'unsupported_contract', 'Relationship fact provider returned an invalid result.' );
		}
		if ( $facts->owner_ref() !== $owner->reference() ) {
			throw new ContractException( 'unauthorized', 'Relationship facts belong to another owner.' );
		}
		$report = $this->validate( $before->document_key, $expected, $before->graph, $facts, $copy );
		$fresh  = $facts_provider->read( $owner, $document_key, $expected );
		if ( ! $fresh instanceof RelationshipFacts || $fresh->owner_ref() !== $owner->reference() || ! hash_equals( $facts->revision(), $fresh->revision() ) ) {
			throw new ContractException( 'evidence_stale', 'Relationship facts changed during validation.' );
		}
		try {
			$after = $graphs->current( $owner, $document_key, $expected, 2000, 5000, null === $copy );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'stale_version', 'Document graph changed during relationship validation.' );
		}
		if ( $before->generation !== $after->generation || ! hash_equals( $before->graph_hash, $after->graph_hash ) ) {
			throw new ContractException( 'stale_version', 'Document graph changed during relationship validation.' );
		}
		$report->document_key        = $document_key;
		$report->snapshot_generation = $before->generation;
		$report->snapshot_hash       = $before->graph_hash;
		return (object) array( 'report' => $report, 'facts' => $fresh, 'snapshot' => $after );
	}

	/** Validate one exact graph against current authorized facts and frozen rules. */
	public function validate( string $document_key, GraphVersion $expected, object $graph, RelationshipFacts $facts, ?BuilderPrivateCopyCoverage $copy = null, bool $candidate = false ): object {
		GraphVersion::identifier( $document_key );
		if ( null !== $copy ) {
			$copy->assert_graph( $document_key, $expected, $graph, $candidate );
		}
		$parsed = $this->graph( $expected, $graph );
		$diagnostics = array();
		$nodes       = $parsed['nodes'];
		if ( get_current_blog_id() !== $facts->site_id() || $document_key !== $facts->document_key() || ! hash_equals( $expected->hash(), $facts->graph_version_hash() ) ) {
			$this->diagnostic( $diagnostics, 'stale_fact_authority', 'facts.graph_version_hash', 'native-facts', $facts->revision(), true, true, (object) array() );
		}
		if ( ! hash_equals( $expected->environment_hash(), $facts->environment_hash() ) ) {
			$this->diagnostic( $diagnostics, 'stale_environment_facts', 'facts.environment_hash', 'native-facts', $facts->revision(), true, true, (object) array() );
		}
		if ( ! hash_equals( $expected->directory_schema_hash(), $facts->directory_revision() ) ) {
			$this->diagnostic( $diagnostics, 'stale_directory_facts', 'facts.directory_revision', 'native-facts', $facts->revision(), true, true, (object) array() );
		}
		$node_rules  = array();
		$scope_keys  = array();
		$instance_keys = array();
		foreach ( $nodes as $id => $node ) {
			$rule = $this->rules->component_for( $node->provider_id, $node->native_id );
			if ( null === $rule ) {
				$this->diagnostic( $diagnostics, 'unsupported_component_rule', 'nodes.' . $id, 'registry', '0', false, false, (object) array( 'provider_id' => $node->provider_id ) );
				continue;
			}
			$node_rules[ $id ] = $rule;
			$this->component_scope( $diagnostics, $id, $node->scope, $rule, $facts );
			$scope_hash = hash( 'sha256', $this->canonical->encode( $node->scope ) );
			$scope_keys[ $scope_hash ] = $node->scope;
			$instance_id = $node->locator->native_id;
			if ( 'elementor' === $graph->document->builder && null !== $instance_id ) {
				if ( isset( $instance_keys[ $instance_id ] ) ) {
					$this->diagnostic( $diagnostics, 'ambiguous_native_instance', 'nodes.' . $id . '.locator.native_id', $rule->id, $rule->revision, false, false, (object) array( 'other_node_id' => $instance_keys[ $instance_id ] ) );
				} else {
					$instance_keys[ $instance_id ] = $id;
				}
			}
			$this->conditions( $diagnostics, $id, $node, $rule );
		}
		$this->coverage_scopes( $diagnostics, $graph->slot_coverage, $nodes );
		if ( 'partial' === $graph->completeness ) {
			$this->diagnostic( $diagnostics, 'graph_incomplete', 'completeness', 'graph-coverage', '1', true, true, (object) array() );
		}
		if ( 'partial' === $graph->consumer_coverage && null === $copy ) {
			$this->diagnostic( $diagnostics, 'consumer_coverage_incomplete', 'consumer_coverage', 'graph-coverage', '1', true, true, (object) array() );
		}
		foreach ( $graph->omissions as $index => $omission ) {
			$this->diagnostic( $diagnostics, 'graph_omission', 'omissions.' . $index . '.' . $omission->path, 'graph-coverage', '1', true, true, (object) array( 'omission_code' => $omission->code ) );
		}

		$contains       = array();
		$parents        = array();
		$positions      = array();
		$context_edges  = array();
		$dependency_edges = array();
		$render_targets = array();
		foreach ( $graph->edges as $index => $edge ) {
			$path   = 'edges.' . $index;
			$source = $nodes[ $edge->source ];
			$target = null === $edge->target_document_key ? $nodes[ $edge->target ] : null;
			$source_rule = $node_rules[ $edge->source ] ?? null;
			$relation = $this->rules->relationship( $edge->provenance->rule_id );
			$target_rule = null === $target ? ( null === $relation ? null : $this->rules->component( $relation->target_rule_id ) ) : ( $node_rules[ $edge->target ] ?? null );
			if ( null === $relation || null === $source_rule || null === $target_rule || ( null === $target && ( 'directorist-ai' !== $target_rule->provider_id || ! str_ends_with( $target_rule->native_id, 'external-document' ) ) ) || $relation->kind !== $edge->kind || $relation->source_rule_id !== $source_rule->id || $relation->target_rule_id !== $target_rule->id ) {
				$this->diagnostic( $diagnostics, 'relationship_rule_mismatch', $path, $edge->provenance->rule_id, $edge->provenance->resolver_revision, false, false, (object) array( 'kind' => $edge->kind ) );
				continue;
			}
			if ( $relation->revision !== $edge->provenance->resolver_revision ) {
				$this->diagnostic( $diagnostics, 'stale_rule_revision', $path . '.provenance.resolver_revision', $relation->id, $relation->revision, true, true, (object) array() );
			}
			if ( $relation->source_ref->kind !== $edge->provenance->source_ref->kind || $relation->source_ref->id !== $edge->provenance->source_ref->id || ! is_string( $edge->provenance->source_ref->version ) ) {
				$this->diagnostic( $diagnostics, 'provenance_mismatch', $path . '.provenance.source_ref', $relation->id, $relation->revision, false, false, (object) array() );
			} elseif ( 'template' === $edge->kind && null !== $edge->target_document_key ) {
				$reference = $facts->reference( $edge->target_document_key );
				if ( null !== $reference && 'current' === $reference->status && ! hash_equals( $reference->version_hash, $edge->provenance->source_ref->version ) ) {
					$this->diagnostic( $diagnostics, 'stale_reference_version', $path . '.provenance.source_ref.version', $relation->id, $relation->revision, true, true, (object) array() );
				}
			} elseif ( $relation->revision !== $edge->provenance->source_ref->version ) {
				$this->diagnostic( $diagnostics, 'provenance_mismatch', $path . '.provenance.source_ref.version', $relation->id, $relation->revision, false, false, (object) array() );
			}
			$this->relationship_scope( $diagnostics, $path, $edge->scope, $source->scope, null === $target ? null : $target->scope, $relation, $facts );
			$scope_keys[ hash( 'sha256', $this->canonical->encode( $edge->scope ) ) ] = $edge->scope;
			if ( ( null !== $source->opaque_ref && ! $source_rule->opaque_context_safe ) || ( null !== $target && null !== $target->opaque_ref && ! $target_rule->opaque_context_safe ) ) {
				$this->diagnostic( $diagnostics, 'opaque_context_unverifiable', $path, $relation->id, $relation->revision, true, true, (object) array( 'kind' => $edge->kind ) );
			}
			switch ( $edge->kind ) {
				case 'contains':
					$contains[ $edge->source ][] = $edge->target;
					if ( isset( $parents[ $edge->target ] ) ) {
						$this->diagnostic( $diagnostics, 'multiple_containment_parents', $path . '.target', $relation->id, $relation->revision, false, false, (object) array() );
					}
					$parents[ $edge->target ] = $edge->source;
					if ( null !== $edge->order ) {
						$position_key = $edge->source . '|' . hash( 'sha256', $this->canonical->encode( $edge->scope ) ) . '|' . $edge->order;
						if ( isset( $positions[ $position_key ] ) ) {
							$this->diagnostic( $diagnostics, 'duplicate_containment_position', $path . '.order', $relation->id, $relation->revision, false, false, (object) array() );
						}
						$positions[ $position_key ] = true;
					}
					break;
				case 'context':
					$context_edges[ $edge->target . '|' . $relation->context_id ][] = array( 'source' => $edge->source, 'path' => $path, 'rule' => $relation );
					break;
				case 'field':
					$this->field( $diagnostics, $path, $target, $relation, $facts );
					break;
				case 'dependency':
					$this->dependency( $diagnostics, $path, $target, $relation, $facts );
					$dependency_edges[ $edge->source . '|' . $target->native_id ] = true;
					break;
				case 'template':
					$this->reference( $diagnostics, $path, $edge, $relation, $facts );
					if ( 'render' === $relation->reference_mode && null !== $edge->target_document_key ) {
						$render_targets[] = $edge->target_document_key;
					}
					break;
				case 'style':
					break;
			}
		}
		$this->containment_cycles( $diagnostics, $contains );
		$this->render_cycles( $diagnostics, $document_key, $render_targets, $facts );

		$resolved_contexts = array();
		foreach ( $node_rules as $node_id => $rule ) {
			foreach ( $rule->requires_contexts as $context_id ) {
				$key        = $node_id . '|' . $context_id;
				$resolution = $facts->context( $node_id, $context_id );
				$edges      = $context_edges[ $key ] ?? array();
				if ( count( $edges ) > 1 ) {
					$this->diagnostic( $diagnostics, 'context_ambiguous', 'nodes.' . $node_id . '.context.' . $context_id, $rule->id, $rule->revision, false, false, (object) array( 'status' => 'ambiguous' ) );
					continue;
				}
				$edge = $edges[0] ?? null;
				if ( null === $resolution || 'resolved' !== $resolution->status || null === $edge || $edge['source'] !== $resolution->provider_node_id ) {
					$status = null === $resolution ? 'missing' : $resolution->status;
					if ( 'resolved' === $status ) {
						$status = null === $edge ? 'edge_missing' : 'provider_mismatch';
					}
					$this->diagnostic( $diagnostics, 'context_' . $status, 'nodes.' . $node_id . '.context.' . $context_id, $rule->id, $rule->revision, true, true, (object) array( 'status' => $status ) );
					continue;
				}
				if ( $edge['rule']->revision !== $resolution->resolver_revision || $edge['rule']->resolver_id !== $resolution->resolver_id ) {
					$this->diagnostic( $diagnostics, 'stale_context_resolution', $edge['path'], $edge['rule']->id, $edge['rule']->revision, true, true, (object) array() );
					continue;
				}
				$resolved_contexts[] = (object) array( 'consumer_node_id' => $node_id, 'context_id' => $context_id, 'provider_node_id' => $resolution->provider_node_id, 'resolver_id' => $resolution->resolver_id, 'resolver_revision' => $resolution->resolver_revision );
			}
			foreach ( $rule->requires_dependencies as $dependency_id ) {
				$dependency = $facts->dependency( $dependency_id );
				if ( null === $dependency || 'available' !== $dependency->status || ! isset( $dependency_edges[ $node_id . '|' . $dependency_id ] ) ) {
					$this->diagnostic( $diagnostics, 'dependency_unavailable', 'nodes.' . $node_id . '.dependencies.' . $dependency_id, $rule->id, $rule->revision, true, true, (object) array() );
				}
			}
		}

		usort( $diagnostics, static fn( object $left, object $right ): int => array( $left->path, $left->code, $left->rule_id ) <=> array( $right->path, $right->code, $right->rule_id ) );
		usort( $resolved_contexts, static fn( object $left, object $right ): int => array( $left->consumer_node_id, $left->context_id ) <=> array( $right->consumer_node_id, $right->context_id ) );
		ksort( $scope_keys, SORT_STRING );
		$status = 'valid';
		foreach ( $diagnostics as $diagnostic ) {
			if ( $diagnostic->blocking && ! $diagnostic->retry ) {
				$status = 'invalid';
				break;
			}
			if ( $diagnostic->blocking ) {
				$status = 'incomplete';
			}
		}
		return (object) array(
			'status'             => $status,
			'accepted'           => 'valid' === $status,
			'graph_version_hash' => $expected->hash(),
			'rule_set_revision'  => $this->rules->revision(),
			'fact_revision'      => $facts->revision(),
			'affected_scopes'    => array_values( $scope_keys ),
			'resolved_contexts'  => $resolved_contexts,
			'diagnostics'        => $diagnostics,
		);
	}

	/** Validate the closed persisted graph contract and return indexed nodes. */
	private function graph( GraphVersion $expected, object $graph ): array {
		$this->decoder->check( $graph );
		$this->closed( $graph, array( 'document', 'version', 'nodes', 'edges', 'slot_coverage', 'completeness', 'consumer_coverage', 'omissions' ) );
		$this->closed( $graph->document, array( 'builder', 'native_kind', 'native_id' ) );
		if ( ! in_array( $graph->document->builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! $graph->version instanceof \stdClass || ! is_array( $graph->nodes ) || count( $graph->nodes ) < 1 || count( $graph->nodes ) > 2000 || ! is_array( $graph->edges ) || count( $graph->edges ) > 5000 || ! is_array( $graph->slot_coverage ) || count( $graph->slot_coverage ) > 200 || ! is_array( $graph->omissions ) || count( $graph->omissions ) > 100 || ! in_array( $graph->completeness, array( 'complete', 'partial' ), true ) || ! in_array( $graph->consumer_coverage, array( 'complete', 'partial' ), true ) || ! hash_equals( $expected->hash(), ( new GraphVersion( get_object_vars( $graph->version ) ) )->hash() ) ) {
			throw new ContractException( 'invalid_input', 'Semantic graph contract is invalid.' );
		}
		GraphVersion::identifier( $graph->document->native_kind );
		GraphVersion::identifier( $graph->document->native_id );
		$nodes = array();
		foreach ( $graph->nodes as $node ) {
			$this->closed( $node, array( 'id', 'provider_id', 'native_id', 'locator', 'scope', 'attributes', 'opaque_ref' ) );
			$id = GraphVersion::identifier( $node->id );
			GraphVersion::identifier( $node->provider_id );
			GraphVersion::identifier( $node->native_id );
			if ( isset( $nodes[ $id ] ) || ! $node->attributes instanceof \stdClass || ( null !== $node->opaque_ref && ! is_string( $node->opaque_ref ) ) ) {
				throw new ContractException( 'invalid_input', 'Semantic graph node is invalid.' );
			}
			if ( null !== $node->opaque_ref ) {
				GraphVersion::identifier( $node->opaque_ref );
			}
			$this->locator( $node->locator, $graph->document, $expected );
			if ( 'elementor' === $graph->document->builder && null === $node->locator->native_id ) {
				throw new ContractException( 'invalid_input', 'Elementor graph nodes require a native instance identity.' );
			}
			$this->scope( $node->scope );
			$nodes[ $id ] = $node;
		}
		$edge_keys = array();
		foreach ( $graph->edges as $edge ) {
			$this->closed( $edge, array( 'kind', 'source', 'target', 'target_document_key', 'scope', 'order', 'provenance' ) );
			$source = GraphVersion::identifier( $edge->source );
			$target = GraphVersion::identifier( $edge->target );
			if ( ! isset( $nodes[ $source ] ) || ( null === $edge->target_document_key && ! isset( $nodes[ $target ] ) ) || ( null !== $edge->target_document_key && ! is_string( $edge->target_document_key ) ) || ! in_array( $edge->kind, array( 'contains', 'context', 'field', 'template', 'style', 'dependency' ), true ) ) {
				throw new ContractException( 'invalid_input', 'Semantic graph edge endpoint is invalid.' );
			}
			if ( null !== $edge->target_document_key ) {
				GraphVersion::identifier( $edge->target_document_key );
			}
			$this->scope( $edge->scope );
			if ( ( 'contains' === $edge->kind && ( ( null !== $edge->order && ( ! is_int( $edge->order ) || $edge->order < 0 || $edge->order > 2147483647 ) ) || null !== $edge->target_document_key ) ) || ( 'contains' !== $edge->kind && null !== $edge->order ) ) {
				throw new ContractException( 'invalid_input', 'Semantic graph edge ordering is invalid.' );
			}
			$this->closed( $edge->provenance, array( 'source_ref', 'rule_id', 'resolver_revision' ) );
			$this->closed( $edge->provenance->source_ref, array( 'kind', 'id', 'version' ) );
			if ( ! in_array( $edge->provenance->source_ref->kind, array( 'source', 'artifact', 'run', 'operation', 'receipt' ), true ) || ( null !== $edge->provenance->source_ref->version && ! is_string( $edge->provenance->source_ref->version ) ) ) {
				throw new ContractException( 'invalid_input', 'Semantic graph provenance is invalid.' );
			}
			GraphVersion::identifier( $edge->provenance->rule_id );
			GraphVersion::identifier( $edge->provenance->resolver_revision );
			GraphVersion::identifier( $edge->provenance->source_ref->id );
			if ( null !== $edge->provenance->source_ref->version ) {
				GraphVersion::identifier( $edge->provenance->source_ref->version );
			}
			$edge_key = hash( 'sha256', $this->canonical->encode( $edge ) );
			if ( isset( $edge_keys[ $edge_key ] ) ) {
				throw new ContractException( 'invalid_input', 'Semantic graph edge is duplicated.' );
			}
			$edge_keys[ $edge_key ] = true;
		}
		$coverage_keys = array();
		foreach ( $graph->slot_coverage as $coverage ) {
			$this->closed( $coverage, array( 'slot', 'scope', 'complete', 'node_ids' ) );
			GraphVersion::identifier( $coverage->slot );
			$this->scope( $coverage->scope );
			$key = $coverage->slot . '|' . hash( 'sha256', $this->canonical->encode( $coverage->scope ) );
			if ( isset( $coverage_keys[ $key ] ) || ! is_bool( $coverage->complete ) || ! is_array( $coverage->node_ids ) ) {
				throw new ContractException( 'invalid_input', 'Semantic slot coverage is invalid.' );
			}
			$coverage_keys[ $key ] = true;
			$covered = array();
			foreach ( $coverage->node_ids as $id ) {
				if ( ! is_string( $id ) || ! isset( $nodes[ $id ] ) || isset( $covered[ $id ] ) ) {
					throw new ContractException( 'invalid_input', 'Semantic slot node is invalid.' );
				}
				$covered[ $id ] = true;
			}
		}
		foreach ( $graph->omissions as $omission ) {
			$this->closed( $omission, array( 'code', 'path' ) );
			if ( ! is_string( $omission->code ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $omission->code ) || ! is_string( $omission->path ) ) {
				throw new ContractException( 'invalid_input', 'Semantic graph omission is invalid.' );
			}
		}
		return array( 'nodes' => $nodes );
	}

	private function component_scope( array &$diagnostics, string $id, object $scope, object $rule, RelationshipFacts $facts ): void {
		if ( ! $facts->has_directory( $scope->directory_id ) ) {
			$this->diagnostic( $diagnostics, 'directory_scope_mismatch', 'nodes.' . $id . '.scope.directory_id', $rule->id, $rule->revision, false, false, (object) array() );
		}
		if ( ! in_array( $scope->view, $rule->allowed_views, true ) ) {
			$this->diagnostic( $diagnostics, 'view_scope_unsupported', 'nodes.' . $id . '.scope.view', $rule->id, $rule->revision, false, false, (object) array() );
		}
		if ( ! in_array( $scope->breakpoint_id, $rule->allowed_breakpoints, true ) || ( null !== $scope->breakpoint_id && null === $facts->breakpoint( $scope->breakpoint_id ) ) ) {
			$this->diagnostic( $diagnostics, 'breakpoint_scope_unsupported', 'nodes.' . $id . '.scope.breakpoint_id', $rule->id, $rule->revision, false, false, (object) array() );
		}
		if ( ! in_array( $scope->interaction, $rule->allowed_interactions, true ) ) {
			$this->diagnostic( $diagnostics, 'interaction_scope_unsupported', 'nodes.' . $id . '.scope.interaction', $rule->id, $rule->revision, false, false, (object) array() );
		}
	}

	private function relationship_scope( array &$diagnostics, string $path, object $edge, object $source, ?object $target, object $rule, RelationshipFacts $facts ): void {
		$exact = static fn( object $left, object $right ): bool => $left->directory_id === $right->directory_id && $left->view === $right->view && $left->breakpoint_id === $right->breakpoint_id && $left->interaction === $right->interaction;
		$valid = $exact( $edge, $source ) && ( null === $target || $exact( $edge, $target ) );
		if ( 'responsive' === $rule->scope_policy ) {
			$base_match = $edge->directory_id === $source->directory_id && $edge->view === $source->view && $edge->interaction === $source->interaction && ( null === $target || ( $edge->directory_id === $target->directory_id && $edge->view === $target->view && $edge->interaction === $target->interaction ) );
			$valid = $base_match && $facts->breakpoint_contains( $source->breakpoint_id, $edge->breakpoint_id ) && ( null === $target || $facts->breakpoint_contains( $target->breakpoint_id, $edge->breakpoint_id ) );
		}
		if ( 'target' === $rule->scope_policy ) {
			$valid = null !== $target && $exact( $edge, $target );
		}
		if ( ! $valid ) {
			$this->diagnostic( $diagnostics, 'relationship_scope_mismatch', $path . '.scope', $rule->id, $rule->revision, false, false, (object) array( 'policy' => $rule->scope_policy ) );
		}
	}

	private function conditions( array &$diagnostics, string $id, object $node, object $rule ): void {
		foreach ( $rule->conditional_controls as $condition ) {
			list( $control_found ) = $this->path_value( $node->attributes, $condition->control_path );
			if ( ! $control_found ) {
				continue;
			}
			list( $when_found, $when_value ) = $this->path_value( $node->attributes, $condition->when_path );
			$allowed = false;
			if ( 'present' === $condition->operator ) {
				$allowed = $when_found === $condition->expected;
			} elseif ( 'equals' === $condition->operator && $when_found ) {
				$allowed = $this->canonical->encode( $when_value ) === $this->canonical->encode( $condition->expected );
			} elseif ( 'in' === $condition->operator && $when_found ) {
				foreach ( $condition->expected as $candidate ) {
					$allowed = $allowed || $this->canonical->encode( $candidate ) === $this->canonical->encode( $when_value );
				}
			}
			if ( ! $allowed ) {
				$this->diagnostic( $diagnostics, 'conditional_control_inactive', 'nodes.' . $id . '.attributes.' . $condition->control_path, $rule->id, $rule->revision, false, false, (object) array( 'when_path' => $condition->when_path ) );
			}
		}
	}

	/** Require a slot row to enumerate nodes belonging to its exact declared scope. */
	private function coverage_scopes( array &$diagnostics, array $coverage_rows, array $nodes ): void {
		$covered = array();
		if ( ! $coverage_rows ) {
			$this->diagnostic( $diagnostics, 'slot_coverage_incomplete', 'slot_coverage', 'slot-coverage', '1', true, true, (object) array( 'missing_nodes' => count( $nodes ) ) );
			return;
		}
		foreach ( $coverage_rows as $index => $coverage ) {
			if ( ! $coverage->complete ) {
				$this->diagnostic( $diagnostics, 'slot_coverage_incomplete', 'slot_coverage.' . $index . '.complete', 'slot-coverage', '1', true, true, (object) array() );
			}
			foreach ( $coverage->node_ids as $node_id ) {
				$covered[ $node_id ] = true;
				if ( $this->canonical->encode( $coverage->scope ) !== $this->canonical->encode( $nodes[ $node_id ]->scope ) ) {
					$this->diagnostic( $diagnostics, 'slot_scope_mismatch', 'slot_coverage.' . $index . '.node_ids.' . $node_id, 'slot-coverage', '1', false, false, (object) array() );
				}
			}
		}
		$missing = array_diff_key( $nodes, $covered );
		if ( $missing ) {
			$this->diagnostic( $diagnostics, 'slot_coverage_incomplete', 'slot_coverage', 'slot-coverage', '1', true, true, (object) array( 'missing_nodes' => count( $missing ) ) );
		}
	}

	private function field( array &$diagnostics, string $path, ?object $target, object $rule, RelationshipFacts $facts ): void {
		$field = null === $target ? null : $facts->field( $target->native_id, $target->scope->directory_id );
		if ( null === $field || ! $field->eligible || ! in_array( $field->type, $rule->field_types, true ) ) {
			$this->diagnostic( $diagnostics, 'directory_field_unavailable', $path, $rule->id, $rule->revision, true, true, (object) array() );
		}
	}

	private function dependency( array &$diagnostics, string $path, ?object $target, object $rule, RelationshipFacts $facts ): void {
		$dependency = null === $target ? null : $facts->dependency( $target->native_id );
		if ( null === $dependency || 'available' !== $dependency->status || ! in_array( $target->native_id, $rule->dependency_ids, true ) ) {
			$this->diagnostic( $diagnostics, 'dependency_unavailable', $path, $rule->id, $rule->revision, true, true, (object) array() );
		}
	}

	private function reference( array &$diagnostics, string $path, object $edge, object $rule, RelationshipFacts $facts ): void {
		if ( null === $edge->target_document_key ) {
			$this->diagnostic( $diagnostics, 'reference_target_missing', $path, $rule->id, $rule->revision, false, false, (object) array() );
			return;
		}
		$reference = $facts->reference( $edge->target_document_key );
		if ( null === $reference || 'current' !== $reference->status ) {
			$status = null === $reference ? 'missing' : $reference->status;
			$this->diagnostic( $diagnostics, 'reference_' . $status, $path, $rule->id, $rule->revision, true, true, (object) array( 'status' => $status ) );
		}
	}

	private function containment_cycles( array &$diagnostics, array $contains ): void {
		$indegree = array();
		foreach ( $contains as $parent => $children ) {
			$indegree[ $parent ] ??= 0;
			foreach ( $children as $child ) {
				$indegree[ $child ] = ( $indegree[ $child ] ?? 0 ) + 1;
			}
		}
		$queue = array_keys( array_filter( $indegree, static fn( int $value ): bool => 0 === $value ) );
		$seen  = 0;
		for ( $cursor = 0; $cursor < count( $queue ); ++$cursor ) {
			$node = $queue[ $cursor ];
			++$seen;
			foreach ( $contains[ $node ] ?? array() as $child ) {
				if ( 0 === --$indegree[ $child ] ) {
					$queue[] = $child;
				}
			}
		}
		if ( count( $indegree ) !== $seen ) {
			$this->diagnostic( $diagnostics, 'containment_cycle', 'edges', 'containment', '1', false, false, (object) array() );
		}
	}

	private function render_cycles( array &$diagnostics, string $document_key, array $initial, RelationshipFacts $facts ): void {
		$adjacency = array( $document_key => array_values( array_unique( $initial ) ) );
		$queue     = $adjacency[ $document_key ];
		$queued    = array_fill_keys( $queue, true );
		$documents = 0;
		$edges     = count( $queue );
		for ( $cursor = 0; $cursor < count( $queue ); ++$cursor ) {
			$current = $queue[ $cursor ];
			if ( $current === $document_key ) {
				continue;
			}
			if ( ++$documents > 1000 || $edges > 5000 ) {
				$this->diagnostic( $diagnostics, 'reference_traversal_incomplete', 'references', 'render-reference', '1', true, true, (object) array() );
				return;
			}
			$reference = $facts->reference( $current );
			if ( null === $reference || 'current' !== $reference->status ) {
				$status = null === $reference ? 'missing' : $reference->status;
				$this->diagnostic( $diagnostics, 'reference_' . $status, 'references.' . $current, 'render-reference', '1', true, true, (object) array( 'status' => $status ) );
				$adjacency[ $current ] = array();
				continue;
			}
			$targets              = $reference->render_targets;
			$adjacency[ $current ] = $targets;
			$edges                += count( $targets );
			foreach ( $targets as $target ) {
				if ( $target !== $document_key && ! isset( $queued[ $target ] ) ) {
					$queued[ $target ] = true;
					$queue[]            = $target;
				}
			}
		}
		if ( $edges > 5000 ) {
			$this->diagnostic( $diagnostics, 'reference_traversal_incomplete', 'references', 'render-reference', '1', true, true, (object) array() );
			return;
		}

		$state = array();
		foreach ( array_keys( $adjacency ) as $root ) {
			if ( 2 === ( $state[ $root ] ?? 0 ) ) {
				continue;
			}
			$state[ $root ] = 1;
			$stack = array( array( 'node' => $root, 'index' => 0 ) );
			while ( $stack ) {
				$frame_index = count( $stack ) - 1;
				$node        = $stack[ $frame_index ]['node'];
				$targets     = $adjacency[ $node ] ?? array();
				if ( $stack[ $frame_index ]['index'] >= count( $targets ) ) {
					$state[ $node ] = 2;
					array_pop( $stack );
					continue;
				}
				$target = $targets[ $stack[ $frame_index ]['index']++ ];
				if ( 1 === ( $state[ $target ] ?? 0 ) ) {
					$this->diagnostic( $diagnostics, 'recursive_render_cycle', 'references.' . $target, 'render-reference', '1', false, false, (object) array() );
					return;
				}
				if ( 2 !== ( $state[ $target ] ?? 0 ) ) {
					$state[ $target ] = 1;
					$stack[]          = array( 'node' => $target, 'index' => 0 );
				}
			}
		}
	}

	private function path_value( object $value, string $path ): array {
		$current = $value;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! $current instanceof \stdClass || ! property_exists( $current, $part ) ) {
				return array( false, null );
			}
			$current = $current->{$part};
		}
		return array( true, $current );
	}

	private function locator( object $locator, object $document, GraphVersion $version ): void {
		$this->closed( $locator, array( 'document', 'snapshot_hash', 'slot_path', 'native_id', 'editor_epoch' ) );
		$this->closed( $locator->document, array( 'builder', 'native_kind', 'native_id' ) );
		if ( $this->canonical->encode( $locator->document ) !== $this->canonical->encode( $document ) || ! is_string( $locator->snapshot_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $locator->snapshot_hash ) || ! is_array( $locator->slot_path ) || ! $locator->slot_path || count( $locator->slot_path ) > 32 || $locator->editor_epoch !== $version->editor_epoch() || ( null !== $locator->native_id && ! is_string( $locator->native_id ) ) ) {
			throw new ContractException( 'stale_version', 'Semantic graph locator is stale.' );
		}
		foreach ( $locator->slot_path as $part ) {
			GraphVersion::identifier( $part );
		}
		if ( null !== $locator->native_id ) {
			GraphVersion::identifier( $locator->native_id );
		}
	}

	private function scope( object $scope ): void {
		$this->closed( $scope, array( 'directory_id', 'view', 'breakpoint_id', 'interaction' ) );
		if ( ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ( null !== $scope->breakpoint_id && ! is_string( $scope->breakpoint_id ) ) || ! in_array( $scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Semantic graph scope is invalid.' );
		}
		if ( null !== $scope->breakpoint_id ) {
			GraphVersion::identifier( $scope->breakpoint_id );
		}
	}

	private function closed( $value, array $expected ): void {
		if ( ! $value instanceof \stdClass ) {
			throw new ContractException( 'invalid_input', 'Semantic graph object is invalid.' );
		}
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'invalid_input', 'Semantic graph object shape is invalid.' );
		}
	}

	private function diagnostic( array &$diagnostics, string $code, string $path, string $rule_id, string $revision, bool $retry, bool $incomplete, object $details ): void {
		$diagnostics[] = (object) array( 'code' => $code, 'path' => $path, 'rule_id' => $rule_id, 'rule_revision' => $revision, 'message' => str_replace( '_', ' ', $code ) . '.', 'retry' => $retry, 'blocking' => true, 'incomplete' => $incomplete, 'details' => $details );
	}
}
