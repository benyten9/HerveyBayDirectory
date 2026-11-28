<?php
/**
 * Scoped, reviewable builder change proposal planning.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Generic.Formatting.DisallowMultipleStatements,Generic.Formatting.MultipleStatementAlignment,PSR2.ControlStructures.SwitchDeclaration.BodyOnNextLineCASE,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,Squiz.ControlStructures.ControlSignature,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.WhiteSpace.ControlStructureSpacing.BlankLineAfterEnd -- Closed planning invariants remain adjacent to normalization.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Pure planner plus immutable artifact/review persistence; no native effect handler exists here. */
final class BuilderChangePlanner {
	private const MAX_CHANGES  = 100;
	private const MAX_TARGETS  = 20;
	private const MAX_POSITION = 2147483647;
	private Database $db;
	private GraphSnapshotStore $graphs;
	private RelationshipValidator $relationships;
	private CandidateRelationshipFactsProvider $facts;
	private BuilderPlanningPolicyRegistry $policies;
	private ApprovalService $approvals;
	private JsonCanonicalizer $canonical;
	/** One successful pure page validation; live authority is always re-read. */
	private ?object $validated_semantics = null;

	public function __construct( Database $db, GraphSnapshotStore $graphs, RelationshipValidator $relationships, CandidateRelationshipFactsProvider $facts, BuilderPlanningPolicyRegistry $policies, ?ApprovalService $approvals = null ) {
		$this->db            = $db;
		$this->graphs        = $graphs;
		$this->relationships = $relationships;
		$this->facts         = $facts;
		$this->policies      = $policies;
		$this->approvals     = $approvals ?? new ApprovalService( $db );
		$this->canonical     = new JsonCanonicalizer();
	}

	/** Create one idempotent immutable proposal and pending exact review. */
	public function propose( Context $context, string $request_id, string $document_key, GraphVersion $expected, object $scope, array $changes, array $consumer_bases, ?BuilderRecipeSelection $recipe_selection = null, ?BuilderRecipeFreshnessFence $recipe_fence = null, ?BuilderPrivateCopyCoverage $copy = null, ?BuilderElementorLoopCreationProof $loop_creation = null ): object {
		$context->actor()->refresh();
		$context->assert_current();
		if ( $context->actor()->is_guest() || null === $context->conversation_id() || null === $context->run_id() || ( ! $context->actor()->can( 'edit_pages' ) && ! $context->actor()->can( 'edit_posts' ) && ! $context->actor()->can( 'edit_theme_options' ) ) ) {
			throw new ContractException( 'unauthorized', 'Builder planning requires an authenticated workflow owner.' );
		}
		GraphVersion::identifier( $request_id );
		GraphVersion::identifier( $document_key );
		$this->scope( $scope );
		if ( null !== $recipe_selection ) {
			$recipe_selection->assert_matches( $document_key, $expected, $scope, $changes );
			if ( null === $recipe_fence ) {
				throw new ContractException( 'evidence_stale', 'Builder recipe needs a current source fence.' );
			}
			$recipe_fence->assert_current();
		} elseif ( null !== $recipe_fence ) {
			throw new ContractException( 'invalid_input', 'Builder recipe fence has no selection.' );
		}
		$run_id = $this->run_internal_id( $context );
		if ( ! $changes || count( $changes ) > self::MAX_CHANGES ) {
			throw new ContractException( 'invalid_input', 'Builder change count is invalid.' );
		}
		( new JsonDecoder( 2097152, 32 ) )->check( $changes );
		$owner      = $context->actor()->owner();
		$validated  = $this->relationships->validate_current_bundle( $this->graphs, $owner, $document_key, $expected, $this->facts, $copy );
		$validation = $validated->report;
		if ( ! $validation->accepted || 'valid' !== $validation->status ) {
			throw new ContractException( 'relationship_invalid', 'Current graph relationships do not authorize planning.' );
		}
		$current = $this->graphs->current( $owner, $document_key, $expected, 2000, 5000, null === $copy );
		if ( null !== $copy ) {
			$copy->assert_graph( $document_key, $expected, $current->graph );
			if ( $consumer_bases ) {
				throw new ContractException( 'invalid_input', 'An isolated private copy cannot include consumer mutations.' );
			}
		}
		if ( null !== $loop_creation ) {
			$loop_creation->assert_snapshot( $document_key, $expected, $current->graph_hash );
			if ( 1 !== count( $changes ) || $consumer_bases ) {
				throw new ContractException( 'scope_conflict', 'New Elementor view accepts one isolated loop insertion.' );
			}
			$loop_creation->assert_change( $changes[0] );
		}
		$this->conversation_target( $context, $document_key, $current->graph->document, $scope );
		if ( $validation->snapshot_generation !== $current->generation || ! hash_equals( $validation->snapshot_hash, $current->graph_hash ) ) {
			throw new ContractException( 'stale_version', 'Relationship validation and planning snapshot differ.' );
		}
		$impact  = null === $copy ? $this->graphs->affected_consumers( $owner, $document_key, self::MAX_TARGETS - 1, 1000 ) : (object) array( 'complete' => true, 'consumers' => array() );
		if ( ! $impact->complete ) {
			throw new ContractException( 'incomplete_graph', 'Affected consumer enumeration is incomplete.' );
		}
		$consumers = $this->consumer_snapshots( $owner, $impact->consumers, $consumer_bases );
		$indexed   = $this->index_graph( $current->graph );
		$facts     = $validated->facts;
		if ( ! $facts instanceof RelationshipFacts || $facts->owner_ref() !== $owner->reference() || ! hash_equals( $facts->revision(), $validation->fact_revision ) ) {
			throw new ContractException( 'evidence_stale', 'Relationship facts changed during change planning.' );
		}
		$selection_context = BuilderConversationTarget::active_context( $this->db, $context );
		$selection_binding = null === $selection_context ? null : json_decode( $selection_context );
		if ( isset( $selection_binding->builder_selection ) ) { BuilderEditorSelection::assert_source( $selection_binding->builder_selection, $current->graph->document ); }
		$compiled  = $this->compile_changes( $document_key, $current, $scope, $changes, $indexed, $facts, $loop_creation, $selection_context );
		BuilderStyleIntent::assert_run( $this->db, $context, $compiled['changes'] );
		if ( isset( $selection_binding->builder_selection ) ) {
			BuilderEditorSelection::assert_source( $selection_binding->builder_selection, $current->graph->document );
			BuilderEditorSelection::assert_changes( $selection_context, $current->graph, $compiled['validated_changes'] );
		}
		$candidate = $this->complete_candidate( $owner, $document_key, $expected, $this->materialize_candidate( $current, $compiled['changes'], $indexed, $loop_creation ), $compiled['changes'], $loop_creation, $copy );
		$candidate_facts = $this->facts->read_candidate( $owner, $document_key, $expected, $candidate );
		if ( $candidate_facts->owner_ref() !== $owner->reference() || $candidate_facts->document_key() !== $document_key || ! hash_equals( $candidate_facts->graph_version_hash(), $expected->hash() ) ) {
			throw new ContractException( 'evidence_stale', 'Candidate relationship facts are unavailable.' );
		}
		$candidate_validation = $this->relationships->validate( $document_key, $expected, $candidate, $candidate_facts, $copy, null !== $copy );
		if ( ! $candidate_validation->accepted || 'valid' !== $candidate_validation->status ) {
			throw new ContractException( 'relationship_invalid', 'Candidate graph fails deterministic semantic validation.', array_column( $candidate_validation->diagnostics, 'code' ) );
		}
		$regions   = $this->regions( $current->graph, $compiled['affected_node_ids'] );

		$base_versions = array_merge(
			array( $this->snapshot_reference( $document_key, $current, $expected ) ),
			array_map( static fn( array $consumer ): object => $consumer['reference'], $consumers )
		);
		usort( $base_versions, static fn( object $left, object $right ): int => strcmp( $left->document_key, $right->document_key ) );
		$effect_material = (object) array(
			'kind'                   => 'builder_change',
			'document_key'           => $document_key,
			'requested_scope'        => $scope,
			'targets'                => array_map( static fn( object $base ): object => (object) array( 'kind' => 'builder_document', 'document_key' => $base->document_key, 'graph_hash' => $base->graph_hash ), $base_versions ),
			'base_versions'          => $base_versions,
			'normalized_changes'     => $compiled['changes'],
			'identity_remap'         => $compiled['identity_remap'],
			'predicted_effects'      => (object) array( 'change_count' => count( $compiled['changes'] ), 'effects' => $this->effect_diff( $compiled['changes'] ), 'candidate_graph_hash' => $this->canonical->digest( $candidate ), 'touched_scope_hashes' => $compiled['touched_scope_hashes'], 'affected_document_count' => count( $base_versions ) ),
			'affected_consumers'     => array_values( $impact->consumers ),
			'protected_scope_hashes' => $regions['protected'],
			'validation_ref'         => (object) array( 'graph_version_hash' => $validation->graph_version_hash, 'snapshot_generation' => $validation->snapshot_generation, 'snapshot_hash' => $validation->snapshot_hash, 'rule_set_revision' => $validation->rule_set_revision, 'fact_revision' => $validation->fact_revision, 'candidate_fact_revision' => $candidate_facts->revision(), 'candidate_graph_hash' => $this->canonical->digest( $candidate ), 'candidate_status' => $candidate_validation->status ),
			'snapshot_requirements'  => $base_versions,
		);
		if ( $compiled['omitted_optional'] ) { $effect_material->omitted_optional = $compiled['omitted_optional']; }
		if ( null !== $copy ) {
			$effect_material->effect_scope = $copy->effect_scope();
		}
		if ( null !== $loop_creation && null === $copy ) {
			$effect_material->effect_scope = $loop_creation->effect_scope();
		}
		if ( null !== $recipe_selection ) {
			$effect_material->recipe_selection = $recipe_selection->value();
		}
		$this->final_fence( $context, $document_key, $expected, $current, $facts->revision(), $candidate, $candidate_facts->revision(), $impact->consumers, $consumers, $scope, $copy, $loop_creation );
		if ( null !== $recipe_fence ) {
			$recipe_fence->assert_current();
		}
		$request_hash = hash( 'sha256', $context->run_id() . "\0" . $request_id );
		$artifact_id  = 'builder-proposal-' . substr( $request_hash, 0, 32 );
		$existing     = $this->existing_artifact( $owner, $artifact_id );
		if ( null === $existing ) {
			$created_at = gmdate( DATE_RFC3339 );
			$payload    = (object) array_merge(
				(array) $effect_material,
				array(
					'artifact_ref' => (object) array( 'id' => $artifact_id, 'version' => 1 ),
					'created_at'   => $created_at,
					'expires_at'   => gmdate( DATE_RFC3339, time() + 900 ),
				)
			);
			$artifact = ( new ArtifactStore( $this->db ) )->put_once( $owner, $context->conversation_id(), $artifact_id, 'proposal', $payload, $expected->environment_hash() );
		} else {
			$artifact = $existing;
			$payload  = json_decode( $artifact['payload_json'] );
			$stored_material = clone $payload;
			unset( $stored_material->artifact_ref, $stored_material->created_at, $stored_material->expires_at );
			if ( $this->canonical->encode( $stored_material ) !== $this->canonical->encode( $effect_material ) || strtotime( $payload->expires_at ) <= time() ) {
				throw new ContractException( 'request_conflict', 'Builder proposal request conflicts with an earlier plan.' );
			}
		}
		$this->final_fence( $context, $document_key, $expected, $current, $facts->revision(), $candidate, $candidate_facts->revision(), $impact->consumers, $consumers, $scope, $copy, $loop_creation );
		if ( null !== $recipe_fence ) {
			$recipe_fence->assert_current();
		}

		$targets = array();
		foreach ( $base_versions as $base ) {
			$targets[] = new EffectTarget( 'builder:' . hash( 'sha256', $base->document_key ), $base->graph_hash, $artifact['content_hash'] );
		}
		$operation_id = 'builder-apply-' . substr( $request_hash, 0, 32 );
		$plan = new EffectPlan( $operation_id, $owner->reference(), 'builder.apply', '1', $artifact['content_hash'], $targets, $run_id );
		$review_impact = (object) array( 'kind' => 'builder_change', 'document_key' => $document_key, 'change_count' => count( $compiled['changes'] ), 'effects' => $effect_material->predicted_effects->effects, 'affected_consumers' => array_values( $impact->consumers ), 'touched_scope_hashes' => $compiled['touched_scope_hashes'], 'protected_scope_hashes' => $regions['protected'] );
		if ( null !== $copy ) {
			$review_impact->effect_scope = $copy->effect_scope();
		}
		if ( null !== $loop_creation && null === $copy ) {
			$review_impact->effect_scope = $loop_creation->effect_scope();
		}
		$review = $this->approvals->create_staged(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				(int) $artifact['version'],
				$artifact['content_hash'],
				$expected->environment_hash(),
				$review_impact,
				(object) array( 'max_targets' => self::MAX_TARGETS, 'max_changes' => self::MAX_CHANGES )
			)
		);
		$this->final_fence( $context, $document_key, $expected, $current, $facts->revision(), $candidate, $candidate_facts->revision(), $impact->consumers, $consumers, $scope, $copy, $loop_creation );
		if ( null !== $recipe_fence ) {
			try {
				$recipe_fence->assert_current();
			} catch ( \Throwable $error ) {
				$this->approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] );
				throw new ContractException( 'evidence_stale', 'Builder recipe changed before review activation.' );
			}
		}
		if ( $run_id !== $this->run_internal_id( $context ) ) {
			throw new ContractException( 'stale_version', 'Builder workflow run changed before approval activation.' );
		}
		if ( null === $recipe_fence ) {
			$review = $this->approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		}
		return (object) array(
			'artifact'      => (object) array( 'id' => $artifact['public_id'], 'version' => (int) $artifact['version'], 'hash' => $artifact['content_hash'] ),
			'proposal'      => $payload,
			'proposal_hash' => $artifact['content_hash'],
			'operation_id'  => $operation_id,
			'review'        => (object) array( 'id' => $review['id'], 'state' => $review['state'], 'review_hash' => $review['review_hash'], 'expires_at' => $review['expires_at'] ),
		);
	}

	/** Rebuild an immutable proposal candidate against current graph and native facts before staging. */
	public function revalidate_candidate( Context $context, string $document_key, GraphVersion $expected, array $normalized_changes, string $candidate_hash, string $candidate_fact_revision, ?BuilderPrivateCopyCoverage $copy = null, ?BuilderElementorLoopCreationProof $loop_creation = null ): object {
		return $this->revalidate_candidate_bound( $context, $document_key, $expected, $normalized_changes, $candidate_hash, $candidate_fact_revision, null, null, $copy, $loop_creation );
	}

	/** Rebuild a reviewed candidate after the originating run has completed. */
	public function revalidate_reviewed_candidate( Context $context, string $conversation_id, string $run_id, string $document_key, GraphVersion $expected, array $normalized_changes, string $candidate_hash, string $candidate_fact_revision, ?BuilderPrivateCopyCoverage $copy = null, ?BuilderElementorLoopCreationProof $loop_creation = null ): object {
		ConversationStore::identifier( $conversation_id );
		ConversationStore::identifier( $run_id );
		if ( ( null !== $context->conversation_id() && $context->conversation_id() !== $conversation_id ) || ( null !== $context->run_id() && $context->run_id() !== $run_id ) ) {
			throw new ContractException( 'unauthorized', 'Reviewed builder run differs from the execution context.' );
		}
		return $this->revalidate_candidate_bound( $context, $document_key, $expected, $normalized_changes, $candidate_hash, $candidate_fact_revision, $conversation_id, $run_id, $copy, $loop_creation );
	}

	private function revalidate_candidate_bound( Context $context, string $document_key, GraphVersion $expected, array $normalized_changes, string $candidate_hash, string $candidate_fact_revision, ?string $reviewed_conversation_id, ?string $reviewed_run_id, ?BuilderPrivateCopyCoverage $copy, ?BuilderElementorLoopCreationProof $loop_creation ): object {
		$previous_controls = $this->validated_semantics;
		$this->validated_semantics = null;
		$this->permission_fence( $context );
		if ( $context->actor()->is_guest() || ! preg_match( '/^[a-f0-9]{64}$/D', $candidate_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $candidate_fact_revision ) || ! $normalized_changes || count( $normalized_changes ) > self::MAX_CHANGES ) {
			throw new ContractException( 'invalid_input', 'Builder candidate revalidation requires a bounded exact proposal.' );
		}
		GraphVersion::identifier( $document_key );
		( new JsonDecoder( 2097152, 32 ) )->check( $normalized_changes );
		$owner      = $context->actor()->owner();
		$reuse_key = null;
		if ( ( null === $copy || $copy->is_editor_candidate() ) && count( $normalized_changes ) === count( array_filter( $normalized_changes, static fn( $change ): bool => $change instanceof \stdClass && in_array( $change->op ?? null, array( 'insert', 'set_control' ), true ) ) ) ) {
			$reuse_key = $this->canonical->digest(
				(object) array(
					'site'                  => get_current_blog_id(),
					'owner'                 => $owner->reference(),
					'profile'               => $context->profile(),
					'transport'             => $context->transport(),
					'conversation'          => $context->conversation_id(),
					'run'                   => $context->run_id(),
					'reviewed_conversation' => $reviewed_conversation_id,
					'reviewed_run'          => $reviewed_run_id,
					'document'              => $document_key,
					'version'               => $expected->hash(),
					'changes'               => $normalized_changes,
					'candidate'             => $candidate_hash,
					'facts'                 => $candidate_fact_revision,
					'rules'                 => $this->relationships->rule_set_revision(),
					'factory'               => $context->builder_factory_authority(),
					'loop_creation'         => null !== $loop_creation,
					'coverage_scope'        => null === $copy ? null : $copy->effect_scope(),
				)
			);
		}
		$reused = null !== $reuse_key && null !== $previous_controls && $previous_controls->loop_creation === $loop_creation && hash_equals( $previous_controls->key, $reuse_key );
		BuilderStyleIntent::assert_run( $this->db, $context, $normalized_changes, $reviewed_conversation_id, $reviewed_run_id );
		$validation = $reused ? $previous_controls->validation : $this->relationships->validate_current( $this->graphs, $owner, $document_key, $expected, $this->facts, $copy );
		if ( ! $validation->accepted || 'valid' !== $validation->status ) {
			throw new ContractException( 'relationship_invalid', 'Current graph relationships no longer authorize the proposal.' );
		}
		$current = $this->graphs->current( $owner, $document_key, $expected, 2000, 5000, null === $copy );
		if ( null !== $copy ) {
			$copy->assert_graph( $document_key, $expected, $current->graph );
		}
		if ( null !== $loop_creation ) {
			$loop_creation->assert_snapshot( $document_key, $expected, $current->graph_hash );
			if ( 1 !== count( $normalized_changes ) ) {
				throw new ContractException( 'scope_conflict', 'Reviewed Elementor loop must contain one insertion.' );
			}
			$loop_creation->assert_normalized_change( $normalized_changes[0] );
		}
		if ( null === $reviewed_conversation_id ) {
			$this->conversation_target( $context, $document_key, $current->graph->document );
		} else {
			$this->reviewed_conversation_target( $context, $reviewed_conversation_id, $reviewed_run_id, $document_key, $current->graph->document );
		}
		if ( $validation->snapshot_generation !== $current->generation || ! hash_equals( $validation->snapshot_hash, $current->graph_hash ) ) {
			throw new ContractException( 'stale_version', 'Relationship validation and current graph differ.' );
		}
		if ( $reused && $this->canonical->encode( $previous_controls->native_artifact ) !== $this->canonical->encode( $current->native_artifact ) ) {
			throw new ContractException( 'stale_version', 'Native checkpoint changed during candidate revalidation.' );
		}
		$candidate = $this->complete_candidate( $owner, $document_key, $expected, $this->materialize_candidate( $current, $normalized_changes, $this->index_graph( $current->graph ), $loop_creation ), $normalized_changes, $loop_creation, $copy );
		$actual_hash = $this->canonical->digest( $candidate );
		if ( ! hash_equals( $candidate_hash, $actual_hash ) ) {
			throw new ContractException( 'evidence_stale', 'Stored proposal no longer produces its reviewed candidate graph.' );
		}
		if ( ! $reused ) {
			$facts = $this->facts->read_candidate( $owner, $document_key, $expected, $candidate );
			if ( $facts->owner_ref() !== $owner->reference() || $facts->document_key() !== $document_key || ! hash_equals( $facts->graph_version_hash(), $expected->hash() ) || ! hash_equals( $candidate_fact_revision, $facts->revision() ) ) {
				throw new ContractException( 'evidence_stale', 'Candidate relationship facts changed before draft preparation.' );
			}
			$checked = $this->relationships->validate( $document_key, $expected, $candidate, $facts, $copy, null !== $copy );
			if ( ! $checked->accepted || 'valid' !== $checked->status ) {
				throw new ContractException( 'relationship_invalid', 'Stored proposal candidate is no longer semantically valid.' );
			}
		}
		$pair = $this->fact_pair( $owner, $document_key, $expected, $candidate );
		$current_facts = null !== $pair ? $pair[0] : $this->facts->read( $owner, $document_key, $expected );
		$candidate_facts = null !== $pair ? $pair[1] : $this->facts->read_candidate( $owner, $document_key, $expected, $candidate );
		if ( $current_facts->owner_ref() !== $owner->reference() || ! hash_equals( $current_facts->revision(), $validation->fact_revision ) || $candidate_facts->owner_ref() !== $owner->reference() || ! hash_equals( $candidate_facts->revision(), $candidate_fact_revision ) ) {
			throw new ContractException( 'stale_version', 'Native relationship facts changed during candidate revalidation.' );
		}
		$fresh = $this->graphs->current( $owner, $document_key, $expected, 2000, 5000, null === $copy );
		if ( $fresh->generation !== $current->generation || ! hash_equals( $fresh->graph_hash, $current->graph_hash ) ) {
			throw new ContractException( 'stale_version', 'Published graph changed during candidate revalidation.' );
		}
		$this->permission_fence( $context );
		if ( null !== $reviewed_conversation_id ) {
			$this->reviewed_conversation_target( $context, $reviewed_conversation_id, $reviewed_run_id, $document_key, $fresh->graph->document );
		} else {
			$this->conversation_target( $context, $document_key, $fresh->graph->document );
		}
		if ( null !== $reuse_key ) {
			// Only deterministic semantic acceptance is reused. Native insertion
			// completion, materialization and hashing run on every attempt. The
			// same frozen policy/rule registries belong to this planner instance;
			// source and candidate facts, permissions and workflow stay fresh.
			$this->validated_semantics = (object) array( 'key' => $reuse_key, 'loop_creation' => $loop_creation, 'native_artifact' => json_decode( $this->canonical->encode( $fresh->native_artifact ) ), 'validation' => (object) array( 'accepted' => true, 'status' => 'valid', 'snapshot_generation' => $fresh->generation, 'snapshot_hash' => $fresh->graph_hash, 'fact_revision' => $validation->fact_revision ) );
		}
		return (object) array( 'graph' => $fresh->graph, 'graph_hash' => $fresh->graph_hash, 'generation' => $fresh->generation, 'native_artifact' => $fresh->native_artifact, 'candidate_hash' => $actual_hash, 'candidate_fact_revision' => $candidate_facts->revision() );
	}

	/** Normalize all requested changes or reject the entire plan before persistence. */
	private function compile_changes( string $document_key, object $current, object $requested_scope, array $changes, array $indexed, RelationshipFacts $facts, ?BuilderElementorLoopCreationProof $loop_creation = null, ?string $selection_context = null ): array {
		$page_redesign = null !== BuilderPageRedesign::batches( $current->graph, $changes );
		$result = array();
		$seen   = array();
		$removed = array();
		$variant_scopes = array();
		$scope_changes = array();
		$change_nodes = array();
		$change_kinds = array();
		$all_affected = array();
		$identity_remap = array();
		$touched_scopes = array();
		$unchanged_controls = array();
		$omitted_optional = array();
		foreach ( $changes as $sequence => $change ) {
			if ( ! $change instanceof \stdClass || ! isset( $change->op ) || ! is_string( $change->op ) || ! in_array( $change->op, array( 'insert', 'move', 'remove', 'set_control', 'bind_field', 'set_variant' ), true ) ) {
				throw new ContractException( 'unsupported_operation', 'Builder proposal contains an unsupported operation.' );
			}
			$scope = $change->scope ?? null;
			if ( ! $scope instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'Builder change requires an exact scope.', array( 'change_scope_required', 'copy_current_request_scope' ) );
			}
			$this->scope( $scope );
			if ( $this->canonical->encode( $scope ) !== $this->canonical->encode( $requested_scope ) && ! ( 'set_control' === $change->op && ( $change->target ?? null ) instanceof \stdClass && ( BuilderCompoundCardStyle::narrow_control_scope( $current->graph, $change->target, $scope, $requested_scope, $change->control_path ?? null ) || ( $page_redesign && BuilderPageRedesign::narrow_scope( $current->graph, $change->target, $scope, $requested_scope, $change->control_path ?? null ) ) ) ) ) {
				throw new ContractException( 'scope_conflict', 'Builder change cannot broaden the requested scope.', array( 'change_scope_mismatch', 'copy_current_request_scope' ) );
			}
			if ( in_array( $change->op, array( 'set_control', 'bind_field' ), true ) ) {
				$this->closed( $change, 'set_control' === $change->op ? array( 'op', 'target', 'control_path', 'value', 'scope' ) : array( 'op', 'target', 'control_path', 'field_id', 'scope' ) );
				$optional_target = $this->resolve_locator( $change->target, $change->scope, $indexed );
				$this->safe_region( $optional_target, $indexed );
				$this->path( $change->control_path );
				$node = $indexed['nodes'][ $optional_target ];
				$optional_policy = $this->policies->component( $node->provider_id, $node->native_id );
				$declared = null === $optional_policy ? array() : array_filter( $optional_policy->controls, static fn( $control ): bool => $control->path === $change->control_path );
				foreach ( $declared as $control ) { if ( $control->private ) { throw new ContractException( 'unauthorized', 'Private builder control is unavailable.' ); } }
				$missing_field = false;
				if ( 'bind_field' === $change->op ) {
					GraphVersion::identifier( $change->field_id );
					$missing_field = null === $facts->field( $change->field_id, $change->scope->directory_id );
				}
				if ( ! $declared || $missing_field ) {
					if ( $page_redesign ) {
						throw new ContractException( 'unsupported_operation', 'A required native page redesign control is unavailable.' );
					}
					BuilderEditorSelection::assert_changes( $selection_context, $current->graph, array( (object) array( 'target_id' => $optional_target ) ) );
					$omitted_optional[] = (object) array( 'control_path' => $change->control_path, 'reason' => $missing_field ? 'field_unavailable' : 'control_unavailable' );
					continue;
				}
			}
			$scope_hash = hash( 'sha256', $this->canonical->encode( $scope ) );
			$normalized = $this->normalize_change( $document_key, $current, $change, $sequence, $indexed, $identity_remap, $facts, $loop_creation );
			if ( isset( $variant_scopes[ $scope_hash ] ) || ( 'set_variant' === $change->op && isset( $scope_changes[ $scope_hash ] ) ) ) {
				throw new ContractException( 'scope_conflict', 'Variant selection overlaps another change in the same scope.' );
			}
			$scope_changes[ $scope_hash ] = true;
			if ( 'set_variant' === $change->op ) {
				$variant_scopes[ $scope_hash ] = true;
			}
			foreach ( $normalized['overlap_keys'] as $key ) {
				if ( isset( $seen[ $key ] ) || ( str_starts_with( $key, 'control:' ) && $this->control_overlap( $seen, $key ) ) ) {
					throw new ContractException( 'scope_conflict', 'Builder changes overlap or contradict each other.' );
				}
				$seen[ $key ] = true;
			}
			$change_nodes[] = $normalized['affected_ids'];
			$change_kinds[] = $change->op;
			if ( $normalized['unchanged'] ?? false ) {
				$unchanged_controls[ $sequence ] = true;
			} else {
				$touched_scopes[ $scope_hash ] = true;
				foreach ( $normalized['affected_ids'] as $affected_id ) { $all_affected[ $affected_id ] = true; }
			}
			if ( 'remove' === $change->op ) {
				$removed[ $normalized['target_id'] ] = true;
			}
			$result[] = $normalized['change'];
		}
		foreach ( $result as $change ) {
			foreach ( array( 'target_id', 'parent_id' ) as $field ) {
				if ( ! isset( $change->{$field} ) || ( 'remove' === $change->op && 'target_id' === $field ) ) {
					continue;
				}
				foreach ( array_keys( $removed ) as $removed_id ) {
					if ( $change->{$field} === $removed_id || $this->descendant( $removed_id, $change->{$field}, $indexed['children'] ) ) {
						throw new ContractException( 'scope_conflict', 'Builder plan uses a node removed by the same plan.' );
					}
				}
			}
		}
		$removed_ids = array_keys( $removed );
		for ( $left = 0; $left < count( $removed_ids ); ++$left ) {
			for ( $right = $left + 1; $right < count( $removed_ids ); ++$right ) {
				if ( $this->descendant( $removed_ids[ $left ], $removed_ids[ $right ], $indexed['children'] ) || $this->descendant( $removed_ids[ $right ], $removed_ids[ $left ], $indexed['children'] ) ) {
					throw new ContractException( 'scope_conflict', 'Builder plan contains overlapping removals.' );
				}
			}
		}
		for ( $left = 0; $left < count( $change_nodes ); ++$left ) {
			for ( $right = $left + 1; $right < count( $change_nodes ); ++$right ) {
				foreach ( $change_nodes[ $left ] as $left_id ) {
					foreach ( $change_nodes[ $right ] as $right_id ) {
						// A control changes only its exact node attributes. Styling a
						// container and editing its child's copy are independent even
						// though their nodes share containment ancestry. Structural
						// operations retain the subtree overlap fence below; duplicate
						// and prefix control paths were already rejected above.
						$independent_controls = ( 'set_control' === $change_kinds[ $left ] && 'set_control' === $change_kinds[ $right ] )
							|| ( $left_id === $right_id && in_array( $change_kinds[ $left ], array( 'set_control', 'bind_field' ), true ) && in_array( $change_kinds[ $right ], array( 'set_control', 'bind_field' ), true ) );
						if ( ! $independent_controls && ( $left_id === $right_id || $this->descendant( $left_id, $right_id, $indexed['children'] ) || $this->descendant( $right_id, $left_id, $indexed['children'] ) ) ) {
							throw new ContractException( 'scope_conflict', 'Builder changes overlap through containment ancestry.' );
						}
					}
				}
			}
		}
		// Equal controls still participate in every conflict and selection check.
		// Only the meaningful, validated edits enter the immutable native proposal.
		$validated_changes = $result;
		$result = array();
		foreach ( $validated_changes as $change ) {
			if ( ! isset( $unchanged_controls[ $change->sequence ] ) ) {
				$actual = clone $change;
				$actual->sequence = count( $result );
				$result[] = $actual;
			}
		}
		if ( ! $result ) {
			if ( $omitted_optional ) { throw new ContractException( 'optional_controls_unavailable', 'The requested optional settings are unavailable. Existing content is unchanged.' ); }
			throw new ContractException( 'invalid_input', 'Builder control change is a no-op.', array( 'control_already_has_value', 'omit_unchanged_controls' ) );
		}
		ksort( $identity_remap, SORT_STRING );
		$scope_hashes = array_keys( $touched_scopes );
		sort( $scope_hashes, SORT_STRING );
		$affected_node_ids = array_keys( $all_affected );
		sort( $affected_node_ids, SORT_STRING );
		return array( 'omitted_optional' => $omitted_optional, 'changes' => $result, 'validated_changes' => $validated_changes, 'identity_remap' => (object) $identity_remap, 'touched_scope_hashes' => $scope_hashes, 'affected_node_ids' => $affected_node_ids );
	}

	private function normalize_change( string $document_key, object $current, object $change, int $sequence, array $indexed, array &$identity_remap, RelationshipFacts $facts, ?BuilderElementorLoopCreationProof $loop_creation = null ): array {
		$scope_hash = hash( 'sha256', $this->canonical->encode( $change->scope ) );
		switch ( $change->op ) {
			case 'insert':
				$this->closed( $change, array( 'op', 'parent', 'position', 'subtree', 'scope' ) );
				$this->position( $change->position );
				$parent_id = $this->resolve_locator( $change->parent, $change->scope, $indexed, $loop_creation );
				$this->safe_region( $parent_id, $indexed );
				$subtree = $this->normalize_subtree( $document_key, $current, $change->subtree, $change->scope, $parent_id, $indexed, $identity_remap );
				return array( 'target_id' => null, 'affected_ids' => array( $parent_id ), 'overlap_keys' => array( 'parent:' . $parent_id . ':position:' . $change->position ), 'change' => (object) array( 'sequence' => $sequence, 'op' => 'insert', 'planned_node_id' => $subtree->root_node_id, 'parent_id' => $parent_id, 'position' => $change->position, 'subtree' => $subtree, 'scope' => $change->scope ) );
			case 'move':
				$this->closed( $change, array( 'op', 'target', 'parent', 'position', 'scope' ) );
				$this->position( $change->position );
				$target_id = $this->resolve_locator( $change->target, $change->scope, $indexed );
				$parent_id = $this->resolve_locator( $change->parent, $change->scope, $indexed );
				if ( ( $indexed['parents'][ $target_id ] ?? null ) === $parent_id && ( $indexed['orders'][ $target_id ] ?? null ) === $change->position ) {
					throw new ContractException( 'invalid_input', 'Builder move is a no-op.' );
				}
				if ( $target_id === $parent_id || $this->descendant( $target_id, $parent_id, $indexed['children'] ) ) {
					throw new ContractException( 'relationship_invalid', 'Builder move would create a containment cycle.' );
				}
				$this->safe_region( $target_id, $indexed );
				$this->safe_region( $parent_id, $indexed );
				$parent = $indexed['nodes'][ $parent_id ];
				$target = $indexed['nodes'][ $target_id ];
				if ( ! $this->relationships->supports_relationship( 'contains', $parent->provider_id, $parent->native_id, $target->provider_id, $target->native_id ) ) {
					throw new ContractException( 'relationship_invalid', 'Moved component is not allowed in the selected parent.' );
				}
				return array( 'target_id' => $target_id, 'affected_ids' => array( $target_id, $parent_id ), 'overlap_keys' => array( 'node:' . $target_id, 'parent:' . $parent_id . ':position:' . $change->position ), 'change' => (object) array( 'sequence' => $sequence, 'op' => 'move', 'target_id' => $target_id, 'parent_id' => $parent_id, 'from_parent_id' => $indexed['parents'][ $target_id ] ?? null, 'from_position' => $indexed['orders'][ $target_id ] ?? null, 'position' => $change->position, 'scope' => $change->scope ) );
			case 'remove':
				$this->closed( $change, array( 'op', 'target', 'scope' ) );
				$target_id = $this->resolve_locator( $change->target, $change->scope, $indexed );
				$this->safe_region( $target_id, $indexed );
				if ( ! isset( $indexed['parents'][ $target_id ] ) ) {
					throw new ContractException( 'unsupported_operation', 'Builder root removal is unavailable.' );
				}
				$subtree_ids = $this->subtree_ids( $target_id, $indexed['children'] );
				return array( 'target_id' => $target_id, 'affected_ids' => $subtree_ids, 'overlap_keys' => array( 'node:' . $target_id ), 'change' => (object) array( 'sequence' => $sequence, 'op' => 'remove', 'target_id' => $target_id, 'removed_node_ids' => $subtree_ids, 'removed_subtree' => $this->subtree_summary( $subtree_ids, $indexed ), 'scope' => $change->scope ) );
			case 'set_control':
				$this->closed( $change, array( 'op', 'target', 'control_path', 'value', 'scope' ) );
				$target_id = $this->resolve_locator( $change->target, $change->scope, $indexed );
				$this->safe_region( $target_id, $indexed );
				$this->path( $change->control_path );
				$target = $indexed['nodes'][ $target_id ];
				$policy = $this->policy( $target->provider_id, $target->native_id );
				$this->policies->validate_control( $policy, $change->control_path, $change->value );
				list( $found, $before ) = $this->path_value( $target->attributes, $change->control_path );
				( new JsonDecoder( 262144, 24 ) )->check( $change->value );
				$unchanged = $found && $this->canonical->encode( $before ) === $this->canonical->encode( $change->value );
				return array( 'unchanged' => $unchanged, 'target_id' => $target_id, 'affected_ids' => array( $target_id ), 'overlap_keys' => array( 'control:' . $target_id . ':' . $change->control_path ), 'change' => (object) array( 'sequence' => $sequence, 'op' => 'set_control', 'target_id' => $target_id, 'control_path' => $change->control_path, 'before' => $found ? $before : null, 'value' => $change->value, 'scope' => $change->scope ) );
			case 'bind_field':
				$this->closed( $change, array( 'op', 'target', 'control_path', 'field_id', 'scope' ) );
				$target_id = $this->resolve_locator( $change->target, $change->scope, $indexed );
				$this->safe_region( $target_id, $indexed );
				$this->path( $change->control_path );
				GraphVersion::identifier( $change->field_id );
				$field = $facts->field( $change->field_id, $change->scope->directory_id );
				$target = $indexed['nodes'][ $target_id ];
				$policy = $this->policy( $target->provider_id, $target->native_id );
				if ( null === $field || ! $field->eligible ) {
					throw new ContractException( 'unsupported_operation', 'Builder field binding is unavailable.' );
				}
				list( $found, $before ) = $this->path_value( $target->attributes, $change->control_path );
				$after = (object) array( 'field_id' => $change->field_id );
				$this->policies->validate_binding( $policy, $change->control_path, $field->type, $after );
				if ( $found && $this->canonical->encode( $before ) === $this->canonical->encode( $after ) ) { throw new ContractException( 'invalid_input', 'Builder field binding is a no-op.' ); }
				return array( 'target_id' => $target_id, 'affected_ids' => array( $target_id ), 'overlap_keys' => array( 'control:' . $target_id . ':' . $change->control_path ), 'change' => (object) array( 'sequence' => $sequence, 'op' => 'bind_field', 'target_id' => $target_id, 'control_path' => $change->control_path, 'before' => $found ? $before : null, 'field_id' => $change->field_id, 'scope' => $change->scope ) );
			case 'set_variant':
				$this->closed( $change, array( 'op', 'target', 'variant_id', 'mode', 'plan', 'scope' ) );
				$target_id = $this->resolve_locator( $change->target, $change->scope, $indexed );
				$this->safe_region( $target_id, $indexed );
				GraphVersion::identifier( $change->variant_id );
				if ( ! is_string( $change->mode ) || ! $change->plan instanceof \stdClass ) {
					throw new ContractException( 'invalid_input', 'Builder variant plan is invalid.' );
				}
				$attributes = $indexed['nodes'][ $target_id ]->attributes;
				$target     = $indexed['nodes'][ $target_id ];
				$policy     = $this->policy( $target->provider_id, $target->native_id );
				$this->policies->validate_variant( $policy, $change->variant_id, $change->mode, $change->plan );
				$before_variant = isset( $attributes->active_variant ) ? $attributes->active_variant : null;
				if ( 'select_existing' === $change->mode && $before_variant === $change->variant_id ) { throw new ContractException( 'invalid_input', 'Builder variant selection is a no-op.' ); }
				$current_variants = isset( $attributes->variants ) && is_array( $attributes->variants ) ? $attributes->variants : array();
				$current_plans = isset( $attributes->variant_plans ) && $attributes->variant_plans instanceof \stdClass ? $attributes->variant_plans : (object) array();
				if ( 'select_existing' === $change->mode && ! in_array( $change->variant_id, $current_variants, true ) && ! property_exists( $current_plans, $change->variant_id ) ) { throw new ContractException( 'unsupported_operation', 'Selected variant is absent from the current document.' ); }
				if ( 'create' === $change->mode && ( in_array( $change->variant_id, $current_variants, true ) || ( isset( $attributes->variant_plans ) && $attributes->variant_plans instanceof \stdClass && property_exists( $attributes->variant_plans, $change->variant_id ) ) ) ) { throw new ContractException( 'scope_conflict', 'Builder variant already exists.' ); }
				( new JsonDecoder( 262144, 24 ) )->check( $change->plan );
				return array( 'target_id' => $target_id, 'affected_ids' => array( $target_id ), 'overlap_keys' => array( 'scope:' . $scope_hash, 'variant:' . $target_id . ':' . $change->variant_id ), 'change' => (object) array( 'sequence' => $sequence, 'op' => 'set_variant', 'target_id' => $target_id, 'before_variant' => $before_variant, 'variant_id' => $change->variant_id, 'mode' => $change->mode, 'plan' => $change->plan, 'scope' => $change->scope ) );
		}
		throw new ContractException( 'unsupported_operation', 'Builder operation is unavailable.' );
	}

	/** Normalize a bounded semantic subtree and allocate every client identity. */
	private function normalize_subtree( string $document_key, object $current, $subtree, object $scope, string $parent_id, array $indexed, array &$identity_remap ): object {
		if ( ! $subtree instanceof \stdClass ) { throw new ContractException( 'invalid_input', 'Inserted semantic subtree is invalid.' ); }
		$this->closed( $subtree, array( 'root_client_id', 'nodes', 'edges' ) );
		GraphVersion::identifier( $subtree->root_client_id );
		if ( ! is_array( $subtree->nodes ) || ! $subtree->nodes || count( $subtree->nodes ) > 400 || ! is_array( $subtree->edges ) || count( $subtree->edges ) > 800 ) {
			throw new ContractException( 'needs_expansion', 'Inserted semantic subtree exceeds its bounds.' );
		}
		$nodes = array();
		$by_client = array();
		foreach ( $subtree->nodes as $node ) {
			if ( ! $node instanceof \stdClass ) { throw new ContractException( 'invalid_input', 'Inserted subtree node is invalid.' ); }
			$this->closed( $node, array( 'client_id', 'provider_id', 'native_id', 'attributes' ) );
			foreach ( array( 'client_id', 'provider_id', 'native_id' ) as $field ) { GraphVersion::identifier( $node->{$field} ); }
			if ( ! $node->attributes instanceof \stdClass || isset( $by_client[ $node->client_id ] ) || isset( $identity_remap[ $node->client_id ] ) || ! $this->relationships->supports_component( $node->provider_id, $node->native_id ) ) {
				throw new ContractException( 'invalid_input', 'Inserted subtree node identity is invalid.' );
			}
			$this->policies->validate_insert( $this->policy( $node->provider_id, $node->native_id ), $node->attributes );
			$planned = 'new-' . substr( hash( 'sha256', $document_key . '|' . $current->generation . '|' . $node->client_id ), 0, 24 );
			if ( isset( $indexed['nodes'][ $planned ] ) ) { throw new ContractException( 'request_conflict', 'Allocated subtree identity conflicts with current graph.' ); }
			$identity_remap[ $node->client_id ] = $planned;
			$by_client[ $node->client_id ] = $node;
			$nodes[] = (object) array( 'node_id' => $planned, 'provider_id' => $node->provider_id, 'native_id' => $node->native_id, 'attributes' => $node->attributes );
		}
		if ( ! isset( $by_client[ $subtree->root_client_id ] ) ) { throw new ContractException( 'invalid_input', 'Inserted subtree root is unavailable.' ); }
		$parent = $indexed['nodes'][ $parent_id ];
		$root   = $by_client[ $subtree->root_client_id ];
		if ( ! $this->relationships->supports_relationship( 'contains', $parent->provider_id, $parent->native_id, $root->provider_id, $root->native_id ) ) {
			throw new ContractException( 'relationship_invalid', 'Inserted subtree root is not allowed in the selected parent.', array( 'insert_parent_incompatible' ) );
		}
		$edges = array();
		$edge_keys = array();
		foreach ( $subtree->edges as $edge ) {
			if ( ! $edge instanceof \stdClass ) { throw new ContractException( 'invalid_input', 'Inserted subtree edge is invalid.' ); }
			$this->closed( $edge, array( 'kind', 'source_client_id', 'target_client_id', 'order' ) );
			// A context may use the already authorized insertion parent, never an arbitrary existing node.
			$parent_context = 'context' === $edge->kind && '$parent' === $edge->source_client_id;
			if ( ! is_string( $edge->kind ) || ! is_string( $edge->source_client_id ) || ! is_string( $edge->target_client_id ) || ! in_array( $edge->kind, array( 'contains', 'context', 'field', 'style', 'dependency' ), true ) || ( ! $parent_context && ! isset( $by_client[ $edge->source_client_id ] ) ) || ! isset( $by_client[ $edge->target_client_id ] ) || ( 'contains' === $edge->kind ? ( null !== $edge->order && ( ! is_int( $edge->order ) || $edge->order < 0 || $edge->order > self::MAX_POSITION ) ) : null !== $edge->order ) ) {
				throw new ContractException( 'invalid_input', 'Inserted subtree edge contract is invalid.', array( 'insert_edge_contract_invalid' ) );
			}
			$source = $parent_context ? $parent : $by_client[ $edge->source_client_id ]; $target = $by_client[ $edge->target_client_id ];
			if ( ! $this->relationships->supports_relationship( $edge->kind, $source->provider_id, $source->native_id, $target->provider_id, $target->native_id ) ) { throw new ContractException( 'relationship_invalid', 'Inserted subtree relationship is not registered.', array( 'relationship_tuple_unregistered' ) ); }
			$normalized = (object) array( 'kind' => $edge->kind, 'source_id' => $parent_context ? $parent_id : $identity_remap[ $edge->source_client_id ], 'target_id' => $identity_remap[ $edge->target_client_id ], 'order' => $edge->order );
			$key = $this->canonical->encode( $normalized );
			if ( isset( $edge_keys[ $key ] ) ) { throw new ContractException( 'scope_conflict', 'Inserted subtree edge is duplicated.' ); }
			$edge_keys[ $key ] = true; $edges[] = $normalized;
		}
		return (object) array( 'root_node_id' => $identity_remap[ $subtree->root_client_id ], 'nodes' => $nodes, 'edges' => $edges );
	}

	/** Materialize the full candidate graph and preserve every untouched native node/edge. */
	private function materialize_candidate( object $current, array $changes, array $indexed, ?BuilderElementorLoopCreationProof $loop_creation = null ): object {
		$candidate = json_decode( json_encode( $current->graph, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), false, 64, JSON_THROW_ON_ERROR );
		foreach ( $changes as $change ) {
			switch ( $change->op ) {
				case 'insert':
					$parent = $indexed['nodes'][ $change->parent_id ];
					// Inserting at a native ordinal keeps existing siblings in their
					// relative order. Other directory/view slots retain their authority.
					foreach ( $candidate->edges as $edge ) {
						if ( 'contains' === $edge->kind && $change->parent_id === $edge->source && is_int( $edge->order ) && $edge->order >= $change->position && $this->canonical->encode( $edge->scope ) === $this->canonical->encode( $change->scope ) ) {
							++$edge->order;
						}
					}
					$inserted = array();
					foreach ( $change->subtree->nodes as $node ) {
						$locator = (object) array( 'document' => $parent->locator->document, 'snapshot_hash' => hash( 'sha256', $current->graph_hash . '|' . $node->node_id ), 'slot_path' => array_merge( $parent->locator->slot_path, array( $node->node_id ) ), 'native_id' => $node->node_id, 'editor_epoch' => $parent->locator->editor_epoch );
						$materialized = (object) array( 'id' => $node->node_id, 'provider_id' => $node->provider_id, 'native_id' => $node->native_id, 'locator' => $locator, 'scope' => $change->scope, 'attributes' => $node->attributes, 'opaque_ref' => null );
						$inserted[ $node->node_id ] = $materialized; $candidate->nodes[] = $materialized; $this->coverage_add( $candidate, $change->scope, $node->node_id, $loop_creation, $parent );
					}
					$candidate->edges[] = $this->candidate_edge( 'contains', $change->parent_id, $change->subtree->root_node_id, $change->scope, $change->position, $parent, $inserted[ $change->subtree->root_node_id ] );
					foreach ( $change->subtree->edges as $edge ) { $candidate->edges[] = $this->candidate_edge( $edge->kind, $edge->source_id, $edge->target_id, $change->scope, $edge->order, $edge->source_id === $change->parent_id ? $parent : $inserted[ $edge->source_id ], $inserted[ $edge->target_id ] ); }
					break;
				case 'move':
					$candidate->edges = array_values( array_filter( $candidate->edges, static fn( object $edge ): bool => ! ( 'contains' === $edge->kind && $edge->target === $change->target_id ) ) );
					$candidate->edges[] = $this->candidate_edge( 'contains', $change->parent_id, $change->target_id, $change->scope, $change->position, $indexed['nodes'][ $change->parent_id ], $indexed['nodes'][ $change->target_id ] );
					break;
				case 'remove':
					$removed = array_fill_keys( $this->subtree_ids( $change->target_id, $indexed['children'] ), true );
					$candidate->nodes = array_values( array_filter( $candidate->nodes, static fn( object $node ): bool => ! isset( $removed[ $node->id ] ) ) );
					$candidate->edges = array_values( array_filter( $candidate->edges, static fn( object $edge ): bool => ! isset( $removed[ $edge->source ] ) && ! isset( $removed[ $edge->target ] ) ) );
					foreach ( $candidate->slot_coverage as $coverage ) { $coverage->node_ids = array_values( array_filter( $coverage->node_ids, static fn( string $id ): bool => ! isset( $removed[ $id ] ) ) ); }
					break;
				case 'set_control':
					$this->candidate_set( $candidate, $change->target_id, $change->control_path, $change->value );
					break;
				case 'bind_field':
					$this->candidate_set( $candidate, $change->target_id, $change->control_path, (object) array( 'field_id' => $change->field_id ) );
					break;
				case 'set_variant':
					$this->candidate_set( $candidate, $change->target_id, 'active_variant', $change->variant_id );
					if ( 'create' === $change->mode ) {
						$this->candidate_set( $candidate, $change->target_id, 'variant_plans.' . $change->variant_id, $change->plan );
						foreach ( $candidate->nodes as $candidate_node ) { if ( $candidate_node->id === $change->target_id ) { $candidate_node->attributes->variants[] = $change->variant_id; break; } }
					}
					break;
			}
		}
		return $candidate;
	}

	/** Admit only adapter-derived context/dependency links for planned insertions. */
	private function complete_candidate( \DirectoristAIPro\Storage\Owner $owner, string $document_key, GraphVersion $version, object $candidate, array $changes, ?BuilderElementorLoopCreationProof $loop_creation = null, ?BuilderPrivateCopyCoverage $copy = null ): object {
		$bindings = array_values( array_filter( $changes, static fn( object $change ): bool => 'bind_field' === $change->op ) );
		if ( $bindings && $this->facts instanceof CandidateFieldBindingRelationshipReconciler ) {
			$candidate = ( new BuilderFieldBindingReconciliation( $this->relationships, $this->policies ) )->reconcile( $this->facts, $owner, $document_key, $version, $candidate, $bindings, $this->facts->read( $owner, $document_key, $version ) );
		}
		if ( ! $this->facts instanceof CandidateGraphRelationshipAugmentor ) {
			return $candidate;
		}
		$inserted = array();
		foreach ( $changes as $change ) {
			if ( 'insert' !== $change->op ) { continue; }
			foreach ( $change->subtree->nodes as $node ) { $inserted[ $node->node_id ] = true; }
		}
		if ( ! $inserted ) { return $candidate; }
		$base = json_decode( $this->canonical->encode( $candidate ), false, 64, JSON_THROW_ON_ERROR );
		$completed = $this->facts->complete_candidate( $owner, $document_key, $version, $candidate, array_keys( $inserted ) );
		if ( ! $completed instanceof \stdClass || ! is_array( $completed->nodes ?? null ) || ! is_array( $completed->edges ?? null ) || count( $completed->nodes ) < count( $base->nodes ) || count( $completed->nodes ) > count( $base->nodes ) + 16 || count( $completed->edges ) < count( $base->edges ) || count( $completed->edges ) > count( $base->edges ) + 256 ) {
			throw new ContractException( 'relationship_invalid', 'Native candidate completion exceeded its append-only bound.' );
		}
		$base_other = clone $base;
		$completed_other = clone $completed;
		unset( $base_other->nodes, $base_other->edges, $base_other->slot_coverage, $completed_other->nodes, $completed_other->edges, $completed_other->slot_coverage );
		if ( $this->canonical->encode( $base_other ) !== $this->canonical->encode( $completed_other ) ) {
			throw new ContractException( 'relationship_invalid', 'Native candidate completion changed document authority or coverage.' );
		}
		foreach ( $base->nodes as $index => $node ) {
			if ( $this->canonical->encode( $node ) !== $this->canonical->encode( $completed->nodes[ $index ] ) ) {
				throw new ContractException( 'relationship_invalid', 'Native candidate completion changed a planned node.' );
			}
		}
		foreach ( $base->edges as $index => $edge ) {
			if ( $this->canonical->encode( $edge ) !== $this->canonical->encode( $completed->edges[ $index ] ) ) {
				throw new ContractException( 'relationship_invalid', 'Native candidate completion changed a planned relationship.' );
			}
		}
		$known = array();
		$new_dependencies = array();
		$dependency_scopes = array();
		foreach ( $base->nodes as $node ) { $known[ $node->id ] = true; }
		foreach ( array_slice( $completed->nodes, count( $base->nodes ) ) as $node ) {
			if ( ! $node instanceof \stdClass || ! is_string( $node->id ?? null ) || ! is_string( $node->provider_id ?? null ) || ! is_string( $node->native_id ?? null ) || isset( $known[ $node->id ] ) || ! $node->attributes instanceof \stdClass || (array) $node->attributes || null !== ( $node->opaque_ref ?? null ) || ! $this->relationships->supports_component( $node->provider_id, $node->native_id ) ) {
				throw new ContractException( 'relationship_invalid', 'Native candidate completion added an unsupported dependency node.' );
			}
			GraphVersion::identifier( $node->id );
			$known[ $node->id ] = true;
			$new_dependencies[ $node->id ] = false;
			$dependency_scopes[ $node->id ] = $this->canonical->encode( $node->scope ?? null );
		}
		if ( ! is_array( $base->slot_coverage ?? null ) || ! is_array( $completed->slot_coverage ?? null ) || count( $completed->slot_coverage ) < count( $base->slot_coverage ) || count( $completed->slot_coverage ) > count( $base->slot_coverage ) + 1 ) {
			throw new ContractException( 'relationship_invalid', 'Native candidate completion changed slot coverage rows.' );
		}
		$covered_dependencies = array();
		foreach ( $base->slot_coverage as $index => $coverage ) {
			$completed_coverage = $completed->slot_coverage[ $index ];
			if ( ! is_array( $coverage->node_ids ?? null ) || ! is_array( $completed_coverage->node_ids ?? null ) || count( $completed_coverage->node_ids ) < count( $coverage->node_ids ) ) {
				throw new ContractException( 'relationship_invalid', 'Native candidate completion removed slot coverage.' );
			}
			$base_row = clone $coverage;
			$completed_row = clone $completed_coverage;
			unset( $base_row->node_ids, $completed_row->node_ids );
			if ( $this->canonical->encode( $base_row ) !== $this->canonical->encode( $completed_row ) ) {
				throw new ContractException( 'relationship_invalid', 'Native candidate completion changed native slot coverage.' );
			}
			foreach ( $coverage->node_ids as $node_index => $node_id ) {
				if ( $node_id !== $completed_coverage->node_ids[ $node_index ] ) {
					throw new ContractException( 'relationship_invalid', 'Native candidate completion reordered slot coverage.' );
				}
			}
			foreach ( array_slice( $completed_coverage->node_ids, count( $coverage->node_ids ) ) as $node_id ) {
				if ( ! is_string( $node_id ) || ! isset( $new_dependencies[ $node_id ] ) || isset( $covered_dependencies[ $node_id ] ) || $dependency_scopes[ $node_id ] !== $this->canonical->encode( $coverage->scope ) ) {
					throw new ContractException( 'relationship_invalid', 'Native candidate completion added unrelated slot coverage.' );
				}
				$covered_dependencies[ $node_id ] = true;
			}
		}
		foreach ( array_slice( $completed->slot_coverage, count( $base->slot_coverage ) ) as $coverage ) {
			if ( ( null === $loop_creation && null === $copy ) || 'dependencies' !== ( $coverage->slot ?? null ) || true !== ( $coverage->complete ?? null ) || ! is_array( $coverage->node_ids ?? null ) || count( $coverage->node_ids ) < 1 || count( $coverage->node_ids ) > 2 ) {
				throw new ContractException( 'relationship_invalid', 'Native candidate completion added unrelated slot coverage.' );
			}
			foreach ( $coverage->node_ids as $node_id ) {
				if ( ! isset( $new_dependencies[ $node_id ] ) || isset( $covered_dependencies[ $node_id ] ) || $dependency_scopes[ $node_id ] !== $this->canonical->encode( $coverage->scope ?? null ) ) {
					throw new ContractException( 'relationship_invalid', 'Native candidate completion added unrelated dependency coverage.' );
				}
				$covered_dependencies[ $node_id ] = true;
			}
		}
		if ( count( $covered_dependencies ) !== count( $new_dependencies ) ) {
			throw new ContractException( 'relationship_invalid', 'Native dependency node has no exact slot coverage.' );
		}
		foreach ( array_slice( $completed->edges, count( $base->edges ) ) as $edge ) {
			if ( ! $edge instanceof \stdClass || ! is_string( $edge->source ?? null ) || ! is_string( $edge->target ?? null ) || ! isset( $known[ $edge->source ], $known[ $edge->target ] ) || null !== ( $edge->target_document_key ?? null ) || null !== ( $edge->order ?? null ) ) {
				throw new ContractException( 'relationship_invalid', 'Native candidate completion added an invalid relationship.' );
			}
			if ( 'context' === ( $edge->kind ?? null ) && isset( $inserted[ $edge->target ] ) ) {
				continue;
			}
			if ( 'dependency' === ( $edge->kind ?? null ) && isset( $inserted[ $edge->source ] ) ) {
				if ( isset( $new_dependencies[ $edge->target ] ) ) { $new_dependencies[ $edge->target ] = true; }
				continue;
			}
			throw new ContractException( 'relationship_invalid', 'Native candidate completion added an unrelated relationship.' );
		}
		if ( in_array( false, $new_dependencies, true ) ) {
			throw new ContractException( 'relationship_invalid', 'Native candidate completion added an unused dependency node.' );
		}
		return $completed;
	}

	private function candidate_edge( string $kind, string $source_id, string $target_id, object $scope, ?int $order, object $source, object $target ): object {
		$rule = $this->relationships->relationship_rule( $kind, $source->provider_id, $source->native_id, $target->provider_id, $target->native_id );
		if ( null === $rule ) {
			throw new ContractException( 'relationship_invalid', 'Candidate relationship is not registered.' );
		}
		return (object) array(
			'kind'                => $kind,
			'source'              => $source_id,
			'target'              => $target_id,
			'target_document_key' => null,
			'scope'               => $scope,
			'order'               => $order,
			'provenance'          => (object) array( 'source_ref' => (object) array( 'kind' => $rule->source_ref->kind, 'id' => $rule->source_ref->id, 'version' => $rule->revision ), 'rule_id' => $rule->id, 'resolver_revision' => $rule->revision ),
		);
	}

	private function candidate_set( object $candidate, string $node_id, string $path, $value ): void {
		foreach ( $candidate->nodes as $node ) {
			if ( $node_id !== $node->id ) { continue; }
			$current = $node->attributes;
			$parts   = explode( '.', $path );
			foreach ( $parts as $index => $part ) {
				if ( count( $parts ) - 1 === $index ) { $current->{$part} = $value; return; }
				if ( ! isset( $current->{$part} ) || ! $current->{$part} instanceof \stdClass ) { $current->{$part} = (object) array(); }
				$current = $current->{$part};
			}
		}
		throw new ContractException( 'stale_version', 'Candidate target disappeared.' );
	}

	private function coverage_add( object $candidate, object $scope, string $node_id, ?BuilderElementorLoopCreationProof $loop_creation = null, ?object $parent_node = null ): void {
		foreach ( $candidate->slot_coverage as $coverage ) {
			if ( $this->canonical->encode( $coverage->scope ) === $this->canonical->encode( $scope ) ) { $coverage->node_ids[] = $node_id; return; }
		}
		if ( null !== $loop_creation && $parent_node instanceof \stdClass && $loop_creation->permits_parent( $parent_node->id, $parent_node->locator, $scope ) ) {
			$candidate->slot_coverage[] = (object) array( 'slot' => 'elements-' . substr( $parent_node->id, 0, 24 ), 'scope' => $scope, 'complete' => true, 'node_ids' => array( $node_id ) );
			return;
		}
		throw new ContractException( 'incomplete_graph', 'Candidate insertion scope has no complete slot coverage.' );
	}

	private function subtree_ids( string $root, array $children ): array {
		$result = array();
		$stack  = array( $root );
		while ( $stack ) {
			$id = array_pop( $stack );
			if ( isset( $result[ $id ] ) ) { continue; }
			$result[ $id ] = true;
			foreach ( $children[ $id ] ?? array() as $child ) { $stack[] = $child; }
		}
		return array_keys( $result );
	}

	private function subtree_summary( array $node_ids, array $indexed ): object {
		$set   = array_fill_keys( $node_ids, true );
		$full_nodes = array();
		$nodes = array();
		foreach ( $node_ids as $node_id ) {
			$node = $indexed['nodes'][ $node_id ];
			$full_nodes[] = $node;
			$public_controls = (object) array();
			$policy = $this->policies->component( $node->provider_id, $node->native_id );
			if ( null !== $policy ) {
				foreach ( $policy->controls as $control ) {
					if ( $control->private ) { continue; }
					list( $found, $value ) = $this->path_value( $node->attributes, $control->path );
					if ( $found ) { $public_controls->{$control->path} = $this->redact_private_descendants( $value, $control->path, $policy->controls ); }
				}
			}
			$nodes[] = (object) array( 'id' => $node->id, 'provider_id' => $node->provider_id, 'native_id' => $node->native_id, 'public_controls' => $public_controls, 'scope' => $node->scope, 'opaque' => null !== $node->opaque_ref );
		}
		usort( $nodes, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		usort( $full_nodes, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
		$full_edges = array();
		$relationships = array();
		foreach ( $indexed['edges'] as $edge ) {
			if ( ! isset( $set[ $edge->source ] ) && ! isset( $set[ $edge->target ] ) ) { continue; }
			$full_edges[] = $edge;
			$relationships[] = (object) array( 'kind' => $edge->kind, 'source' => $edge->source, 'target' => $edge->target, 'target_document_key' => $edge->target_document_key, 'scope' => $edge->scope, 'order' => $edge->order, 'rule_id' => $edge->provenance->rule_id );
		}
		usort( $full_edges, fn( object $left, object $right ): int => strcmp( $this->canonical->encode( $left ), $this->canonical->encode( $right ) ) );
		usort( $relationships, fn( object $left, object $right ): int => strcmp( $this->canonical->encode( $left ), $this->canonical->encode( $right ) ) );
		$exact = (object) array( 'nodes' => $full_nodes, 'relationships' => $full_edges );
		return (object) array( 'hash' => $this->canonical->digest( $exact ), 'node_count' => count( $nodes ), 'relationship_count' => count( $relationships ), 'nodes' => $nodes, 'relationships' => $relationships );
	}

	private function effect_diff( array $changes ): array {
		return array_map(
			static function ( object $change ): object {
				$effect = array( 'sequence' => $change->sequence, 'op' => $change->op, 'scope' => $change->scope );
				switch ( $change->op ) {
					case 'insert': $effect += array( 'root_node_id' => $change->planned_node_id, 'parent_id' => $change->parent_id, 'position' => $change->position, 'subtree' => $change->subtree ); break;
					case 'move': $effect += array( 'target_id' => $change->target_id, 'before' => (object) array( 'parent_id' => $change->from_parent_id, 'position' => $change->from_position ), 'after' => (object) array( 'parent_id' => $change->parent_id, 'position' => $change->position ) ); break;
					case 'remove': $effect += array( 'target_id' => $change->target_id, 'before' => $change->removed_subtree, 'after' => null ); break;
					case 'set_control': $effect += array( 'target_id' => $change->target_id, 'control_path' => $change->control_path, 'before' => $change->before, 'after' => $change->value ); break;
					case 'bind_field': $effect += array( 'target_id' => $change->target_id, 'control_path' => $change->control_path, 'before' => $change->before, 'after' => (object) array( 'field_id' => $change->field_id ) ); break;
					case 'set_variant': $effect += array( 'target_id' => $change->target_id, 'before' => $change->before_variant, 'after' => $change->variant_id, 'mode' => $change->mode, 'plan' => $change->plan ); break;
				}
				return (object) $effect;
			},
			$changes
		);
	}

	/** Build exact node/locator and containment indexes from a validated graph. */
	private function index_graph( object $graph ): array {
		$nodes     = array();
		$locators  = array();
		$children  = array();
		$parents   = array();
		$orders    = array();
		foreach ( $graph->nodes as $node ) {
			$nodes[ $node->id ] = $node;
			$key = $this->canonical->encode( $node->locator );
			if ( isset( $locators[ $key ] ) ) {
				throw new ContractException( 'relationship_invalid', 'Current graph locator is ambiguous.' );
			}
			$locators[ $key ] = $node->id;
		}
		foreach ( $graph->edges as $edge ) {
			if ( 'contains' === $edge->kind ) {
				$children[ $edge->source ][] = $edge->target;
				$parents[ $edge->target ] = $edge->source;
				$orders[ $edge->target ]  = $edge->order;
			}
		}
		return array( 'nodes' => $nodes, 'locators' => $locators, 'children' => $children, 'parents' => $parents, 'orders' => $orders, 'edges' => $graph->edges );
	}

	private function resolve_locator( $locator, object $scope, array $indexed, ?BuilderElementorLoopCreationProof $loop_creation = null ): string {
		if ( ! $locator instanceof \stdClass ) {
			throw new ContractException( 'invalid_input', 'Builder locator is invalid.' );
		}
		$key = $this->canonical->encode( $locator );
		$id  = $indexed['locators'][ $key ] ?? null;
		if ( null === $id || ( $this->canonical->encode( $indexed['nodes'][ $id ]->scope ) !== $this->canonical->encode( $scope ) && ( null === $loop_creation || ! $loop_creation->permits_parent( $id, $locator, $scope ) ) ) ) {
			throw new ContractException( 'stale_version', 'Builder locator or scope is stale.' );
		}
		return $id;
	}

	private function safe_node( string $node_id, array $indexed ): void {
		if ( null !== $indexed['nodes'][ $node_id ]->opaque_ref ) {
			throw new ContractException( 'incomplete_graph', 'Opaque builder node cannot be changed safely.' );
		}
	}

	private function safe_region( string $node_id, array $indexed ): void {
		$ancestor = $node_id;
		$depth    = 0;
		while ( isset( $indexed['parents'][ $ancestor ] ) ) {
			$ancestor = $indexed['parents'][ $ancestor ];
			$this->safe_node( $ancestor, $indexed );
			if ( ++$depth > 2000 ) { throw new ContractException( 'needs_expansion', 'Builder ancestor traversal exceeds graph bounds.' ); }
		}
		$stack = array( $node_id );
		$seen  = array();
		while ( $stack ) {
			$current = array_pop( $stack );
			if ( isset( $seen[ $current ] ) ) {
				continue;
			}
			$seen[ $current ] = true;
			$this->safe_node( $current, $indexed );
			foreach ( $indexed['children'][ $current ] ?? array() as $child ) {
				$stack[] = $child;
			}
		}
	}

	private function descendant( string $ancestor, string $candidate, array $children ): bool {
		$stack = $children[ $ancestor ] ?? array();
		$seen  = array();
		while ( $stack ) {
			$current = array_pop( $stack );
			if ( $current === $candidate ) {
				return true;
			}
			if ( isset( $seen[ $current ] ) ) {
				continue;
			}
			$seen[ $current ] = true;
			foreach ( $children[ $current ] ?? array() as $child ) {
				$stack[] = $child;
			}
		}
		return false;
	}

	/** Hash each untouched exact scope from canonical nodes and relationships. */
	private function regions( object $graph, array $affected_node_ids ): array {
		$affected = array_fill_keys( $affected_node_ids, true );
		$regions = array();
		foreach ( $graph->nodes as $node ) {
			$key = hash( 'sha256', $this->canonical->encode( $node->scope ) );
			$regions[ $key ]['scope'] = $node->scope;
			$regions[ $key ]['nodes'][] = $node;
		}
		foreach ( $graph->edges as $edge ) {
			$key = hash( 'sha256', $this->canonical->encode( $edge->scope ) );
			$regions[ $key ]['scope'] = $edge->scope;
			$regions[ $key ]['edges'][] = $edge;
		}
		foreach ( $graph->slot_coverage as $coverage ) {
			$key = hash( 'sha256', $this->canonical->encode( $coverage->scope ) );
			$regions[ $key ]['scope'] = $coverage->scope;
			$regions[ $key ]['coverage'][] = $coverage;
		}
		$protected = array();
		foreach ( $regions as $key => $region ) {
			$nodes = array_values( array_filter( $region['nodes'] ?? array(), static fn( object $node ): bool => ! isset( $affected[ $node->id ] ) ) );
			$node_ids = array_map( static fn( object $node ): string => $node->id, $nodes );
			$node_set = array_fill_keys( $node_ids, true );
			$edges = array_values( array_filter( $region['edges'] ?? array(), static fn( object $edge ): bool => isset( $node_set[ $edge->source ] ) && ( null !== $edge->target_document_key || isset( $node_set[ $edge->target ] ) ) ) );
			$coverage = $region['coverage'] ?? array();
			foreach ( $coverage as $index => $row ) { $copy = clone $row; $copy->node_ids = array_values( array_filter( $copy->node_ids, static fn( string $id ): bool => isset( $node_set[ $id ] ) ) ); $coverage[ $index ] = $copy; }
			$coverage = array_values( array_filter( $coverage, static fn( object $row ): bool => ! empty( $row->node_ids ) ) );
			if ( ! $nodes && ! $edges && ! $coverage ) { continue; }
			usort( $nodes, static fn( object $left, object $right ): int => strcmp( $left->id, $right->id ) );
			sort( $node_ids, SORT_STRING );
			usort( $edges, fn( object $left, object $right ): int => strcmp( $this->canonical->encode( $left ), $this->canonical->encode( $right ) ) );
			usort( $coverage, fn( object $left, object $right ): int => strcmp( $this->canonical->encode( $left ), $this->canonical->encode( $right ) ) );
			$protected[] = (object) array( 'scope_hash' => $key, 'protected_node_ids' => $node_ids, 'content_hash' => $this->canonical->digest( (object) array( 'scope' => $region['scope'], 'nodes' => $nodes, 'edges' => $edges, 'slot_coverage' => $coverage ) ) );
		}
		usort( $protected, static fn( object $left, object $right ): int => strcmp( $left->scope_hash, $right->scope_hash ) );
		return array( 'protected' => $protected );
	}

	/** Require exact bases for the complete affected-consumer closure. */
	private function consumer_snapshots( $owner, array $consumers, array $bases ): array {
		if ( count( $consumers ) !== count( $bases ) ) {
			throw new ContractException( 'incomplete_graph', 'Affected consumer bases are incomplete.' );
		}
		$provided = array();
		foreach ( $bases as $base ) {
			if ( ! $base instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'Consumer base is invalid.' );
			}
			$this->closed( $base, array( 'document_key', 'version' ) );
			GraphVersion::identifier( $base->document_key );
			if ( isset( $provided[ $base->document_key ] ) || ! $base->version instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'Consumer base is duplicated or invalid.' );
			}
			$provided[ $base->document_key ] = new GraphVersion( get_object_vars( $base->version ) );
		}
		$expected_keys = $consumers;
		sort( $expected_keys, SORT_STRING );
		$provided_keys = array_keys( $provided );
		sort( $provided_keys, SORT_STRING );
		if ( $expected_keys !== $provided_keys ) {
			throw new ContractException( 'incomplete_graph', 'Affected consumer bases do not match current closure.' );
		}
		$result = array();
		foreach ( $expected_keys as $key ) {
			$current = $this->graphs->current( $owner, $key, $provided[ $key ], 2000, 5000, true );
			$result[ $key ] = array( 'current' => $current, 'version' => $provided[ $key ], 'reference' => $this->snapshot_reference( $key, $current, $provided[ $key ] ) );
		}
		return $result;
	}

	private function snapshot_reference( string $document_key, object $current, GraphVersion $version ): object {
		return (object) array( 'document_key' => $document_key, 'generation' => $current->generation, 'graph_hash' => $current->graph_hash, 'version_hash' => $version->hash(), 'source_hash' => $version->source_hash(), 'editor_epoch' => $version->editor_epoch(), 'editor_revision' => $version->editor_revision(), 'native_artifact' => $current->native_artifact, 'required_before_apply' => true );
	}

	/** Share only an immediately paired native read, never a previous attempt. */
	private function fact_pair( \DirectoristAIPro\Storage\Owner $owner, string $document_key, GraphVersion $expected, object $candidate ): ?array {
		// The provider is already trusted and typed to the existing facts
		// interface. Keep this optimization optional for older companions.
		if ( ! method_exists( $this->facts, 'read_pair' ) || ! is_callable( array( $this->facts, 'read_pair' ) ) ) {
			return null;
		}
		$pair = $this->facts->read_pair( $owner, $document_key, $expected, $candidate );
		$keys = $pair instanceof \stdClass ? array_keys( get_object_vars( $pair ) ) : array();
		sort( $keys, SORT_STRING );
		if ( array( 'candidate', 'current' ) !== $keys || ! $pair->current instanceof RelationshipFacts || ! $pair->candidate instanceof RelationshipFacts ) {
			throw new ContractException( 'evidence_stale', 'Native paired relationship facts are invalid.' );
		}
		return array( $pair->current, $pair->candidate );
	}

	/** Recheck every authority source after artifact/review persistence. */
	private function final_fence( Context $context, string $document_key, GraphVersion $expected, object $before, string $fact_revision, object $candidate, string $candidate_fact_revision, array $consumer_keys, array $consumers, object $scope, ?BuilderPrivateCopyCoverage $copy = null, ?BuilderElementorLoopCreationProof $loop_creation = null ): void {
		$this->permission_fence( $context );
		$this->conversation_target( $context, $document_key, $before->graph->document, $scope );
		$owner = $context->actor()->owner();
		$pair = $this->fact_pair( $owner, $document_key, $expected, $candidate );
		$facts = null !== $pair ? $pair[0] : $this->facts->read( $owner, $document_key, $expected );
		if ( ! $facts instanceof RelationshipFacts || $facts->owner_ref() !== $owner->reference() || ! hash_equals( $facts->revision(), $fact_revision ) ) {
			throw new ContractException( 'stale_version', 'Builder planning authority changed before delivery.' );
		}
		$fresh_candidate_facts = null !== $pair ? $pair[1] : $this->facts->read_candidate( $owner, $document_key, $expected, $candidate );
		if ( $fresh_candidate_facts->owner_ref() !== $owner->reference() || ! hash_equals( $fresh_candidate_facts->revision(), $candidate_fact_revision ) ) {
			throw new ContractException( 'stale_version', 'Candidate relationship facts changed before delivery.' );
		}
		try {
			$after = $this->graphs->current( $owner, $document_key, $expected, 2000, 5000, null === $copy );
			if ( null !== $copy ) {
				$copy->assert_graph( $document_key, $expected, $after->graph );
			}
			if ( null !== $loop_creation ) {
				$loop_creation->assert_snapshot( $document_key, $expected, $after->graph_hash );
			}
			if ( $after->generation !== $before->generation || ! hash_equals( $after->graph_hash, $before->graph_hash ) ) {
				throw new ContractException( 'stale_version', 'Affected consumer changed before proposal delivery.' );
			}
			foreach ( $consumer_keys as $key ) {
				$consumer = $consumers[ $key ];
				$fresh = $this->graphs->current( $owner, $key, $consumer['version'], 2000, 5000, true );
				if ( $fresh->generation !== $consumer['current']->generation || ! hash_equals( $fresh->graph_hash, $consumer['current']->graph_hash ) ) {
					throw new ContractException( 'stale_version', 'Affected consumer changed before proposal delivery.' );
				}
			}
			$closure = null === $copy ? $this->graphs->affected_consumers( $owner, $document_key, self::MAX_TARGETS - 1, 1000 ) : (object) array( 'complete' => true, 'consumers' => array() );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'stale_version', 'Builder graph or consumer closure changed before delivery.' );
		}
		if ( ! $closure->complete || $closure->consumers !== $consumer_keys ) {
			throw new ContractException( 'stale_version', 'Affected consumer closure changed before proposal delivery.' );
		}
		$this->permission_fence( $context );
	}

	private function permission_fence( Context $context ): void {
		$context->actor()->refresh();
		$context->assert_current();
		if ( ! $context->actor()->can( 'edit_pages' ) && ! $context->actor()->can( 'edit_posts' ) && ! $context->actor()->can( 'edit_theme_options' ) ) {
			throw new ContractException( 'unauthorized', 'Builder planning authority changed before delivery.' );
		}
	}

	private function existing_artifact( $owner, string $artifact_id ): ?array {
		try {
			return ( new ArtifactStore( $this->db ) )->get( $owner, $artifact_id, 1 );
		} catch ( StorageException $error ) {
			return null;
		}
	}

	private function run_internal_id( Context $context ): int {
		$rows = $this->db->rows( 'SELECT r.id FROM ' . $this->db->table( 'runs' ) . ' r INNER JOIN ' . $this->db->table( 'conversations' ) . " c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.erasure_requested=0 AND r.cancel_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND r.state IN ('queued','running','waiting_approval')", array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference(), $context->actor()->owner()->reference() ) );
		if ( ! $rows ) {
			throw new ContractException( 'unauthorized', 'Builder workflow run is unavailable.' );
		}
		return (int) $rows[0]['id'];
	}

	/**
	 * Recheck any stored conversation document binding against the selected graph.
	 *
	 * @throws ContractException When the owned active run targets another document.
	 */
	private function conversation_target( Context $context, string $document_key, object $native_document, ?object $scope = null ): void {
		$owner = $context->actor()->owner();
		$rows = $this->db->rows( 'SELECT c.context_json FROM ' . $this->db->table( 'runs' ) . ' r INNER JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.erasure_requested=0 AND r.cancel_requested=0', array( $context->run_id(), $context->conversation_id(), $owner->reference(), $owner->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Builder conversation target is unavailable.' );
		}
		( new BuilderDraftResultApi( $this->db ) )->assert_refinement_binding( $rows[0]['context_json'] );
		BuilderConversationTarget::assert_binding( $rows[0]['context_json'], $document_key, $native_document, $scope );
	}

	/** Historical owner and target binding remains valid after the active run clears. */
	private function reviewed_conversation_target( Context $context, string $conversation_id, string $run_id, string $document_key, object $native_document ): void {
		$owner = $context->actor()->owner();
		$runs = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows = $this->db->rows( "SELECT c.context_json FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND r.state IN ('running','waiting_approval','succeeded','partial') AND r.cancel_requested=0", array( $run_id, $conversation_id, $owner->reference(), $owner->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Reviewed builder conversation target is unavailable.' );
		}
		( new BuilderDraftResultApi( $this->db ) )->assert_refinement_binding( $rows[0]['context_json'] );
		BuilderConversationTarget::assert_binding( $rows[0]['context_json'], $document_key, $native_document );
	}

	private function position( $position ): void {
		if ( ! is_int( $position ) || $position < 0 || $position > self::MAX_POSITION ) {
			throw new ContractException( 'invalid_input', 'Builder position is invalid.' );
		}
	}

	private function path( $path ): void {
		if ( ! is_string( $path ) || ! preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$/D', $path ) ) {
			throw new ContractException( 'invalid_input', 'Builder control path is invalid.' );
		}
	}

	private function path_exists( object $value, string $path ): bool {
		$current = $value;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! $current instanceof \stdClass || ! property_exists( $current, $part ) ) {
				return false;
			}
			$current = $current->{$part};
		}
		return true;
	}

	private function path_value( object $value, string $path ): array {
		$current = $value;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! $current instanceof \stdClass || ! property_exists( $current, $part ) ) { return array( false, null ); }
			$current = $current->{$part};
		}
		return array( true, $current );
	}

	private function redact_private_descendants( $value, string $public_path, array $controls ) {
		$copy = json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		if ( ! $copy instanceof \stdClass ) { return $copy; }
		foreach ( $controls as $control ) {
			$prefix = $public_path . '.';
			if ( ! $control->private || ! str_starts_with( $control->path, $prefix ) ) { continue; }
			$this->unset_path( $copy, substr( $control->path, strlen( $prefix ) ) );
		}
		return $copy;
	}

	private function unset_path( object $value, string $path ): void {
		$parts = explode( '.', $path );
		$current = $value;
		foreach ( $parts as $index => $part ) {
			if ( count( $parts ) - 1 === $index ) { unset( $current->{$part} ); return; }
			if ( ! isset( $current->{$part} ) || ! $current->{$part} instanceof \stdClass ) { return; }
			$current = $current->{$part};
		}
	}

	private function control_overlap( array $seen, string $candidate ): bool {
		$parts = explode( ':', $candidate, 3 );
		foreach ( array_keys( $seen ) as $key ) {
			if ( ! str_starts_with( $key, 'control:' ) ) { continue; }
			$other = explode( ':', $key, 3 );
			if ( $parts[1] === $other[1] && ( str_starts_with( $parts[2] . '.', $other[2] . '.' ) || str_starts_with( $other[2] . '.', $parts[2] . '.' ) ) ) { return true; }
		}
		return false;
	}

	private function policy( string $provider_id, string $native_id ): object {
		$policy = $this->policies->component( $provider_id, $native_id );
		if ( null === $policy ) {
			throw new ContractException( 'unsupported_operation', 'Builder component has no trusted planning policy.' );
		}
		return $policy;
	}

	private function scope( object $scope ): void {
		$this->closed( $scope, array( 'directory_id', 'view', 'breakpoint_id', 'interaction' ) );
		if ( ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ( null !== $scope->breakpoint_id && ( ! is_string( $scope->breakpoint_id ) || strlen( $scope->breakpoint_id ) > 128 ) ) || ! in_array( $scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Builder scope is invalid.' );
		}
		if ( null !== $scope->breakpoint_id ) {
			GraphVersion::identifier( $scope->breakpoint_id );
		}
	}

	private function closed( object $value, array $expected ): void {
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'invalid_input', 'Builder change shape is invalid.' );
		}
	}
}
