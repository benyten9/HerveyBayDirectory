<?php
/**
 * Bind a private saved-source artifact to a published builder graph.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal saved-source checkpoint boundary.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Makes a complete native snapshot available to a later exact builder proposal. */
final class BuilderNativeCheckpointService {
	private Database $db;
	private GraphSnapshotStore $graphs;
	private BuilderNativeSourceCaptureService $sources;
	private ArtifactStore $artifacts;

	public function __construct( Database $db ) {
		$this->db        = $db;
		$this->graphs    = new GraphSnapshotStore( $db );
		$this->sources   = new BuilderNativeSourceCaptureService( $db );
		$this->artifacts = new ArtifactStore( $db );
	}

	/** Capture only a current complete saved graph under its active owning run. */
	public function capture( BuilderNativeSourceReader $reader, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, string $operation_id, bool $allow_terminal_rebind = false, ?BuilderPrivateCopyCoverage $copy = null ): object {
		$context = $request->context();
		$context->assert_execution_identity();
		$owner        = $context->actor()->owner();
		$version      = $expected->version();
		$document_key = $expected->document_key();
		if ( null !== $copy ) {
			$copy->assert_graph( $document_key, $version, $expected->graph() );
		}
		$current = $this->graphs->current( $owner, $document_key, $version, 2000, 5000, null === $copy );
		if ( ! hash_equals( $current->graph_hash, $expected->graph_hash() ) || 'complete' !== $expected->completeness() || ( 'complete' !== $expected->consumer_coverage() && null === $copy ) ) {
			throw new ContractException( 'stale_version', 'Published graph differs from the saved native source checkpoint.' );
		}
		$rebind = false;
		if ( null !== $current->native_artifact ) {
			$bound   = $this->artifacts->get( $owner, $current->native_artifact->id, $current->native_artifact->version );
			$payload = ( new JsonDecoder( 2097152, 24 ) )->decode( $bound['payload_json'] );
			if ( 'native_snapshot' !== $bound['kind'] || ! hash_equals( $bound['content_hash'], $current->native_artifact->hash ) || ! $payload instanceof \stdClass ) {
				throw new ContractException( 'request_conflict', 'Published graph belongs to another native source operation.' );
			}
			if ( ( $payload->operation_id ?? null ) !== $operation_id ) {
				$rebind = $allow_terminal_rebind && $this->terminal_source_run( $owner->reference(), $payload->run_id ?? null );
				if ( ! $rebind ) {
					throw new ContractException( 'request_conflict', 'Published graph belongs to another native source operation.' );
				}
			}
		}
		$reference = $this->sources->capture( $reader, $request, $expected, $operation_id, $copy );
		if ( null !== $current->native_artifact && ! $rebind ) {
			if ( $current->native_artifact->id !== $reference->id || $current->native_artifact->version !== $reference->version || ! hash_equals( $current->native_artifact->hash, $reference->hash ) ) {
				throw new ContractException( 'request_conflict', 'Published graph is bound to another native source checkpoint.' );
			}
			return $reference;
		}
		$fresh = $this->graphs->current( $owner, $document_key, $version, 2000, 5000, null === $copy );
		if ( $fresh->generation !== $current->generation || ! hash_equals( $fresh->graph_hash, $current->graph_hash ) || ( null === $current->native_artifact && null !== $fresh->native_artifact ) || ( null !== $current->native_artifact && ( null === $fresh->native_artifact || $current->native_artifact->id !== $fresh->native_artifact->id || $current->native_artifact->version !== $fresh->native_artifact->version || ! hash_equals( $current->native_artifact->hash, $fresh->native_artifact->hash ) ) ) ) {
			throw new ContractException( 'stale_version', 'Published graph changed during native source capture.' );
		}
		$attempt    = 'native-checkpoint-' . bin2hex( random_bytes( 12 ) );
		$generation = $this->graphs->begin( $owner, $document_key, $version, $attempt );
		try {
			if ( ! $this->graphs->stage( $owner, $document_key, $generation, $attempt, $version, $expected->graph(), $reference ) ) {
				throw new ContractException( 'stale_version', 'Native source checkpoint could not be staged.' );
			}
			$this->sources->verify( $reader, $request, $expected, $reference, $operation_id, $copy );
			if ( ! $this->graphs->publish( $owner, $document_key, $generation, $attempt, $version ) ) {
				throw new ContractException( 'stale_version', 'Native source checkpoint could not be published.' );
			}
			$this->sources->verify( $reader, $request, $expected, $reference, $operation_id, $copy );
			$published = $this->graphs->current( $owner, $document_key, $version, 2000, 5000, null === $copy );
			if ( ! hash_equals( $published->graph_hash, $expected->graph_hash() ) || null === $published->native_artifact || $published->native_artifact->id !== $reference->id || $published->native_artifact->version !== $reference->version || ! hash_equals( $published->native_artifact->hash, $reference->hash ) ) {
				throw new ContractException( 'evidence_stale', 'Published native source checkpoint differs from captured bytes.' );
			}
			return $reference;
		} catch ( \Throwable $error ) {
			$this->graphs->invalidate( $owner, $document_key, 'native_source_changed', $version );
			$this->graphs->fail( $owner, $document_key, $generation, $attempt, $error instanceof ContractException ? $error->error_code() : 'checkpoint_unavailable' );
			throw $error;
		}
	}

	/** A later run may replace only a finished run's source binding and settled effects. */
	private function terminal_source_run( string $owner_ref, ?string $run_id ): bool {
		if ( ! is_string( $run_id ) || '' === $run_id ) {
			return false;
		}
		$runs       = $this->db->table( 'runs' );
		$operations = $this->db->table( 'operations' );
		$rows       = $this->db->rows( "SELECT r.id FROM $runs r WHERE r.public_id=%s AND r.owner_ref=%s AND r.state IN ('succeeded','failed','partial','cancelled') AND NOT EXISTS (SELECT 1 FROM $operations o WHERE o.run_id=r.id AND o.state NOT IN ('succeeded','failed','cancelled')) LIMIT 1", array( $run_id, $owner_ref ) );
		return 1 === count( $rows );
	}
}
