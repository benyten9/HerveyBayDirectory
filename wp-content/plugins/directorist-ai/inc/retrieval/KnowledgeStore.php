<?php
/**
 * Approved knowledge-source and hidden-generation storage.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Repository invariants are described at the public boundaries.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageException;

/** Source approval is authoritative; chunks are replaceable derived data. */
final class KnowledgeStore {
	private Database $db;

	/** Bind isolated runtime storage. */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/** Select or replace one source and record its approving actor. */
	public function approve( string $source_key, int $post_id, string $scope, ?int $directory_id, string $source_revision, int $actor_user_id ): array {
		$this->source_key( $source_key );
		$this->positive( $post_id );
		$this->positive( $actor_user_id );
		$this->scope( $scope );
		if ( null !== $directory_id ) {
			$this->positive( $directory_id );
		}
		$this->hash( $source_revision );
		return $this->db->transaction(
			function ( Database $db ) use ( $source_key, $post_id, $scope, $directory_id, $source_revision, $actor_user_id ): array {
				$sources = $db->table( 'knowledge_sources' );
				$chunks  = $db->table( 'knowledge_chunks' );
				$rows    = $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) );
				$aliases = $db->rows( "SELECT source_key FROM $sources WHERE post_id=%d AND scope_key=%s AND directory_key=%d FOR UPDATE", array( $post_id, $scope, $directory_id ?? 0 ) );
				if ( $aliases && $aliases[0]['source_key'] !== $source_key ) {
					throw new StorageException( 'Knowledge source alias already exists.' );
				}
				if ( $rows && 1 === (int) $rows[0]['approved'] && (int) $rows[0]['post_id'] === $post_id && $rows[0]['scope_key'] === $scope && ( null === $directory_id ? null === $rows[0]['directory_id'] : (int) $rows[0]['directory_id'] === $directory_id ) && hash_equals( $rows[0]['source_revision'], $source_revision ) ) {
					return $this->normalize( $rows[0] );
				}
				if ( $rows && null !== $rows[0]['building_generation'] ) {
					$db->query( "DELETE FROM $chunks WHERE source_key=%s AND generation=%d AND eligible=0", array( $source_key, (int) $rows[0]['building_generation'] ) );
				}
				$db->query(
					"INSERT INTO $sources (source_key,post_id,scope_key,directory_id,directory_key,approval_epoch,generation_counter,approved,eligible,wake_required,source_revision,active_generation,building_generation,building_revision,state,cursor_position,updated_at) VALUES (%s,%d,%s,NULLIF(%d,0),%d,1,0,1,0,1,%s,NULL,NULL,NULL,'pending',0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE post_id=VALUES(post_id),scope_key=VALUES(scope_key),directory_id=VALUES(directory_id),directory_key=VALUES(directory_key),approval_epoch=approval_epoch+1,approved=1,eligible=0,wake_required=1,source_revision=VALUES(source_revision),building_generation=NULL,building_revision=NULL,state='pending',cursor_position=0,updated_at=UTC_TIMESTAMP()",
					array( $source_key, $post_id, $scope, $directory_id ?? 0, $directory_id ?? 0, $source_revision )
				);
				$db->query( "UPDATE $chunks SET eligible=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s AND eligible=1", array( $source_key ) );
				$row = $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) )[0];
				$this->audit( $db, $row, 'approve', $actor_user_id );
				return $this->normalize( $row );
			}
		);
	}

	/** Revoke synchronously with its chunks and append actor provenance. */
	public function revoke( string $source_key, int $actor_user_id ): bool {
		$this->source_key( $source_key );
		$this->positive( $actor_user_id );
		return $this->db->transaction(
			function ( Database $db ) use ( $source_key, $actor_user_id ): bool {
				$sources = $db->table( 'knowledge_sources' );
				$chunks  = $db->table( 'knowledge_chunks' );
				$rows    = $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) );
				if ( ! $rows || 1 !== (int) $rows[0]['approved'] ) {
					return false;
				}
				if ( null !== $rows[0]['building_generation'] ) {
					$db->query( "DELETE FROM $chunks WHERE source_key=%s AND generation=%d AND eligible=0", array( $source_key, (int) $rows[0]['building_generation'] ) );
				}
				$db->query( "UPDATE $chunks SET eligible=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s AND eligible=1", array( $source_key ) );
				$db->query( "UPDATE $sources SET approval_epoch=approval_epoch+1,approved=0,eligible=0,wake_required=0,building_generation=NULL,building_revision=NULL,state='revoked',cursor_position=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s", array( $source_key ) );
				$row = $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) )[0];
				$this->audit( $db, $row, 'revoke', $actor_user_id );
				return true;
			}
		);
	}

	/** Fence an approved source and atomically make every old chunk ineligible. */
	public function observe( string $source_key, string $source_revision, bool $native_eligible ): ?array {
		$this->source_key( $source_key );
		$this->hash( $source_revision );
		return $this->db->transaction(
			function ( Database $db ) use ( $source_key, $source_revision, $native_eligible ): ?array {
				$sources = $db->table( 'knowledge_sources' );
				$chunks  = $db->table( 'knowledge_chunks' );
				$rows    = $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) );
				if ( ! $rows || 1 !== (int) $rows[0]['approved'] ) {
					return null;
				}
				if ( hash_equals( $rows[0]['source_revision'], $source_revision ) ) {
					$current = $native_eligible ? ( (bool) $rows[0]['eligible'] || in_array( $rows[0]['state'], array( 'pending', 'building' ), true ) ) : ( ! (bool) $rows[0]['eligible'] && 'ineligible' === $rows[0]['state'] );
					if ( $current ) {
						return $this->normalize( $rows[0] );
					}
				}
				if ( null !== $rows[0]['building_generation'] ) {
					$db->query( "DELETE FROM $chunks WHERE source_key=%s AND generation=%d AND eligible=0", array( $source_key, (int) $rows[0]['building_generation'] ) );
				}
				$db->query( "UPDATE $chunks SET eligible=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s AND eligible=1", array( $source_key ) );
				$db->query( "UPDATE $sources SET approval_epoch=approval_epoch+1,eligible=0,wake_required=%d,source_revision=%s,building_generation=NULL,building_revision=NULL,state=%s,cursor_position=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s", array( $native_eligible ? 1 : 0, $source_revision, $native_eligible ? 'pending' : 'ineligible', $source_key ) );
				return $this->normalize( $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) )[0] );
			}
		);
	}

	/** Read one source configuration. */
	public function source( string $source_key ): ?array {
		$this->source_key( $source_key );
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'knowledge_sources' ) . ' WHERE source_key=%s', array( $source_key ) );
		return $rows ? $this->normalize( $rows[0] ) : null;
	}

	/** Return one keyset page of approved keys bound to one native document. */
	public function keys_for_post( int $post_id, string $after = '', int $limit = 100 ): array {
		$this->positive( $post_id );
		if ( '' !== $after ) {
			$this->source_key( $after );
		}
		if ( $limit < 1 || $limit > 100 ) {
			throw new StorageException( 'Knowledge source page is invalid.' );
		}
		return array_column( $this->db->rows( 'SELECT source_key FROM ' . $this->db->table( 'knowledge_sources' ) . ' WHERE post_id=%d AND approved=1 AND source_key>%s ORDER BY source_key LIMIT %d', array( $post_id, $after, $limit ) ), 'source_key' );
	}

	/** Return one keyset page of approved keys bound to one Directorist directory. */
	public function keys_for_directory( int $directory_id, string $after = '', int $limit = 100 ): array {
		$this->positive( $directory_id );
		if ( '' !== $after ) {
			$this->source_key( $after );
		}
		if ( $limit < 1 || $limit > 100 ) {
			throw new StorageException( 'Knowledge directory page is invalid.' );
		}
		return array_column( $this->db->rows( 'SELECT source_key FROM ' . $this->db->table( 'knowledge_sources' ) . ' WHERE directory_key=%d AND approved=1 AND source_key>%s ORDER BY source_key LIMIT %d', array( $directory_id, $after, $limit ) ), 'source_key' );
	}

	/** Return a bounded recovery scan of sources still requiring a queue wake. */
	public function wake_required( int $limit = 50 ): array {
		if ( $limit < 1 || $limit > 100 ) {
			throw new StorageException( 'Knowledge recovery limit is invalid.' );
		}
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'knowledge_sources' ) . ' WHERE approved=1 AND wake_required=1 ORDER BY updated_at,source_key LIMIT %d', array( $limit ) );
		return array_map( fn( array $row ): array => $this->normalize( $row ), $rows );
	}

	/** Start or resume a hidden generation with a never-reused monotonic identity. */
	public function begin( string $source_key, int $approval_epoch, string $source_revision ): int {
		$this->source_key( $source_key );
		$this->positive( $approval_epoch );
		$this->hash( $source_revision );
		return $this->db->transaction(
			function ( Database $db ) use ( $source_key, $approval_epoch, $source_revision ): int {
				$sources = $db->table( 'knowledge_sources' );
				$chunks  = $db->table( 'knowledge_chunks' );
				$rows    = $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) );
				if ( ! $rows || 1 !== (int) $rows[0]['approved'] || (int) $rows[0]['approval_epoch'] !== $approval_epoch || ! hash_equals( $rows[0]['source_revision'], $source_revision ) ) {
					throw new StorageException( 'Knowledge source fence is stale.' );
				}
				if ( 'building' === $rows[0]['state'] && null !== $rows[0]['building_generation'] && hash_equals( (string) $rows[0]['building_revision'], $source_revision ) ) {
					return (int) $rows[0]['building_generation'];
				}
				if ( null !== $rows[0]['building_generation'] ) {
					$db->query( "DELETE FROM $chunks WHERE source_key=%s AND generation=%d AND eligible=0", array( $source_key, (int) $rows[0]['building_generation'] ) );
				}
				$generation = (int) $rows[0]['generation_counter'] + 1;
				$db->query( "UPDATE $chunks SET eligible=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s AND eligible=1", array( $source_key ) );
				$db->query( "UPDATE $sources SET generation_counter=%d,building_generation=%d,building_revision=%s,state='building',cursor_position=0,eligible=0,wake_required=1,updated_at=UTC_TIMESTAMP() WHERE source_key=%s", array( $generation, $generation, $source_revision, $source_key ) );
				return $generation;
			}
		);
	}

	/** Stage one deterministic chunk under the current hidden generation. */
	public function stage( string $source_key, int $approval_epoch, string $source_revision, int $generation, array $chunk ): bool {
		$this->source_key( $source_key );
		$this->positive( $approval_epoch );
		$this->positive( $generation );
		$this->hash( $source_revision );
		$section_key    = $chunk['section_key'] ?? '';
		$position       = $chunk['position'] ?? 0;
		$document_title = $chunk['document_title'] ?? '';
		$section_title  = $chunk['section_title'] ?? '';
		$text           = $chunk['text'] ?? '';
		if ( ! is_string( $section_key ) || ! preg_match( '/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $section_key ) || ! is_int( $position ) || $position < 0 || ! is_string( $document_title ) || '' === trim( $document_title ) || strlen( $document_title ) > 500 || ! is_string( $section_title ) || '' === trim( $section_title ) || strlen( $section_title ) > 500 || ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > 4000 ) {
			throw new StorageException( 'Knowledge chunk is invalid.' );
		}
		$content_hash = hash( 'sha256', $document_title . "\n" . $section_title . "\n" . $text );
		$search_text  = self::normalize_text( $document_title . ' ' . $section_title . ' ' . $text );
		return $this->db->transaction(
			function ( Database $db ) use ( $source_key, $approval_epoch, $source_revision, $generation, $section_key, $position, $document_title, $section_title, $text, $search_text, $content_hash ): bool {
				$sources = $db->table( 'knowledge_sources' );
				$chunks  = $db->table( 'knowledge_chunks' );
				$rows    = $db->rows( "SELECT approved,approval_epoch,source_revision,scope_key,directory_id,building_generation,building_revision,state FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) );
				if ( ! $rows || 1 !== (int) $rows[0]['approved'] || (int) $rows[0]['approval_epoch'] !== $approval_epoch || 'building' !== $rows[0]['state'] || (int) $rows[0]['building_generation'] !== $generation || ! hash_equals( $rows[0]['source_revision'], $source_revision ) || ! hash_equals( (string) $rows[0]['building_revision'], $source_revision ) ) {
					return false;
				}
				$db->query( "INSERT INTO $chunks (source_key,generation,section_key,source_revision,scope_key,directory_id,position,content_hash,document_title,section_title,chunk_text,search_text,eligible,updated_at) VALUES (%s,%d,%s,%s,%s,NULLIF(%d,0),%d,%s,%s,%s,%s,%s,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE source_revision=VALUES(source_revision),scope_key=VALUES(scope_key),directory_id=VALUES(directory_id),position=VALUES(position),content_hash=VALUES(content_hash),document_title=VALUES(document_title),section_title=VALUES(section_title),chunk_text=VALUES(chunk_text),search_text=VALUES(search_text),eligible=0,updated_at=UTC_TIMESTAMP()", array( $source_key, $generation, $section_key, $source_revision, $rows[0]['scope_key'], null === $rows[0]['directory_id'] ? 0 : (int) $rows[0]['directory_id'], $position, $content_hash, $document_title, $section_title, $text, $search_text ) );
				return true;
			}
		);
	}

	/** Advance a resumable cursor under the exact generation fence. */
	public function checkpoint( string $source_key, int $approval_epoch, string $source_revision, int $generation, int $position ): bool {
		$this->source_key( $source_key );
		$this->positive( $approval_epoch );
		$this->positive( $generation );
		$this->hash( $source_revision );
		if ( $position < 0 ) {
			throw new StorageException( 'Knowledge cursor is invalid.' );
		}
		$table = $this->db->table( 'knowledge_sources' );
		$this->db->query( "UPDATE $table SET cursor_position=%d,updated_at=UTC_TIMESTAMP() WHERE source_key=%s AND approved=1 AND approval_epoch=%d AND source_revision=%s AND building_generation=%d AND building_revision=%s AND state='building'", array( $position, $source_key, $approval_epoch, $source_revision, $generation, $source_revision ) );
		return 1 === $this->db->affected();
	}

	/** Atomically make a complete generation eligible. */
	public function publish( string $source_key, int $approval_epoch, string $source_revision, int $generation, int $expected_chunks ): bool {
		$this->source_key( $source_key );
		$this->positive( $approval_epoch );
		$this->positive( $generation );
		$this->hash( $source_revision );
		if ( $expected_chunks < 1 || $expected_chunks > 500 ) {
			throw new StorageException( 'Knowledge chunk count is invalid.' );
		}
		return $this->db->transaction(
			function ( Database $db ) use ( $source_key, $approval_epoch, $source_revision, $generation, $expected_chunks ): bool {
				$sources = $db->table( 'knowledge_sources' );
				$chunks  = $db->table( 'knowledge_chunks' );
				$rows    = $db->rows( "SELECT * FROM $sources WHERE source_key=%s FOR UPDATE", array( $source_key ) );
				$count   = (int) $db->scalar( "SELECT COUNT(*) FROM $chunks WHERE source_key=%s AND generation=%d", array( $source_key, $generation ) );
				if ( ! $rows || $count !== $expected_chunks || 1 !== (int) $rows[0]['approved'] || (int) $rows[0]['approval_epoch'] !== $approval_epoch || 'building' !== $rows[0]['state'] || (int) $rows[0]['building_generation'] !== $generation || ! hash_equals( $rows[0]['source_revision'], $source_revision ) || ! hash_equals( (string) $rows[0]['building_revision'], $source_revision ) ) {
					return false;
				}
				$db->query( "UPDATE $chunks SET eligible=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s", array( $source_key ) );
				$db->query( "UPDATE $chunks SET eligible=1,updated_at=UTC_TIMESTAMP() WHERE source_key=%s AND generation=%d", array( $source_key, $generation ) );
				$db->query( "UPDATE $sources SET active_generation=%d,building_generation=NULL,building_revision=NULL,state='active',cursor_position=%d,eligible=1,wake_required=0,updated_at=UTC_TIMESTAMP() WHERE source_key=%s", array( $generation, $expected_chunks, $source_key ) );
				return true;
			}
		);
	}

	/** Return one ordered candidate page; callers apply live actor authority. */
	public function candidate_page( array $tokens, ?int $directory_id, array $source_keys, ?array $after, int $limit = 100 ): array {
		if ( ! $tokens || count( $tokens ) > 8 || $limit < 1 || $limit > 201 ) {
			throw new StorageException( 'Knowledge candidate request is invalid.' );
		}
		$where  = array( 's.approved=1', 's.eligible=1', 'c.eligible=1', 'c.generation=s.active_generation' );
		$params = array();
		if ( null === $directory_id ) {
			$where[] = 's.directory_key=0';
		} else {
			$this->positive( $directory_id );
			$where[]  = '(s.directory_key=0 OR s.directory_key=%d)';
			$params[] = $directory_id;
		}
		if ( $source_keys ) {
			if ( count( $source_keys ) > 20 ) {
				throw new StorageException( 'Too many knowledge sources requested.' );
			}
			foreach ( $source_keys as $source_key ) {
				$this->source_key( $source_key );
			}
			$where[] = 's.source_key IN (' . implode( ',', array_fill( 0, count( $source_keys ), '%s' ) ) . ')';
			$params  = array_merge( $params, $source_keys );
		}
		if ( null !== $after ) {
			$this->source_key( $after['source_key'] ?? '' );
			$position = $after['position'] ?? -1;
			if ( ! is_int( $position ) || $position < 0 ) {
				throw new StorageException( 'Knowledge candidate cursor is invalid.' );
			}
			$where[]  = '(s.source_key>%s OR (s.source_key=%s AND c.position>%d))';
			$params[] = $after['source_key'];
			$params[] = $after['source_key'];
			$params[] = $position;
		}
		$matches = array();
		foreach ( $tokens as $token ) {
			$matches[] = 'INSTR(c.search_text,%s)>0';
			$params[]  = $token;
		}
		$params[] = $limit;
		$sources  = $this->db->table( 'knowledge_sources' );
		$chunks   = $this->db->table( 'knowledge_chunks' );
		$sql      = "SELECT s.source_key,s.post_id,s.scope_key,s.directory_id,s.directory_key,s.approval_epoch,s.source_revision,s.active_generation,c.section_key,c.position,c.content_hash,c.document_title,c.section_title,c.chunk_text,c.search_text FROM $chunks c JOIN $sources s ON s.source_key=c.source_key WHERE " . implode( ' AND ', $where ) . ' AND (' . implode( ' OR ', $matches ) . ') ORDER BY s.source_key,c.position LIMIT %d';
		return $this->db->rows( $sql, $params );
	}

	/** Remove old ineligible chunks in a bounded indexed batch. */
	public function prune(): int {
		$chunks = $this->db->table( 'knowledge_chunks' );
		$this->db->query( "DELETE FROM $chunks WHERE eligible=0 AND updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY updated_at LIMIT 500" );
		return $this->db->affected();
	}

	/** Normalize text identically for storage and lexical queries. */
	public static function normalize_text( string $text ): string {
		$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/** Append immutable approval/revocation provenance inside its state transaction. */
	private function audit( Database $db, array $source, string $event, int $actor_user_id ): void {
		$details = hash( 'sha256', implode( ':', array( $source['post_id'], $source['scope_key'], $source['directory_key'], $source['approved'], $source['eligible'] ) ) );
		$db->query( 'INSERT INTO ' . $db->table( 'knowledge_audit' ) . ' (source_key,approval_epoch,event,actor_user_id,source_revision,details_hash,created_at) VALUES (%s,%d,%s,%d,%s,%s,UTC_TIMESTAMP())', array( $source['source_key'], (int) $source['approval_epoch'], $event, $actor_user_id, $source['source_revision'], $details ) );
	}

	/** Convert storage scalars without exposing implementation-only columns. */
	private function normalize( array $row ): array {
		return array(
			'source_key'          => $row['source_key'],
			'post_id'             => (int) $row['post_id'],
			'scope'               => $row['scope_key'],
			'directory_id'        => null === $row['directory_id'] ? null : (int) $row['directory_id'],
			'approval_epoch'      => (int) $row['approval_epoch'],
			'generation_counter'  => (int) $row['generation_counter'],
			'approved'            => (bool) $row['approved'],
			'eligible'            => (bool) $row['eligible'],
			'wake_required'       => (bool) $row['wake_required'],
			'source_revision'     => $row['source_revision'],
			'active_generation'   => null === $row['active_generation'] ? null : (int) $row['active_generation'],
			'building_generation' => null === $row['building_generation'] ? null : (int) $row['building_generation'],
			'state'               => $row['state'],
			'cursor_position'     => (int) $row['cursor_position'],
		);
	}

	private function source_key( string $source_key ): void {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $source_key ) ) {
			throw new StorageException( 'Knowledge source key is invalid.' );
		}
	}

	private function scope( string $scope ): void {
		if ( ! in_array( $scope, array( 'public', 'private' ), true ) ) {
			throw new StorageException( 'Knowledge source scope is invalid.' );
		}
	}

	private function hash( string $hash ): void {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new StorageException( 'Knowledge source revision is invalid.' );
		}
	}

	private function positive( int $value ): void {
		if ( $value < 1 ) {
			throw new StorageException( 'Knowledge identity is invalid.' );
		}
	}
}
