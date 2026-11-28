<?php
/**
 * One visible visitor conversation per verified site owner.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Lifecycle;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Storage\StorageException;

/** Serializes allocation and clearing without retaining a browser credential. */
final class VisitorConversations {

	/** Site-scoped connection.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind the site database.
	 *
	 * @param Database $db Site-scoped connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Find the latest generation; an erased generation never revives older chats.
	 *
	 * @param Owner $owner Verified account or guest.
	 * @return array|null Current retained row.
	 */
	public function current( Owner $owner ): ?array {
		$this->db->readiness()->assert_ready();
		$meta    = $this->db->table( 'meta' );
		$pointer = $this->db->scalar( "SELECT value FROM $meta WHERE name=%s", array( $this->pointer_key( $owner ) ) );
		if ( null !== $pointer ) {
			if ( '' === $pointer ) {
				return null;
			}
			try {
				return ( new ConversationStore( $this->db ) )->get( $owner, $pointer );
			} catch ( StorageException $error ) {
				return null;
			}
		}
		$table = $this->db->table( 'conversations' );
		$rows  = $this->db->rows( "SELECT * FROM $table WHERE owner_ref=%s AND profile='visitor' ORDER BY id DESC LIMIT 1", array( $owner->reference() ) );
		if ( ! $rows || (int) $rows[0]['erasure_requested'] ) {
			return null;
		}
		try {
			return ( new ConversationStore( $this->db ) )->get( $owner, $rows[0]['public_id'] );
		} catch ( StorageException $error ) {
			return null;
		}
	}

	/**
	 * Reuse one chat even when two tabs start with different request identities.
	 *
	 * @param Owner  $owner Verified owner.
	 * @param string $request Request deduplication identity.
	 * @param object $context Initial native context.
	 * @param int    $retention Retention seconds.
	 * @return array Current or allocated row.
	 */
	public function create( Owner $owner, string $request, object $context, int $retention ): array {
		return $this->locked(
			$owner,
			function () use ( $owner, $request, $context, $retention ): array {
				$row = $this->current( $owner ) ?? ( new ConversationStore( $this->db ) )->create( $owner, $request, 'visitor', $context, $retention );
				$this->point( $owner, $row['public_id'] );
				return $row;
			}
		);
	}

	/** Save an explicit preference without creating a turn, model call or native effect. */
	public function language( Owner $owner, string $id, string $preference, ?object $expected_policy = null ): array {
		$preference = \DirectoristAIPro\AI\LanguagePolicy::preference( $preference );
		return $this->locked( $owner, function () use ( $owner, $id, $preference, $expected_policy ): array {
			return $this->db->transaction( function ( Database $db ) use ( $owner, $id, $preference, $expected_policy ): array {
				$row = ( new ConversationStore( $db ) )->get( $owner, $id );
				if ( 'visitor' !== $row['profile'] || ( $this->current( $owner )['public_id'] ?? null ) !== $id ) {
					throw new StorageException( 'This visitor conversation is unavailable.' );
				}
				$rows = $db->rows( 'SELECT * FROM ' . $db->table( 'conversations' ) . ' WHERE id=%d AND owner_ref=%s AND erasure_requested=0 FOR UPDATE', array( (int) $row['id'], $owner->reference() ) );
				if ( ! $rows ) { throw new StorageException( 'This visitor conversation is unavailable.' ); }
				$row = $rows[0];
				$context = json_decode( $row['context_json'] );
				if ( null !== $expected_policy ) {
					$expected = (object) array( 'chat_language' => $expected_policy->preference, 'chat_language_confirmed' => true === ( $expected_policy->confirmed ?? false ), 'chat_language_resolved' => \DirectoristAIPro\AI\LanguagePolicy::resolved( $expected_policy ), 'chat_language_revision' => $expected_policy->revision ?? 0 );
					if ( \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $context ) !== \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $expected ) ) {
						return array_merge( array( 'conversation_id' => $id ), \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $context ) );
					}
				}
				$next = \DirectoristAIPro\AI\LanguagePolicy::explicit_context( $context, $preference );
				if ( \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $context ) !== \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $next ) ) {
					$context = $next;
					$db->query( 'UPDATE ' . $db->table( 'conversations' ) . ' SET context_json=%s,state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND owner_ref=%s', array( wp_json_encode( $context ), (int) $row['id'], $owner->reference() ) );
				}
				return array_merge( array( 'conversation_id' => $id ), \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $next ) );
			} );
		} );
	}

	/** Persist one same-language substantive classification, never an explicit choice. */
	public function resolve_language( Owner $owner, string $id, string $expected_preference ): object {
		$expected_preference = \DirectoristAIPro\AI\LanguagePolicy::preference( $expected_preference );
		return $this->locked( $owner, function () use ( $owner, $id, $expected_preference ): object {
			return $this->db->transaction( function ( Database $db ) use ( $owner, $id, $expected_preference ): object {
				$row = ( new ConversationStore( $db ) )->get( $owner, $id );
				if ( 'visitor' !== $row['profile'] || ( $this->current( $owner )['public_id'] ?? null ) !== $id ) { throw new StorageException( 'This visitor conversation is unavailable.' ); }
				$rows = $db->rows( 'SELECT context_json FROM ' . $db->table( 'conversations' ) . ' WHERE id=%d AND owner_ref=%s AND erasure_requested=0 FOR UPDATE', array( (int) $row['id'], $owner->reference() ) );
				if ( ! $rows ) { throw new StorageException( 'This visitor conversation is unavailable.' ); }
				$context = json_decode( $rows[0]['context_json'] ); $state = \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $context );
				if ( ! $state['chat_language_resolved'] && $state['chat_language'] === $expected_preference ) {
					$context->chat_language_resolved = true; $context->chat_language_source = 'auto'; $context->chat_language_revision = $state['chat_language_revision'] + 1;
					$db->query( 'UPDATE ' . $db->table( 'conversations' ) . ' SET context_json=%s,state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND owner_ref=%s', array( wp_json_encode( $context ), (int) $row['id'], $owner->reference() ) );
				}
				return $context;
			} );
		} );
	}

	/**
	 * Clear the exact current generation without erasing a newer tab's chat.
	 *
	 * @param Owner  $owner Verified owner.
	 * @param string $id Exact displayed conversation.
	 * @return array Clear receipt.
	 */
	public function clear( Owner $owner, string $id ): array {
		ConversationStore::identifier( $id );
		return $this->locked(
			$owner,
			function () use ( $owner, $id ): array {
				$table = $this->db->table( 'conversations' );
				$rows  = $this->db->rows( "SELECT id,erasure_requested FROM $table WHERE public_id=%s AND owner_ref=%s AND profile='visitor'", array( $id, $owner->reference() ) );
				if ( ! $rows ) {
					throw new StorageException( 'Conversation is unavailable.' );
				}
				$current = $this->current( $owner );
				if ( ! (int) $rows[0]['erasure_requested'] ) {
					( new Lifecycle( $this->db ) )->request_erasure( $owner, $id );
				}
				if ( $current && $current['public_id'] === $id ) {
					$this->point( $owner, '' );
				}
				return array(
					'cleared'         => true,
					'conversation_id' => $id,
				);
			}
		);
	}

	/**
	 * Keep only an opaque conversation pointer independent of transcript cleanup.
	 *
	 * @param Owner $owner Verified owner.
	 * @return string Bounded metadata key.
	 */
	private function pointer_key( Owner $owner ): string {
		return 'visitor_head_' . substr( hash( 'sha256', $owner->reference() ), 0, 40 );
	}

	/**
	 * Record the current generation under the owner mutex.
	 *
	 * @param Owner  $owner Verified owner.
	 * @param string $id Current conversation or cleared sentinel.
	 */
	private function point( Owner $owner, string $id ): void {
		$table = $this->db->table( 'meta' );
		$this->db->query( "INSERT INTO $table (name,value) VALUES (%s,%s) ON DUPLICATE KEY UPDATE value=VALUES(value)", array( $this->pointer_key( $owner ), $id ) );
	}

	/**
	 * A site/owner mutex covers only short database operations.
	 *
	 * @param Owner    $owner Verified owner.
	 * @param callable $work Allocation or clearing callback.
	 * @return array|object Callback result.
	 * @throws StorageException Concurrent operation is busy.
	 */
	private function locked( Owner $owner, callable $work ): array|object {
		$key = 'dai-visitor-' . substr( hash( 'sha256', $this->db->table( 'conversations' ) . $owner->reference() ), 0, 40 );
		if ( '1' !== (string) $this->db->scalar( 'SELECT GET_LOCK(%s,2)', array( $key ) ) ) {
			throw new StorageException( 'Conversation is busy. Please try again.' );
		}
		try {
			return $work();
		} finally {
			$this->db->scalar( 'SELECT RELEASE_LOCK(%s)', array( $key ) );
		}
	}
}
