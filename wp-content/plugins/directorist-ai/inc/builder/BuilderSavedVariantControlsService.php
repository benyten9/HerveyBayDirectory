<?php
/** One exact reviewed transaction for supported existing native loop designs. */
namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Approvals\{ApprovalService, ApprovalSpec};
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\{EffectPlan, EffectTarget};
use DirectoristAIPro\Storage\{ArtifactStore, Database};
use DirectoristAIPro\UI\EditorWorkingCopy;
use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer};

/** Explicit batch authority never changes ordinary single-scope planning. */
final class BuilderSavedVariantControlsService {
	private const REVISION = 'saved-variant-controls-v1';
	private Database $db;
	private BuilderNativeSourceReader $reader;
	private BuilderDraftCandidateCompiler $compiler;
	private CatalogEnvironmentReader $environment;
	private DirectoryFactsReader $directories;
	public function __construct( Database $db, BuilderNativeSourceReader $reader, BuilderDraftCandidateCompiler $compiler, CatalogEnvironmentReader $environment, DirectoryFactsReader $directories ) { $this->db = $db; $this->reader = $reader; $this->compiler = $compiler; $this->environment = $environment; $this->directories = $directories; }
	public static function batch_schema( array $scope ): array {
		$closed = static fn( array $properties ): array => array( 'type' => 'object', 'properties' => $properties, 'required' => array_keys( $properties ), 'additionalProperties' => false );
		$path = array( 'type' => 'array', 'minItems' => 2, 'maxItems' => 32, 'items' => array( 'type' => 'string', 'maxLength' => 128 ) );
		$kinds = array( 'enum' => array( 'card_surface', 'card_title', 'search_button' ) );
		$target = $closed( array( 'kind' => $kinds, 'variant_key' => array( 'type' => 'string', 'pattern' => '^dir-[1-9][0-9]*(?:-(?:grid|list|map))?$' ), 'container_path' => $path, 'element_path' => $path, 'element_id' => array( 'type' => 'string', 'maxLength' => 128 ), 'scope' => $scope ) );
		$control = $closed( array( 'path' => array( 'enum' => array( 'template_container_background_background', 'template_container_background_color', 'template_container_padding', 'template_container_border_radius', 'title_color', 'native_controls.button_color' ) ), 'before' => (object) array(), 'value' => (object) array() ) );
		$goal = $closed( array( 'kind' => $kinds, 'path' => $control['properties']['path'], 'value' => (object) array() ) );
		$elementor = $closed( array( 'inventory_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ), 'kinds' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'uniqueItems' => true, 'items' => $kinds ), 'goals' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 6, 'items' => $goal ), 'variants' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 24, 'items' => $closed( array( 'target' => $target, 'controls' => array( 'type' => 'array', 'maxItems' => 4, 'items' => $control ) ) ) ) ) );
		// Keep Elementor's original shape intact. Gutenberg declares only its native
		// card background path and an exact goal for each of the two card views.
		$gb_path = array( 'enum' => array( 'design_elements.cardWrapper.backgroundColor' ) );
		$gb_kind = array( 'enum' => array( 'card_surface' ) );
		$gb_target = $target; $gb_target['properties']['kind'] = $gb_kind; $gb_target['properties']['variant_key']['pattern'] = '^dir-[1-9][0-9]*-(?:grid|list)$';
		$gb_control = $closed( array( 'path' => $gb_path, 'before' => (object) array(), 'value' => (object) array() ) );
		$gb_goal = $closed( array( 'kind' => $gb_kind, 'view' => array( 'enum' => array( 'grid', 'list' ) ), 'path' => $gb_path, 'value' => (object) array() ) );
		$gutenberg = $closed( array( 'inventory_hash' => $elementor['properties']['inventory_hash'], 'kinds' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 1, 'items' => $gb_kind ), 'goals' => array( 'type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => $gb_goal ), 'variants' => array( 'type' => 'array', 'minItems' => 2, 'maxItems' => 16, 'items' => $closed( array( 'target' => $gb_target, 'controls' => array( 'type' => 'array', 'maxItems' => 1, 'items' => $gb_control ) ) ) ) ) );
		return array( 'oneOf' => array( $elementor, $gutenberg ) );
	}
	private function native_loop(): array {
		$identities = array( 'elementor' => array( 'directorist-elementor', 'directorist_listings_loop' ), 'gutenberg' => array( 'directorist-gb-integration', 'directorist-gb-integration/listings-loop' ) );
		if ( ! isset( $identities[$this->reader->builder()] ) || $this->compiler->builder() !== $this->reader->builder() ) { throw new ContractException( 'unsupported_dependency', 'This builder has no reviewed saved-variant transaction.' ); }
		return $identities[$this->reader->builder()];
	}
	private function supported(): void {
		$this->native_loop();
		if ( ! is_callable( array( $this->compiler, 'saved_variant_controls_contract' ) ) || ! is_callable( array( $this->compiler, 'compile_saved_variant_controls' ) ) || ! is_callable( array( $this->reader, 'inspect_candidate_source' ) ) ) { throw new ContractException( 'unsupported_dependency', 'This builder has no reviewed saved-variant transaction.' ); }
	}
	private function accepted( BuilderGraphResult $result ): void {
		$rules = new SemanticRuleRegistry(); do_action( 'directorist_ai_pro_register_builder_semantic_rules', $rules ); $rules->freeze();
		$report = ( new RelationshipValidator( $rules ) )->validate( $result->document_key(), $result->version(), $result->graph(), $result->facts() );
		if ( 'complete' !== $result->completeness() || 'complete' !== $result->consumer_coverage() || ! $report->accepted ) { throw new ContractException( 'relationship_invalid', 'Every saved native variant must have complete current relationships.' ); }
	}
	private function authority( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, object $target ): void {
		$binding = BuilderConversationTarget::active_context( $this->db, $request->context() );
		if ( null !== $binding ) {
			BuilderConversationTarget::assert_binding( $binding, $current->document_key(), $request->document(), $request->scope() );
			$selected = json_decode( $binding )->builder_selection ?? null;
			if ( null !== $selected ) { BuilderEditorSelection::assert_source( $selected, $request->document() ); }
		}
		$canonical = new JsonCanonicalizer();
		$nodes = array_values( array_filter( $current->graph()->nodes, static fn( object $node ): bool => $canonical->encode( $node->locator ) === $canonical->encode( $target ) ) );
		list( $provider, $native ) = $this->native_loop();
		if ( 1 !== count( $nodes ) || $native !== $nodes[0]->native_id || $provider !== $nodes[0]->provider_id ) { throw new ContractException( 'scope_conflict', 'One exact existing Listings Loop must own this transaction.' ); }
		BuilderEditorSelection::assert_changes( $binding, $current->graph(), array( (object) array( 'target_id' => $nodes[0]->id ) ) );
	}
	/** Discovery exposes only loops contained by the immutable selected area. */
	public function describe( BuilderGraphInspectionRequest $request, BuilderGraphResult $current ): object {
		$this->supported(); $this->accepted( $current );
		$source = $this->reader->read_saved_source( $request, $current ); $targets = array();
		list( $provider, $native ) = $this->native_loop();
		foreach ( $current->graph()->nodes as $node ) {
			if ( $provider !== $node->provider_id || $native !== $node->native_id || null !== $node->opaque_ref ) { continue; }
			try { $this->authority( $request, $current, $node->locator ); $targets[] = $this->compiler->saved_variant_controls_contract( $source, $current->graph(), $node->locator ); }
			catch ( ContractException $error ) { if ( ! in_array( $error->error_code(), array( 'scope_conflict', 'unsupported_operation', 'unsupported_dependency' ), true ) ) { throw $error; } }
		}
		return (object) array(
			'operation' => 'configure_saved_variants',
			'revision'  => self::REVISION,
			'targets'   => $targets,
			'next_read' => 'Use only the kinds and controls declared by each returned native contract. Gutenberg saved variants currently expose card backgrounds only, not title colors. A background plus title request instead requires fresh native card/title control reads and their atomic set_control batch. Elementor exposes its own declared kinds. Copy target and inventory_hash; include EVERY returned variant of each requested kind with exact before values. controls=[] is allowed only for already-correct variants. Preserve captured outer scope, query, fields and active selections. Never describe a subset as the complete requested result.',
		);
	}
	/** Compile privately and re-inspect the complete candidate under the original scope. */
	public function compile( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, string $source, object $target, object $batch ): object {
		$this->supported(); $this->authority( $request, $current, $target ); $this->accepted( $current );
		if ( ! hash_equals( $current->version()->source_hash(), hash( 'sha256', $source ) ) ) { throw new ContractException( 'stale_version', 'The native source changed before saved-variant preparation.' ); }
		$native = $this->compiler->compile_saved_variant_controls( $source, $current->graph(), $target, $batch );
		if ( ! $native instanceof \stdClass || ! is_string( $native->content ?? null ) || strlen( $native->content ) > 1048576 || ! is_string( $native->content_hash ?? null ) || ! hash_equals( hash( 'sha256', $native->content ), $native->content_hash ) || ! is_int( $native->block_count ?? null ) || $native->block_count < 1 || $native->block_count > 24 || $this->native_loop()[1] !== ( $native->root_name ?? null ) || ! is_string( $native->dependency_revision ?? null ) ) { throw new ContractException( 'native_invalid', 'The native transaction returned no complete verified candidate.' ); }
		if ( 'elementor' === $this->reader->builder() ) {
			$bundle = json_decode( $source ); $bundle->elementor_data = $native->content;
			$candidate_source = wp_json_encode( $bundle, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		} else { $candidate_source = $native->content; }
		$candidate = $this->reader->inspect_candidate_source( $request, $candidate_source ); $this->accepted( $candidate );
		if ( ! hash_equals( $candidate->version()->source_hash(), hash( 'sha256', $candidate_source ) ) ) { throw new ContractException( 'native_invalid', 'Fresh inspection did not verify the complete native transaction.' ); }
		$native->candidate_graph_hash = $candidate->graph_hash();
		$native->dependency_revision = hash( 'sha256', $native->dependency_revision . '|' . $candidate->version()->environment_hash() . '|' . $candidate->version()->directory_schema_hash() );
		$request->assert_environment_current(); return $native;
	}
	public function stage( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, object $input, object $change ): object {
		$context = $request->context(); $context->assert_execution_identity(); $capture = EditorWorkingCopy::editor_context( $request->document() );
		$keys = array_keys( get_object_vars( $change ) ); sort( $keys );
		if ( ! $capture || 'editor_candidate' !== ( $capture['output_mode'] ?? null ) || 'page' !== $request->document()->native_kind || 'private_page_draft' !== $input->intent || array( 'batch', 'op', 'scope', 'target' ) !== $keys || 'configure_saved_variants' !== $change->op || ! ( $change->target ?? null ) instanceof \stdClass || ! ( $change->batch ?? null ) instanceof \stdClass || ( new JsonCanonicalizer() )->encode( $change->scope ) !== ( new JsonCanonicalizer() )->encode( $request->scope() ) ) { throw new ContractException( 'scope_conflict', 'Prepare saved variants in the exact captured page/common-loop context.' ); }
		$source = $this->reader->read_saved_source( $request, $current ); $native = $this->compile( $request, $current, $source, $change->target, $change->batch );
		$source_operation = 'builder-variants-source-' . substr( hash( 'sha256', $context->run_id() . '|' . $input->request_id ), 0, 32 );
		$source_ref = ( new BuilderNativeSourceCaptureService( $this->db ) )->capture( $this->reader, $request, $current, $source_operation );
		$run = $this->db->rows( 'SELECT r.id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.active_run_id=r.id AND r.state=%s AND c.erasure_requested=0 AND r.cancel_requested=0', array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference(), 'running' ) );
		if ( 1 !== count( $run ) ) { throw new ContractException( 'unauthorized', 'This transaction requires its active owner run.' ); }
		$operation = 'builder-stage-' . substr( hash( 'sha256', $context->run_id() . '|saved-variants|' . $input->request_id ), 0, 32 );
		$payload = (object) array( 'kind' => 'builder_draft_stage', 'intent' => 'private_page_draft', 'document_key' => $current->document_key(), 'source_page_id' => (int) substr( $request->document()->native_id, 5 ), 'source_template_ref' => null, 'source_document' => $request->document(), 'source_scope' => $request->scope(), 'source_ref' => $source_ref, 'source_operation_id' => $source_operation, 'source_hash' => hash( 'sha256', $source ), 'graph_version_hash' => $current->version()->hash(), 'graph_hash' => $current->graph_hash(), 'conversation_id' => $context->conversation_id(), 'run_id' => $context->run_id(), 'native_content' => $native->content, 'native_content_hash' => $native->content_hash, 'dependency_revision' => $native->dependency_revision, 'block_count' => $native->block_count, 'root_name' => $native->root_name, 'output_mode' => 'editor_candidate', 'original_document' => $capture['document'], 'capture_id' => $capture['capture_id'], 'captured_snapshot_hash' => $capture['captured_snapshot_hash'], 'candidate_id' => $operation, 'saved' => false, 'native_saved_variants' => (object) array( 'revision' => self::REVISION, 'target' => $change->target, 'batch' => $change->batch, 'candidate_graph_hash' => $native->candidate_graph_hash ) );
		EditorWorkingCopy::assert_candidate_source( $payload );
		$artifact = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'builder_draft', $payload, $current->version()->environment_hash() );
		$plan = new EffectPlan( $operation, $context->actor()->owner()->reference(), 'builder.stage', '1', $artifact['content_hash'], array( new EffectTarget( 'builder-draft:' . hash( 'sha256', $current->document_key() ), $payload->source_hash, $native->content_hash ) ), (int) $run[0]['id'] );
		$impact = (object) array( 'kind' => 'builder_draft_stage', 'intent' => $payload->intent, 'document_key' => $payload->document_key, 'source_document' => $payload->source_document, 'scope' => $request->scope(), 'source_hash' => $payload->source_hash, 'candidate_hash' => $native->content_hash, 'output_mode' => 'editor_candidate', 'original_document' => $payload->original_document, 'capture_id' => $payload->capture_id, 'captured_snapshot_hash' => $payload->captured_snapshot_hash, 'candidate_id' => $operation, 'saved' => false, 'block_count' => $native->block_count, 'root_name' => $native->root_name, 'changes' => array( (object) array( 'operation' => 'configure_saved_variants', 'target' => $change->target, 'batch' => $change->batch ) ) );
		$approvals = new ApprovalService( $this->db ); $review = $approvals->create_staged( $context->actor(), new ApprovalSpec( $plan, $artifact['public_id'], (int) $artifact['version'], $artifact['content_hash'], $current->version()->environment_hash(), $impact, (object) array( 'max_drafts' => 0, 'max_blocks' => 80, 'max_variants' => 24 ) ) );
		try { $this->verify( $context, $payload ); } catch ( \Throwable $error ) { $approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] ); throw $error; }
		$review = $approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		return (object) array( 'operation_id' => $operation, 'artifact' => (object) array( 'id' => $artifact['public_id'], 'version' => (int) $artifact['version'], 'hash' => $artifact['content_hash'] ), 'review' => (object) array( 'id' => $review['id'], 'state' => $review['state'], 'review_hash' => $review['review_hash'] ), 'builder' => $request->document()->builder, 'document_key' => $current->document_key(), 'intent' => $input->intent, 'native_content_hash' => $native->content_hash, 'block_count' => $native->block_count, 'output_mode' => 'editor_candidate' );
	}
	/** Exact cold verification at activation and approved effect execution. */
	public function verify( Context $context, object $stage ): object {
		$proof = $stage->native_saved_variants ?? null;
		if ( ! $proof instanceof \stdClass || self::REVISION !== ( $proof->revision ?? null ) || 'editor_candidate' !== ( $stage->output_mode ?? null ) || 'private_page_draft' !== ( $stage->intent ?? null ) || $stage->conversation_id !== $context->conversation_id() || $stage->run_id !== $context->run_id() ) { throw new ContractException( 'approval_mismatch', 'The saved-variant transaction belongs to another request.' ); }
		EditorWorkingCopy::assert_candidate_source( $stage ); $scope = $stage->source_scope; $facts = null === $scope->directory_id ? null : $this->directories->read( $context, (int) $scope->directory_id );
		$request = new BuilderGraphInspectionRequest( $context, $stage->source_document, $scope, array(), $facts, null, $this->environment ); $current = $this->reader->inspect( $request ); $request->assert_result( $current );
		if ( $current->document_key() !== $stage->document_key || ! hash_equals( $current->version()->hash(), $stage->graph_version_hash ) || ! hash_equals( $current->graph_hash(), $stage->graph_hash ) ) { throw new ContractException( 'stale_version', 'The saved native source changed after transaction review.' ); }
		$source = ( new BuilderNativeSourceCaptureService( $this->db ) )->verify_reviewed( $this->reader, $request, $current, $stage->source_ref, $stage->source_operation_id, $stage->conversation_id, $stage->run_id );
		$native = $this->compile( $request, $current, $source, $proof->target, $proof->batch );
		if ( ! hash_equals( $stage->native_content_hash, $native->content_hash ) || ! hash_equals( $stage->native_content, $native->content ) || ! hash_equals( $stage->dependency_revision, $native->dependency_revision ) || ! hash_equals( $proof->candidate_graph_hash, $native->candidate_graph_hash ) ) { throw new ContractException( 'stale_version', 'Saved variant controls, facts or native normalization changed after review.' ); }
		return (object) array( 'payload' => $stage, 'source' => $source, 'graph' => $current->graph(), 'changes' => array(), 'native_content_hash' => $native->content_hash, 'dependency_revision' => $native->dependency_revision, 'generation' => 0 );
	}
}
