<?php
/**
 * Prepare one private native draft and its separate exact human review.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal reviewed staging boundary.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Builds review material only; the approved native write uses a separate operation. */
final class BuilderDraftStagePreparationService {
	private Database $db;
	private ArtifactStore $artifacts;
	private ApprovalService $approvals;
	private BuilderNativeSourceCaptureService $sources;
	private BuilderChangePlanner $planner;

	public function __construct( Database $db, BuilderChangePlanner $planner ) {
		$this->db        = $db;
		$this->artifacts = new ArtifactStore( $db );
		$this->approvals = new ApprovalService( $db );
		$this->sources   = new BuilderNativeSourceCaptureService( $db );
		$this->planner   = $planner;
	}

	/** Prepare one isolated supported native page or Gutenberg template draft. */
	public function prepare( BuilderNativeSourceReader $reader, BuilderDraftCandidateCompiler $compiler, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, object $proposal_ref, string $proposal_review_id, object $source_ref, string $source_operation_id, string $request_id, string $intent, ?string $native_preset_view = null, ?object $directory_query = null ): object {
		$context = $request->context();
		$context->assert_execution_identity();
		if ( null !== $native_preset_view && ! in_array( $native_preset_view, array( 'grid', 'list' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Native layout presentation must name a supported initial view.' );
		}
		GraphVersion::identifier( $request_id );
		GraphVersion::identifier( $source_operation_id );
		ConversationStore::identifier( $proposal_review_id );
		if ( ! in_array( $intent, array( 'private_page_draft', 'private_template_draft' ), true ) || ! in_array( $compiler->builder(), array( 'gutenberg', 'elementor', 'divi' ), true ) || $reader->builder() !== $compiler->builder() || $context->actor()->is_guest() || null === $context->conversation_id() || null === $context->run_id() || ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) ) {
			throw new ContractException( 'unauthorized', 'Native draft preparation requires its authenticated workflow.' );
		}
		$document         = $request->document();
		$editor_capture   = \DirectoristAIPro\UI\EditorWorkingCopy::editor_context( $document );
		$editor_candidate = null !== $editor_capture && 'editor_candidate' === ( $editor_capture['output_mode'] ?? null );
		if ( null !== $directory_query && ( ! $editor_candidate || 'page' !== $document->native_kind || 'private_page_draft' !== $intent ) ) {
			throw new ContractException( 'unsupported_operation', 'Configure a shared directory layout in the current native page editor.' );
		}
		if ( null !== $directory_query && null === $native_preset_view ) {
			throw new ContractException( 'scope_conflict', 'A shared directory layout requires a certified native initial-loop insertion.', array( 'initial_loop_recipe_required' ) );
		}
		if ( preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $editor_match ) && \DirectoristAIPro\UI\EditorWorkingCopy::INTERNAL_STATUS === get_post_status( (int) $editor_match[1] ) && null === $editor_capture ) {
			throw new ContractException( 'stale_version', 'This native editor snapshot has expired or lost its source authority.' );
		}
		$request->assert_result( $expected );
		$copy          = BuilderPrivateCopyCoverage::for_editor_candidate( $request, $expected ) ?? ( 'private_template_draft' === $intent ? BuilderPrivateCopyCoverage::for_saved_template( $request, $expected ) : null );
		$loop_creation = BuilderElementorLoopCreationProof::for_saved_page( $request, $expected );
		if ( 'partial' === $expected->consumer_coverage() && null === $copy ) {
			throw new ContractException( 'incomplete_graph', 'Native template copy coverage is unavailable.' );
		}
		$page_id      = null;
		$template_ref = null;
		$directory_id = null === $request->scope()->directory_id ? null : (int) $request->scope()->directory_id;
		if ( $compiler->builder() !== $document->builder ) {
			throw new ContractException( 'unsupported_operation', 'Native draft requires the selected builder source.' );
		}
		if ( 'private_page_draft' === $intent && 'page' === $document->native_kind && preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $matches ) ) {
			$page_id   = (int) $matches[1];
			$page_type = get_post_type_object( 'page' );
			if ( ! $page_type instanceof \WP_Post_Type || ! current_user_can( 'edit_post', $page_id ) || ! current_user_can( $page_type->cap->create_posts ) ) {
				throw new ContractException( 'unauthorized', 'Native page edit or draft creation right is unavailable.' );
			}
		} elseif ( 'gutenberg' === $document->builder && 'private_template_draft' === $intent && preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $document->native_id, $matches ) && GutenbergEditorSnapshotBinding::matches_saved_document( $document->native_id, $document->native_kind, $directory_id, $request->directory_facts()->directory_slug ?? null ) ) {
			$template_ref = $document->native_id;
			if ( ! GutenbergTemplateSource::read( $document->native_id ) ) {
				throw new ContractException( 'unauthorized', 'Gutenberg template source or draft creation right is unavailable.' );
			}
		} elseif ( 'elementor' === $document->builder && 'private_template_draft' === $intent && in_array( $document->native_kind, array( 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) && preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $matches ) ) {
			$template_ref = $document->native_id;
			$type         = get_post_type_object( 'template:archive_listing' === $document->native_kind ? 'page' : 'elementor_library' );
			if ( ! $type instanceof \WP_Post_Type || ! current_user_can( $type->cap->create_posts ) || ! ElementorEditorSnapshotBinding::matches_saved_document( (int) $matches[1], $document->native_kind, $directory_id ) ) {
				throw new ContractException( 'unauthorized', 'Elementor template source or isolated native draft creation right is unavailable.' );
			}
		} elseif ( 'divi' === $document->builder && 'private_template_draft' === $intent && in_array( $document->native_kind, array( 'template:single_listing', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' ), true ) && preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $matches ) ) {
			$template_ref = $document->native_id;
			$source_type  = get_post_type( (int) $matches[1] );
			$native_type  = 'template:archive_listing' === $document->native_kind && 'page' === $source_type ? 'page' : 'et_body_layout';
			$body_type    = get_post_type_object( $native_type );
			if ( ! $body_type instanceof \WP_Post_Type || get_post_type( (int) $matches[1] ) !== $native_type || ! current_user_can( 'edit_post', (int) $matches[1] ) || ( 'et_body_layout' === $native_type && ! current_user_can( 'edit_others_posts' ) ) || ! current_user_can( $body_type->cap->create_posts ) ) {
				throw new ContractException( 'unauthorized', 'Divi body source edit or isolated draft creation right is unavailable.' );
			}
		} else {
			throw new ContractException( 'unsupported_operation', 'This native draft preparation supports saved pages and current-theme templates only.' );
		}
		$this->reference( $proposal_ref );
		$this->reference( $source_ref );
		$owner    = $context->actor()->owner();
		$run_id   = $this->active_run_id( $context );
		$reviewed = $this->approvals->get( $context->actor(), $proposal_review_id );
		$review   = $reviewed['review'];
		if ( ! in_array( $reviewed['state'], array( 'pending', 'approved' ), true ) || 'builder.apply' !== ( $review->plan->capability ?? null ) || $review->plan->run_id !== $run_id || $review->artifact->id !== $proposal_ref->id || $review->artifact->version !== $proposal_ref->version || ! hash_equals( $review->artifact->hash, $proposal_ref->hash ) ) {
			throw new ContractException( 'approval_mismatch', 'Stored builder proposal review differs from the active workflow.' );
		}
		$proposal_row = $this->artifacts->get( $owner, $proposal_ref->id, $proposal_ref->version );
		if ( 'proposal' !== $proposal_row['kind'] || ! hash_equals( $proposal_row['content_hash'], $proposal_ref->hash ) || ! hash_equals( $proposal_row['environment_hash'], $expected->version()->environment_hash() ) || ! hash_equals( $review->plan->proposal_hash, $proposal_ref->hash ) || ! $this->same_conversation( $context->conversation_id(), $proposal_row ) ) {
			throw new ContractException( 'evidence_stale', 'Reviewed builder proposal artifact changed.' );
		}
		$proposal = ( new JsonDecoder( 2097152, 32 ) )->decode( $proposal_row['payload_json'] );
		if ( ! $proposal instanceof \stdClass || 'builder_change' !== ( $proposal->kind ?? null ) || $proposal->document_key !== $expected->document_key() || ! is_array( $proposal->normalized_changes ?? null ) || ! $proposal->normalized_changes || ! isset( $proposal->validation_ref->candidate_graph_hash, $proposal->validation_ref->candidate_fact_revision ) ) {
			throw new ContractException( 'evidence_stale', 'Builder proposal cannot be used for native draft staging.' );
		}
		// Existing reviewed artifacts retain their original stricter coverage path.
		if ( null !== $copy && $copy->is_editor_candidate() && 'isolated_editor_candidate' !== ( $proposal->effect_scope ?? null ) ) {
			$copy = null;
		}
		$expected_effect_scope = null !== $copy ? $copy->effect_scope() : ( null !== $loop_creation ? $loop_creation->effect_scope() : null );
		if ( ( null === $expected_effect_scope && isset( $proposal->effect_scope ) ) || ( null !== $expected_effect_scope && ! hash_equals( $expected_effect_scope, (string) ( $proposal->effect_scope ?? '' ) ) ) ) {
			throw new ContractException( 'evidence_stale', 'Builder proposal effect scope differs from the isolated template copy.' );
		}
		if ( null !== $loop_creation ) {
			if ( 1 !== count( $proposal->normalized_changes ) ) {
				throw new ContractException( 'scope_conflict', 'Reviewed Elementor loop must contain one insertion.' );
			}
			$loop_creation->assert_normalized_change( $proposal->normalized_changes[0] );
		}
		$base = $this->proposal_base( $proposal, $expected->document_key() );
		if ( $base->version_hash !== $expected->version()->hash() || $base->source_hash !== $expected->version()->source_hash() || $base->graph_hash !== $expected->graph_hash() || ! $this->equal_reference( $base->native_artifact, $source_ref ) ) {
			throw new ContractException( 'stale_version', 'Builder proposal predates the selected native source checkpoint.' );
		}
		$revalidated = $this->planner->revalidate_candidate( $context, $expected->document_key(), $expected->version(), $proposal->normalized_changes, $proposal->validation_ref->candidate_graph_hash, $proposal->validation_ref->candidate_fact_revision, $copy, $loop_creation );
		if ( ! $this->equal_reference( $revalidated->native_artifact, $source_ref ) || ! hash_equals( $revalidated->graph_hash, $expected->graph_hash() ) ) {
			throw new ContractException( 'evidence_stale', 'Current graph has no matching native source checkpoint.' );
		}
		$source            = $this->sources->verify( $reader, $request, $expected, $source_ref, $source_operation_id, $copy );
		$prepared_revision = $editor_candidate ? BuilderPreparedNativeCandidate::revision( $compiler, $revalidated->graph ) : null;
		$native            = $this->compile_native_layout( $reader, $compiler, $request, $expected, $source, $revalidated->graph, $proposal->normalized_changes, $directory_query, $native_preset_view );
		if ( ! $native instanceof \stdClass || ! is_string( $native->content ?? null ) || ! is_string( $native->content_hash ?? null ) || ! hash_equals( hash( 'sha256', $native->content ), $native->content_hash ) || ! is_int( $native->block_count ?? null ) || $native->block_count < 1 || ! is_string( $native->root_name ?? null ) ) {
			throw new ContractException( 'native_invalid', 'Native builder compiler did not return an exact bounded candidate.' );
		}
		$native_omissions = BuilderCompletionNotes::initial_omissions( $native->omitted_optional ?? array(), $expected->graph(), $proposal->normalized_changes );
		$dependency_revision = $this->dependency_revision( $native );
		$identity            = hash( 'sha256', $context->run_id() . "\0" . $request_id );
		$artifact_id         = 'builder-stage-' . substr( $identity, 0, 32 );
		$operation_id        = 'builder-stage-' . substr( $identity, 0, 32 );
		$payload             = (object) array(
			'kind'                    => 'builder_draft_stage',
			'intent'                  => $intent,
			'document_key'            => $expected->document_key(),
			'source_page_id'          => $page_id,
			'source_template_ref'     => $template_ref,
			'source_document'         => $document,
			'source_ref'              => $source_ref,
			'source_operation_id'     => $source_operation_id,
			'source_hash'             => hash( 'sha256', $source ),
			'graph_version_hash'      => $expected->version()->hash(),
			'graph_hash'              => $revalidated->graph_hash,
			'graph_generation'        => $revalidated->generation,
			'candidate_graph_hash'    => $revalidated->candidate_hash,
			'candidate_fact_revision' => $revalidated->candidate_fact_revision,
			'proposal_ref'            => $proposal_ref,
			'proposal_review_id'      => $proposal_review_id,
			'conversation_id'         => $context->conversation_id(),
			'run_id'                  => $context->run_id(),
			'native_content'          => $native->content,
			'native_content_hash'     => $native->content_hash,
			'dependency_revision'     => $dependency_revision,
			'block_count'             => $native->block_count,
			'root_name'               => $native->root_name,
		);
		if ( 'private_template_draft' === $intent ) {
			$payload->source_scope = $request->scope();
		}
		if ( $editor_candidate ) {
			$payload->output_mode            = 'editor_candidate';
			$payload->original_document      = $editor_capture['document'];
			$payload->capture_id             = $editor_capture['capture_id'];
			$payload->captured_snapshot_hash = $editor_capture['captured_snapshot_hash'];
			$payload->candidate_id           = $operation_id;
			$payload->saved                  = false;
			if ( null !== $directory_query ) {
				$payload->initial_directory_query      = $directory_query;
				$payload->initial_directory_query_view = $native_preset_view; }
			\DirectoristAIPro\UI\EditorWorkingCopy::assert_candidate_source( $payload );
		}
		$prepared_proof = BuilderPreparedNativeCandidate::seal( $compiler, $request, new DirectoristDirectoryFactsReader( new \DirectoristAIPro\Domain\DirectoristInspector() ), $source, $revalidated->graph, $proposal->normalized_changes, $payload, $native, $prepared_revision );
		if ( null !== $prepared_proof ) {
			$payload->prepared_native_proof = $prepared_proof;
		}
		if ( is_callable( array( $compiler, 'completion_notes' ) ) ) {
			$run_rows   = $this->db->rows( 'SELECT checkpoint_json FROM ' . $this->db->table( 'runs' ) . ' WHERE id=%d AND owner_ref=%s AND public_id=%s', array( $run_id, $owner->reference(), $context->run_id() ) );
			$checkpoint = isset( $run_rows[0]['checkpoint_json'] ) ? json_decode( $run_rows[0]['checkpoint_json'] ) : null;
			$text       = ( $checkpoint->reply ?? $checkpoint->intent ?? null )->text ?? '';
			if ( is_string( $text ) && preg_match( '/\nUser request: (.*?)(?:\nEarlier conversation \(context only\):|$)/sD', $text, $parts ) ) {
				$text = $parts[1];
			}
			$notes = BuilderCompletionNotes::validate( $compiler->completion_notes( $expected->graph(), $proposal->normalized_changes, is_string( $text ) ? $text : '' ) );
			if ( $notes ) {
				$payload->completion_notes = $notes;
			}
		}
		if ( $native_omissions ) { $payload->omitted_optional = $native_omissions; }
		if ( ! empty( $proposal->omitted_optional ) || $native_omissions ) {
			$notes = $payload->completion_notes ?? array();
			if ( ! in_array( 'unsupported_optional_settings_omitted', array_column( $notes, 'code' ), true ) ) { $notes[] = (object) array( 'code' => 'unsupported_optional_settings_omitted' ); }
			$payload->completion_notes = BuilderCompletionNotes::validate( $notes );
		}
		$this->final_fence( $context, $reader, $compiler, $request, $expected, $source_ref, $source_operation_id, $proposal, $revalidated, $native->content_hash, $dependency_revision, $copy, $loop_creation, $directory_query, $native_preset_view, $payload );
		$artifact = $this->artifacts->put_once( $owner, $context->conversation_id(), $artifact_id, 'builder_draft', $payload, $expected->version()->environment_hash() );
		$plan     = new EffectPlan( $operation_id, $owner->reference(), 'builder.stage', '1', $artifact['content_hash'], array( new EffectTarget( 'builder-draft:' . substr( hash( 'sha256', $expected->document_key() ), 0, 64 ), $expected->version()->source_hash(), $native->content_hash ) ), $run_id );
		$review   = $this->approvals->create_staged(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				(int) $artifact['version'],
				$artifact['content_hash'],
				$expected->version()->environment_hash(),
				(object) array(
					'kind'                    => 'builder_draft_stage',
					'intent'                  => $intent,
					'document_key'            => $expected->document_key(),
					'source_page_id'          => $page_id,
					'source_document'         => $document,
					'scope'                   => $request->scope(),
					'source_hash'             => $payload->source_hash,
					'candidate_hash'          => $native->content_hash,
					'output_mode'             => $editor_candidate ? 'editor_candidate' : 'private_draft',
					'original_document'       => $editor_candidate ? $payload->original_document : null,
					'capture_id'              => $editor_candidate ? $payload->capture_id : null,
					'captured_snapshot_hash'  => $editor_candidate ? $payload->captured_snapshot_hash : null,
					'candidate_id'            => $editor_candidate ? $operation_id : null,
					'saved'                   => false,
					'dependency_revision'     => $dependency_revision,
					'block_count'             => $native->block_count,
					'root_name'               => $native->root_name,
					'native_layout_view'      => null === $directory_query ? $native_preset_view : $directory_query->view,
					'initial_directory_query' => $directory_query,
					'presentation_note'       => null === $native_preset_view ? null : 'Prepares the listing section and its supported presentation changes for review. Check the result in your editor, then save when ready.',
					'changes'                 => $this->review_changes( $proposal->normalized_changes ),
					'completion_notes'        => $payload->completion_notes ?? array(),
				),
				(object) array(
					'max_drafts' => $editor_candidate ? 0 : 1,
					'max_blocks' => 80,
				)
			)
		);
		try {
			$this->final_fence( $context, $reader, $compiler, $request, $expected, $source_ref, $source_operation_id, $proposal, $revalidated, $native->content_hash, $dependency_revision, $copy, $loop_creation, $directory_query, $native_preset_view, $payload );
		} catch ( \Throwable $error ) {
			$this->approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] );
			throw $error;
		}
		$review = $this->approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		return (object) array(
			'artifact'            => (object) array(
				'id'      => $artifact['public_id'],
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash'],
			),
			'operation_id'        => $operation_id,
			'review'              => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
			),
			'native_content_hash' => $native->content_hash,
			'dependency_revision' => $dependency_revision,
			'block_count'         => $native->block_count,
			'output_mode'         => $editor_candidate ? 'editor_candidate' : 'private_draft',
		);
	}

	/** Describe validated semantic operations without copying native payloads into the approval. */
	private function review_changes( array $changes ): array {
		$summary = array();
		foreach ( $changes as $change ) {
			$row = array( 'operation' => $change->op );
			if ( isset( $change->target_id ) ) {
				$row['target'] = $change->target_id;
			}
			if ( isset( $change->parent_id ) ) {
				$row['parent'] = $change->parent_id;
			}
			if ( isset( $change->control_path ) ) {
				$row['control'] = $change->control_path;
			}
			if ( 'set_control' === $change->op ) {
				$row['before'] = $this->review_value( $change->before );
				$row['after']  = $this->review_value( $change->value );
			}
			if ( 'insert' === $change->op ) {
				foreach ( $change->subtree->nodes as $node ) {
					if ( $node->node_id === $change->subtree->root_node_id ) {
						$row['component'] = $node->native_id;
						break;
					}
				}
				$row['position'] = $change->position;
			}
			if ( 'set_variant' === $change->op ) {
				$row['variant'] = $change->variant_id;
			}
			if ( 'bind_field' === $change->op ) {
				$row['field'] = $change->field_id;
			}
			$summary[] = (object) $row;
		}
		return $summary;
	}

	/** Keep scalar/control previews small while retaining exact hashes for longer values. */
	private function review_value( $value ): object {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			throw new ContractException( 'native_invalid', 'Reviewed native control value cannot be encoded.' );
		}
		$complete = strlen( $encoded ) <= 240;
		return (object) array(
			'text'     => $complete ? $encoded : wp_check_invalid_utf8( substr( $encoded, 0, 200 ), true ) . '…',
			'complete' => $complete,
			'sha256'   => hash( 'sha256', $encoded ),
		);
	}

	private function final_fence( Context $context, BuilderNativeSourceReader $reader, BuilderDraftCandidateCompiler $compiler, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, object $source_ref, string $source_operation_id, object $proposal, object $before, string $content_hash, ?string $dependency_revision, ?BuilderPrivateCopyCoverage $copy, ?BuilderElementorLoopCreationProof $loop_creation, ?object $directory_query = null, ?string $native_preset_view = null, ?object $prepared_stage = null ): void {
		$again = $this->planner->revalidate_candidate( $context, $expected->document_key(), $expected->version(), $proposal->normalized_changes, $proposal->validation_ref->candidate_graph_hash, $proposal->validation_ref->candidate_fact_revision, $copy, $loop_creation );
		if ( $again->generation !== $before->generation || ! hash_equals( $again->graph_hash, $before->graph_hash ) || ! $this->equal_reference( $again->native_artifact, $source_ref ) ) {
			throw new ContractException( 'stale_version', 'Native draft authority changed before review activation.' );
		}
		$source = $this->sources->verify( $reader, $request, $expected, $source_ref, $source_operation_id, $copy );
		$fresh  = null === $prepared_stage ? null : BuilderPreparedNativeCandidate::reuse( $compiler, $request, new DirectoristDirectoryFactsReader( new \DirectoristAIPro\Domain\DirectoristInspector() ), $source, $again->graph, $proposal->normalized_changes, $prepared_stage );
		if ( null === $fresh ) {
			$fresh = $this->compile_native_layout( $reader, $compiler, $request, $expected, $source, $again->graph, $proposal->normalized_changes, $directory_query, $native_preset_view );
		} else {
			$this->sources->verify( $reader, $request, $expected, $source_ref, $source_operation_id, $copy );
		}
		if ( ! is_string( $fresh->content_hash ?? null ) || ! hash_equals( $content_hash, $fresh->content_hash ) || ! is_string( $fresh->content ?? null ) || ! hash_equals( $fresh->content_hash, hash( 'sha256', $fresh->content ) ) || ! $this->same_dependency_revision( $dependency_revision, $this->dependency_revision( $fresh ) ) ) {
			throw new ContractException( 'stale_version', 'Native draft candidate changed before review activation.', array( 'native_candidate_changed_before_review' ) );
		}
	}


	/** Use the same exact construction and mandatory query amendment at every fence. */
	private function compile_native_layout( BuilderNativeSourceReader $reader, BuilderDraftCandidateCompiler $compiler, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, string $source, object $graph, array $changes, ?object $directory_query, ?string $native_preset_view ): object {
		if ( null === $directory_query ) {
			return $compiler->compile( $source, $graph, $changes );
		}
		$query_service = new BuilderDirectoryQueryService( $this->db, $reader, $compiler, new CatalogEnvironmentReader( $this->query_integrations() ), new DirectoristDirectoryFactsReader( new \DirectoristAIPro\Domain\DirectoristInspector() ) );
		$native        = $query_service->compile_initial_layout( $request, $expected, $source, $graph, $changes, $directory_query, $native_preset_view );
		$result        = $query_service->amend_initial( $request, $expected, $native, $directory_query, $native_preset_view );
		// Query amendment certifies content; preserve only the value-free compiler
		// report for the closed, source-bound validation in prepare().
		if ( property_exists( $native, 'omitted_optional' ) ) { $result->omitted_optional = $native->omitted_optional; }
		return $result;
	}

	private function query_integrations(): IntegrationRegistry {
		$integrations = new IntegrationRegistry();
		do_action( 'directorist_ai_pro_register_builder_providers', $integrations );
		$integrations->freeze();
		return $integrations;
	}

	private function dependency_revision( object $native ): ?string {
		$revision = $native->dependency_revision ?? null;
		if ( null !== $revision && ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) ) {
			throw new ContractException( 'native_invalid', 'Native builder dependency revision is invalid.' );
		}
		return $revision;
	}

	private function same_dependency_revision( ?string $expected, ?string $actual ): bool {
		return null === $expected ? null === $actual : null !== $actual && hash_equals( $expected, $actual );
	}

	private function proposal_base( object $proposal, string $document_key ): object {
		foreach ( $proposal->base_versions ?? array() as $base ) {
			if ( $base instanceof \stdClass && ( $base->document_key ?? null ) === $document_key ) {
				return $base;
			}
		}
		throw new ContractException( 'evidence_stale', 'Builder proposal has no matching native base.' );
	}

	private function active_run_id( Context $context ): int {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s AND c.owner_ref=%s AND c.active_run_id=r.id AND c.erasure_requested=0 AND r.state='running' AND r.cancel_requested=0", array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id(), $context->actor()->owner()->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Native draft preparation requires the active owning run.' );
		}
		return (int) $rows[0]['id'];
	}

	private function same_conversation( string $conversation_id, array $artifact ): bool {
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT id FROM $conversations WHERE public_id=%s", array( $conversation_id ) );
		return 1 === count( $rows ) && (int) $rows[0]['id'] === (int) $artifact['conversation_id'];
	}

	private function reference( object $reference ): void {
		$keys = array_keys( get_object_vars( $reference ) );
		sort( $keys, SORT_STRING );
		if ( array( 'hash', 'id', 'version' ) !== $keys || ! is_string( $reference->id ) || ! is_int( $reference->version ) || $reference->version < 1 || ! is_string( $reference->hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $reference->hash ) ) {
			throw new ContractException( 'invalid_input', 'Private native draft reference is invalid.' );
		}
		ConversationStore::identifier( $reference->id );
	}

	private function equal_reference( $left, object $right ): bool {
		return $left instanceof \stdClass && isset( $left->id, $left->version, $left->hash ) && $left->id === $right->id && $left->version === $right->version && hash_equals( $left->hash, $right->hash );
	}
}
