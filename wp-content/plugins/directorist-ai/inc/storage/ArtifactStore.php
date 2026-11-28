<?php
/**
 * Immutable, conversation-owned private artifact versions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

use DirectoristAIPro\Validation\JsonDecoder;

/** Artifacts are stored privately; no public upload URL is generated. */
final class ArtifactStore {
	/**
	 * Dedicated connection.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind a site-scoped connection.
	 *
	 * @param Database $db Runtime connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Create or append an artifact while locking its owning conversation.
	 *
	 * @param Owner       $owner Server-resolved owner.
	 * @param string      $conversation_id Owning conversation.
	 * @param string      $kind Trusted artifact kind.
	 * @param object      $payload Typed private payload.
	 * @param string      $environment_hash Current environment hash.
	 * @param string|null $artifact_id Existing artifact or null for new.
	 * @param int|null    $base_version Expected head version for append.
	 * @return array New immutable row.
	 * @throws StorageException Invalid kind, ownership or stale version.
	 */
	public function put( Owner $owner, string $conversation_id, string $kind, object $payload, string $environment_hash, ?string $artifact_id = null, ?int $base_version = null ): array {
		if ( ! in_array( $kind, array( 'proposal', 'native_snapshot', 'builder_draft', 'media_suggestion', 'validation_report', 'source_facts', 'task_summary' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $environment_hash ) ) {
			throw new StorageException( 'Invalid artifact metadata.' );
		}
		if ( ( null === $artifact_id ) !== ( null === $base_version ) ) {
			throw new StorageException( 'Artifact append requires an explicit base version.' );
		}
		( new JsonDecoder( 5242880, 64 ) )->check( $payload );
		$json        = json_encode( $payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		$artifact_id = $artifact_id ?? bin2hex( random_bytes( 16 ) );
		ConversationStore::identifier( $artifact_id );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $conversation_id, $kind, $json, $environment_hash, $artifact_id, $base_version ): array {
				$conversations = $db->table( 'conversations' );
				$owned         = $db->rows( "SELECT id,expires_at FROM $conversations WHERE public_id=%s AND owner_ref=%s AND erasure_requested=0 AND (expires_at>UTC_TIMESTAMP() OR active_run_id IS NOT NULL) FOR UPDATE", array( $conversation_id, $owner->reference() ) );
				if ( ! $owned ) {
					throw new StorageException( 'Artifact owner is unavailable.' );
				}
				$table = $db->table( 'artifacts' );
				$head  = $db->rows( "SELECT * FROM $table WHERE public_id=%s ORDER BY version DESC LIMIT 1 FOR UPDATE", array( $artifact_id ) );
				if ( $head ) {
					if ( $head[0]['conversation_id'] !== $owned[0]['id'] || (int) $head[0]['version'] !== $base_version || $head[0]['kind'] !== $kind ) {
						throw new StorageConflictException( 'Artifact base version or ownership has changed.' );
					}
				} elseif ( null !== $base_version ) {
					throw new StorageConflictException( 'Artifact base version is unavailable.' );
				}
				$version = null === $base_version ? 1 : $base_version + 1;
				$db->query( "INSERT INTO $table (public_id,version,parent_version,owner_ref,conversation_id,kind,environment_hash,content_hash,payload_json,source_refs_json,created_at,expires_at) VALUES (%s,%d,NULLIF(%d,0),%s,%d,%s,%s,%s,%s,'[]',UTC_TIMESTAMP(),%s)", array( $artifact_id, $version, $base_version ?? 0, $owner->reference(), (int) $owned[0]['id'], $kind, $environment_hash, hash( 'sha256', $json ), $json, $owned[0]['expires_at'] ) );
				return $db->rows( "SELECT * FROM $table WHERE public_id=%s AND version=%d", array( $artifact_id, $version ) )[0];
			}
		);
	}

	/**
	 * Read an immutable version only through its current owner scope.
	 *
	 * @param Owner  $owner Server-resolved principal.
	 * @param string $artifact_id Artifact identity.
	 * @param int    $version Exact requested version.
	 * @return array Private artifact row.
	 * @throws StorageException Missing/expired/inaccessible artifact.
	 */
	public function get( Owner $owner, string $artifact_id, int $version ): array {
		ConversationStore::identifier( $artifact_id );
		$table         = $this->db->table( 'artifacts' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT a.* FROM $table a INNER JOIN $conversations c ON c.id=a.conversation_id WHERE a.public_id=%s AND a.version=%d AND c.owner_ref=%s AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND (a.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL)", array( $artifact_id, $version, $owner->reference() ) );
		if ( ! $rows ) {
			throw new StorageException( 'Artifact is unavailable.' );
		}
		return $rows[0];
	}

	/**
	 * Create one deterministic private artifact or return its exact retry.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $conversation_id Owning conversation.
	 * @param string $artifact_id Server-derived deterministic identity.
	 * @param string $kind Trusted artifact kind.
	 * @param object $payload Typed private payload.
	 * @param string $environment_hash Current environment hash.
	 * @return array Original or inserted immutable row.
	 * @throws StorageException Invalid metadata or inaccessible owner.
	 */
	public function put_once( Owner $owner, string $conversation_id, string $artifact_id, string $kind, object $payload, string $environment_hash ): array {
		ConversationStore::identifier( $artifact_id );
		if ( ! in_array( $kind, array( 'proposal', 'native_snapshot', 'builder_draft', 'media_suggestion' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $environment_hash ) ) {
			throw new StorageException( 'Invalid deterministic artifact metadata.' );
		}
		( new JsonDecoder( 5242880, 64 ) )->check( $payload );
		$json = json_encode( $payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		$hash = hash( 'sha256', $json );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $conversation_id, $artifact_id, $kind, $environment_hash, $json, $hash ): array {
				$conversations = $db->table( 'conversations' );
				$owned         = $db->rows( "SELECT id,expires_at FROM $conversations WHERE public_id=%s AND owner_ref=%s AND erasure_requested=0 AND (expires_at>UTC_TIMESTAMP() OR active_run_id IS NOT NULL) FOR UPDATE", array( $conversation_id, $owner->reference() ) );
				if ( ! $owned ) {
					throw new StorageException( 'Artifact owner is unavailable.' );
				}
				$table = $db->table( 'artifacts' );
				$db->query( "INSERT IGNORE INTO $table (public_id,version,parent_version,owner_ref,conversation_id,kind,environment_hash,content_hash,payload_json,source_refs_json,created_at,expires_at) VALUES (%s,1,NULL,%s,%d,%s,%s,%s,%s,'[]',UTC_TIMESTAMP(),%s)", array( $artifact_id, $owner->reference(), (int) $owned[0]['id'], $kind, $environment_hash, $hash, $json, $owned[0]['expires_at'] ) );
				$rows = $db->rows( "SELECT * FROM $table WHERE public_id=%s AND version=1 FOR UPDATE", array( $artifact_id ) );
				if ( ! $rows || $rows[0]['owner_ref'] !== $owner->reference() || (int) $rows[0]['conversation_id'] !== (int) $owned[0]['id'] || $rows[0]['kind'] !== $kind || ! hash_equals( $rows[0]['environment_hash'], $environment_hash ) || ! hash_equals( $rows[0]['content_hash'], $hash ) ) {
					throw new StorageConflictException( 'Deterministic artifact identity conflicts with earlier content.' );
				}
				return $rows[0];
			}
		);
	}
}
