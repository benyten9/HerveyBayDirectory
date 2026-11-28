<?php
/**
 * Conversation-owned saved source capture for a future native draft effect.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal source capture boundary.

use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Persists native bytes privately; it does not grant authoring or invoke a write. */
final class BuilderNativeSourceCaptureService {
	private Database $db;
	private ArtifactStore $artifacts;

	public function __construct( Database $db ) {
		$this->db        = $db;
		$this->artifacts = new ArtifactStore( $db );
	}

	/** Capture one complete saved-source version under a server-bound workflow. */
	public function capture( BuilderNativeSourceReader $reader, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, string $operation_id, ?BuilderPrivateCopyCoverage $copy = null ): object {
		$context = $request->context();
		$context->assert_execution_identity();
		ConversationStore::identifier( $operation_id );
		$conversation_id = $context->conversation_id();
		$run_id          = $context->run_id();
		$graph           = $expected->graph();
		$version         = $expected->version();
		if ( $context->actor()->is_guest() || ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || null === $conversation_id || null === $run_id || null !== $request->editor() || $version->editor_bound() ) {
			throw new ContractException( 'unauthorized', 'Saved native source requires an authenticated draft workflow.' );
		}
		$builder = $reader->builder();
		if ( null !== $copy ) {
			$copy->assert_graph( $expected->document_key(), $version, $graph );
		}
		if ( ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || $builder !== $request->document()->builder || 'complete' !== $graph->completeness || ( 'complete' !== $graph->consumer_coverage && null === $copy ) || ! hash_equals( $version->environment_hash(), $request->environment_hash() ) ) {
			throw new ContractException( 'incomplete_graph', 'Native source capture requires a complete saved builder graph.' );
		}
		if ( ( new JsonCanonicalizer() )->encode( $graph->document ) !== ( new JsonCanonicalizer() )->encode( $request->document() ) || $expected->facts()->site_id() !== get_current_blog_id() || $expected->facts()->owner_ref() !== $context->actor()->owner()->reference() ) {
			throw new ContractException( 'unauthorized', 'Native source graph authority differs from the workflow.' );
		}
		$this->assert_active_run( $context->actor()->owner()->reference(), $conversation_id, $run_id, $expected->document_key(), $graph->document );
		$request->assert_result( $expected );
		$request->assert_environment_current();
		$content = $reader->read_saved_source( $request, $expected );
		if ( strlen( $content ) > 1048576 || ! hash_equals( $version->source_hash(), hash( 'sha256', $content ) ) ) {
			throw new ContractException( 'stale_version', 'Private native source differs from the selected graph version.' );
		}
		$identity = hash( 'sha256', get_current_blog_id() . '|' . $context->actor()->owner()->reference() . '|' . $conversation_id . '|' . $run_id . '|' . $operation_id . '|' . $expected->document_key() );
		$payload  = (object) array(
			'builder'        => $builder,
			'document_key'   => $expected->document_key(),
			'graph_hash'     => $expected->graph_hash(),
			'version_hash'   => $version->hash(),
			'source_hash'    => $version->source_hash(),
			'source_content' => $content,
			'operation_id'   => $operation_id,
			'run_id'         => $run_id,
		);
		$prefix   = array(
			'gutenberg' => 'gb-source-',
			'elementor' => 'el-source-',
			'divi'      => 'dv-source-',
		)[ $builder ];
		$artifact = $this->artifacts->put_once( $context->actor()->owner(), $conversation_id, $prefix . substr( $identity, 0, 32 ), 'native_snapshot', $payload, $version->environment_hash() );
		$after    = $reader->read_saved_source( $request, $expected );
		$this->assert_active_run( $context->actor()->owner()->reference(), $conversation_id, $run_id, $expected->document_key(), $graph->document );
		if ( ! hash_equals( $version->source_hash(), hash( 'sha256', $after ) ) || ! hash_equals( $content, $after ) ) {
			throw new ContractException( 'stale_version', 'Native source changed during private capture.' );
		}
		return (object) array(
			'id'      => $artifact['public_id'],
			'version' => (int) $artifact['version'],
			'hash'    => $artifact['content_hash'],
		);
	}

	/** Re-fence a private source artifact at an eventual native effect boundary. */
	public function verify( BuilderNativeSourceReader $reader, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, object $reference, string $operation_id, ?BuilderPrivateCopyCoverage $copy = null ): string {
		return $this->verify_reference( $reader, $request, $expected, $reference, $operation_id, $request->context()->conversation_id(), $request->context()->run_id(), true, $copy );
	}

	/** Re-fence owned source after a reviewed workflow run has finished. */
	public function verify_reviewed( BuilderNativeSourceReader $reader, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, object $reference, string $operation_id, string $conversation_id, string $run_id, ?BuilderPrivateCopyCoverage $copy = null ): string {
		$context = $request->context();
		$context->assert_execution_identity();
		if ( $context->actor()->is_guest() || ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || ( null !== $context->conversation_id() && ( $context->conversation_id() !== $conversation_id || $context->run_id() !== $run_id ) ) ) {
			throw new ContractException( 'unauthorized', 'Reviewed native source requires its authenticated workflow owner.' );
		}
		$fresh = $reader->inspect( $request );
		$request->assert_result( $fresh );
		$request->assert_environment_current();
		if ( ! hash_equals( $expected->version()->hash(), $fresh->version()->hash() ) || ! hash_equals( $expected->graph_hash(), $fresh->graph_hash() ) || ! hash_equals( $expected->opaque_hash(), $fresh->opaque_hash() ) || ! hash_equals( $expected->facts()->revision(), $fresh->facts()->revision() ) ) {
			throw new ContractException( 'stale_version', 'Reviewed native source graph changed before effect preparation.' );
		}
		if ( null !== $copy ) {
			$copy->assert_graph( $fresh->document_key(), $fresh->version(), $fresh->graph() );
		}
		return $this->verify_reference( $reader, $request, $fresh, $reference, $operation_id, $conversation_id, $run_id, false, $copy );
	}

	private function verify_reference( BuilderNativeSourceReader $reader, BuilderGraphInspectionRequest $request, BuilderGraphResult $expected, object $reference, string $operation_id, ?string $conversation_id, ?string $run_id, bool $require_active_run, ?BuilderPrivateCopyCoverage $copy ): string {
		$context = $request->context();
		$context->assert_execution_identity();
		ConversationStore::identifier( $operation_id );
		$keys = array_keys( get_object_vars( $reference ) );
		sort( $keys, SORT_STRING );
		if ( array( 'hash', 'id', 'version' ) !== $keys || ! is_string( $reference->id ) || ! is_int( $reference->version ) || $reference->version < 1 || ! is_string( $reference->hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $reference->hash ) ) {
			throw new ContractException( 'invalid_input', 'Private native source reference is invalid.' );
		}
		$owner   = $context->actor()->owner();
		$graph   = $expected->graph();
		$version = $expected->version();
		if ( null !== $copy ) {
			$copy->assert_graph( $expected->document_key(), $version, $graph );
		}
		if ( null === $conversation_id || null === $run_id || null !== $request->editor() || $version->editor_bound() || ! in_array( $reader->builder(), array( 'gutenberg', 'elementor', 'divi' ), true ) || $reader->builder() !== $request->document()->builder || 'complete' !== $graph->completeness || ( 'complete' !== $graph->consumer_coverage && null === $copy ) || ( new JsonCanonicalizer() )->encode( $graph->document ) !== ( new JsonCanonicalizer() )->encode( $request->document() ) || $expected->facts()->site_id() !== get_current_blog_id() || $expected->facts()->owner_ref() !== $owner->reference() ) {
			throw new ContractException( 'unauthorized', 'Private native source authority is unavailable.' );
		}
		$active = $require_active_run ? $this->assert_active_run( $owner->reference(), $conversation_id, $run_id, $expected->document_key(), $graph->document ) : $this->assert_reviewed_run( $owner->reference(), $conversation_id, $run_id, $expected->document_key(), $graph->document );
		$row    = $this->artifacts->get( $owner, $reference->id, $reference->version );
		if ( 'native_snapshot' !== $row['kind'] || (int) $row['conversation_id'] !== (int) $active['conversation_internal_id'] || ! hash_equals( $row['content_hash'], $reference->hash ) || ! hash_equals( $row['environment_hash'], $version->environment_hash() ) ) {
			throw new ContractException( 'evidence_stale', 'Private native source artifact binding changed.' );
		}
		$payload      = ( new JsonDecoder( 2097152, 24 ) )->decode( $row['payload_json'] );
		$payload_keys = $payload instanceof \stdClass ? array_keys( get_object_vars( $payload ) ) : array();
		sort( $payload_keys, SORT_STRING );
		if ( array( 'builder', 'document_key', 'graph_hash', 'operation_id', 'run_id', 'source_content', 'source_hash', 'version_hash' ) !== $payload_keys || $reader->builder() !== $payload->builder || $expected->document_key() !== $payload->document_key || ! hash_equals( $expected->graph_hash(), $payload->graph_hash ) || ! hash_equals( $version->hash(), $payload->version_hash ) || ! hash_equals( $version->source_hash(), $payload->source_hash ) || $operation_id !== $payload->operation_id || $run_id !== $payload->run_id || ! is_string( $payload->source_content ) || strlen( $payload->source_content ) > 1048576 || ! hash_equals( $version->source_hash(), hash( 'sha256', $payload->source_content ) ) ) {
			throw new ContractException( 'evidence_stale', 'Private native source artifact payload differs from the current graph.' );
		}
		$request->assert_environment_current();
		$live_content = $reader->read_saved_source( $request, $expected );
		if ( $require_active_run ) {
			$this->assert_active_run( $owner->reference(), $conversation_id, $run_id, $expected->document_key(), $graph->document );
		} else {
			$this->assert_reviewed_run( $owner->reference(), $conversation_id, $run_id, $expected->document_key(), $graph->document );
		}
		if ( ! hash_equals( $payload->source_content, $live_content ) ) {
			throw new ContractException( 'stale_version', 'Saved source changed after private artifact capture.' );
		}
		return $payload->source_content;
	}

	private function assert_reviewed_run( string $owner_ref, string $conversation_id, string $run_id, string $document_key, object $native_document ): array {
		ConversationStore::identifier( $conversation_id );
		ConversationStore::identifier( $run_id );
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.id,c.id AS conversation_internal_id,c.context_json FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s AND c.owner_ref=%s AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND r.state IN ('running','waiting_approval','succeeded','partial') AND r.cancel_requested=0", array( $run_id, $owner_ref, $conversation_id, $owner_ref ) );
		if ( ! $rows ) {
			throw new ContractException( 'unauthorized', 'Reviewed native source requires a surviving owning run.' );
		}
		BuilderConversationTarget::assert_binding( $rows[0]['context_json'], $document_key, $native_document );
		return $rows[0];
	}

	private function assert_active_run( string $owner_ref, string $conversation_id, string $run_id, string $document_key, object $native_document ): array {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.id,c.id AS conversation_internal_id,c.context_json FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s AND c.owner_ref=%s AND c.active_run_id=r.id AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND r.state='running' AND r.cancel_requested=0", array( $run_id, $owner_ref, $conversation_id, $owner_ref ) );
		if ( ! $rows ) {
			throw new ContractException( 'unauthorized', 'Private source capture requires the active owning run.' );
		}
		try {
			$stored_target = ( new JsonDecoder( 65536, 12 ) )->decode( $rows[0]['context_json'] );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Stored builder document target is invalid.' );
		}
		if ( ! $stored_target instanceof \stdClass || ( ! property_exists( $stored_target, 'document_key' ) && ( ! property_exists( $stored_target, 'target' ) || null === $stored_target->target ) ) ) {
			throw new ContractException( 'unauthorized', 'Private source capture requires a stored document target.' );
		}
		BuilderConversationTarget::assert_binding( $rows[0]['context_json'], $document_key, $native_document );
		return $rows[0];
	}
}
