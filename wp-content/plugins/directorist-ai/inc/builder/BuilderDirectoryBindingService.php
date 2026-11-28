<?php
/**
 * Closed native directory source binding with fresh native proof.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,Generic.Formatting.MultipleStatementAlignment -- Closed proof boundary; native adapters own serialization.
use DirectoristAIPro\Approvals\{ApprovalService, ApprovalSpec};
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\{EffectPlan, EffectTarget};
use DirectoristAIPro\Storage\{ArtifactStore, Database};
use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer, JsonDecoder};
use DirectoristAIPro\UI\EditorWorkingCopy;

/** Model input selects native binding settings, never source bytes or arbitrary attributes. */
final class BuilderDirectoryBindingService {
	private Database $db;
	private BuilderNativeSourceReader $reader;
	private BuilderDraftCandidateCompiler $compiler;
	private CatalogEnvironmentReader $environment;
	private DirectoryFactsReader $directories;
	private JsonCanonicalizer $canonical;

	public function __construct( Database $db, BuilderNativeSourceReader $reader, BuilderDraftCandidateCompiler $compiler, CatalogEnvironmentReader $environment, DirectoryFactsReader $directories ) {
		$this->db = $db;
		$this->reader = $reader;
		$this->compiler = $compiler;
		$this->environment = $environment;
		$this->directories = $directories;
		$this->canonical = new JsonCanonicalizer();
	}

	public static function configuration_schema( bool $before = false ): array {
		$id = array(
			'type'    => 'string',
			'pattern' => '^[1-9][0-9]{0,9}$',
		);
		$selection = array();
		foreach ( $before ? array( 'all', 'selected', 'unresolved' ) : array( 'all', 'selected' ) as $state ) {
			$selection[] = array(
				'type'                 => 'object',
				'properties'           => array(
					'state'         => array( 'enum' => array( $state ) ),
					'directory_ids' => array(
						'type'        => 'array',
						'minItems'    => 'selected' === $state ? 1 : 0,
						'maxItems'    => 'selected' === $state ? 8 : 0,
						'uniqueItems' => true,
						'items'       => $id,
					),
				),
				'required'             => array( 'state', 'directory_ids' ),
				'additionalProperties' => false,
			);
		}
		$default = array();
		foreach ( $before ? array( 'inherited', 'directory', 'unresolved' ) : array( 'inherited', 'directory' ) as $state ) {
			$default[] = array(
				'type'                 => 'object',
				'properties'           => array(
					'state'        => array( 'enum' => array( $state ) ),
					'directory_id' => 'directory' === $state ? $id : array( 'type' => 'null' ),
				),
				'required'             => array( 'state', 'directory_id' ),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'binding_kind' => array( 'enum' => array( 'taxonomy_directory_source' ) ),
				'selection'    => array( 'oneOf' => $selection ),
				'default'      => array( 'oneOf' => $default ),
			),
			'required'             => array( 'binding_kind', 'selection', 'default' ),
			'additionalProperties' => false,
		);
	}

	public static function assert_configuration( object $config, bool $before = false ): void {
		$keys = array_keys( get_object_vars( $config ) );
		sort( $keys );
		if ( array( 'binding_kind', 'default', 'selection' ) !== $keys || 'taxonomy_directory_source' !== $config->binding_kind || ! ( $config->selection ?? null ) instanceof \stdClass || ! ( $config->default ?? null ) instanceof \stdClass ) {
			throw new ContractException( 'invalid_input', 'Use one complete typed native directory binding.' ); }
		$s = $config->selection;
		$d = $config->default;
		$sk = array_keys( get_object_vars( $s ) );
		sort( $sk );
		$dk = array_keys( get_object_vars( $d ) );
		sort( $dk );
		if ( array( 'directory_ids', 'state' ) !== $sk || array( 'directory_id', 'state' ) !== $dk || ! in_array( $s->state, $before ? array( 'all', 'selected', 'unresolved' ) : array( 'all', 'selected' ), true ) || ! in_array( $d->state, $before ? array( 'inherited', 'directory', 'unresolved' ) : array( 'inherited', 'directory' ), true ) || ! is_array( $s->directory_ids ) || ! self::is_list( $s->directory_ids ) || count( $s->directory_ids ) > 8 || count( array_unique( $s->directory_ids, SORT_STRING ) ) !== count( $s->directory_ids ) || ( 'selected' === $s->state ) !== (bool) $s->directory_ids ) {
			throw new ContractException( 'invalid_input', 'The native All/selected/default binding state is invalid.' ); }
		foreach ( array_merge( $s->directory_ids, 'directory' === $d->state ? array( $d->directory_id ) : array() ) as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) || (string) (int) $id !== $id ) {
				throw new ContractException( 'invalid_input', 'Native binding IDs must identify existing directories.' ); }
		}
		if ( 'directory' !== $d->state && null !== $d->directory_id ) {
			throw new ContractException( 'invalid_input', 'An inherited binding has no explicit directory ID.' ); }
		if ( ! $before && 'selected' === $s->state && 'directory' === $d->state && ! in_array( $d->directory_id, $s->directory_ids, true ) ) {
			throw new ContractException( 'scope_conflict', 'The default directory must belong to the native selection.' ); }
	}

	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ); }

	private function facts( Context $context, array $ids ): array {
		$facts = array();
		foreach ( array_unique( $ids, SORT_STRING )as$id ) {
			$facts[ (string) $id ] = $this->directories->read( $context, (int) $id );
		}
		return $facts;
	}

	private function accepted( BuilderGraphResult $result ): void {
		$graph = $result->graph();
		$rules = new SemanticRuleRegistry();
		do_action( 'directorist_ai_pro_register_builder_semantic_rules', $rules );
		$rules->freeze();
		$report = ( new RelationshipValidator( $rules ) )->validate( $result->document_key(), $result->version(), $graph, $result->facts() );
		if ( 'complete' !== $graph->completeness || 'complete' !== $graph->consumer_coverage || ! $report->accepted ) {
			throw new ContractException( 'relationship_invalid', 'The complete native directory layout could not be verified.', array_column( $report->diagnostics, 'code' ) );
		}
	}

	private function target( object $graph, object $locator ): object {
		$matches = array_values( array_filter( $graph->nodes, fn( object $node ): bool=>$this->canonical->encode( $node->locator ) === $this->canonical->encode( $locator ) ) );
		if ( count( $matches ) !== 1 || null !== $matches[0]->opaque_ref ) {
			throw new ContractException( 'stale_version', 'Inspect the exact native module before configuring its directories.' );
		}
		return $matches[0];
	}

	private function candidate_source( string $source, string $content ): string {
		if ( 'elementor' !== $this->reader->builder() ) {
			return $content;
		}
		$bundle = ( new JsonDecoder( 2097152, 48 ) )->decode( $source );
		if ( ! $bundle instanceof \stdClass || ! is_string( $bundle->elementor_data ?? null ) ) {
			throw new ContractException( 'native_invalid', 'The native Elementor source bundle is unavailable.' );
		}
		$bundle->elementor_data = $content;
		return wp_json_encode( $bundle, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	private function supported(): void {
		if ( ! is_callable( array( $this->compiler, 'compile_directory_binding' ) ) || ! is_callable( array( $this->compiler, 'directory_binding_contract_for_graph' ) ) || ! is_callable( array( $this->compiler, 'directory_binding_directory_ids' ) ) || ! is_callable( array( $this->reader, 'inspect_candidate_source' ) ) ) {
			throw new ContractException( 'unsupported_dependency', 'This native builder has no verified directory binding configurator.' );
		}
	}

	/** Compile and fully re-inspect without saving either the source or candidate. */
	public function compile( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, string $source, object $target, object $before, object $after ): object {
		$this->supported();
		self::assert_configuration( $before, true );
		self::assert_configuration( $after );
		$this->accepted( $current );
		if ( ! hash_equals( $current->version()->source_hash(), hash( 'sha256', $source ) ) ) {
			throw new ContractException( 'stale_version', 'Native query source changed before preparation.' );
		}
		$node = $this->target( $current->graph(), $target );
		BuilderEditorSelection::assert_changes(
			BuilderConversationTarget::active_context( $this->db, $request->context() ),
			$current->graph(),
			array(
				(object) array(
					'op'        => 'configure_directory_binding',
					'target_id' => $node->id,
				),
			)
		);
		$catalog = $this->compiler->directory_binding_contract_for_graph( $current->graph() );
		$targets = $catalog->targets ?? $catalog->loops ?? array();
		$match = array();
		foreach ( $targets as$item ) {
			if ( $this->canonical->encode( $item->target ?? null ) === $this->canonical->encode( $target ) ) {
				$match[] = $item;
			}
		}
		if ( count( $match ) !== 1 || true !== ( $match[0]->can_configure ?? false ) || $this->canonical->encode( $match[0]->configuration ?? $match[0]->before ?? null ) !== $this->canonical->encode( $before ) ) {
			throw new ContractException( 'stale_version', 'This module binding changed. Inspect it again before preparing a change.' );
		}
		$ids = $this->compiler->directory_binding_directory_ids( $current->graph(), $target, $before, $after );
		$scope = clone $request->scope();
		if ( null !== $scope->directory_id ) {
			$ids[] = $scope->directory_id; }
		$ids = array_values( array_unique( $ids, SORT_STRING ) );
		if ( ! $ids || count( $ids ) > 16 ) {
			throw new ContractException( 'needs_expansion', 'The complete affected native directory set exceeds its existing bound.' ); }
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) || (string) (int) $id !== $id ) {
				throw new ContractException( 'native_invalid', 'The native binding returned an invalid directory identity.' ); }
		}
		$facts = $this->facts( $request->context(), $ids );
		$native = $this->compiler->compile_directory_binding( $source, $current->graph(), $target, $before, $after, $facts );
		if ( ! $native instanceof \stdClass || ! is_string( $native->content ?? null ) || strlen( $native->content ) > 1048576 || ! is_string( $native->content_hash ?? null ) || ! hash_equals( hash( 'sha256', $native->content ), $native->content_hash ) || ! is_int( $native->block_count ?? null ) || $native->block_count < 1 || $native->block_count > 80 || ! is_string( $native->root_name ?? null ) ) {
			throw new ContractException( 'native_invalid', 'The native binding adapter returned no complete verified layout.' );
		}
		foreach ( array(
			'configuration_before' => $before,
			'configuration_after'  => $after,
		)as$key => $expected ) {
			if ( $this->canonical->encode( $native->$key ?? null ) !== $this->canonical->encode( $expected ) ) {
				throw new ContractException( 'native_invalid', 'The native binding result does not match the reviewed configuration.' );
			}
		}
		// Query ownership and aggregate All do not relabel the saved design scope.
		$primary = null === $scope->directory_id ? null : $facts[ $scope->directory_id ];
		$candidate_request = new BuilderGraphInspectionRequest( $request->context(), $request->document(), $scope, array(), $primary, null, $this->environment );
		$candidate_source = $this->candidate_source( $source, $native->content );
		$candidate = $this->reader->inspect_candidate_source( $candidate_request, $candidate_source );
		$this->accepted( $candidate );
		$after_contract = $this->compiler->directory_binding_contract_for_graph( $candidate->graph() );
		$after_matches = array_values( array_filter( $after_contract->targets ?? array(), fn( object $item ): bool => $this->canonical->encode( $item->target->slot_path ?? null ) === $this->canonical->encode( $target->slot_path ?? null ) && ( $item->target->native_id ?? null ) === ( $target->native_id ?? null ) && $this->canonical->encode( $item->configuration ?? null ) === $this->canonical->encode( $after ) ) );
		if ( 1 !== count( $after_matches ) || $native->root_name !== $node->native_id ) {
			throw new ContractException( 'native_invalid', 'Fresh native inspection does not match the approved binding.' ); }
		if ( ! hash_equals( $candidate->version()->source_hash(), hash( 'sha256', $candidate_source ) ) ) {
			throw new ContractException( 'native_invalid', 'The native candidate reader did not verify these exact bytes.' );
		}
		$native->binding_fact_revision = hash( 'sha256', $this->canonical->encode( $facts ) );
		$native->binding_graph_hash = $candidate->graph_hash();
		$native->dependency_revision = hash( 'sha256', $native->binding_fact_revision . '|' . $candidate->version()->environment_hash() . '|' . ( $native->dependency_revision ?? '' ) );
		$request->assert_environment_current();
		return $native;
	}

	/** Prepare one exact current-page query review. No native post write occurs. */
	public function stage( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, object $input, object $change ): object {
		$context = $request->context();
		$context->assert_execution_identity();
		$capture = EditorWorkingCopy::editor_context( $request->document() );
		if ( ! $capture || 'editor_candidate' !== ( $capture['output_mode'] ?? null ) || 'page' !== $request->document()->native_kind || 'private_page_draft' !== $input->intent ) {
			throw new ContractException( 'unsupported_operation', 'Configure directory queries in the current native page editor.' );
		}
		$keys = array_keys( get_object_vars( $change ) );
		sort( $keys );
		if ( array( 'after', 'before', 'op', 'scope', 'target' ) !== $keys || 'configure_directory_binding' !== $change->op || ! ( $change->before ?? null ) instanceof \stdClass || ! ( $change->after ?? null ) instanceof \stdClass || ! ( $change->target ?? null ) instanceof \stdClass || $this->canonical->encode( $change->scope ) !== $this->canonical->encode( $request->scope() ) ) {
			throw new ContractException( 'invalid_input', 'A directory binding change requires its exact target, preimage and scope.' );
		}
		$source = $this->reader->read_saved_source( $request, $current );
		$native = $this->compile( $request, $current, $source, $change->target, $change->before, $change->after );
		$source_operation = 'builder-binding-source-' . substr( hash( 'sha256', $context->run_id() . '|' . $input->request_id ), 0, 32 );
		$source_ref = ( new BuilderNativeSourceCaptureService( $this->db ) )->capture( $this->reader, $request, $current, $source_operation );
		$run = $this->db->rows( 'SELECT r.id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.active_run_id=r.id AND r.state=%s AND c.erasure_requested=0', array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference(), 'running' ) );
		if ( count( $run ) !== 1 ) {
			throw new ContractException( 'unauthorized', 'This directory binding has no active owner run.' );
		}
		$operation = 'builder-stage-' . substr( hash( 'sha256', $context->run_id() . '|directory-binding|' . $input->request_id ), 0, 32 );
		$page = (int) substr( $request->document()->native_id, 5 );
		$payload = (object) array(
			'kind'                     => 'builder_draft_stage',
			'intent'                   => 'private_page_draft',
			'document_key'             => $current->document_key(),
			'source_page_id'           => $page,
			'source_template_ref'      => null,
			'source_document'          => $request->document(),
			'source_scope'             => $request->scope(),
			'source_ref'               => $source_ref,
			'source_operation_id'      => $source_operation,
			'source_hash'              => hash( 'sha256', $source ),
			'graph_version_hash'       => $current->version()->hash(),
			'graph_hash'               => $current->graph_hash(),
			'conversation_id'          => $context->conversation_id(),
			'run_id'                   => $context->run_id(),
			'native_content'           => $native->content,
			'native_content_hash'      => $native->content_hash,
			'dependency_revision'      => $native->dependency_revision,
			'block_count'              => $native->block_count,
			'root_name'                => $native->root_name,
			'output_mode'              => 'editor_candidate',
			'original_document'        => $capture['document'],
			'capture_id'               => $capture['capture_id'],
			'captured_snapshot_hash'   => $capture['captured_snapshot_hash'],
			'candidate_id'             => $operation,
			'saved'                    => false,
			'native_directory_binding' => (object) array(
				'revision'             => 'native-directory-binding-v1',
				'target'               => $change->target,
				'before'               => $change->before,
				'after'                => $change->after,
				'fact_revision'        => $native->binding_fact_revision,
				'candidate_graph_hash' => $native->binding_graph_hash,
			),
		);
		EditorWorkingCopy::assert_candidate_source( $payload );
		$artifact = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'builder_draft', $payload, $current->version()->environment_hash() );
		$plan = new EffectPlan( $operation, $context->actor()->owner()->reference(), 'builder.stage', '1', $artifact['content_hash'], array( new EffectTarget( 'builder-draft:' . hash( 'sha256', $current->document_key() ), $payload->source_hash, $native->content_hash ) ), (int) $run[0]['id'] );
		$approvals = new ApprovalService( $this->db );
		$review = $approvals->create_staged(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				(int) $artifact['version'],
				$artifact['content_hash'],
				$current->version()->environment_hash(),
				(object) array(
					'kind'                   => 'builder_draft_stage',
					'intent'                 => $payload->intent,
					'document_key'           => $payload->document_key,
					'source_document'        => $payload->source_document,
					'scope'                  => $request->scope(),
					'source_hash'            => $payload->source_hash,
					'candidate_hash'         => $native->content_hash,
					'output_mode'            => 'editor_candidate',
					'original_document'      => $payload->original_document,
					'capture_id'             => $payload->capture_id,
					'captured_snapshot_hash' => $payload->captured_snapshot_hash,
					'candidate_id'           => $operation,
					'saved'                  => false,
					'block_count'            => $native->block_count,
					'root_name'              => $native->root_name,
					'changes'                => array(
						(object) array(
							'operation' => 'configure_directory_binding',
							'before'    => $change->before,
							'after'     => $change->after,
						),
					),
				),
				(object) array(
					'max_drafts' => 0,
					'max_blocks' => 80,
				)
			)
		);
		try {
			$this->verify( $context, $payload );
		} catch ( \Throwable$error ) {
			$approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] );
			throw $error;}
		$review = $approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		return (object) array(
			'operation_id'        => $operation,
			'artifact'            => (object) array(
				'id'      => $artifact['public_id'],
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash'],
			),
			'review'              => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
			),
			'builder'             => $request->document()->builder,
			'document_key'        => $current->document_key(),
			'intent'              => $input->intent,
			'native_content_hash' => $native->content_hash,
			'block_count'         => $native->block_count,
			'output_mode'         => 'editor_candidate',
		);
	}

	/** Recompile exact approved settings against fresh source and directory schemas. */
	public function verify( Context $context, object $stage ): object {
		$proof = $stage->native_directory_binding ?? null;
		if ( ! $proof instanceof \stdClass || ( $proof->revision ?? null ) !== 'native-directory-binding-v1' || ( $stage->output_mode ?? null ) !== 'editor_candidate' || ( $stage->intent ?? null ) !== 'private_page_draft' || $stage->conversation_id !== $context->conversation_id() || $stage->run_id !== $context->run_id() ) {
			throw new ContractException( 'approval_mismatch', 'This native binding proof belongs to another request.' );
		}
		EditorWorkingCopy::assert_candidate_source( $stage );
		$scope = $stage->source_scope;
		$facts = null === $scope->directory_id ? null : $this->directories->read( $context, (int) $scope->directory_id );
		$request = new BuilderGraphInspectionRequest( $context, $stage->source_document, $scope, array(), $facts, null, $this->environment );
		$current = $this->reader->inspect( $request );
		$request->assert_result( $current );
		if ( $current->document_key() !== $stage->document_key || ! hash_equals( $current->version()->hash(), $stage->graph_version_hash ) || ! hash_equals( $current->graph_hash(), $stage->graph_hash ) ) {
			throw new ContractException( 'stale_version', 'The native binding source graph changed after review.' );
		}
		$source = ( new BuilderNativeSourceCaptureService( $this->db ) )->verify_reviewed( $this->reader, $request, $current, $stage->source_ref, $stage->source_operation_id, $stage->conversation_id, $stage->run_id );
		$native = $this->compile( $request, $current, $source, $proof->target, $proof->before, $proof->after );
		if ( ! hash_equals( $stage->native_content_hash, $native->content_hash ) || ! hash_equals( $stage->native_content, $native->content ) || ! hash_equals( $proof->fact_revision, $native->binding_fact_revision ) || ! hash_equals( $proof->candidate_graph_hash, $native->binding_graph_hash ) || ! hash_equals( $stage->dependency_revision, $native->dependency_revision ) ) {
			throw new ContractException( 'stale_version', 'Native directory configuration or field schemas changed after review.' );
		}
		return (object) array(
			'payload'             => $stage,
			'source'              => $source,
			'graph'               => $current->graph(),
			'changes'             => array(),
			'native_content_hash' => $native->content_hash,
			'dependency_revision' => $native->dependency_revision,
			'generation'          => 0,
		);
	}
}
