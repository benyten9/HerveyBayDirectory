<?php
/**
 * Reuse an immutable, verified native candidate under fresh input fences.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\JsonCanonicalizer;

/** This receipt never grants source, planner, approval or write authority. */
final class BuilderPreparedNativeCandidate {
	/**
	 * Request-local numeric diagnostics, never model or write authority.
	 *
	 * @var array
	 */
	private static array $counts = array(
		'sealed' => 0,
		'reused' => 0,
		'seal_unavailable_revision' => 0,
		'seal_changed_revision' => 0,
		'seal_ineligible_output' => 0,
		'seal_changed_query_facts' => 0,
		'seal_unavailable_key' => 0,
		'reuse_invalid_receipt' => 0,
		'reuse_changed_inputs' => 0,
	);

	/** Return bounded numeric evidence for native lifecycle qualification. */
	public static function diagnostics(): array {
		return self::$counts;
	}

	/**
	 * Optional native certificate sampled on both sides of complete compilation.
	 *
	 * @param BuilderDraftCandidateCompiler $compiler Installed native compiler.
	 * @param object                        $graph Fresh validated semantic candidate.
	 */
	public static function revision( BuilderDraftCandidateCompiler $compiler, object $graph ): ?string {
		if ( ! is_callable( array( $compiler, 'prepared_candidate_revision' ) ) ) {
			return null;
		}
		$value = $compiler->prepared_candidate_revision( $graph );
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) ? ( new JsonCanonicalizer() )->digest( array( $value, PHP_VERSION_ID, ini_get( 'precision' ), ini_get( 'serialize_precision' ), ini_get( 'default_charset' ) ) ) : null;
	}

	/**
	 * Seal only a fully compiled ordinary editor candidate without media dependencies.
	 *
	 * @param BuilderDraftCandidateCompiler $compiler Installed native compiler.
	 * @param BuilderGraphInspectionRequest $request Fresh actor-bound native request.
	 * @param DirectoryFactsReader          $directories Current authorized directory facts.
	 * @param string                        $source Fresh exact native source bytes.
	 * @param object                        $graph Fresh validated semantic candidate.
	 * @param array                         $changes Validated normalized operations.
	 * @param object                        $stage Immutable server-owned stage payload.
	 * @param object                        $native Fully compiled and verified native output.
	 * @param string|null                   $before_revision Native certificate sampled before compilation.
	 */
	public static function seal( BuilderDraftCandidateCompiler $compiler, BuilderGraphInspectionRequest $request, DirectoryFactsReader $directories, string $source, object $graph, array $changes, object $stage, object $native, ?string $before_revision ): ?object {
		if ( null === $before_revision ) {
			++self::$counts['seal_unavailable_revision'];
			return null;
		}
		if ( ! hash_equals( $before_revision, self::revision( $compiler, $graph ) ?? '' ) ) {
			++self::$counts['seal_changed_revision'];
			return null;
		}
		if ( 'editor_candidate' !== ( $stage->output_mode ?? null ) || ( isset( $stage->initial_directory_query ) ? true !== ( $native->prepared_reuse_eligible ?? null ) : null !== ( $native->dependency_revision ?? null ) ) ) {
			++self::$counts['seal_ineligible_output'];
			return null;
		}
		$ids = $native->query_directory_ids ?? array();
		if ( ! is_array( $ids ) || count( $ids ) > 32 ) {
			++self::$counts['seal_ineligible_output'];
			return null;
		}
		if ( isset( $stage->initial_directory_query ) ) {
			$facts = array();
			foreach ( $ids as $id ) {
				$facts[ (string) $id ] = $directories->read( $request->context(), (int) $id );
			}
			if ( ! is_string( $native->query_fact_revision ?? null ) || ! hash_equals( $native->query_fact_revision, ( new JsonCanonicalizer() )->digest( $facts ) ) ) {
				++self::$counts['seal_changed_query_facts'];
				return null;
			}
		}
		$receipt = (object) array(
			'version'       => 1,
			'expires'       => time() + 900,
			'directory_ids' => $ids,
		);
		$key     = self::key( $compiler, $request, $directories, $source, $graph, $changes, $stage, $ids, $before_revision );
		if ( null === $key ) {
			++self::$counts['seal_unavailable_key'];
			return null;
		}
		$receipt->key = $key;
		$receipt->mac = self::mac( $receipt, $stage );
		++self::$counts['sealed'];
		return $receipt;
	}

	/**
	 * Caller must freshly validate source, planner, actor and approval before reuse.
	 *
	 * @param BuilderDraftCandidateCompiler $compiler Installed native compiler.
	 * @param BuilderGraphInspectionRequest $request Fresh actor-bound native request.
	 * @param DirectoryFactsReader          $directories Current authorized directory facts.
	 * @param string                        $source Fresh exact native source bytes.
	 * @param object                        $graph Fresh validated semantic candidate.
	 * @param array                         $changes Validated normalized operations.
	 * @param object                        $stage Immutable server-owned stage payload.
	 */
	public static function reuse( BuilderDraftCandidateCompiler $compiler, BuilderGraphInspectionRequest $request, DirectoryFactsReader $directories, string $source, object $graph, array $changes, object $stage ): ?object {
		$receipt = $stage->prepared_native_proof ?? null;
		if ( ! $receipt instanceof \stdClass || 1 !== ( $receipt->version ?? null ) || ! is_int( $receipt->expires ?? null ) || $receipt->expires < time() || $receipt->expires > time() + 900 || ! is_array( $receipt->directory_ids ?? null ) || count( $receipt->directory_ids ) > 32 || ! is_string( $receipt->key ?? null ) || ! is_string( $receipt->mac ?? null ) || ! hash_equals( self::mac( $receipt, $stage ), $receipt->mac ) || ! is_string( $stage->native_content ?? null ) || ! hash_equals( hash( 'sha256', $stage->native_content ), $stage->native_content_hash ?? '' ) ) {
			++self::$counts['reuse_invalid_receipt'];
			return null;
		}
		$key = self::key( $compiler, $request, $directories, $source, $graph, $changes, $stage, $receipt->directory_ids );
		if ( null === $key || ! hash_equals( $receipt->key, $key ) ) {
			++self::$counts['reuse_changed_inputs'];
			return null;
		}
		++self::$counts['reused'];
		return (object) array(
			'content'             => $stage->native_content,
			'content_hash'        => $stage->native_content_hash,
			'dependency_revision' => $stage->dependency_revision,
			'block_count'         => $stage->block_count,
			'root_name'           => $stage->root_name,
		);
	}

	/**
	 * Close over fresh native configuration in addition to the caller's graph fences.
	 *
	 * @param BuilderDraftCandidateCompiler $compiler Installed native compiler.
	 * @param BuilderGraphInspectionRequest $request Fresh actor-bound native request.
	 * @param DirectoryFactsReader          $directories Current authorized directory facts.
	 * @param string                        $source Fresh exact native source bytes.
	 * @param object                        $graph Fresh validated semantic candidate.
	 * @param array                         $changes Validated normalized operations.
	 * @param object                        $stage Immutable server-owned stage payload.
	 * @param array                         $ids Exact directories read by compilation.
	 * @param string|null                   $expected_revision Optional precompilation certificate.
	 */
	private static function key( BuilderDraftCandidateCompiler $compiler, BuilderGraphInspectionRequest $request, DirectoryFactsReader $directories, string $source, object $graph, array $changes, object $stage, array $ids, ?string $expected_revision = null ): ?string {
		if ( ! is_callable( array( $compiler, 'prepared_candidate_revision' ) ) || 'editor_candidate' !== ( $stage->output_mode ?? null ) ) {
			return null;
		}
		$request->context()->assert_execution_identity();
		$request->assert_environment_current();
		$canonical = new JsonCanonicalizer();
		$rules     = new SemanticRuleRegistry();
		do_action( 'directorist_ai_pro_register_builder_semantic_rules', $rules );
		$rules->freeze();
		$ids = array_merge( $ids, $stage->initial_directory_query->directory_ids ?? array(), null === $request->scope()->directory_id ? array() : array( (int) $request->scope()->directory_id ) );
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		if ( count( $ids ) > 32 || ( $ids && min( $ids ) < 1 ) ) {
			return null;
		}
		$facts = array();
		foreach ( $ids as $id ) {
			$facts[] = $directories->read( $request->context(), $id );
		}
		$revision = self::revision( $compiler, $graph );
		if ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) || ( null !== $expected_revision && ! hash_equals( $expected_revision, $revision ) ) ) {
			return null;
		}
		// Native configuration capture may itself execute callbacks. Reject a
		// directory change during that capture rather than sealing mixed inputs.
		$after = array();
		foreach ( $ids as $id ) {
			$after[] = $directories->read( $request->context(), $id );
		}
		if ( $canonical->digest( $facts ) !== $canonical->digest( $after ) ) {
			return null;
		}
		$request->context()->assert_execution_identity();
		$request->assert_environment_current();
		$sources = array();
		foreach ( array( __FILE__, __DIR__ . '/BuilderStyleIntent.php', __DIR__ . '/BuilderPrivateCopyCoverage.php', dirname( __DIR__ ) . '/ui/EditorWorkingCopy.php', dirname( __DIR__ ) . '/ui/EditorAssistant.php', __DIR__ . '/BuilderDirectoryQueryService.php', __DIR__ . '/BuilderDraftStagePreparationService.php', __DIR__ . '/BuilderDraftEffectHandler.php', __DIR__ . '/RelationshipValidator.php', __DIR__ . '/SemanticRuleRegistry.php', __DIR__ . '/BuilderChangePlanner.php' ) as $file ) {
			$hash = hash_file( 'sha256', $file );
			if ( false === $hash ) {
				return null;
			}
			$sources[ basename( $file ) ] = $hash;
		}
		return $canonical->digest(
			array(
				'site'         => get_current_blog_id(),
				'sources'      => $sources,
				'actor'        => $request->context()->actor()->owner()->reference(),
				'compiler'     => $compiler->builder(),
				'revision'     => $revision,
				'rules'        => $rules->revision(),
				'document'     => $request->document(),
				'scope'        => $request->scope(),
				'source'       => hash( 'sha256', $source ),
				'graph'        => $graph,
				'changes'      => $changes,
				'facts'        => $facts,
				'query'        => $stage->initial_directory_query ?? null,
				'view'         => $stage->initial_directory_query_view ?? null,
				'capture'      => $stage->capture_id ?? null,
				'snapshot'     => $stage->captured_snapshot_hash ?? null,
				'original'     => $stage->original_document ?? null,
				'source_ref'   => $stage->source_ref ?? null,
				'generation'   => $stage->graph_generation ?? null,
				'conversation' => $stage->conversation_id ?? null,
				'run'          => $stage->run_id ?? null,
			)
		);
	}

	/**
	 * Bind the already-reviewed output metadata as well as its input certificate.
	 *
	 * @param object $receipt Private authenticated reuse receipt.
	 * @param object $stage Immutable server-owned stage payload.
	 */
	private static function mac( object $receipt, object $stage ): string {
		$value = clone $receipt;
		unset( $value->mac );
		return hash_hmac( 'sha256', ( new JsonCanonicalizer() )->encode( array( $value, $stage->native_content_hash ?? null, $stage->dependency_revision ?? null, $stage->block_count ?? null, $stage->root_name ?? null ) ), wp_salt( 'auth' ) );
	}
}
