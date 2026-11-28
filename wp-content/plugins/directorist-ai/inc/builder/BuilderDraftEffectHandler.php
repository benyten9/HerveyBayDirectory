<?php
/**
 * Reviewed isolated native draft effect and marker-only reconciliation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal native effect boundary.

use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Executes only an exact reviewed private native draft stage. */
final class BuilderDraftEffectHandler implements EffectHandler {
	private BuilderDraftStageArtifactReader $stages;
	private Database $db;
	private ArtifactStore $artifacts;
	private BuilderNativeSourceCaptureService $sources;
	private BuilderChangePlannerFactory $planners;
	private BuilderCandidateRelationshipFactsFactory $facts;
	private BuilderNativeSourceReader $reader;
	private BuilderDraftCandidateCompiler $compiler;
	private BuilderNativeDraftWriter $writer;
	private CatalogEnvironmentReader $environment;
	private DirectoryFactsReader $directories;

	public function __construct( Database $db, BuilderChangePlannerFactory $planners, BuilderCandidateRelationshipFactsFactory $facts, BuilderNativeSourceReader $reader, BuilderDraftCandidateCompiler $compiler, BuilderNativeDraftWriter $writer, CatalogEnvironmentReader $environment, DirectoryFactsReader $directories ) {
		if ( ! in_array( $reader->builder(), array( 'gutenberg', 'elementor', 'divi' ), true ) || $reader->builder() !== $compiler->builder() || $reader->builder() !== $writer->builder() || $reader->builder() !== $facts->builder() ) {
			throw new ContractException( 'unsupported_dependency', 'Native draft reader, compiler and writer disagree.' );
		}
		$this->db          = $db;
		$this->stages      = new BuilderDraftStageArtifactReader( $db );
		$this->artifacts   = new ArtifactStore( $db );
		$this->sources     = new BuilderNativeSourceCaptureService( $db );
		$this->planners    = $planners;
		$this->facts       = $facts;
		$this->reader      = $reader;
		$this->compiler    = $compiler;
		$this->writer      = $writer;
		$this->environment = $environment;
		$this->directories = $directories;
	}

	/** The dispatcher resolves this value only from trusted installed adapters. */
	public function builder(): string {
		return $this->reader->builder();
	}

	/** Check only the owner and native draft creation/edit authority before reservation. */
	public function permitted( EffectPlan $plan, Context $context ): bool {
		if ( $context->actor()->is_guest() || $context->actor()->owner()->reference() !== $plan->actor_ref || get_current_user_id() !== $context->actor()->user_id() ) {
			return false;
		}
		try {
			$stage = $this->stages->for_plan( $plan )->payload;
		} catch ( \Throwable $error ) {
			return false;
		}
		return $this->source_permitted( $stage );
	}

	/** Return the current installed native dependency identity, not the stored one. */
	public function environment_hash( EffectPlan $plan ): string {
		$this->stages->for_plan( $plan );
		return $this->environment->read()->fingerprint();
	}

	/** Revalidate current source and relationships, then write under the native lock. */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		try {
			$prepared = $this->verify_current( $plan, $target );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		}
		$guard = function () use ( $plan, $target, $prepared ): void {
			$fresh = $this->verify_current( $plan, $target );
			if ( ! hash_equals( $prepared->source, $fresh->source ) || ! hash_equals( $prepared->native_content_hash, $fresh->native_content_hash ) || ! $this->same_dependency_revision( $prepared->dependency_revision, $fresh->dependency_revision ) || $prepared->generation !== $fresh->generation ) {
				throw new ContractException( 'stale_version', 'Native draft authority changed at the write boundary.' );
			}
		};
		try {
			if ( 'editor_candidate' === ( $prepared->payload->output_mode ?? null ) ) {
				$guard();
				return $this->candidate_outcome( $prepared->payload, $target, $fence, false );
			}
			$receipt = $this->writer->stage( $this->source_identifier( $prepared->payload ), $prepared->source, $prepared->graph, $prepared->changes, $plan->operation_id, $guard );
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
		}
		return $this->outcome( $receipt, $target, $fence, false );
	}

	/** Observe only native operation markers after an uncertain attempt. */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$payload = $this->stages->for_plan( $plan )->payload;
		if ( 'editor_candidate' === ( $payload->output_mode ?? null ) ) {
			$prepared = $this->verify_current( $plan, $target );
			return $this->candidate_outcome( $prepared->payload, $target, $fence, true );
		}
		$receipt = $this->writer->reconcile( $this->source_identifier( $payload ), $payload->source_hash, $payload->native_content_hash, $plan->operation_id );
		return $this->outcome( $receipt, $target, $fence, true );
	}

	private function verify_current( EffectPlan $plan, EffectTarget $target ): object {
		$loaded  = $this->stages->for_plan( $plan );
		$stage   = $loaded->payload;
		$context = $this->context( $plan );
		if ( ! $this->source_permitted( $stage ) || ! hash_equals( $loaded->environment_hash, $this->environment->read()->fingerprint() ) ) {
			throw new ContractException( 'unauthorized', 'Native draft target or environment is unavailable.' );
		}
		if ( isset( $stage->native_saved_variants ) ) {
			$variant_context = new Context( $context->actor(), 'builder', 'worker', $stage->conversation_id, $stage->run_id );
			return ( new BuilderSavedVariantControlsService( $this->db, $this->reader, $this->compiler, $this->environment, $this->directories ) )->verify( $variant_context, $stage );
		}
		if ( isset( $stage->native_directory_query ) ) {
			// The exact stage reader has already bound these IDs to the approved plan.
			$query_context = new Context( $context->actor(), 'builder', 'worker', $stage->conversation_id, $stage->run_id );
			return ( new BuilderDirectoryQueryService( $this->db, $this->reader, $this->compiler, $this->environment, $this->directories ) )->verify( $query_context, $stage );
		}
		if ( isset( $stage->native_directory_binding ) ) {
			$binding_context = new Context( $context->actor(), 'builder', 'worker', $stage->conversation_id, $stage->run_id );
			return ( new BuilderDirectoryBindingService( $this->db, $this->reader, $this->compiler, $this->environment, $this->directories ) )->verify( $binding_context, $stage );
		}
		$reference = $stage->proposal_ref ?? null;
		if ( ! $reference instanceof \stdClass || ! is_string( $reference->id ?? null ) || ! is_int( $reference->version ?? null ) || ! is_string( $reference->hash ?? null ) ) {
			throw new ContractException( 'evidence_stale', 'Native draft has no exact reviewed builder proposal.' );
		}
		$proposal_row = $this->artifacts->get( $context->actor()->owner(), $reference->id, $reference->version );
		if ( 'proposal' !== $proposal_row['kind'] || ! hash_equals( $reference->hash, $proposal_row['content_hash'] ) ) {
			throw new ContractException( 'evidence_stale', 'Original builder proposal artifact changed.' );
		}
		$proposal = ( new JsonDecoder( 2097152, 32 ) )->decode( $proposal_row['payload_json'] );
		if ( ! $proposal instanceof \stdClass || 'builder_change' !== ( $proposal->kind ?? null ) || ! is_array( $proposal->normalized_changes ?? null ) || ! $proposal->normalized_changes || ! isset( $proposal->validation_ref->candidate_graph_hash, $proposal->validation_ref->candidate_fact_revision, $proposal->requested_scope ) || $proposal->document_key !== $stage->document_key ) {
			throw new ContractException( 'evidence_stale', 'Original builder proposal no longer matches the staged native draft.' );
		}
		$document = $stage->source_document;
		$scope    = $proposal->requested_scope;
		if ( 'elementor' === $this->reader->builder() && 'private_template_draft' === $stage->intent && ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $stage->source_scope ?? null ) !== ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $scope ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor template source scope differs from the reviewed proposal.' );
		}
		$directory = null === ( $scope->directory_id ?? null ) ? null : $this->directories->read( $context, (int) $scope->directory_id );
		$request   = new BuilderGraphInspectionRequest( $context, $document, $scope, array(), $directory, null, $this->environment );
		$current   = $this->reader->inspect( $request );
		$request->assert_result( $current );
		if ( $current->document_key() !== $stage->document_key || ! hash_equals( $stage->graph_version_hash, $current->version()->hash() ) || ! hash_equals( $stage->graph_hash, $current->graph_hash() ) || ! hash_equals( $target->expected_version, $current->version()->source_hash() ) ) {
			throw new ContractException( 'stale_version', 'Native draft source graph changed after review.' );
		}
		$copy                  = 'isolated_editor_candidate' === ( $proposal->effect_scope ?? null ) ? BuilderPrivateCopyCoverage::for_editor_candidate( $request, $current ) : ( 'private_template_draft' === $stage->intent ? BuilderPrivateCopyCoverage::for_saved_template( $request, $current ) : null );
		$loop_creation         = BuilderElementorLoopCreationProof::for_saved_page( $request, $current );
		$expected_effect_scope = null !== $copy ? $copy->effect_scope() : ( null !== $loop_creation ? $loop_creation->effect_scope() : null );
		if ( ( 'partial' === $current->graph()->consumer_coverage && null === $copy ) || ( null === $expected_effect_scope && isset( $proposal->effect_scope ) ) || ( null !== $expected_effect_scope && ! hash_equals( $expected_effect_scope, (string) ( $proposal->effect_scope ?? '' ) ) ) ) {
			throw new ContractException( 'incomplete_graph', 'Reviewed template copy coverage is no longer available.' );
		}
		if ( null !== $loop_creation ) {
			if ( 1 !== count( $proposal->normalized_changes ) ) {
				throw new ContractException( 'scope_conflict', 'Reviewed Elementor loop must contain one insertion.' );
			}
			$loop_creation->assert_normalized_change( $proposal->normalized_changes[0] );
		}
		$source  = $this->sources->verify_reviewed( $this->reader, $request, $current, $stage->source_ref, $stage->source_operation_id, $stage->conversation_id, $stage->run_id, $copy );
		$planner = $this->planners->create( $this->facts->create( $request ) );
		$checked = $planner->revalidate_reviewed_candidate( $context, $stage->conversation_id, $stage->run_id, $stage->document_key, $current->version(), $proposal->normalized_changes, $stage->candidate_graph_hash, $stage->candidate_fact_revision, $copy, $loop_creation );
		if ( $checked->generation !== $stage->graph_generation || ! hash_equals( $checked->graph_hash, $stage->graph_hash ) || ! $this->same_reference( $checked->native_artifact, $stage->source_ref ) ) {
			throw new ContractException( 'stale_version', 'Published builder graph or source checkpoint changed after review.' );
		}
		$native = BuilderPreparedNativeCandidate::reuse( $this->compiler, $request, $this->directories, $source, $checked->graph, $proposal->normalized_changes, $stage );
		if ( null === $native ) {
			$native = $this->compile_native_layout( $request, $current, $source, $checked->graph, $proposal->normalized_changes, $stage );
		} else {
			$this->sources->verify_reviewed( $this->reader, $request, $current, $stage->source_ref, $stage->source_operation_id, $stage->conversation_id, $stage->run_id, $copy );
		}
		$stage_dependency  = $this->dependency_revision( $stage );
		$native_dependency = $this->dependency_revision( $native );
		if ( ! hash_equals( $stage->native_content_hash, $native->content_hash ) || ! hash_equals( $stage->native_content, $native->content ) || ! hash_equals( $target->effect_hash, $native->content_hash ) || ! $this->same_dependency_revision( $stage_dependency, $native_dependency ) ) {
			throw new ContractException( 'stale_version', 'Native draft serialization changed after human review.' );
		}
		return (object) array(
			'payload'             => $stage,
			'source'              => $source,
			'graph'               => $checked->graph,
			'changes'             => $proposal->normalized_changes,
			'native_content_hash' => $native->content_hash,
			'dependency_revision' => $native_dependency,
			'generation'          => $checked->generation,
		);
	}

	/** Recompile reviewed bytes without performing an effect or changing source authority. */
	private function compile_native_layout( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, string $source, object $graph, array $changes, object $stage ): object {
		if ( isset( $stage->initial_directory_query ) ) {
			if ( ! in_array( $stage->initial_directory_query_view ?? null, array( 'grid', 'list' ), true ) ) {
				throw new ContractException( 'evidence_stale', 'The native initial-loop creation certificate is unavailable.' );
			}
			$query_service = new BuilderDirectoryQueryService( $this->db, $this->reader, $this->compiler, $this->environment, $this->directories );
			$native        = $query_service->compile_initial_layout( $request, $current, $source, $graph, $changes, $stage->initial_directory_query, $stage->initial_directory_query_view );
			$native        = $query_service->amend_initial( $request, $current, $native, $stage->initial_directory_query, $stage->initial_directory_query_view );
		} else {
			$native = $this->compiler->compile( $source, $graph, $changes );
		}
		return $native;
	}

	private function dependency_revision( object $value ): ?string {
		$revision = $value->dependency_revision ?? null;
		if ( null !== $revision && ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) ) {
			throw new ContractException( 'evidence_stale', 'Native draft dependency revision is invalid.' );
		}
		return $revision;
	}

	private function same_dependency_revision( ?string $expected, ?string $actual ): bool {
		return null === $expected ? null === $actual : null !== $actual && hash_equals( $expected, $actual );
	}

	private function source_identifier( object $stage ): int|string {
		return 'private_page_draft' === $stage->intent ? $stage->source_page_id : $stage->source_template_ref;
	}

	private function source_permitted( object $stage ): bool {
		if ( 'editor_candidate' === ( $stage->output_mode ?? null ) ) {
			try {
				\DirectoristAIPro\UI\EditorWorkingCopy::assert_candidate_source( $stage );
			} catch ( \Throwable $error ) {
				return false;
			}
		}
		if ( ! $stage->source_document instanceof \stdClass || $this->reader->builder() !== ( $stage->source_document->builder ?? null ) ) {
			return false;
		}
		if ( 'private_page_draft' === $stage->intent ) {
			$page_type = get_post_type_object( 'page' );
			return $page_type instanceof \WP_Post_Type && current_user_can( 'edit_post', $stage->source_page_id ) && current_user_can( $page_type->cap->create_posts ) && 'page' === get_post_type( $stage->source_page_id );
		}
		if ( 'elementor' === $this->reader->builder() && 'private_template_draft' === $stage->intent ) {
			if ( ! is_string( $stage->source_template_ref ?? null ) || ! preg_match( '/^post:([1-9][0-9]*)$/D', $stage->source_template_ref, $match ) || ! in_array( $stage->source_document->native_kind ?? null, array( 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) ) {
				return false;
			}
			$kind      = $stage->source_document->native_kind;
			$type      = get_post_type_object( 'template:archive_listing' === $kind ? 'page' : 'elementor_library' );
			$directory = $stage->source_scope->directory_id ?? null;
			return $type instanceof \WP_Post_Type && current_user_can( $type->cap->create_posts ) && ElementorEditorSnapshotBinding::matches_saved_document( (int) $match[1], $kind, null === $directory ? null : (int) $directory );
		}
		if ( 'divi' === $this->reader->builder() && 'private_template_draft' === $stage->intent ) {
			if ( ! is_string( $stage->source_template_ref ?? null ) || ! preg_match( '/^post:([1-9][0-9]*)$/D', $stage->source_template_ref, $match ) || ! in_array( $stage->source_document->native_kind ?? null, array( 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) ) {
				return false;
			}
			$source_type = get_post_type( (int) $match[1] );
			$native_type = 'template:archive_listing' === $stage->source_document->native_kind && 'page' === $source_type ? 'page' : 'et_body_layout';
			$body_type   = get_post_type_object( $native_type );
			return $body_type instanceof \WP_Post_Type && get_post_type( (int) $match[1] ) === $native_type && current_user_can( 'edit_post', (int) $match[1] ) && ( 'page' === $native_type || current_user_can( 'edit_others_posts' ) ) && current_user_can( $body_type->cap->create_posts );
		}
		if ( 'gutenberg' !== $this->reader->builder() || 'private_template_draft' !== $stage->intent || ! GutenbergTemplateSource::read( (string) $stage->source_template_ref ) ) {
			return false;
		}
		$document  = $stage->source_document ?? null;
		$directory = $stage->source_scope->directory_id ?? null;
		return $document instanceof \stdClass && is_string( $document->native_kind ?? null ) && GutenbergEditorSnapshotBinding::matches_saved_document( $stage->source_template_ref, $document->native_kind, null === $directory ? null : (int) $directory, null );
	}

	private function context( EffectPlan $plan ): Context {
		if ( ! preg_match( '/^user:([1-9][0-9]*)$/D', $plan->actor_ref, $match ) || get_current_user_id() !== (int) $match[1] ) {
			throw new ContractException( 'unauthorized', 'Native draft effect requires its current authenticated WordPress user.' );
		}
		return new Context( Actor::user( (int) $match[1] ), 'builder', 'worker' );
	}

	private function same_reference( $current, object $expected ): bool {
		return $current instanceof \stdClass && isset( $current->id, $current->version, $current->hash ) && $current->id === $expected->id && $current->version === $expected->version && hash_equals( $current->hash, $expected->hash );
	}

	private function outcome( object $receipt, EffectTarget $target, int $fence, bool $reconciled ): EffectOutcome {
		if ( ! in_array( $receipt->status ?? null, array( 'succeeded', 'failed', 'unknown' ), true ) || ! hash_equals( $target->expected_version, (string) ( $receipt->source_hash ?? '' ) ) || ! hash_equals( $target->effect_hash, (string) ( $receipt->draft_content_hash ?? '' ) ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_receipt_mismatch' );
		}
		if ( 'succeeded' === $receipt->status && is_int( $receipt->draft_post_id ?? null ) && $receipt->draft_post_id > 0 ) {
			$effects = array(
				'draft_post_id' => $receipt->draft_post_id,
				'status'        => 'draft',
				'content_hash'  => $target->effect_hash,
				'fence'         => $fence,
				'reconciled'    => $reconciled,
			);
			if ( is_string( $receipt->draft_native_ref ?? null ) && ( ( in_array( $this->reader->builder(), array( 'elementor', 'divi' ), true ) && 'post:' . $receipt->draft_post_id === $receipt->draft_native_ref ) || 1 === preg_match( '/^template:(wp_template|wp_template_part):[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+$/D', $receipt->draft_native_ref ) ) ) {
				$effects['draft_native_ref'] = $receipt->draft_native_ref;
			}
			return new EffectOutcome(
				'succeeded',
				$target->expected_version,
				$target->effect_hash,
				(object) $effects,
				array(
					array(
						'kind'  => 'wordpress_post_hooks',
						'state' => 'external_effects_unverified',
					),
				)
			);
		}
		if ( 'failed' === $receipt->status && 'deleted' === ( $receipt->cleanup ?? null ) ) {
			return new EffectOutcome(
				'failed',
				$target->expected_version,
				$target->expected_version,
				(object) array( 'cleanup' => 'deleted' ),
				array(
					array(
						'kind'  => 'wordpress_post_hooks',
						'state' => 'external_effects_unverified',
					),
				),
				$receipt->diagnostic ?? 'native_save_failed'
			);
		}
		return new EffectOutcome(
			'unknown',
			$target->expected_version,
			null,
			(object) array( 'cleanup' => $receipt->cleanup ?? 'unverified' ),
			array(
				array(
					'kind'  => 'wordpress_post_hooks',
					'state' => 'external_effects_unverified',
				),
			),
			$receipt->diagnostic ?? 'native_outcome_unavailable'
		);
	}

	/** This effect exposes reviewed bytes, and never saves any native document. */
	private function candidate_outcome( object $payload, EffectTarget $target, int $fence, bool $reconciled ): EffectOutcome {
		\DirectoristAIPro\UI\EditorWorkingCopy::assert_candidate_source( $payload );
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			$target->effect_hash,
			(object) array(
				'output_mode'            => 'editor_candidate',
				'candidate_id'           => $payload->candidate_id,
				'builder'                => $payload->source_document->builder,
				'original_document'      => $payload->original_document,
				'capture_id'             => $payload->capture_id,
				'captured_snapshot_hash' => $payload->captured_snapshot_hash,
				'native_content_hash'    => $payload->native_content_hash,
				'saved'                  => false,
				'status'                 => 'candidate_ready',
				'fence'                  => $fence,
				'reconciled'             => $reconciled,
			),
			array()
		);
	}
}
