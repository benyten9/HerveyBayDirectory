<?php
/**
 * Closed native directory query configuration with fresh native proof.
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

/** Model input selects native query settings, never source bytes or arbitrary attributes. */
final class BuilderDirectoryQueryService {
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

	/** Query edits use a signed current canvas, including only the native Home template family. */
	public static function editor_query_context( object $document ): ?array {
		$capture = EditorWorkingCopy::editor_context( $document );
		if ( null === $capture || 'editor_candidate' !== ( $capture['output_mode'] ?? null ) ) {
			return null;
		}
		if ( 'page' === ( $document->native_kind ?? null ) ) {
			return $capture;
		}
		$original = $capture['document'] ?? null;
		if ( 'gutenberg' !== ( $document->builder ?? null ) || 'template:home_search' !== ( $document->native_kind ?? null ) || ! $original instanceof \stdClass || 'gutenberg' !== ( $original->builder ?? null ) || 'template:home_search' !== ( $original->native_kind ?? null ) || ! preg_match( '/^template:wp_template:([A-Za-z0-9_.\/-]+):directorist-ai-working-[a-f0-9]{24}$/D', $document->native_id ?? '', $match ) || get_stylesheet() !== $match[1] || ! is_int( $capture['post_id'] ?? null ) || 'wp_template' !== get_post_type( $capture['post_id'] ) || EditorWorkingCopy::INTERNAL_STATUS !== get_post_status( $capture['post_id'] ) || ! current_user_can( 'edit_post', $capture['post_id'] ) || ! current_user_can( 'edit_theme_options' ) ) {
			return null;
		}
		$template = GutenbergTemplateSource::read( $document->native_id );
		if ( ! $template instanceof \WP_Block_Template || (int) $template->wp_id !== $capture['post_id'] || ! GutenbergEditorSnapshotBinding::matches_saved_document( $document->native_id, 'template:home_search', null, null ) || ! GutenbergEditorSnapshotBinding::matches_saved_document( $original->native_id, 'template:home_search', null, null ) ) {
			return null;
		}
		foreach ( array( $document, $original ) as $native_document ) {
			$family = apply_filters( 'directorist_ai_pro_gutenberg_native_template_context', null, $native_document->native_id );
			if ( ! is_array( $family ) || 'template:home_search' !== ( $family['native_kind'] ?? null ) || ! array_key_exists( 'directory_id', $family ) || null !== $family['directory_id'] ) {
				return null;
			}
		}
		return $capture;
	}

	public static function configuration_schema( bool $existing_loop = false ): array {
		$id = array(
			'type'    => 'string',
			'pattern' => '^[1-9][0-9]{0,9}$',
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'directory_ids'        => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 8,
					'uniqueItems' => true,
					'items'       => $id,
				),
				'default_directory_id' => $id,
				'active_directory_id'  => $id,
				'view'                 => array( 'enum' => $existing_loop ? array( 'grid', 'list', 'map' ) : array( 'grid', 'list' ) ),
				'show_switcher'        => array(
					'type'        => 'boolean',
					'description' => 'Show directory tabs so visitors can switch between directory_ids. Independent of the Grid/List view switcher. Preserve requested tabs during refinements.',
				),
			),
			'required'             => array( 'directory_ids', 'default_directory_id', 'active_directory_id', 'view', 'show_switcher' ),
			'additionalProperties' => false,
		);
	}

	public static function assert_configuration( object $config, bool $existing_loop = false ): void {
		$keys = array_keys( get_object_vars( $config ) );
		sort( $keys );
		if ( array( 'active_directory_id', 'default_directory_id', 'directory_ids', 'show_switcher', 'view' ) !== $keys || ! is_array( $config->directory_ids ) || ! $config->directory_ids || count( $config->directory_ids ) > 8 || count( array_unique( $config->directory_ids, SORT_STRING ) ) !== count( $config->directory_ids ) || ! is_bool( $config->show_switcher ) || ! in_array( $config->view, $existing_loop ? array( 'grid', 'list', 'map' ) : array( 'grid', 'list' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Use one complete native directory query configuration.' );
		}
		foreach ( $config->directory_ids as$id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) ) {
				throw new ContractException( 'invalid_input', 'Directory query IDs must be current native directory identifiers.' );
			}
		}
		if ( ! in_array( $config->default_directory_id, $config->directory_ids, true ) || ! in_array( $config->active_directory_id, $config->directory_ids, true ) ) {
			throw new ContractException( 'scope_conflict', 'Default and active directories must belong to this loop.' );
		}
	}

	/** The adapter proves native global defaults; model input never supplies this certificate. */
	public static function assert_home_certificate( $certificate, object $document, string $source_hash, object $target, object $configuration ): void {
		$keys = $certificate instanceof \stdClass ? array_keys( get_object_vars( $certificate ) ) : array();
		sort( $keys, SORT_STRING );
		$canonical = new JsonCanonicalizer();
		if ( array( 'composition_hash', 'configuration_hash', 'directory_ids', 'directory_inventory_hash', 'document', 'revision', 'source_hash', 'target_hash' ) !== $keys || 'gutenberg-home-query-v1' !== $certificate->revision || 'gutenberg' !== ( $document->builder ?? null ) || 'template:home_search' !== ( $document->native_kind ?? null ) || ! ( $certificate->document ?? null ) instanceof \stdClass || $canonical->encode( $document ) !== $canonical->encode( $certificate->document ) || ! is_array( $certificate->directory_ids ) || ! $certificate->directory_ids || count( $certificate->directory_ids ) > 8 || array_keys( $certificate->directory_ids ) !== range( 0, count( $certificate->directory_ids ) - 1 ) || count( array_unique( $certificate->directory_ids, SORT_STRING ) ) !== count( $certificate->directory_ids ) ) {
			throw new ContractException( 'native_invalid', 'The native Home query requires its exact current template certificate.' );
		}
		foreach ( array( 'source_hash', 'target_hash', 'configuration_hash', 'directory_inventory_hash', 'composition_hash' ) as $key ) {
			if ( ! is_string( $certificate->$key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $certificate->$key ) ) {
				throw new ContractException( 'native_invalid', 'The native Home query certificate has an invalid revision proof.' );
			}
		}
		foreach ( $certificate->directory_ids as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) ) {
				throw new ContractException( 'native_invalid', 'The native Home query directory inventory is invalid.' );
			}
		}
		self::assert_configuration( $configuration, true );
		if ( ! hash_equals( $source_hash, $certificate->source_hash ) || ! hash_equals( hash( 'sha256', $canonical->encode( $target ) ), $certificate->target_hash ) || ! hash_equals( hash( 'sha256', $canonical->encode( $configuration ) ), $certificate->configuration_hash ) || array_diff( $configuration->directory_ids, $certificate->directory_ids ) ) {
			throw new ContractException( 'stale_version', 'The native Home query certificate belongs to another source, loop or directory configuration.' );
		}
	}

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
			throw new ContractException( 'stale_version', 'Inspect the exact native loop before configuring its directories.' );
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
		if ( ! is_callable( array( $this->compiler, 'compile_directory_query' ) ) || ! is_callable( array( $this->compiler, 'directory_query_contract_for_graph' ) ) || ! is_callable( array( $this->reader, 'inspect_candidate_source' ) ) ) {
			throw new ContractException( 'unsupported_dependency', 'This native builder has no verified directory query configurator.' );
		}
	}

	/** Compile and fully re-inspect without saving either the source or candidate. */
	public function compile( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, string $source, object $target, object $before, object $after, bool $initial = false ): object {
		$this->supported();
		$home_template = 'page' !== $request->document()->native_kind;
		if ( $home_template && null === self::editor_query_context( $request->document() ) ) {
			throw new ContractException( 'unsupported_operation', 'Native template query configuration requires the signed Home template editor.' );
		}
		self::assert_configuration( $before, true );
		self::assert_configuration( $after, true );
		$this->accepted( $current );
		if ( ! hash_equals( $current->version()->source_hash(), hash( 'sha256', $source ) ) ) {
			throw new ContractException( 'stale_version', 'Native query source changed before preparation.' );
		}
		$node = $this->target( $current->graph(), $target );
		if ( $home_template && ( 'directorist-gb-integration' !== $node->provider_id || 'directorist-gb-integration/homepage-search-loop' !== $node->native_id ) ) {
			throw new ContractException( 'scope_conflict', 'The native Home template query target must be its existing Homepage Search Loop.' );
		}
		BuilderEditorSelection::assert_changes(
			BuilderConversationTarget::active_context( $this->db, $request->context() ),
			$current->graph(),
			array(
				(object) array(
					'op'        => 'configure_directory_query',
					'target_id' => $node->id,
				),
			)
		);
		$catalog = $this->compiler->directory_query_contract_for_graph( $current->graph() );
		$targets = $catalog->targets ?? $catalog->loops ?? array();
		$match = array();
		foreach ( $targets as$item ) {
			if ( $this->canonical->encode( $item->target ?? null ) === $this->canonical->encode( $target ) ) {
				$match[] = $item;
			}
		}
		if ( count( $match ) !== 1 || $this->canonical->encode( $match[0]->configuration ?? $match[0]->before ?? null ) !== $this->canonical->encode( $before ) ) {
			throw new ContractException( 'stale_version', 'This loop query changed. Inspect it again before preparing a change.' );
		}
		$certificate = $home_template ? ( $match[0]->native_certificate ?? null ) : null;
		if ( $home_template ) {
			self::assert_home_certificate( $certificate, $request->document(), $current->version()->source_hash(), $target, $before );
			if ( array_diff( $after->directory_ids, $certificate->directory_ids ) ) {
				throw new ContractException( 'scope_conflict', 'The requested Home query directory is outside its current native inventory.' );
			}
		}
		$facts = $this->facts( $request->context(), array_merge( $before->directory_ids, $after->directory_ids, $certificate->directory_ids ?? array() ) );
		$method = $initial && is_callable( array( $this->compiler, 'compile_initial_directory_query' ) ) ? 'compile_initial_directory_query' : 'compile_directory_query';
		$native = $this->compiler->{$method}( $source, $current->graph(), $target, $before, $after, $facts );
		if ( ! $native instanceof \stdClass || ! is_string( $native->content ?? null ) || strlen( $native->content ) > 1048576 || ! is_string( $native->content_hash ?? null ) || ! hash_equals( hash( 'sha256', $native->content ), $native->content_hash ) || ! is_int( $native->block_count ?? null ) || $native->block_count < 1 || $native->block_count > 80 || ! is_string( $native->root_name ?? null ) ) {
			throw new ContractException( 'native_invalid', 'The native query adapter returned no complete verified layout.' );
		}
		foreach ( array(
			'configuration_before' => $before,
			'configuration_after'  => $after,
		)as$key => $expected ) {
			if ( $this->canonical->encode( $native->$key ?? null ) !== $this->canonical->encode( $expected ) ) {
				throw new ContractException( 'native_invalid', 'The native query result does not match the reviewed configuration.' );
			}
		}
		if ( $home_template && $this->canonical->encode( $certificate ) !== $this->canonical->encode( $native->native_certificate ?? null ) ) {
			throw new ContractException( 'native_invalid', 'The native Home query compiler did not preserve its exact fresh certificate.' );
		}
		$scope = clone$request->scope();
		$scope->directory_id = $after->active_directory_id;
		$scope->view = null;
		$candidate_request = new BuilderGraphInspectionRequest( $request->context(), $request->document(), $scope, array(), $facts[ $after->active_directory_id ], null, $this->environment );
		$candidate_source = $this->candidate_source( $source, $native->content );
		$candidate = $this->reader->inspect_candidate_source( $candidate_request, $candidate_source );
		$this->accepted( $candidate );
		if ( ! hash_equals( $candidate->version()->source_hash(), hash( 'sha256', $candidate_source ) ) ) {
			throw new ContractException( 'native_invalid', 'The native candidate reader did not verify these exact bytes.' );
		}
		$native->query_fact_revision = hash( 'sha256', $this->canonical->encode( $facts ) );
		$native->query_directory_ids = array_map( 'intval', array_keys( $facts ) );
		$native->query_graph_hash = $candidate->graph_hash();
		$certificate_revision = $home_template ? '|' . hash( 'sha256', $this->canonical->encode( $certificate ) ) : '';
		$native->compilation_dependency_revision = $native->dependency_revision ?? null;
		$native->dependency_revision = hash( 'sha256', $native->query_fact_revision . '|' . $candidate->version()->environment_hash() . '|' . ( $native->dependency_revision ?? '' ) . $certificate_revision );
		$request->assert_environment_current();
		return $native;
	}



	/** Expand one NEW Gutenberg carrier against all explicit target-directory facts. */
	public function expand_initial_changes( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, array $changes, object $configuration ): array {
		$document = $request->document();
		if ( 'gutenberg' !== ( $document->builder ?? null ) || 'page' !== ( $document->native_kind ?? null ) || ! is_callable( array( $this->compiler, 'expand_initial_directory_changes' ) ) || ! is_callable( array( $this->compiler, 'native_preset_view_for_changes' ) ) ) {
			throw new ContractException( 'unsupported_operation', 'Initial directory role expansion requires its native ordinary-page constructor.' );
		}
		self::assert_configuration( $configuration );
		$this->supported();
		$source = $this->reader->read_saved_source( $request, $current );
		$graph = $current->graph();
		self::assert_initial_layout_graph( $graph, $current->graph_hash(), $current->version(), $document, $source );
		$this->accepted( $current );
		$request->assert_environment_current();
		$facts = $this->facts( $request->context(), $configuration->directory_ids );
		self::assert_initial_layout_facts( $configuration, $facts );
		$request->assert_environment_current();
		$before = $this->canonical->digest( array( $graph, $changes, $configuration, array_values( $facts ) ) );
		$expanded = $this->compiler->expand_initial_directory_changes( $graph, $changes, $configuration, $facts );
		if ( ! hash_equals( $before, $this->canonical->digest( array( $graph, $changes, $configuration, array_values( $facts ) ) ) ) || 'grid' !== $this->compiler->native_preset_view_for_changes( $graph, $expanded ) ) {
			throw new ContractException( 'scope_conflict', 'Initial directory role expansion changed its source or lacks the original exact native grid certificate.' );
		}
		$request->assert_environment_current();
		return $expanded;
	}

	/**
	 * Compile a fresh initial loop with its explicit validated directory membership.
	 *
	 * The saved source graph and normalized insertion keep their original scope.
	 * The optional native hook uses these separately read facts only while building
	 * NEW loop defaults; ordinary saved-element compilation is unchanged.
	 */
	public function compile_initial_layout( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, string $source, object $graph, array $changes, object $configuration, ?string $certified_view = null ): object {
		self::assert_initial_layout_graph( $graph, $current->graph_hash(), $current->version(), $request->document(), $source );
		if ( ! is_callable( array( $this->compiler, 'compile_initial_layout' ) ) ) {
			if ( 'elementor' === $request->document()->builder ) {
				throw new ContractException( 'unsupported_dependency', 'The native initial directory construction hook is unavailable.' );
			}
			// Other native adapters retain their existing compile-then-amend path.
			return $this->compiler->compile( $source, $graph, $changes );
		}
		$this->supported();
		if ( $this->reader->builder() !== $this->compiler->builder() ) {
			throw new ContractException( 'unsupported_dependency', 'Initial directory construction requires matching native reader and compiler.' );
		}
		self::assert_initial_layout_input( $request->document(), $graph->document, $current->version()->source_hash(), $source, $configuration, $certified_view, $this->compiler->builder() );
		$this->accepted( $current );
		$request->assert_environment_current();
		$facts = $this->facts( $request->context(), $configuration->directory_ids );
		self::assert_initial_layout_facts( $configuration, $facts );
		$request->assert_environment_current();
		$native = $this->compiler->compile_initial_layout( $source, $graph, $changes, $configuration, $facts, $certified_view );
		$request->assert_environment_current();
		return $native;
	}


	/** Retain the exact freshly revalidated graph object at both native fences. */
	private static function assert_initial_layout_graph( object $graph, string $graph_hash, GraphVersion $version, object $document, string $source ): void {
		$canonical = new JsonCanonicalizer();
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $graph_hash ) || ! hash_equals( $graph_hash, $canonical->digest( $graph ) ) || $canonical->encode( $graph->version ?? null ) !== $canonical->encode( $version->value() ) || $canonical->encode( $graph->document ?? null ) !== $canonical->encode( $document ) || ! hash_equals( $version->source_hash(), hash( 'sha256', $source ) ) ) {
			throw new ContractException( 'stale_version', 'Initial layout compilation requires its exact freshly revalidated source graph.' );
		}
	}

	/** Validate only a new ordinary-page query construction, never a source rebind. */
	private static function assert_initial_layout_input( object $document, object $graph_document, string $source_hash, string $source, object $configuration, ?string $certified_view, string $construction_builder = 'elementor' ): void {
		self::assert_configuration( $configuration );
		$canonical = new JsonCanonicalizer();
		if ( ! in_array( $construction_builder, array( 'elementor', 'gutenberg' ), true ) || ( $document->builder ?? null ) !== $construction_builder || 'page' !== ( $document->native_kind ?? null ) || $canonical->encode( $document ) !== $canonical->encode( $graph_document ) || ! in_array( $certified_view, array( 'grid', 'list' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $source_hash ) || ! hash_equals( $source_hash, hash( 'sha256', $source ) ) ) {
			throw new ContractException( 'scope_conflict', 'Initial directory construction requires its exact saved page, source and native view.' );
		}
	}

	/** Fresh actor-visible facts cover every named membership before construction. */
	private static function assert_initial_layout_facts( object $configuration, array $facts ): void {
		$ids = array_map( 'strval', array_keys( $facts ) );
		$requested = $configuration->directory_ids;
		sort( $ids, SORT_STRING );
		sort( $requested, SORT_STRING );
		if ( $ids !== $requested ) {
			throw new ContractException( 'scope_conflict', 'Initial directory construction needs exactly its named directory facts.' );
		}
		foreach ( $configuration->directory_ids as $id ) {
			$fact = $facts[ $id ] ?? null;
			if ( ! $fact instanceof \stdClass || (string) ( $fact->directory_id ?? '' ) !== $id || true !== ( $fact->complete ?? null ) || ! is_string( $fact->revision ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fact->revision ) || ! is_array( $fact->fields ?? null ) || ! is_array( $fact->field_ids ?? null ) ) {
				throw new ContractException( 'evidence_stale', 'Initial directory construction needs complete current public directory facts.' );
			}
		}
	}

	/** Amend only the newly generated native loop before any review/application. */
	public function amend_initial( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, object $native, object $configuration, ?string $certified_view = null ): object {
		$this->supported();
		if ( ! in_array( $certified_view, array( 'grid', 'list' ), true ) ) {
			throw new ContractException( 'scope_conflict', 'A shared directory layout requires a certified native initial-loop insertion.', array( 'initial_loop_recipe_required' ) );
		}
		self::assert_configuration( $configuration );
		$source = $this->reader->read_saved_source( $request, $current );
		$candidate_source = $this->candidate_source( $source, $native->content );
		$candidate = $this->reader->inspect_candidate_source( $request, $candidate_source );
		$this->accepted( $candidate );
		$key = fn( object $locator ): string=>$this->canonical->encode(
			(object) array(
				'native_id' => $locator->native_id,
				'slot_path' => $locator->slot_path,
			)
		);
		$old = $this->compiler->directory_query_contract_for_graph( $current->graph() );
		$old_keys = array();
		foreach ( $old->targets ?? $old->loops ?? array()as$item ) {
			$old_keys[ $key( $item->target ) ] = true;
		}
		$new = $this->compiler->directory_query_contract_for_graph( $candidate->graph() );
		$old_targets = $old->targets ?? $old->loops ?? array();
		$new_targets = $new->targets ?? $new->loops ?? array();
		if ( count( $new_targets ) !== count( $old_targets ) + 1 ) {
			throw new ContractException( 'scope_conflict', 'A shared directory layout must create exactly one new native loop.', array( 'initial_loop_recipe_required' ) );
		}
		foreach ( $old_targets as $retained ) {
			$matches = array_values( array_filter( $new_targets, fn( object $item ): bool => $key( $item->target ) === $key( $retained->target ) ) );
			if ( 1 !== count( $matches ) || $this->canonical->encode( $matches[0]->configuration ?? $matches[0]->before ?? null ) !== $this->canonical->encode( $retained->configuration ?? $retained->before ?? null ) ) {
				throw new ContractException( 'scope_conflict', 'Initial creation must preserve every existing native loop and its directory settings.' );
			}
		}
		$targets = array_values( array_filter( $new->targets ?? $new->loops ?? array(), fn( object $item ): bool=> ! isset( $old_keys[ $key( $item->target ) ] ) ) );
		if ( count( $targets ) !== 1 ) {
			throw new ContractException( 'scope_conflict', 'A shared directory layout needs one newly generated native loop.' );
		}
		$before = $targets[0]->configuration ?? $targets[0]->before;
		$result = $this->compile( $request, $candidate, $candidate_source, $targets[0]->target, $before, $configuration, true );
		$result->block_count = max( $native->block_count, $result->block_count );
		$result->prepared_reuse_eligible = null === ( $native->dependency_revision ?? null ) && null === ( $result->compilation_dependency_revision ?? null );
		return $result;
	}

	/** Prepare one exact current-canvas query review. No native post write occurs. */
	public function stage( BuilderGraphInspectionRequest $request, BuilderGraphResult $current, object $input, object $change ): object {
		$context = $request->context();
		$context->assert_execution_identity();
		$capture = self::editor_query_context( $request->document() );
		$home_template = 'page' !== $request->document()->native_kind;
		if ( null === $capture || ( $home_template ? 'private_template_draft' : 'private_page_draft' ) !== $input->intent ) {
			throw new ContractException( 'unsupported_operation', 'Configure directory queries in the signed native page or Home template editor.' );
		}
		$keys = array_keys( get_object_vars( $change ) );
		sort( $keys );
		if ( array( 'after', 'before', 'op', 'scope', 'target' ) !== $keys || 'configure_directory_query' !== $change->op || ! ( $change->before ?? null ) instanceof \stdClass || ! ( $change->after ?? null ) instanceof \stdClass || ! ( $change->target ?? null ) instanceof \stdClass || $this->canonical->encode( $change->scope ) !== $this->canonical->encode( $request->scope() ) ) {
			throw new ContractException( 'invalid_input', 'A directory query change requires its exact target, preimage and scope.' );
		}
		$source = $this->reader->read_saved_source( $request, $current );
		$native = $this->compile( $request, $current, $source, $change->target, $change->before, $change->after );
		$source_operation = 'builder-query-source-' . substr( hash( 'sha256', $context->run_id() . '|' . $input->request_id ), 0, 32 );
		$source_ref = ( new BuilderNativeSourceCaptureService( $this->db ) )->capture( $this->reader, $request, $current, $source_operation );
		$run = $this->db->rows( 'SELECT r.id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.active_run_id=r.id AND r.state=%s AND c.erasure_requested=0', array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference(), 'running' ) );
		if ( count( $run ) !== 1 ) {
			throw new ContractException( 'unauthorized', 'This directory query has no active owner run.' );
		}
		$operation = 'builder-stage-' . substr( hash( 'sha256', $context->run_id() . '|directory-query|' . $input->request_id ), 0, 32 );
		$page = $home_template ? null : (int) substr( $request->document()->native_id, 5 );
		$payload = (object) array(
			'kind'                   => 'builder_draft_stage',
			'intent'                 => $input->intent,
			'document_key'           => $current->document_key(),
			'source_page_id'         => $page,
			'source_template_ref'    => $home_template ? $request->document()->native_id : null,
			'source_document'        => $request->document(),
			'source_scope'           => $request->scope(),
			'source_ref'             => $source_ref,
			'source_operation_id'    => $source_operation,
			'source_hash'            => hash( 'sha256', $source ),
			'graph_version_hash'     => $current->version()->hash(),
			'graph_hash'             => $current->graph_hash(),
			'conversation_id'        => $context->conversation_id(),
			'run_id'                 => $context->run_id(),
			'native_content'         => $native->content,
			'native_content_hash'    => $native->content_hash,
			'dependency_revision'    => $native->dependency_revision,
			'block_count'            => $native->block_count,
			'root_name'              => $native->root_name,
			'output_mode'            => 'editor_candidate',
			'original_document'      => $capture['document'],
			'capture_id'             => $capture['capture_id'],
			'captured_snapshot_hash' => $capture['captured_snapshot_hash'],
			'candidate_id'           => $operation,
			'saved'                  => false,
			'native_directory_query' => (object) array(
				'revision'             => 'native-directory-query-v1',
				'target'               => $change->target,
				'before'               => $change->before,
				'after'                => $change->after,
				'fact_revision'        => $native->query_fact_revision,
				'candidate_graph_hash' => $native->query_graph_hash,
			),
		);
		if ( $home_template ) {
			$payload->native_directory_query->native_certificate = $native->native_certificate;
		}
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
							'operation' => 'configure_directory_query',
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
		$proof = $stage->native_directory_query ?? null;
		$home_template = 'template:home_search' === ( $stage->source_document->native_kind ?? null );
		if ( ! $proof instanceof \stdClass || ( $proof->revision ?? null ) !== 'native-directory-query-v1' || ( $stage->output_mode ?? null ) !== 'editor_candidate' || ( $stage->intent ?? null ) !== ( $home_template ? 'private_template_draft' : 'private_page_draft' ) || $stage->conversation_id !== $context->conversation_id() || $stage->run_id !== $context->run_id() ) {
			throw new ContractException( 'approval_mismatch', 'This native query proof belongs to another request.' );
		}
		EditorWorkingCopy::assert_candidate_source( $stage );
		if ( null === self::editor_query_context( $stage->source_document ) || ( $home_template && ( null !== ( $stage->source_page_id ?? null ) || ( $stage->source_template_ref ?? null ) !== $stage->source_document->native_id ) ) ) {
			throw new ContractException( 'approval_mismatch', 'This native query proof has no current signed source identity.' );
		}
		$scope = $stage->source_scope;
		$facts = null === $scope->directory_id ? null : $this->directories->read( $context, (int) $scope->directory_id );
		$request = new BuilderGraphInspectionRequest( $context, $stage->source_document, $scope, array(), $facts, null, $this->environment );
		$current = $this->reader->inspect( $request );
		$request->assert_result( $current );
		if ( $current->document_key() !== $stage->document_key || ! hash_equals( $current->version()->hash(), $stage->graph_version_hash ) || ! hash_equals( $current->graph_hash(), $stage->graph_hash ) ) {
			throw new ContractException( 'stale_version', 'The native query source graph changed after review.' );
		}
		$source = ( new BuilderNativeSourceCaptureService( $this->db ) )->verify_reviewed( $this->reader, $request, $current, $stage->source_ref, $stage->source_operation_id, $stage->conversation_id, $stage->run_id );
		$native = $this->compile( $request, $current, $source, $proof->target, $proof->before, $proof->after );
		if ( $home_template && $this->canonical->encode( $proof->native_certificate ?? null ) !== $this->canonical->encode( $native->native_certificate ?? null ) ) {
			throw new ContractException( 'stale_version', 'The native Home query source ownership or directory inventory changed after review.' );
		}
		if ( ! hash_equals( $stage->native_content_hash, $native->content_hash ) || ! hash_equals( $stage->native_content, $native->content ) || ! hash_equals( $proof->fact_revision, $native->query_fact_revision ) || ! hash_equals( $proof->candidate_graph_hash, $native->query_graph_hash ) || ! hash_equals( $stage->dependency_revision, $native->dependency_revision ) ) {
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
