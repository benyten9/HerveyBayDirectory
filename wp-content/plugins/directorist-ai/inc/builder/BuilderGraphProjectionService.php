<?php
/**
 * Fresh native extraction to fenced P4 graph projection.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Projection invariants are documented inline.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Refreshes read-only projection state; it never invokes a native write handler. */
final class BuilderGraphProjectionService {
	private Database $db;
	private GraphSnapshotStore $graphs;
	private RelationshipValidator $relationships;

	public function __construct( Database $db, RelationshipValidator $relationships ) {
		$this->db            = $db;
		$this->graphs        = new GraphSnapshotStore( $db );
		$this->relationships = $relationships;
	}

	/** Extract, semantically validate, and publish only a complete accepted graph. */
	public function inspect( BuilderGraphProvider $provider, BuilderGraphInspectionRequest $request ): object {
		$result = $provider->inspect( $request );
		$request->assert_result( $result );
		$this->authority( $request, $result );
		$this->conversation_target( $request, $result );
		$graph       = $result->graph();
		$report      = $this->relationships->validate( $result->document_key(), $result->version(), $graph, $result->facts() );
		$copy        = BuilderPrivateCopyCoverage::for_saved_template( $request, $result );
		$copy_report = null === $copy ? null : $this->relationships->validate( $result->document_key(), $result->version(), $graph, $result->facts(), $copy );
		$owner       = $request->context()->actor()->owner();
		$document    = $request->document();
		$this->graphs->observe( $owner, $result->document_key(), $document->builder, $document->native_kind, $document->native_id, $result->version() );
		$projection = (object) array(
			'status'              => 'incomplete',
			'generation'          => null,
			'mutation_ready'      => false,
			'authoring_available' => false,
		);
		if ( 'complete' !== $graph->completeness || ( ! $report->accepted && ( null === $copy_report || ! $copy_report->accepted ) ) ) {
			return (object) array(
				'result'              => $result,
				'semantic_report'     => $report,
				'private_copy_report' => $copy_report,
				'projection'          => $projection,
			);
		}
		$mutation_input = $report->accepted && 'complete' === $graph->consumer_coverage;
		$current        = null;
		try {
			$current = $this->graphs->current( $owner, $result->document_key(), $result->version(), 2000, 5000, $mutation_input );
		} catch ( \Throwable $error ) {
			$current = null; }
		if ( null !== $current && hash_equals( $current->graph_hash, $result->graph_hash() ) ) {
			$this->fresh( $provider, $request, $result );
			$projection->status         = 'current';
			$projection->generation     = $current->generation;
			$projection->mutation_ready = false;
			return (object) array(
				'result'              => $result,
				'semantic_report'     => $report,
				'private_copy_report' => $copy_report,
				'projection'          => $projection,
			);
		}
		$attempt    = 'graph-' . bin2hex( random_bytes( 12 ) );
		$generation = $this->graphs->begin( $owner, $result->document_key(), $result->version(), $attempt );
		try {
			if ( ! $this->graphs->stage( $owner, $result->document_key(), $generation, $attempt, $result->version(), $graph, null ) ) {
				throw new ContractException( 'stale_version', 'Builder graph changed before projection publication.' );
			}
			do_action( 'directorist_ai_builder_graph_projection_staged', $result->document_key(), $generation );
			$this->fresh( $provider, $request, $result );
			if ( ! $this->graphs->publish( $owner, $result->document_key(), $generation, $attempt, $result->version() ) ) {
				throw new ContractException( 'stale_version', 'Builder graph changed before projection publication.' );
			}
			$this->fresh( $provider, $request, $result );
			$current = $this->graphs->current( $owner, $result->document_key(), $result->version(), 2000, 5000, $mutation_input );
			if ( ! hash_equals( $current->graph_hash, $result->graph_hash() ) ) {
				throw new ContractException( 'evidence_stale', 'Published builder graph differs from native extraction.' );
			}
			$projection->status         = 'published';
			$projection->generation     = $current->generation;
			$projection->mutation_ready = false;
			return (object) array(
				'result'              => $result,
				'semantic_report'     => $report,
				'private_copy_report' => $copy_report,
				'projection'          => $projection,
			);
		} catch ( \Throwable $error ) {
			$this->graphs->invalidate( $owner, $result->document_key(), 'native_source_changed', $result->version() );
			$this->graphs->fail( $owner, $result->document_key(), $generation, $attempt, $error instanceof ContractException ? $error->error_code() : 'graph_unavailable' );
			throw $error;
		}
	}

	/** Re-extract native/editor state and fence an obsolete head before returning. */
	private function fresh( BuilderGraphProvider $provider, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected ): void {
		try {
			$fresh = $provider->inspect( $request );
			$request->assert_result( $fresh );
			$this->authority( $request, $fresh );
			$this->conversation_target( $request, $fresh );
		} catch ( \Throwable $error ) {
			$this->graphs->invalidate( $request->context()->actor()->owner(), $expected->document_key(), 'native_source_changed', $expected->version() );
			throw new ContractException( 'stale_version', 'Native builder source changed during projection publication.' );
		}
		if ( ! hash_equals( $fresh->version()->hash(), $expected->version()->hash() ) || ! hash_equals( $fresh->graph_hash(), $expected->graph_hash() ) || ! hash_equals( $fresh->opaque_hash(), $expected->opaque_hash() ) || ! hash_equals( $fresh->facts()->revision(), $expected->facts()->revision() ) ) {
			$document = $request->document();
			if ( hash_equals( $fresh->version()->hash(), $expected->version()->hash() ) ) {
				$this->graphs->invalidate( $request->context()->actor()->owner(), $expected->document_key(), 'native_facts_changed', $expected->version() );
			} else {
				$this->graphs->observe( $request->context()->actor()->owner(), $fresh->document_key(), $document->builder, $document->native_kind, $document->native_id, $fresh->version() );
			}
			throw new ContractException( 'stale_version', 'Native builder source changed during projection publication.' );
		}
	}

	private function authority( BuilderGraphInspectionRequest $request, BuilderGraphResult $result ): void {
		if ( get_current_blog_id() !== $result->facts()->site_id() || $result->facts()->owner_ref() !== $request->context()->actor()->owner()->reference() ) {
			throw new ContractException( 'unauthorized', 'Builder graph facts belong to another site or owner.' );
		}
	}

	/** A model inspecting a bound conversation cannot spend context on another source or scope. */
	private function conversation_target( BuilderGraphInspectionRequest $request, BuilderGraphResult $result ): void {
		$json = BuilderConversationTarget::active_context( $this->db, $request->context() );
		if ( null === $json ) {
			return;
		}
		$binding = json_decode( $json );
		$scope   = $request->scope();
		if ( $binding instanceof \stdClass && BuilderCompoundCardStyle::narrow_read_scope( $binding, $result->graph(), $scope, $request->selection() ) ) {
			$scope = $binding->builder_scope; }
		$selection = $request->selection();
		if ( ! isset( $binding->builder_selection ) && ( $binding->builder_scope ?? null ) instanceof \stdClass && count( $selection ) === 1 && BuilderPageRedesign::narrow_scope( $result->graph(), $selection[0], $scope, $binding->builder_scope ) ) {
			$scope = $binding->builder_scope;
		}
		BuilderConversationTarget::assert_binding( $json, $result->document_key(), $request->document(), $scope );
	}
}
