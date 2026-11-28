<?php
/**
 * Versioned private document-graph projection and relationship indexes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Repository invariants stay beside the transaction boundaries.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Native/editor state remains authority; stored graphs are rebuildable projections. */
final class GraphSnapshotStore {
	private const MAX_NODES = 2000;
	private const MAX_EDGES = 5000;
	private const MAX_SLOTS = 200;
	private Database $db;
	private ArtifactStore $artifacts;
	private JsonDecoder $decoder;
	private JsonCanonicalizer $canonical;
	private int $blog_id;

	/** Bind one physical site database; switched-blog reuse fails closed. */
	public function __construct( Database $db, ?int $blog_id = null ) {
		$this->db      = $db;
		$this->artifacts = new ArtifactStore( $db );
		$this->decoder = new JsonDecoder( 5242880, 32 );
		$this->canonical = new JsonCanonicalizer();
		$this->blog_id = $blog_id ?? get_current_blog_id();
	}

	/** Observe current native/editor identity and synchronously fence stale graphs. */
	public function observe( Owner $owner, string $document_key, string $builder, string $document_kind, string $native_id, GraphVersion $version ): array {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		$this->builder( $builder );
		GraphVersion::identifier( $document_kind );
		GraphVersion::identifier( $native_id );
		$graph_key = $this->graph_key( $owner, $document_key );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $graph_key, $document_key, $builder, $document_kind, $native_id, $version ): array {
				$heads     = $db->table( 'graph_heads' );
				$snapshots = $db->table( 'graph_snapshots' );
				$edges     = $db->table( 'graph_edges' );
				$rows      = $db->rows( "SELECT * FROM $heads WHERE graph_key=%s FOR UPDATE", array( $graph_key ) );
				if ( $rows && ( $rows[0]['builder'] !== $builder || $rows[0]['document_kind'] !== $document_kind || $rows[0]['native_id'] !== $native_id ) ) {
					throw new ContractException( 'scope_conflict', 'Document graph identity changed.' );
				}
				if ( $rows && hash_equals( $rows[0]['current_version_hash'], $version->hash() ) ) {
					return $this->head_value( $rows[0] );
				}
				if ( $rows && null !== $rows[0]['building_generation'] ) {
					$db->query( "DELETE FROM $edges WHERE graph_key=%s AND generation=%d", array( $graph_key, (int) $rows[0]['building_generation'] ) );
					$db->query( "DELETE FROM $snapshots WHERE graph_key=%s AND generation=%d AND state<>'complete'", array( $graph_key, (int) $rows[0]['building_generation'] ) );
				}
				$values = $this->version_values( $version );
				if ( ! $rows ) {
					$db->query( "INSERT INTO $heads (graph_key,document_key,owner_ref,builder,document_kind,native_id,source_epoch,generation_counter,current_version_hash,current_source_hash,current_editor_epoch,current_editor_revision,current_environment_hash,current_directory_hash,current_adapter_revision,current_normalization_revision,active_generation,building_generation,building_version_hash,building_attempt_id,eligible,state,failure_code,updated_at) VALUES (%s,%s,%s,%s,%s,%s,1,0,%s,%s,NULLIF(%s,''),NULLIF(%s,''),%s,%s,%s,%s,NULL,NULL,NULL,NULL,0,'cold',NULL,UTC_TIMESTAMP())", array_merge( array( $graph_key, $document_key, $owner->reference(), $builder, $document_kind, $native_id ), $values ) );
				} else {
					$db->query( "UPDATE $heads SET source_epoch=source_epoch+1,current_version_hash=%s,current_source_hash=%s,current_editor_epoch=NULLIF(%s,''),current_editor_revision=NULLIF(%s,''),current_environment_hash=%s,current_directory_hash=%s,current_adapter_revision=%s,current_normalization_revision=%s,building_generation=NULL,building_version_hash=NULL,building_attempt_id=NULL,eligible=0,state='stale',failure_code='source_changed',updated_at=UTC_TIMESTAMP() WHERE graph_key=%s", array_merge( $values, array( $graph_key ) ) );
				}
				$this->bump_relationship_epoch( $db );
				return $this->head_value( $db->rows( "SELECT * FROM $heads WHERE graph_key=%s FOR UPDATE", array( $graph_key ) )[0] );
			}
		);
	}

	/** Start or resume a hidden monotonic generation under the exact observed version. */
	public function begin( Owner $owner, string $document_key, GraphVersion $version, string $attempt_id ): int {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		GraphVersion::identifier( $attempt_id );
		$graph_key = $this->graph_key( $owner, $document_key );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $graph_key, $document_key, $version, $attempt_id ): int {
				$heads     = $db->table( 'graph_heads' );
				$snapshots = $db->table( 'graph_snapshots' );
				$edges     = $db->table( 'graph_edges' );
				$rows      = $db->rows( "SELECT * FROM $heads WHERE graph_key=%s FOR UPDATE", array( $graph_key ) );
				$row       = $this->authorized_head( $rows, $owner, $version );
				if ( null !== $row['building_generation'] ) {
					$db->query( "DELETE FROM $edges WHERE graph_key=%s AND generation=%d", array( $graph_key, (int) $row['building_generation'] ) );
					$db->query( "DELETE FROM $snapshots WHERE graph_key=%s AND generation=%d AND state<>'complete'", array( $graph_key, (int) $row['building_generation'] ) );
				}
				$stored_max = (int) $db->scalar( "SELECT COALESCE(MAX(generation),0) FROM $snapshots WHERE graph_key=%s", array( $graph_key ) );
				$generation = max( (int) $row['generation_counter'], $stored_max ) + 1;
				$db->query( "UPDATE $heads SET generation_counter=%d,building_generation=%d,building_version_hash=%s,building_attempt_id=%s,state='building',failure_code=NULL,updated_at=UTC_TIMESTAMP() WHERE graph_key=%s", array( $generation, $generation, $version->hash(), $attempt_id, $graph_key ) );
				$db->query( "INSERT INTO $snapshots (graph_key,generation,attempt_id,version_hash,version_json,snapshot_hash,graph_hash,relation_hash,native_artifact_hash,state,completeness,consumer_complete,node_count,edge_count,slot_count,graph_json,native_artifact_json,created_at,completed_at) VALUES (%s,%d,%s,%s,%s,NULL,NULL,NULL,NULL,'building',NULL,0,0,0,0,NULL,NULL,UTC_TIMESTAMP(),NULL)", array( $graph_key, $generation, $attempt_id, $version->hash(), $version->json() ) );
				return $generation;
			}
		);
	}

	/** Stage one complete private JSON snapshot and its ordinary edge indexes. */
	public function stage( Owner $owner, string $document_key, int $generation, string $attempt_id, GraphVersion $version, object $graph, ?object $native_artifact = null ): bool {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		if ( $generation < 1 ) {
			throw new ContractException( 'invalid_input', 'Graph generation is invalid.' );
		}
		GraphVersion::identifier( $attempt_id );
		$validated = $this->validate_graph( $document_key, $version, $graph );
		$artifact  = $this->artifact_json( $owner, $native_artifact );
		$graph_key = $this->graph_key( $owner, $document_key );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $graph_key, $document_key, $generation, $attempt_id, $version, $validated, $artifact ): bool {
				$heads     = $db->table( 'graph_heads' );
				$snapshots = $db->table( 'graph_snapshots' );
				$edges     = $db->table( 'graph_edges' );
				$rows      = $db->rows( "SELECT * FROM $heads WHERE graph_key=%s FOR UPDATE", array( $graph_key ) );
				if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() || $rows[0]['builder'] !== $validated['builder'] || $rows[0]['document_kind'] !== $validated['document_kind'] || $rows[0]['native_id'] !== $validated['native_id'] || (int) $rows[0]['building_generation'] !== $generation || $rows[0]['building_attempt_id'] !== $attempt_id || ! hash_equals( (string) $rows[0]['building_version_hash'], $version->hash() ) || ! hash_equals( $rows[0]['current_version_hash'], $version->hash() ) ) {
					return false;
				}
				$db->query( "DELETE FROM $edges WHERE graph_key=%s AND generation=%d", array( $graph_key, $generation ) );
				foreach ( $validated['edges'] as $edge ) {
					$db->query( "INSERT INTO $edges (graph_key,document_key,generation,edge_key,owner_ref,kind,source_node,target_node,target_document_key,scope_hash,edge_order,provenance_json,edge_json) VALUES (%s,%s,%d,%s,%s,%s,%s,%s,NULLIF(%s,''),%s,NULLIF(%d,-2147483648),%s,%s)", array( $graph_key, $document_key, $generation, $edge['key'], $owner->reference(), $edge['kind'], $edge['source'], $edge['target'], $edge['target_document_key'] ?? '', $edge['scope_hash'], null === $edge['order'] ? -2147483648 : $edge['order'], $edge['provenance_json'], $edge['json'] ) );
				}
				$binding = $this->snapshot_binding( $owner->reference(), $graph_key, $generation, $attempt_id, $version->hash(), $validated['json'], $artifact['json'], $validated['relation_hash'] );
				$db->query( "UPDATE $snapshots SET snapshot_hash=%s,graph_hash=%s,relation_hash=%s,native_artifact_hash=NULLIF(%s,''),state='staged',completeness=%s,consumer_complete=%d,node_count=%d,edge_count=%d,slot_count=%d,graph_json=%s,native_artifact_json=NULLIF(%s,'') WHERE graph_key=%s AND generation=%d AND attempt_id=%s AND version_hash=%s AND version_json=%s AND state='building'", array( $binding, $validated['hash'], $validated['relation_hash'], $artifact['hash'] ?? '', $validated['completeness'], $validated['consumer_complete'] ? 1 : 0, $validated['node_count'], $validated['edge_count'], $validated['slot_count'], $validated['json'], $artifact['json'] ?? '', $graph_key, $generation, $attempt_id, $version->hash(), $version->json() ) );
				return 1 === $db->affected();
			}
		);
	}

	/** Atomically publish only a structurally complete snapshot under its current fence. */
	public function publish( Owner $owner, string $document_key, int $generation, string $attempt_id, GraphVersion $version ): bool {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		GraphVersion::identifier( $attempt_id );
		$graph_key = $this->graph_key( $owner, $document_key );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $graph_key, $document_key, $generation, $attempt_id, $version ): bool {
				$heads     = $db->table( 'graph_heads' );
				$snapshots = $db->table( 'graph_snapshots' );
				$edges     = $db->table( 'graph_edges' );
				$rows      = $db->rows( "SELECT * FROM $heads WHERE graph_key=%s FOR UPDATE", array( $graph_key ) );
				if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() || (int) $rows[0]['building_generation'] !== $generation || $rows[0]['building_attempt_id'] !== $attempt_id || ! hash_equals( (string) $rows[0]['building_version_hash'], $version->hash() ) || ! hash_equals( $rows[0]['current_version_hash'], $version->hash() ) ) {
					return false;
				}
				$snapshot = $db->rows( "SELECT * FROM $snapshots WHERE graph_key=%s AND generation=%d AND attempt_id=%s FOR UPDATE", array( $graph_key, $generation, $attempt_id ) );
				if ( ! $snapshot || 'staged' !== $snapshot[0]['state'] || 'complete' !== $snapshot[0]['completeness'] || ! is_string( $snapshot[0]['graph_json'] ) || ! is_string( $snapshot[0]['graph_hash'] ) ) {
					throw new ContractException( 'incomplete_graph', 'Only a complete staged graph can be published.' );
				}
				try {
					$stored_version = new GraphVersion( get_object_vars( $this->decoder->decode( $snapshot[0]['version_json'] ) ) );
				} catch ( \Throwable $error ) {
					throw new ContractException( 'stale_version', 'Staged graph version is invalid.' );
				}
				if ( ! hash_equals( $stored_version->hash(), $version->hash() ) || ! hash_equals( $snapshot[0]['version_hash'], $version->hash() ) ) {
					throw new ContractException( 'stale_version', 'Staged graph version changed before publication.' );
				}
				try {
					$validated_graph = $this->validate_graph( $document_key, $stored_version, $this->decoder->decode( $snapshot[0]['graph_json'] ) );
				} catch ( \Throwable $error ) {
					throw new ContractException( 'incomplete_graph', 'Staged graph contract is invalid.' );
				}
				$artifact_hash = null;
				if ( null !== $snapshot[0]['native_artifact_json'] ) {
					try {
						$validated_artifact = $this->artifact_json( $owner, $this->decoder->decode( $snapshot[0]['native_artifact_json'] ) );
						$artifact_hash      = $validated_artifact['hash'];
					} catch ( \Throwable $error ) {
						throw new ContractException( 'incomplete_graph', 'Staged native graph artifact is invalid.' );
					}
				}
				$stored_edges = $db->rows( "SELECT * FROM $edges WHERE graph_key=%s AND generation=%d ORDER BY edge_key", array( $graph_key, $generation ) );
				$binding = $this->snapshot_binding( $owner->reference(), $graph_key, $generation, $attempt_id, $version->hash(), $snapshot[0]['graph_json'], $snapshot[0]['native_artifact_json'], $validated_graph['relation_hash'] );
				if ( count( $stored_edges ) !== (int) $snapshot[0]['edge_count'] || ! is_string( $snapshot[0]['snapshot_hash'] ) || ! hash_equals( $snapshot[0]['snapshot_hash'], $binding ) || $snapshot[0]['completeness'] !== $validated_graph['completeness'] || (bool) $snapshot[0]['consumer_complete'] !== $validated_graph['consumer_complete'] || (int) $snapshot[0]['node_count'] !== $validated_graph['node_count'] || (int) $snapshot[0]['edge_count'] !== $validated_graph['edge_count'] || (int) $snapshot[0]['slot_count'] !== $validated_graph['slot_count'] || ! is_string( $snapshot[0]['relation_hash'] ) || ! hash_equals( $snapshot[0]['relation_hash'], $validated_graph['relation_hash'] ) || ! hash_equals( $snapshot[0]['relation_hash'], $this->stored_relation_hash( $stored_edges, $graph_key, $document_key, $owner->reference(), $generation ) ) || ! hash_equals( $snapshot[0]['graph_hash'], hash( 'sha256', $snapshot[0]['graph_json'] ) ) || ! hash_equals( $snapshot[0]['graph_hash'], $validated_graph['hash'] ) || ( null === $snapshot[0]['native_artifact_hash'] ? null !== $artifact_hash : ( null === $artifact_hash || ! hash_equals( $snapshot[0]['native_artifact_hash'], $artifact_hash ) ) ) ) {
					throw new ContractException( 'incomplete_graph', 'Graph snapshot integrity could not be verified.' );
				}
				$db->query( "UPDATE $snapshots SET state='complete',completed_at=UTC_TIMESTAMP() WHERE graph_key=%s AND generation=%d AND attempt_id=%s AND state='staged'", array( $graph_key, $generation, $attempt_id ) );
				$db->query( "UPDATE $heads SET active_generation=%d,building_generation=NULL,building_version_hash=NULL,building_attempt_id=NULL,eligible=1,state='ready',failure_code=NULL,updated_at=UTC_TIMESTAMP() WHERE graph_key=%s", array( $generation, $graph_key ) );
				$this->bump_relationship_epoch( $db );
				return true;
			}
		);
	}

	/** Mark a current build failed without reviving a stale active snapshot. */
	public function fail( Owner $owner, string $document_key, int $generation, string $attempt_id, string $code ): bool {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		GraphVersion::identifier( $attempt_id );
		$graph_key = $this->graph_key( $owner, $document_key );
		$code = preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) ? $code : 'graph_unavailable';
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $graph_key, $document_key, $generation, $attempt_id, $code ): bool {
				$heads     = $db->table( 'graph_heads' );
				$snapshots = $db->table( 'graph_snapshots' );
				$rows      = $db->rows( "SELECT * FROM $heads WHERE graph_key=%s FOR UPDATE", array( $graph_key ) );
				if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() || (int) $rows[0]['building_generation'] !== $generation || $rows[0]['building_attempt_id'] !== $attempt_id ) {
					return false;
				}
				$db->query( "UPDATE $snapshots SET state='failed',graph_json=NULL,native_artifact_json=NULL,completed_at=UTC_TIMESTAMP() WHERE graph_key=%s AND generation=%d AND attempt_id=%s AND state<>'complete'", array( $graph_key, $generation, $attempt_id ) );
				$active = null !== $rows[0]['active_generation'] && (bool) $rows[0]['eligible'];
				$db->query( "UPDATE $heads SET building_generation=NULL,building_version_hash=NULL,building_attempt_id=NULL,state=%s,failure_code=%s,updated_at=UTC_TIMESTAMP() WHERE graph_key=%s", array( $active ? 'ready' : 'unavailable', $code, $graph_key ) );
				return true;
			}
		);
	}

	/** Immediately fence an active projection when native/editor freshness is lost. */
	public function invalidate( Owner $owner, string $document_key, string $code = 'native_source_changed', ?GraphVersion $expected = null ): bool {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		$code = preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) ? $code : 'native_source_changed';
		$graph_key = $this->graph_key( $owner, $document_key );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $graph_key, $code, $expected ): bool {
				$heads = $db->table( 'graph_heads' );
				$rows = $db->rows( "SELECT owner_ref,current_version_hash,building_generation FROM $heads WHERE graph_key=%s FOR UPDATE", array( $graph_key ) );
				if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() || ( null !== $expected && ! hash_equals( $rows[0]['current_version_hash'], $expected->hash() ) ) ) {
					return false;
				}
				if ( null !== $rows[0]['building_generation'] ) {
					$generation = (int) $rows[0]['building_generation'];
					$db->query( 'DELETE FROM ' . $db->table( 'graph_edges' ) . ' WHERE graph_key=%s AND generation=%d', array( $graph_key, $generation ) );
					$db->query( 'UPDATE ' . $db->table( 'graph_snapshots' ) . " SET state='failed',graph_json=NULL,native_artifact_json=NULL,completed_at=UTC_TIMESTAMP() WHERE graph_key=%s AND generation=%d AND state<>'complete'", array( $graph_key, $generation ) );
				}
				$db->query( "UPDATE $heads SET eligible=0,state='stale',failure_code=%s,building_generation=NULL,building_version_hash=NULL,building_attempt_id=NULL,updated_at=UTC_TIMESTAMP() WHERE graph_key=%s", array( $code, $graph_key ) );
				$this->bump_relationship_epoch( $db );
				return true;
			}
		);
	}

	/** Read a full current snapshot; bounded reads never return a partial slice. */
	public function current( Owner $owner, string $document_key, GraphVersion $expected, int $max_nodes = self::MAX_NODES, int $max_edges = self::MAX_EDGES, bool $mutation_input = false ): object {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		if ( $max_nodes < 1 || $max_nodes > self::MAX_NODES || $max_edges < 1 || $max_edges > self::MAX_EDGES ) {
			throw new ContractException( 'invalid_input', 'Graph read bound is invalid.' );
		}
		$rows = $this->active_rows( $owner, $document_key );
		try {
			$stored_version = new GraphVersion( get_object_vars( $this->decoder->decode( $rows[0]['version_json'] ) ) );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Stored graph version is unavailable.' );
		}
		if ( ! hash_equals( $rows[0]['current_version_hash'], $expected->hash() ) || ! hash_equals( $rows[0]['version_hash'], $expected->hash() ) || ! hash_equals( $stored_version->hash(), $expected->hash() ) ) {
			throw new ContractException( 'stale_version', 'Graph version changed before delivery.' );
		}
		if ( (int) $rows[0]['node_count'] > $max_nodes || (int) $rows[0]['edge_count'] > $max_edges ) {
			throw new ContractException( 'needs_expansion', 'Complete graph exceeds the requested bound.' );
		}
		try {
			$graph = $this->decoder->decode( $rows[0]['graph_json'] );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Stored graph JSON is unavailable.' );
		}
		try {
			$validated_graph = $this->validate_graph( $document_key, $stored_version, $graph );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Stored graph contract is invalid.' );
		}
		if ( ! is_string( $rows[0]['graph_hash'] ) || ! hash_equals( $rows[0]['graph_hash'], hash( 'sha256', $rows[0]['graph_json'] ) ) || ! hash_equals( $rows[0]['graph_hash'], $validated_graph['hash'] ) || $rows[0]['completeness'] !== $validated_graph['completeness'] || (bool) $rows[0]['consumer_complete'] !== $validated_graph['consumer_complete'] || (int) $rows[0]['node_count'] !== $validated_graph['node_count'] || (int) $rows[0]['edge_count'] !== $validated_graph['edge_count'] || (int) $rows[0]['slot_count'] !== $validated_graph['slot_count'] ) {
			throw new ContractException( 'evidence_stale', 'Stored graph hash is invalid.' );
		}
		if ( $mutation_input && ( 'complete' !== $validated_graph['completeness'] || ! $validated_graph['consumer_complete'] ) ) {
			throw new ContractException( 'incomplete_graph', 'Graph cannot authorize mutation until consumer and slot coverage are complete.' );
		}
		$artifact = null;
		if ( null !== $rows[0]['native_artifact_json'] ) {
			try {
				$artifact = $this->decoder->decode( $rows[0]['native_artifact_json'] );
				$validated_artifact = $this->artifact_json( $owner, $artifact );
			} catch ( \Throwable $error ) {
				throw new ContractException( 'evidence_stale', 'Stored native graph artifact is unavailable.' );
			}
			if ( null === $rows[0]['native_artifact_hash'] || ! hash_equals( $rows[0]['native_artifact_hash'], $validated_artifact['hash'] ) ) {
				throw new ContractException( 'evidence_stale', 'Stored native graph artifact hash is invalid.' );
			}
		} elseif ( null !== $rows[0]['native_artifact_hash'] ) {
			throw new ContractException( 'evidence_stale', 'Stored native graph artifact binding is invalid.' );
		}
		if ( ! is_string( $rows[0]['relation_hash'] ) ) {
			throw new ContractException( 'evidence_stale', 'Stored graph relation binding is invalid.' );
		}
		$binding = $this->snapshot_binding( $owner->reference(), $rows[0]['graph_key'], (int) $rows[0]['active_generation'], $rows[0]['attempt_id'], $rows[0]['version_hash'], $rows[0]['graph_json'], $rows[0]['native_artifact_json'], $rows[0]['relation_hash'] );
		if ( ! is_string( $rows[0]['snapshot_hash'] ) || ! hash_equals( $rows[0]['snapshot_hash'], $binding ) ) {
			throw new ContractException( 'evidence_stale', 'Stored graph snapshot ownership binding is invalid.' );
		}
		do_action( 'directorist_ai_pro_graph_before_final_fence', $document_key, (int) $rows[0]['active_generation'], $mutation_input );
		$this->assert_relation_integrity( $rows[0] );
		$this->final_fence( $owner, $document_key, (int) $rows[0]['active_generation'], $stored_version, $rows[0]['snapshot_hash'], $rows[0]['graph_hash'], $rows[0]['relation_hash'], $rows[0]['native_artifact_hash'], $artifact );
		return (object) array( 'document_key' => $document_key, 'generation' => (int) $rows[0]['active_generation'], 'source_epoch' => (int) $rows[0]['source_epoch'], 'version' => $stored_version->value(), 'graph_hash' => $rows[0]['graph_hash'], 'graph' => $graph, 'native_artifact' => $artifact, 'mutation_ready' => 'complete' === $validated_graph['completeness'] && $validated_graph['consumer_complete'] );
	}

	/** Return one bounded forward edge set from the current generation. */
	public function forward( Owner $owner, string $document_key, string $source_node, int $limit = 200 ): array {
		$this->site();
		$this->actor( $owner );
		$this->document( $document_key );
		GraphVersion::identifier( $source_node );
		$this->limit( $limit, 200 );
		$epoch  = $this->relationship_epoch();
		$active = $this->active_rows( $owner, $document_key )[0];
		$this->assert_relation_integrity( $active );
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'graph_edges' ) . ' WHERE graph_key=%s AND generation=%d AND source_node=%s ORDER BY edge_key LIMIT %d', array( $active['graph_key'], (int) $active['active_generation'], $source_node, $limit + 1 ) );
		if ( count( $rows ) > $limit ) {
			throw new ContractException( 'needs_expansion', 'Forward graph relation set exceeds its bound.' );
		}
		$result = array_map( fn( array $row ): object => $this->decoder->decode( $row['edge_json'] ), $rows );
		do_action( 'directorist_ai_pro_graph_before_relationship_fence', 'forward', $document_key );
		$this->relationship_final_fence( $epoch, array( $active ) );
		return $result;
	}

	/** Traverse reverse document consumers with cycle and access-completeness bounds. */
	public function affected_consumers( Owner $owner, string $target_document_key, int $max_documents = 50, int $max_edges = 500 ): object {
		$this->site();
		$this->actor( $owner );
		$this->document( $target_document_key );
		$this->limit( $max_documents, 100 );
		$this->limit( $max_edges, 1000 );
		$initial_binding = null;
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$epoch    = $this->relationship_epoch();
			$target   = $this->active_rows( $owner, $target_document_key )[0];
			$authority = $this->active_relationship_authority( 1000 );
			foreach ( $authority as $active ) {
				$this->assert_relation_integrity( $active );
			}
			$complete = (bool) $target['consumer_complete'];
			$visited  = array( $target_document_key => true );
			$frontier = array( $target_document_key );
			$result   = array();
			$seen     = 0;
			$observed = array();
			while ( $frontier ) {
				$placeholders = implode( ',', array_fill( 0, count( $frontier ), '%s' ) );
				$sql = 'SELECT e.graph_key,e.document_key,e.owner_ref,s.consumer_complete FROM ' . $this->db->table( 'graph_edges' ) . ' e JOIN ' . $this->db->table( 'graph_heads' ) . ' h ON h.graph_key=e.graph_key AND h.active_generation=e.generation AND h.eligible=1 JOIN ' . $this->db->table( 'graph_snapshots' ) . " s ON s.graph_key=e.graph_key AND s.generation=e.generation WHERE e.target_document_key IN ($placeholders) ORDER BY e.target_document_key,e.document_key,e.edge_key LIMIT %d";
				$rows = $this->db->rows( $sql, array_merge( $frontier, array( $max_edges + 1 ) ) );
				$frontier = array();
				foreach ( $rows as $row ) {
					$observed[ $row['graph_key'] ] = true;
					++$seen;
					if ( $seen > $max_edges ) {
						throw new ContractException( 'needs_expansion', 'Affected-consumer edge traversal exceeds its bound.' );
					}
					if ( $row['owner_ref'] !== $owner->reference() ) {
						$complete = false;
						continue;
					}
					if ( ! (bool) $row['consumer_complete'] ) {
						$complete = false;
					}
					$key = $row['document_key'];
					if ( isset( $visited[ $key ] ) ) {
						continue;
					}
					if ( count( $result ) >= $max_documents ) {
						throw new ContractException( 'needs_expansion', 'Affected-consumer document traversal exceeds its bound.' );
					}
					$visited[ $key ] = true;
					$result[]        = $key;
					$frontier[]      = $key;
				}
			}
			$binding = $complete ? $this->relationship_read_binding( $target, $authority, array_keys( $observed ), $result ) : null;
			if ( 1 === $attempt && ( null === $binding || ! hash_equals( $initial_binding, $binding ) ) ) {
				throw new ContractException( 'stale_version', 'Affected-consumer authority changed before retry.' );
			}
			do_action( 'directorist_ai_pro_graph_before_relationship_fence', 'affected', $target_document_key );
			try {
				$this->relationship_final_fence( $epoch, $authority );
			} catch ( ContractException $error ) {
				if ( 0 !== $attempt || null === $binding || 'stale_version' !== $error->error_code() || $epoch === $this->relationship_epoch() ) {
					throw $error;
				}
				// Retry only the read. The complete closure and every related head
				// must remain identical, then pass the full global fence again.
				$initial_binding = $binding;
				continue;
			}
			return (object) array( 'target_document_key' => $target_document_key, 'consumers' => $result, 'complete' => $complete, 'diagnostics' => $complete ? array() : array( 'inaccessible_or_omitted_consumer' ) );
		}
		throw new ContractException( 'stale_version', 'Relationship authority remained unstable.' );
	}


	/** Bind only the primary and exact observed consumer heads across one read retry. */
	private function relationship_read_binding( array $target, array $authority, array $observed, array $consumers ): string {
		$heads = array();
		foreach ( $authority as $active ) {
			$heads[ $active['graph_key'] ] = $active;
		}
		$keys = array_unique( array_merge( array( $target['graph_key'] ), $observed ) );
		sort( $keys, SORT_STRING );
		$related = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $heads[ $key ] ) ) {
				throw new ContractException( 'stale_version', 'Affected-consumer head changed during traversal.' );
			}
			$related[ $key ] = $this->relationship_head_identity( $heads[ $key ] );
		}
		if ( $related[ $target['graph_key'] ] !== $this->relationship_head_identity( $target ) ) {
			throw new ContractException( 'stale_version', 'Primary graph changed during traversal.' );
		}
		return $this->canonical->digest( array( 'target' => $target['graph_key'], 'heads' => $related, 'consumers' => $consumers ) );
	}

	/** Preserve substantive head and snapshot identity, excluding only heartbeat time. */
	private function relationship_head_identity( array $row ): array {
		$keys = array( 'graph_key', 'document_key', 'owner_ref', 'builder', 'document_kind', 'native_id', 'source_epoch', 'generation_counter', 'current_version_hash', 'current_source_hash', 'current_editor_epoch', 'current_editor_revision', 'current_environment_hash', 'current_directory_hash', 'current_adapter_revision', 'current_normalization_revision', 'active_generation', 'building_generation', 'building_version_hash', 'building_attempt_id', 'eligible', 'state', 'failure_code', 'attempt_id', 'version_hash', 'version_json', 'snapshot_hash', 'relation_hash', 'completeness', 'consumer_complete' );
		$value = array();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				throw new ContractException( 'stale_version', 'Relationship head identity is incomplete.' );
			}
			$value[ $key ] = $row[ $key ];
		}
		return $value;
	}

	/** Remove old inactive generations without touching current heads. */
	public function prune( int $limit = 100 ): int {
		$this->site();
		$this->limit( $limit, 1000 );
		$snapshots = $this->db->table( 'graph_snapshots' );
		$heads     = $this->db->table( 'graph_heads' );
		$edges     = $this->db->table( 'graph_edges' );
		$count     = 0;
		$stale = $this->db->rows( "SELECT graph_key FROM $heads WHERE eligible=0 AND building_generation IS NULL AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY updated_at,graph_key LIMIT %d", array( $limit ) );
		foreach ( $stale as $row ) {
			$this->db->transaction(
				function ( Database $db ) use ( $heads, $snapshots, $edges, $row ): void {
					$locked = $db->rows( "SELECT eligible,building_generation FROM $heads WHERE graph_key=%s FOR UPDATE", array( $row['graph_key'] ) );
					if ( ! $locked || (bool) $locked[0]['eligible'] || null !== $locked[0]['building_generation'] ) {
						return;
					}
					$db->query( "DELETE FROM $edges WHERE graph_key=%s", array( $row['graph_key'] ) );
					$db->query( "DELETE FROM $snapshots WHERE graph_key=%s", array( $row['graph_key'] ) );
					$db->query( "DELETE FROM $heads WHERE graph_key=%s AND eligible=0 AND building_generation IS NULL", array( $row['graph_key'] ) );
				}
			);
			++$count;
		}
		$remaining = $limit - $count;
		if ( $remaining < 1 ) {
			return $count;
		}
		$rows = $this->db->rows( "SELECT s.graph_key,s.generation FROM $snapshots s LEFT JOIN $heads h ON h.graph_key=s.graph_key AND (h.active_generation=s.generation OR h.building_generation=s.generation) WHERE h.graph_key IS NULL AND s.completed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY s.completed_at LIMIT %d", array( $remaining ) );
		foreach ( $rows as $row ) {
			$this->db->query( "DELETE FROM $edges WHERE graph_key=%s AND generation=%d", array( $row['graph_key'], (int) $row['generation'] ) );
			$this->db->query( "DELETE FROM $snapshots WHERE graph_key=%s AND generation=%d", array( $row['graph_key'], (int) $row['generation'] ) );
		}
		return $count + count( $rows );
	}

	/** Validate graph shape, limits, containment and edge provenance. */
	private function validate_graph( string $document_key, GraphVersion $version, object $graph ): array {
		$this->decoder->check( $graph );
		$required = array( 'document', 'version', 'nodes', 'edges', 'slot_coverage', 'completeness', 'consumer_coverage', 'omissions' );
		if ( ! $this->exact_keys( $graph, $required ) || ! $graph->document instanceof \stdClass || ! $graph->version instanceof \stdClass || ! is_array( $graph->nodes ) || ! is_array( $graph->edges ) || ! is_array( $graph->slot_coverage ) || ! is_array( $graph->omissions ) || ! in_array( $graph->completeness, array( 'complete', 'partial' ), true ) || ! in_array( $graph->consumer_coverage, array( 'complete', 'partial' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Document graph shape is invalid.' );
		}
		if ( ! $this->exact_keys( $graph->document, array( 'builder', 'native_kind', 'native_id' ) ) || ! hash_equals( $version->hash(), ( new GraphVersion( get_object_vars( $graph->version ) ) )->hash() ) ) {
			throw new ContractException( 'stale_version', 'Document graph identity or version is stale.' );
		}
		$this->builder( $graph->document->builder );
		GraphVersion::identifier( $graph->document->native_kind );
		GraphVersion::identifier( $graph->document->native_id );
		if ( count( $graph->nodes ) < 1 || count( $graph->nodes ) > self::MAX_NODES || count( $graph->edges ) > self::MAX_EDGES || count( $graph->slot_coverage ) > self::MAX_SLOTS || count( $graph->omissions ) > 100 ) {
			throw new ContractException( 'needs_expansion', 'Document graph exceeds supported storage bounds.' );
		}
		$nodes = array();
		foreach ( $graph->nodes as $node ) {
			if ( ! $node instanceof \stdClass || ! $this->exact_keys( $node, array( 'id', 'provider_id', 'native_id', 'locator', 'scope', 'attributes', 'opaque_ref' ) ) || ! $node->locator instanceof \stdClass || ! $node->scope instanceof \stdClass || ! $node->attributes instanceof \stdClass || ( null !== $node->opaque_ref && ! is_string( $node->opaque_ref ) ) ) {
				throw new ContractException( 'invalid_input', 'Document graph node is invalid.' );
			}
			$this->locator( $node->locator, $version, $graph->document );
			$this->scope( $node->scope );
			$id = GraphVersion::identifier( $node->id );
			GraphVersion::identifier( $node->provider_id );
			GraphVersion::identifier( $node->native_id );
			if ( null !== $node->opaque_ref ) {
				GraphVersion::identifier( $node->opaque_ref );
			}
			if ( isset( $nodes[ $id ] ) ) {
				throw new ContractException( 'relationship_invalid', 'Document graph node identity is duplicated.' );
			}
			$nodes[ $id ] = true;
		}
		$coverage_keys = array();
		$covered_nodes = array();
		$slots_complete = ! empty( $graph->slot_coverage );
		foreach ( $graph->slot_coverage as $slot ) {
			if ( ! $slot instanceof \stdClass || ! $this->exact_keys( $slot, array( 'slot', 'scope', 'complete', 'node_ids' ) ) || ! is_bool( $slot->complete ) || ! is_array( $slot->node_ids ) || ! $slot->scope instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'Document graph slot coverage is invalid.' );
			}
			GraphVersion::identifier( $slot->slot );
			$this->scope( $slot->scope );
			$coverage_key = $slot->slot . '|' . hash( 'sha256', $this->canonical->encode( $slot->scope ) );
			if ( isset( $coverage_keys[ $coverage_key ] ) ) {
				throw new ContractException( 'relationship_invalid', 'Document graph slot coverage is ambiguous.' );
			}
			$coverage_keys[ $coverage_key ] = true;
			$coverage_nodes = array();
			foreach ( $slot->node_ids as $node_id ) {
				if ( ! is_string( $node_id ) || ! isset( $nodes[ $node_id ] ) || isset( $coverage_nodes[ $node_id ] ) ) {
					throw new ContractException( 'relationship_invalid', 'Document graph slot references an unavailable node.' );
				}
				$coverage_nodes[ $node_id ] = true;
				$covered_nodes[ $node_id ]  = true;
			}
			$slots_complete = $slots_complete && $slot->complete;
		}
		$slots_complete = $slots_complete && count( $covered_nodes ) === count( $nodes );
		foreach ( $graph->omissions as $omission ) {
			if ( ! $omission instanceof \stdClass || ! $this->exact_keys( $omission, array( 'code', 'path' ) ) || ! is_string( $omission->code ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $omission->code ) || ! is_string( $omission->path ) ) {
				throw new ContractException( 'invalid_input', 'Document graph omission diagnostic is invalid.' );
			}
		}
		$edge_rows = array();
		$contains  = array();
		$edge_keys = array();
		foreach ( $graph->edges as $edge ) {
			$row = $this->edge( $document_key, $edge, $nodes );
			if ( isset( $edge_keys[ $row['key'] ] ) ) {
				throw new ContractException( 'relationship_invalid', 'Document graph edge is duplicated.' );
			}
			$edge_keys[ $row['key'] ] = true;
			$edge_rows[]              = $row;
			if ( 'contains' === $row['kind'] ) {
				$contains[ $row['source'] ][] = $row['target'];
			}
		}
		$this->contains_acyclic( $nodes, $contains );
		$json = $this->canonical->encode( $graph );
		if ( strlen( $json ) > 5242880 ) {
			throw new ContractException( 'needs_expansion', 'Serialized document graph exceeds five MiB.' );
		}
		return array( 'json' => $json, 'hash' => hash( 'sha256', $json ), 'relation_hash' => $this->relation_hash( $edge_rows ), 'edges' => $edge_rows, 'node_count' => count( $nodes ), 'edge_count' => count( $edge_rows ), 'slot_count' => count( $graph->slot_coverage ), 'completeness' => $graph->completeness, 'consumer_complete' => 'complete' === $graph->consumer_coverage && ! $graph->omissions && $slots_complete, 'builder' => $graph->document->builder, 'document_kind' => $graph->document->native_kind, 'native_id' => $graph->document->native_id );
	}

	/** Normalize one validated relation for forward/reverse indexes. */
	private function edge( string $document_key, object $edge, array $nodes ): array {
		if ( ! $this->exact_keys( $edge, array( 'kind', 'source', 'target', 'target_document_key', 'scope', 'order', 'provenance' ) ) || ! in_array( $edge->kind, array( 'contains', 'context', 'field', 'template', 'style', 'dependency' ), true ) || ! $edge->scope instanceof \stdClass || ! $edge->provenance instanceof \stdClass ) {
			throw new ContractException( 'relationship_invalid', 'Document graph edge is invalid.' );
		}
		$this->scope( $edge->scope );
		$source = GraphVersion::identifier( $edge->source );
		$target = GraphVersion::identifier( $edge->target );
		$target_document = $edge->target_document_key;
		if ( ! isset( $nodes[ $source ] ) || ( null === $target_document && ! isset( $nodes[ $target ] ) ) || ( null !== $target_document && ! is_string( $target_document ) ) || ( 'contains' === $edge->kind && null !== $target_document ) ) {
			throw new ContractException( 'relationship_invalid', 'Document graph edge endpoint is unavailable.' );
		}
		if ( null !== $target_document ) {
			$this->document( $target_document );
		}
		if ( ! $this->exact_keys( $edge->provenance, array( 'source_ref', 'rule_id', 'resolver_revision' ) ) || ! $edge->provenance->source_ref instanceof \stdClass || ! $this->exact_keys( $edge->provenance->source_ref, array( 'kind', 'id', 'version' ) ) || ! in_array( $edge->provenance->source_ref->kind, array( 'source', 'artifact', 'run', 'operation', 'receipt' ), true ) || ! is_string( $edge->provenance->source_ref->id ) || ( null !== $edge->provenance->source_ref->version && ! is_string( $edge->provenance->source_ref->version ) ) ) {
			throw new ContractException( 'relationship_invalid', 'Document graph edge provenance is invalid.' );
		}
		GraphVersion::identifier( $edge->provenance->rule_id );
		GraphVersion::identifier( $edge->provenance->resolver_revision );
		GraphVersion::identifier( $edge->provenance->source_ref->id );
		if ( null !== $edge->provenance->source_ref->version ) {
			GraphVersion::identifier( $edge->provenance->source_ref->version );
		}
		$order = $edge->order;
		if ( ( null !== $order && ( ! is_int( $order ) || $order < 0 || $order > 2147483647 ) ) || ( 'contains' !== $edge->kind && null !== $order ) ) {
			throw new ContractException( 'relationship_invalid', 'Document graph edge order is invalid.' );
		}
		$json            = $this->canonical->encode( $edge );
		$provenance_json = $this->canonical->encode( $edge->provenance );
		return array( 'key' => hash( 'sha256', $json ), 'kind' => $edge->kind, 'source' => $source, 'target' => $target, 'target_document_key' => $target_document, 'scope_hash' => hash( 'sha256', $this->canonical->encode( $edge->scope ) ), 'order' => $order, 'provenance_json' => $provenance_json, 'json' => $json );
	}

	/** Reject containment cycles while permitting cycles among reference edges. */
	private function contains_acyclic( array $nodes, array $contains ): void {
		$indegree = array_fill_keys( array_keys( $nodes ), 0 );
		foreach ( $contains as $children ) {
			foreach ( $children as $child ) {
				++$indegree[ $child ];
			}
		}
		$queue = array();
		foreach ( $indegree as $node => $degree ) {
			if ( 0 === $degree ) {
				$queue[] = $node;
			}
		}
		$seen = 0;
		for ( $cursor = 0; $cursor < count( $queue ); ++$cursor ) {
			$node = $queue[ $cursor ];
			++$seen;
			foreach ( $contains[ $node ] ?? array() as $child ) {
				--$indegree[ $child ];
				if ( 0 === $indegree[ $child ] ) {
					$queue[] = $child;
				}
			}
		}
		if ( count( $nodes ) !== $seen ) {
			throw new ContractException( 'relationship_invalid', 'Document containment cycle is invalid.' );
		}
	}

	/** Return one current head joined to its exact complete snapshot. */
	private function active_rows( Owner $owner, string $document_key ): array {
		$graph_key = $this->graph_key( $owner, $document_key );
		$rows = $this->db->rows( 'SELECT h.*,s.attempt_id,s.version_hash,s.version_json,s.snapshot_hash,s.graph_hash,s.relation_hash,s.native_artifact_hash,s.completeness,s.consumer_complete,s.node_count,s.edge_count,s.slot_count,s.graph_json,s.native_artifact_json FROM ' . $this->db->table( 'graph_heads' ) . ' h JOIN ' . $this->db->table( 'graph_snapshots' ) . " s ON s.graph_key=h.graph_key AND s.generation=h.active_generation AND s.state='complete' WHERE h.graph_key=%s AND h.eligible=1", array( $graph_key ) );
		if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() || $rows[0]['document_key'] !== $document_key || ! hash_equals( $rows[0]['current_version_hash'], $rows[0]['version_hash'] ) ) {
			throw new ContractException( 'not_found', 'Current document graph is unavailable.' );
		}
		return $rows;
	}

	private function authorized_head( array $rows, Owner $owner, GraphVersion $version ): array {
		if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() ) {
			throw new ContractException( 'not_found', 'Document graph is unavailable.' );
		}
		if ( ! hash_equals( $rows[0]['current_version_hash'], $version->hash() ) ) {
			throw new ContractException( 'stale_version', 'Graph version changed before extraction.' );
		}
		return $rows[0];
	}

	/** Re-lock the active head after decode before returning read or mutation input. */
	private function final_fence( Owner $owner, string $document_key, int $generation, GraphVersion $version, string $snapshot_hash, string $graph_hash, string $relation_hash, ?string $artifact_hash, ?object $artifact ): void {
		$graph_key = $this->graph_key( $owner, $document_key );
		$valid = $this->db->transaction(
			function ( Database $db ) use ( $owner, $graph_key, $document_key, $generation, $version, $snapshot_hash, $graph_hash, $relation_hash, $artifact_hash, $artifact ): bool {
				$rows = $db->rows( 'SELECT h.owner_ref,h.document_key,h.current_version_hash,h.active_generation,h.eligible,s.version_hash,s.snapshot_hash,s.graph_hash,s.relation_hash,s.native_artifact_hash,s.state FROM ' . $db->table( 'graph_heads' ) . ' h JOIN ' . $db->table( 'graph_snapshots' ) . ' s ON s.graph_key=h.graph_key AND s.generation=h.active_generation WHERE h.graph_key=%s FOR UPDATE', array( $graph_key ) );
				if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() || $rows[0]['document_key'] !== $document_key || ! (bool) $rows[0]['eligible'] || (int) $rows[0]['active_generation'] !== $generation || 'complete' !== $rows[0]['state'] || ! is_string( $rows[0]['version_hash'] ) || ! is_string( $rows[0]['snapshot_hash'] ) || ! is_string( $rows[0]['graph_hash'] ) || ! is_string( $rows[0]['relation_hash'] ) || ! hash_equals( $rows[0]['current_version_hash'], $version->hash() ) || ! hash_equals( $rows[0]['version_hash'], $version->hash() ) || ! hash_equals( $rows[0]['snapshot_hash'], $snapshot_hash ) || ! hash_equals( $rows[0]['graph_hash'], $graph_hash ) || ! hash_equals( $rows[0]['relation_hash'], $relation_hash ) || ( null === $artifact_hash ? null !== $rows[0]['native_artifact_hash'] : null === $rows[0]['native_artifact_hash'] || ! hash_equals( $rows[0]['native_artifact_hash'], $artifact_hash ) ) ) {
					return false;
				}
				if ( null === $artifact ) {
					return true;
				}
				$authorized = $db->rows( 'SELECT a.content_hash FROM ' . $db->table( 'artifacts' ) . ' a INNER JOIN ' . $db->table( 'conversations' ) . ' c ON c.id=a.conversation_id WHERE a.public_id=%s AND a.version=%d AND c.owner_ref=%s AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND (a.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) FOR UPDATE', array( $artifact->id, $artifact->version, $owner->reference() ) );
				return $authorized && hash_equals( $authorized[0]['content_hash'], $artifact->hash );
			}
		);
		if ( ! $valid ) {
			throw new ContractException( 'stale_version', 'Graph changed during final delivery fencing.' );
		}
	}

	/** Verify the full ordinary relation index against its immutable snapshot binding. */
	private function assert_relation_integrity( array $active ): void {
		try {
			$version = new GraphVersion( get_object_vars( $this->decoder->decode( $active['version_json'] ) ) );
			$graph   = $this->validate_graph( $active['document_key'], $version, $this->decoder->decode( $active['graph_json'] ) );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Relationship source graph contract is invalid.' );
		}
		if ( ! is_string( $active['relation_hash'] ) ) {
			throw new ContractException( 'evidence_stale', 'Relationship source binding is invalid.' );
		}
		$binding = $this->snapshot_binding( $active['owner_ref'], $active['graph_key'], (int) $active['active_generation'], $active['attempt_id'], $active['version_hash'], $active['graph_json'], $active['native_artifact_json'], $active['relation_hash'] );
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'graph_edges' ) . ' WHERE graph_key=%s AND generation=%d ORDER BY edge_key', array( $active['graph_key'], (int) $active['active_generation'] ) );
		if ( count( $rows ) !== (int) $active['edge_count'] || ! is_string( $active['snapshot_hash'] ) || ! hash_equals( $active['snapshot_hash'], $binding ) || $active['completeness'] !== $graph['completeness'] || (bool) $active['consumer_complete'] !== $graph['consumer_complete'] || ! hash_equals( $active['relation_hash'], $graph['relation_hash'] ) || ! hash_equals( $active['relation_hash'], $this->stored_relation_hash( $rows, $active['graph_key'], $active['document_key'], $active['owner_ref'], (int) $active['active_generation'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Relationship index integrity is invalid.' );
		}
	}

	/** Load every active site graph within an explicit hard bound before completeness claims. */
	private function active_relationship_authority( int $limit ): array {
		$rows = $this->db->rows( 'SELECT h.*,s.attempt_id,s.version_hash,s.version_json,s.snapshot_hash,s.graph_json,s.native_artifact_json,s.relation_hash,s.completeness,s.consumer_complete,s.edge_count FROM ' . $this->db->table( 'graph_heads' ) . ' h JOIN ' . $this->db->table( 'graph_snapshots' ) . " s ON s.graph_key=h.graph_key AND s.generation=h.active_generation AND s.state='complete' WHERE h.eligible=1 ORDER BY h.graph_key LIMIT %d", array( $limit + 1 ) );
		if ( count( $rows ) > $limit ) {
			throw new ContractException( 'needs_expansion', 'Relationship authority exceeds its integrity-audit bound.' );
		}
		return $rows;
	}

	/** Initialize and read the monotonic epoch covering all relationship authority changes. */
	private function relationship_epoch(): int {
		$table = $this->db->table( 'graph_index_epochs' );
		$this->db->query( "INSERT IGNORE INTO $table (epoch_key,epoch,updated_at) VALUES ('site',0,UTC_TIMESTAMP())" );
		return (int) $this->db->scalar( "SELECT epoch FROM $table WHERE epoch_key='site'" );
	}

	/** Increment the site relationship epoch inside the caller's publication/invalidation transaction. */
	private function bump_relationship_epoch( Database $db ): void {
		$table = $db->table( 'graph_index_epochs' );
		$db->query( "INSERT INTO $table (epoch_key,epoch,updated_at) VALUES ('site',1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE epoch=epoch+1,updated_at=UTC_TIMESTAMP()" );
	}

	/** Re-lock epoch and every observed active generation after decoding/traversal. */
	private function relationship_final_fence( int $epoch, array $authority ): void {
		usort( $authority, static fn( array $left, array $right ): int => strcmp( $left['graph_key'], $right['graph_key'] ) );
		$valid = $this->db->transaction(
			function ( Database $db ) use ( $epoch, $authority ): bool {
				foreach ( $authority as $active ) {
					$rows = $db->rows( 'SELECT h.active_generation,h.eligible,h.current_version_hash,s.attempt_id,s.version_hash,s.snapshot_hash,s.relation_hash,s.completeness,s.consumer_complete,s.state FROM ' . $db->table( 'graph_heads' ) . ' h JOIN ' . $db->table( 'graph_snapshots' ) . ' s ON s.graph_key=h.graph_key AND s.generation=h.active_generation WHERE h.graph_key=%s FOR UPDATE', array( $active['graph_key'] ) );
					if ( ! $rows || ! (bool) $rows[0]['eligible'] || (int) $rows[0]['active_generation'] !== (int) $active['active_generation'] || 'complete' !== $rows[0]['state'] || $rows[0]['attempt_id'] !== $active['attempt_id'] || ! is_string( $rows[0]['version_hash'] ) || ! is_string( $rows[0]['snapshot_hash'] ) || ! is_string( $rows[0]['relation_hash'] ) || ! is_string( $active['version_hash'] ) || ! is_string( $active['snapshot_hash'] ) || ! is_string( $active['relation_hash'] ) || ! hash_equals( $rows[0]['current_version_hash'], $rows[0]['version_hash'] ) || ! hash_equals( $rows[0]['version_hash'], $active['version_hash'] ) || ! hash_equals( $rows[0]['snapshot_hash'], $active['snapshot_hash'] ) || ! hash_equals( $rows[0]['relation_hash'], $active['relation_hash'] ) || $rows[0]['completeness'] !== $active['completeness'] || (bool) $rows[0]['consumer_complete'] !== (bool) $active['consumer_complete'] ) {
						return false;
					}
				}
				$epochs = $db->rows( 'SELECT epoch FROM ' . $db->table( 'graph_index_epochs' ) . " WHERE epoch_key='site' FOR UPDATE" );
				return $epochs && (int) $epochs[0]['epoch'] === $epoch;
			}
		);
		if ( ! $valid ) {
			throw new ContractException( 'stale_version', 'Relationship authority changed during delivery.' );
		}
	}

	private function version_values( GraphVersion $version ): array {
		return array( $version->hash(), $version->source_hash(), $version->editor_epoch() ?? '', $version->editor_revision() ?? '', $version->environment_hash(), $version->directory_schema_hash(), $version->adapter_revision(), $version->normalization_revision() );
	}

	private function head_value( array $row ): array {
		return array( 'document_key' => $row['document_key'], 'source_epoch' => (int) $row['source_epoch'], 'generation_counter' => (int) $row['generation_counter'], 'current_version_hash' => $row['current_version_hash'], 'active_generation' => null === $row['active_generation'] ? null : (int) $row['active_generation'], 'building_generation' => null === $row['building_generation'] ? null : (int) $row['building_generation'], 'eligible' => (bool) $row['eligible'], 'state' => $row['state'] );
	}

	/** Owner-scoped physical key prevents actor collisions for one native document. */
	private function graph_key( Owner $owner, string $document_key ): string {
		return hash( 'sha256', $this->blog_id . '|' . $owner->reference() . '|' . $document_key );
	}

	/** Bind private snapshot bytes to their site, owner, attempt and complete relation set. */
	private function snapshot_binding( string $owner_ref, string $graph_key, int $generation, string $attempt_id, string $version_hash, string $graph_json, ?string $artifact_json, string $relation_hash ): string {
		$binding = array(
			'site_id'              => $this->blog_id,
			'owner_ref'            => $owner_ref,
			'graph_key'            => $graph_key,
			'generation'           => $generation,
			'attempt_id'           => $attempt_id,
			'version_hash'         => $version_hash,
			'graph_json'           => $graph_json,
			'native_artifact_json' => $artifact_json,
			'relation_hash'        => $relation_hash,
		);
		return $this->canonical->digest( $binding );
	}

	private function artifact_json( Owner $owner, ?object $artifact ): array {
		if ( null === $artifact ) {
			return array( 'json' => null, 'hash' => null );
		}
		if ( ! $this->exact_keys( $artifact, array( 'id', 'version', 'hash' ) ) || ! is_int( $artifact->version ) || $artifact->version < 1 || ! is_string( $artifact->hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $artifact->hash ) ) {
			throw new ContractException( 'invalid_input', 'Private native graph artifact is invalid.' );
		}
		GraphVersion::identifier( $artifact->id );
		try {
			$stored = $this->artifacts->get( $owner, $artifact->id, $artifact->version );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'unauthorized', 'Private native graph artifact is unavailable to this owner.' );
		}
		if ( ! hash_equals( $stored['content_hash'], $artifact->hash ) ) {
			throw new ContractException( 'evidence_stale', 'Private native graph artifact content changed.' );
		}
		$json = $this->canonical->encode( $artifact );
		return array( 'json' => $json, 'hash' => hash( 'sha256', $json ) );
	}

	/** Hash the complete ordered semantic edge set, including index columns and provenance. */
	private function relation_hash( array $rows ): string {
		$normalized = array();
		foreach ( $rows as $row ) {
			$normalized[] = array(
				'key'                 => $row['key'],
				'kind'                => $row['kind'],
				'source'              => $row['source'],
				'target'              => $row['target'],
				'target_document_key' => $row['target_document_key'],
				'scope_hash'          => $row['scope_hash'],
				'order'               => $row['order'],
				'provenance_json'     => $row['provenance_json'],
				'json'                => $row['json'],
			);
		}
		usort( $normalized, static fn( array $left, array $right ): int => strcmp( $left['key'], $right['key'] ) );
		return $this->canonical->digest( $normalized );
	}

	/** Normalize database index rows and reject any unbound column mutation. */
	private function stored_relation_hash( array $rows, string $graph_key, string $document_key, string $owner_ref, int $generation ): string {
		$normalized = array();
		foreach ( $rows as $row ) {
			if ( $row['graph_key'] !== $graph_key || $row['document_key'] !== $document_key || $row['owner_ref'] !== $owner_ref || (int) $row['generation'] !== $generation ) {
				throw new ContractException( 'evidence_stale', 'Relationship index identity is invalid.' );
			}
			$normalized[] = array(
				'key'                 => $row['edge_key'],
				'kind'                => $row['kind'],
				'source'              => $row['source_node'],
				'target'              => $row['target_node'],
				'target_document_key' => $row['target_document_key'],
				'scope_hash'          => $row['scope_hash'],
				'order'               => null === $row['edge_order'] ? null : (int) $row['edge_order'],
				'provenance_json'     => $row['provenance_json'],
				'json'                => $row['edge_json'],
			);
		}
		return $this->relation_hash( $normalized );
	}

	/** Object keys are closed but input member order carries no meaning. */
	private function exact_keys( object $value, array $expected ): bool {
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $actual === $expected;
	}

	/** Locator snapshots bind native paths to the same editor epoch as GraphVersion. */
	private function locator( object $locator, GraphVersion $version, object $document ): void {
		if ( ! $this->exact_keys( $locator, array( 'document', 'snapshot_hash', 'slot_path', 'native_id', 'editor_epoch' ) ) || ! $locator->document instanceof \stdClass || ! $this->exact_keys( $locator->document, array( 'builder', 'native_kind', 'native_id' ) ) || $this->canonical->encode( $locator->document ) !== $this->canonical->encode( $document ) || ! is_array( $locator->slot_path ) || ! $locator->slot_path || count( $locator->slot_path ) > 32 || ! is_string( $locator->snapshot_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $locator->snapshot_hash ) || $locator->editor_epoch !== $version->editor_epoch() ) {
			throw new ContractException( 'stale_version', 'Graph locator snapshot or editor epoch is stale.' );
		}
		if ( null !== $locator->native_id ) {
			GraphVersion::identifier( $locator->native_id );
		}
		foreach ( $locator->slot_path as $part ) {
			GraphVersion::identifier( $part );
		}
	}

	/** Scope coordinates are closed and builder-neutral. */
	private function scope( object $scope ): void {
		if ( ! $this->exact_keys( $scope, array( 'directory_id', 'view', 'breakpoint_id', 'interaction' ) ) || ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ( null !== $scope->breakpoint_id && ! is_string( $scope->breakpoint_id ) ) || ! in_array( $scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Graph scope is invalid.' );
		}
		if ( null !== $scope->breakpoint_id ) {
			GraphVersion::identifier( $scope->breakpoint_id );
		}
	}

	private function site(): void {
		if ( get_current_blog_id() !== $this->blog_id ) {
			throw new ContractException( 'scope_conflict', 'Graph store belongs to another site.' );
		}
	}

	private function actor( Owner $owner ): void {
		if ( null === $owner->user_id() ) {
			throw new ContractException( 'unauthorized', 'Builder graphs require an authenticated owner.' );
		}
	}

	private function document( string $document_key ): void {
		GraphVersion::identifier( $document_key );
	}

	private function builder( string $builder ): void {
		if ( ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Graph builder is invalid.' );
		}
	}

	private function limit( int $value, int $maximum ): void {
		if ( $value < 1 || $value > $maximum ) {
			throw new ContractException( 'invalid_input', 'Graph query bound is invalid.' );
		}
	}
}
