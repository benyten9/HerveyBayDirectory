<?php
/**
 * Immutable normalized native builder graph extraction result.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable value object.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Keeps public graph/context evidence separate from private opaque native bytes. */
final class BuilderGraphResult {
	private string $document_key;
	private GraphVersion $version;
	private object $graph;
	private RelationshipFacts $facts;
	private array $context_trace;
	private string $graph_hash;
	private string $opaque_hash;
	private object $native_snapshot;

	public function __construct( string $document_key, GraphVersion $version, object $graph, RelationshipFacts $facts, array $context_trace, object $native_snapshot ) {
		GraphVersion::identifier( $document_key );
		( new JsonDecoder( 5242880, 32 ) )->check( $graph );
		( new JsonDecoder( 5242880, 32 ) )->check( $native_snapshot );
		if ( $facts->document_key() !== $document_key || ! hash_equals( $facts->graph_version_hash(), $version->hash() ) ) {
			throw new ContractException( 'stale_version', 'Builder graph facts do not match the extracted document version.' );
		}
		if ( count( $context_trace ) > 5000 ) {
			throw new ContractException( 'needs_expansion', 'Builder context trace exceeds its bound.' );
		}
		( new JsonDecoder( 1048576, 24 ) )->check( $context_trace );
		$expected_keys = array( 'consumer_node_id', 'context_id', 'status', 'provider_node_id', 'resolver_id', 'resolver_revision', 'source' );
		sort( $expected_keys, SORT_STRING );
		$trace_keys = array();
		foreach ( $context_trace as $trace ) {
			$keys = $trace instanceof \stdClass ? array_keys( get_object_vars( $trace ) ) : array();
			sort( $keys, SORT_STRING );
			if ( $keys !== $expected_keys || ! in_array( $trace->status, array( 'resolved', 'missing', 'ambiguous', 'inaccessible', 'stale' ), true ) || ! in_array( $trace->source, array( 'nearest-native-provider', 'single-document-fallback', 'explicit-native-binding', 'native-helper' ), true ) || ( 'resolved' === $trace->status ) !== is_string( $trace->provider_node_id ) ) {
				throw new ContractException( 'unsupported_contract', 'Builder context trace is invalid.' );
			}
			GraphVersion::identifier( $trace->consumer_node_id );
			GraphVersion::identifier( $trace->context_id );
			GraphVersion::identifier( $trace->resolver_id );
			GraphVersion::identifier( $trace->resolver_revision );
			if ( null !== $trace->provider_node_id ) {
				GraphVersion::identifier( $trace->provider_node_id ); }
			$fact      = $facts->context( $trace->consumer_node_id, $trace->context_id );
			$trace_key = $trace->consumer_node_id . '|' . $trace->context_id;
			if ( isset( $trace_keys[ $trace_key ] ) || null === $fact || $fact->status !== $trace->status || $fact->provider_node_id !== $trace->provider_node_id || $fact->resolver_id !== $trace->resolver_id || $fact->resolver_revision !== $trace->resolver_revision ) {
				throw new ContractException( 'stale_version', 'Builder context trace differs from current relationship facts.' );
			}
			$trace_keys[ $trace_key ] = true;
		}
		foreach ( $facts->contexts() as $fact ) {
			if ( ! isset( $trace_keys[ $fact->consumer_node_id . '|' . $fact->context_id ] ) ) {
				throw new ContractException( 'stale_version', 'Builder context trace omits a current relationship fact.' );
			}
		}
		$canonical = new JsonCanonicalizer();
		usort( $context_trace, static fn( object $left, object $right ): int => array( $left->consumer_node_id, $left->context_id ) <=> array( $right->consumer_node_id, $right->context_id ) );
		$this->document_key = $document_key;
		$this->version      = $version;
		$this->facts        = $facts;
		// Copying already requires these exact canonical bytes. Hash each buffer
		// once instead of traversing the same validated source again for a digest.
		$graph_json       = $canonical->encode( $graph );
		$this->graph      = json_decode( $graph_json, false, 32, JSON_THROW_ON_ERROR );
		$this->graph_hash = hash( 'sha256', $graph_json );
		unset( $graph_json );
		$this->context_trace   = array_values( $context_trace );
		$native_json           = $canonical->encode( $native_snapshot );
		$this->native_snapshot = json_decode( $native_json, false, 32, JSON_THROW_ON_ERROR );
		$this->opaque_hash     = hash( 'sha256', $native_json );
	}

	public function document_key(): string {
		return $this->document_key; }
	public function version(): GraphVersion {
		return $this->version; }
	/** Read native extraction coverage without copying saved graph attributes. */
	public function completeness(): string {
		return $this->graph->completeness; }
	/** Read native consumer coverage without copying saved graph attributes. */
	public function consumer_coverage(): string {
		return $this->graph->consumer_coverage; }
	public function graph(): object {
		return json_decode( json_encode( $this->graph, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR ); }

	/**
	 * Detach exact selection authority without copying unrelated saved attributes.
	 *
	 * Every native node remains represented. The caller receives only the two
	 * members used by request selection checks; no mutable graph reference escapes.
	 *
	 * @return array Detached native locator and scope pairs.
	 */
	public function selection_index(): array {
		$nodes = array();
		foreach ( $this->graph->nodes as $node ) {
			$nodes[] = (object) array(
				'locator' => $node->locator,
				'scope'   => $node->scope,
			);
		}
		return json_decode( json_encode( $nodes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
	}
	public function facts(): RelationshipFacts {
		return $this->facts; }
	public function context_trace(): array {
		return json_decode( json_encode( $this->context_trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 24, JSON_THROW_ON_ERROR ); }
	public function graph_hash(): string {
		return $this->graph_hash; }
	public function opaque_hash(): string {
		return $this->opaque_hash; }
	/** Private native evidence is never included in response(). */
	public function native_snapshot(): object {
		return json_decode( json_encode( $this->native_snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR ); }

	public function response(): object {
		return (object) array(
			'document_key'  => $this->document_key,
			'graph_hash'    => $this->graph_hash,
			'opaque_hash'   => $this->opaque_hash,
			'graph'         => $this->graph(),
			'context_trace' => $this->context_trace(),
			'fact_revision' => $this->facts->revision(),
		);
	}
}
