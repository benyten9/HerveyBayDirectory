<?php
/**
 * Owned conversations and atomic request/sequence allocation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

use DirectoristAIPro\Validation\JsonDecoder;

/** Durable records; gateway capability checks remain separately mandatory. */
final class ConversationStore {
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
	 * Create once per owner/request; conflicting reuse never overwrites context.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $request_id Client deduplication identity.
	 * @param string $profile Authorized profile, checked by the gateway.
	 * @param object $context Validated task context.
	 * @param int    $retention_seconds Site retention policy.
	 * @return array Original or created conversation.
	 * @throws StorageException Invalid input or conflicting request reuse.
	 */
	public function create( Owner $owner, string $request_id, string $profile, object $context, int $retention_seconds ): array {
		$this->db->readiness()->assert_ready();
		self::identifier( $request_id );
		if ( ! in_array( $profile, array( 'admin', 'owner', 'visitor', 'builder' ), true ) || $retention_seconds < 1 || $retention_seconds > 31536000 ) {
			throw new StorageException( 'Invalid conversation policy.' );
		}
		( new JsonDecoder() )->check( $context );
		$json = json_encode( $context, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		$hash = hash( 'sha256', $profile . "\0" . $json );
		return $this->db->transaction(
			function ( Database $db ) use ( $owner, $request_id, $profile, $json, $hash, $retention_seconds ): array {
				$table        = $db->table( 'conversations' );
				$owner_column = null !== $owner->user_id() ? 'owner_user_id' : 'guest_hash';
				$owner_value  = null !== $owner->user_id() ? (string) $owner->user_id() : $owner->guest_hash();
				$db->query(
					"INSERT INTO $table (public_id,$owner_column,owner_ref,request_id,input_hash,profile,retention_seconds,context_json,created_at,updated_at,expires_at) VALUES (%s,%s,%s,%s,%s,%s,%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
					array( bin2hex( random_bytes( 16 ) ), $owner_value, $owner->reference(), $request_id, $hash, $profile, $retention_seconds, $json, $retention_seconds )
				);
				$rows = $db->rows( "SELECT * FROM $table WHERE owner_ref=%s AND request_id=%s FOR UPDATE", array( $owner->reference(), $request_id ) );
				if ( ! $rows || (int) $rows[0]['erasure_requested'] || ! hash_equals( $rows[0]['input_hash'], $hash ) ) {
					throw new StorageConflictException( 'Conversation request identity conflicts with earlier input.' );
				}
				return $rows[0];
			}
		);
	}

	/**
	 * Read only an owned conversation, including an explicit retained active run.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $public_id Conversation identity.
	 * @return array Owned row.
	 * @throws StorageException Missing, expired or inaccessible conversation.
	 */
	public function get( Owner $owner, string $public_id ): array {
		self::identifier( $public_id );
		$table = $this->db->table( 'conversations' );
		$rows  = $this->db->rows( "SELECT * FROM $table WHERE public_id=%s AND owner_ref=%s AND erasure_requested=0 AND (expires_at>UTC_TIMESTAMP() OR active_run_id IS NOT NULL)", array( $public_id, $owner->reference() ) );
		if ( ! $rows ) {
			throw new StorageException( 'Conversation is unavailable.' );
		}
		return $rows[0];
	}

	/**
	 * Append one turn and message atomically; orchestration is a separate task.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $conversation_id Conversation identity.
	 * @param string $request_id Request deduplication key.
	 * @param object $input Strictly validated turn input.
	 * @return array Original or inserted turn.
	 * @throws StorageException Inaccessible conversation or conflicting retry.
	 */
	public function append( Owner $owner, string $conversation_id, string $request_id, object $input ): array {
		$result = $this->db->transaction( fn(): array => $this->append_in_transaction( $owner, $conversation_id, $request_id, $input ) );
		return $result['turn'];
	}

	/**
	 * Append while a runtime repository owns the surrounding short transaction.
	 *
	 * This method performs database work only. It accepts no callback and cannot
	 * wait for a provider, browser, native editor or user while holding locks.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $conversation_id Conversation identity.
	 * @param string $request_id Request deduplication key.
	 * @param object $input Strictly validated turn input.
	 * @return array Turn, locked conversation snapshot and insertion flag.
	 * @throws StorageException Missing transaction, inaccessible state or conflict.
	 * @throws StorageConflictException Reused request identity with different input.
	 */
	public function append_in_transaction( Owner $owner, string $conversation_id, string $request_id, object $input ): array {
		$this->db->readiness()->assert_ready();
		if ( ! $this->db->in_transaction() ) {
			throw new StorageException( 'Atomic turn allocation requires a runtime transaction.' );
		}
		self::identifier( $request_id );
		( new JsonDecoder() )->check( $input );
		$json = json_encode( $input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		$hash = hash( 'sha256', $json );
		self::identifier( $conversation_id );
		$db            = $this->db;
		$conversations = $db->table( 'conversations' );
		$rows          = $db->rows( "SELECT * FROM $conversations WHERE public_id=%s AND owner_ref=%s AND erasure_requested=0 AND (expires_at>UTC_TIMESTAMP() OR active_run_id IS NOT NULL) FOR UPDATE", array( $conversation_id, $owner->reference() ) );
		if ( ! $rows ) {
			throw new StorageException( 'Conversation is unavailable.' );
		}
		$conversation = $rows[0];
		$turns        = $db->table( 'turns' );
		// The conversation row serializes its turns. A locking read of an absent
		// request also locks index gaps shared with other conversations and can
		// deadlock independent visitors before their first insert. Allocate or
		// recover the exact unique request first, then lock only its existing row.
		$db->query( "INSERT INTO $turns (conversation_id,sequence,request_id,input_hash,state,input_json,created_at) VALUES (%d,%d,%s,%s,'queued',%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", array( (int) $conversation['id'], (int) $conversation['next_turn_seq'], $request_id, $hash, $json ) );
		$turn_id   = $db->insert_id();
		$allocated = $db->rows( "SELECT * FROM $turns WHERE id=%d FOR UPDATE", array( $turn_id ) );
		if ( ! $allocated || $allocated[0]['request_id'] !== $request_id || ! hash_equals( $allocated[0]['input_hash'], $hash ) ) {
			throw new StorageConflictException( 'Turn request identity conflicts with earlier input.' );
		}
		if ( (int) $allocated[0]['sequence'] !== (int) $conversation['next_turn_seq'] ) {
			return array(
				'turn'         => $allocated[0],
				'conversation' => $conversation,
				'inserted'     => false,
			);
		}
		$messages = $db->table( 'messages' );
		$db->query( "INSERT INTO $messages (conversation_id,turn_id,sequence,role,content_json,source_refs_json,created_at) VALUES (%d,%d,%d,'user',%s,'[]',UTC_TIMESTAMP())", array( (int) $conversation['id'], $turn_id, (int) $conversation['next_message_seq'], $json ) );
		$db->query( "UPDATE $conversations SET next_turn_seq=next_turn_seq+1,next_message_seq=next_message_seq+1,state_version=state_version+1,updated_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL retention_seconds SECOND) WHERE id=%d", array( (int) $conversation['id'] ) );
		return array(
			'turn'         => $db->rows( "SELECT * FROM $turns WHERE id=%d", array( $turn_id ) )[0],
			'conversation' => $conversation,
			'inserted'     => true,
		);
	}

	/**
	 * Return a bounded visible-message page for an owned conversation.
	 *
	 * Source visibility references are returned for a later live-redaction layer;
	 * internal actor, lease and operation records are never part of chat history.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $conversation_id Conversation identity.
	 * @param int    $after Last delivered message sequence.
	 * @param int    $limit Maximum messages.
	 * @param bool   $tail Read newest messages before the sequence bound.
	 * @return array Conversation state and visible messages.
	 * @throws StorageException Inaccessible conversation or invalid bounds.
	 */
	public function history( Owner $owner, string $conversation_id, int $after = 0, int $limit = 50, bool $tail = false ): array {
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new StorageException( 'Invalid history bounds.' );
		}
		$conversation = $this->get( $owner, $conversation_id );
		$messages     = $this->db->table( 'messages' );
		$turns        = $this->db->table( 'turns' );
		if ( $tail ) {
			$bound = 0 !== $after ? $after : (int) $conversation['next_message_seq'];
			$rows  = array_reverse( $this->db->rows( "SELECT m.sequence,m.turn_id,m.role,m.content_json,m.source_refs_json,m.created_at,t.input_json FROM $messages m JOIN $turns t ON t.id=m.turn_id AND t.conversation_id=m.conversation_id WHERE m.conversation_id=%d AND m.sequence<%d ORDER BY m.sequence DESC LIMIT %d", array( (int) $conversation['id'], $bound, $limit ) ) );
		} else {
			$rows = $this->db->rows( "SELECT m.sequence,m.turn_id,m.role,m.content_json,m.source_refs_json,m.created_at,t.input_json FROM $messages m JOIN $turns t ON t.id=m.turn_id AND t.conversation_id=m.conversation_id WHERE m.conversation_id=%d AND m.sequence>%d ORDER BY m.sequence LIMIT %d", array( (int) $conversation['id'], $after, $limit ) );
		}
		return array(
			'conversation_id'   => $conversation['public_id'],
			'profile'           => $conversation['profile'],
			'state_version'     => (int) $conversation['state_version'],
			'active_run_id'     => null === $conversation['active_run_id'] ? null : (int) $conversation['active_run_id'],
			'messages'          => $rows,
			'previous_sequence' => $rows ? (int) $rows[0]['sequence'] : 0,
			'has_earlier'       => $rows && (bool) $this->db->scalar( "SELECT COUNT(*) FROM $messages WHERE conversation_id=%d AND sequence<%d", array( (int) $conversation['id'], (int) $rows[0]['sequence'] ) ),
			'next_sequence'     => $rows ? (int) end( $rows )['sequence'] : $after,
		);
	}

	/**
	 * Validate an opaque public/request identity.
	 *
	 * @param string $identifier Supplied identity.
	 * @return void
	 * @throws StorageException Invalid identifier.
	 */
	public static function identifier( string $identifier ): void {
		if ( ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $identifier ) ) {
			throw new StorageException( 'Invalid record identity.' );
		}
	}
}
