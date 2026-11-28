<?php
/**
 * Owned exports, erasure requests and bounded runtime cleanup.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

/** Privacy starts with storage; later modules extend source/file cleanup. */
final class Lifecycle {
	/**
	 * Dedicated connection.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind a site-scoped connection.
	 *
	 * @param Database $db Runtime database.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Page through owned records; callers still apply live source visibility.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $conversation_id Owned conversation.
	 * @param string $collection Approved collection name.
	 * @param int    $after Last exported internal row ID.
	 * @param int    $limit Bounded page size.
	 * @return array Export page without credentials or hidden reasoning.
	 * @throws StorageException Unsupported export request.
	 */
	public function export( Owner $owner, string $conversation_id, string $collection, int $after = 0, int $limit = 100 ): array {
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new StorageException( 'Invalid export bounds.' );
		}
		$conversation     = ( new ConversationStore( $this->db ) )->get( $owner, $conversation_id );
		$conversations_id = (int) $conversation['id'];
		$runs             = $this->db->table( 'runs' );
		$artifacts        = $this->db->table( 'artifacts' );
		if ( in_array( $collection, array( 'turns', 'messages', 'runs', 'artifacts' ), true ) ) {
			$table = $this->db->table( $collection );
			$sql   = "SELECT t.* FROM $table t WHERE t.conversation_id=%d AND t.id>%d ORDER BY t.id LIMIT %d";
		} elseif ( in_array( $collection, array( 'steps', 'events', 'operations', 'usage_reservations' ), true ) ) {
			$table = $this->db->table( $collection );
			$sql   = "SELECT t.* FROM $table t INNER JOIN $runs r ON r.id=t.run_id WHERE r.conversation_id=%d AND t.id>%d ORDER BY t.id LIMIT %d";
		} elseif ( 'approvals' === $collection ) {
			$table = $this->db->table( 'approvals' );
			$sql   = "SELECT t.* FROM $table t INNER JOIN $artifacts a ON a.public_id=t.artifact_id AND a.version=t.artifact_version WHERE a.conversation_id=%d AND t.id>%d ORDER BY t.id LIMIT %d";
		} else {
			throw new StorageException( 'Unsupported export collection.' );
		}
		$rows = $this->db->rows( $sql, array( $conversations_id, $after, $limit ) );
		// Export has no native source verifier. Never export retained grounded facts.
		foreach ( $rows as &$row ) {
			// A recovery archive retains the original private producer audit. Raw
			// exports cannot revalidate its sources, regardless of the current phase.
			if ( 'steps' === $collection && 'evidence_snapshot' === ( $row['kind'] ?? null ) ) {
				$row['data_json'] = '{"status":"withheld","reason":"evidence_archive_private"}';
			}
			if ( 'messages' === $collection && 'assistant' === $row['role'] ) {
				$content = json_decode( $row['content_json'] );
				if ( isset( $content->grounded ) || isset( $content->provenance ) ) {
					$row['content_json']     = wp_json_encode(
						(object) array(
							'text'   => 'This saved grounded answer is unavailable in raw export.',
							'status' => 'withheld',
						)
					);
					$row['source_refs_json'] = '[]';
				}
			}
			$checkpoint = null;
			if ( 'runs' === $collection ) {
				$checkpoint = json_decode( $row['checkpoint_json'] );
			} elseif ( isset( $row['run_id'] ) ) {
				$checkpoint = json_decode( (string) $this->db->scalar( "SELECT checkpoint_json FROM $runs WHERE id=%d AND conversation_id=%d", array( (int) $row['run_id'], $conversations_id ) ) );
			}
			$sourced = isset( $checkpoint->grounded_input ) || isset( $checkpoint->grounded_hash ) || isset( $checkpoint->visitor_delivery_recovery_archive ) || isset( $checkpoint->visitor_delivery_replacement );
			foreach ( $checkpoint->tool_results ?? array() as $result ) {
				$sourced = $sourced || in_array( $result->response->operation ?? '', array( 'listing.search', 'listing.compare', 'knowledge.search' ), true );
			}
			if ( $sourced ) {
				foreach ( array( 'checkpoint_json', 'context_json', 'data_json', 'payload_json', 'receipt_json' ) as $field ) {
					if ( isset( $row[ $field ] ) ) {
						$row[ $field ] = '{"status":"withheld","reason":"grounded_export_revalidation_unavailable"}';
					}
				}
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Hide owned data immediately and cancel future work; cleanup may be pending.
	 *
	 * @param Owner  $owner Actual owner.
	 * @param string $conversation_id Conversation to erase.
	 * @return void
	 * @throws StorageException Missing or inaccessible conversation.
	 */
	public function request_erasure( Owner $owner, string $conversation_id ): void {
		$this->db->transaction(
			function ( Database $db ) use ( $owner, $conversation_id ): void {
				$c    = $db->table( 'conversations' );
				$rows = $db->rows( "SELECT id FROM $c WHERE public_id=%s AND owner_ref=%s FOR UPDATE", array( $conversation_id, $owner->reference() ) );
				if ( ! $rows ) {
					throw new StorageException( 'Conversation is unavailable.' );
				}
				$id = (int) $rows[0]['id'];
				$db->query( "UPDATE $c SET erasure_requested=1,state_version=state_version+1 WHERE id=%d", array( $id ) );
				$runs = $db->table( 'runs' );
				// A queued/waiting run cannot be claimed after erasure, so settle it
				// here. A running lease keeps its fence and observes cancellation.
				$db->query( "UPDATE $runs SET cancel_requested=1,state='cancelled',state_version=state_version+1,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE conversation_id=%d AND state IN ('queued','waiting_input','waiting_approval','waiting_browser')", array( $id ) );
				$db->query( "UPDATE $runs SET cancel_requested=1 WHERE conversation_id=%d AND state='running'", array( $id ) );
				$db->query( "UPDATE $c c LEFT JOIN $runs r ON r.id=c.active_run_id SET c.active_run_id=NULL,c.state_version=c.state_version+1 WHERE c.id=%d AND (r.id IS NULL OR r.state<>'running')", array( $id ) );
				$approvals = $db->table( 'approvals' );
				$artifacts = $db->table( 'artifacts' );
				$db->query( "UPDATE $approvals p INNER JOIN $artifacts a ON a.public_id=p.artifact_id AND a.version=p.artifact_version SET p.state='revoked' WHERE a.conversation_id=%d AND p.state IN ('staged','pending','approved')", array( $id ) );
				$tasks = $db->table( 'visitor_tasks' );
				$db->query( "UPDATE $tasks SET state='discarded',state_version=state_version+1,prepared_json='{}',proposal_hash=NULL,quote_id=NULL,quote_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE conversation_public_id=%s AND owner_ref=%s AND state IN ('draft','ready','needs_input','unavailable')", array( $conversation_id, $owner->reference() ) );
				// Native records remain authoritative; completed chat inputs are not an accounting receipt.
				$db->query( "UPDATE $tasks SET prepared_json='{}',proposal_hash=NULL,quote_id=NULL,quote_expires_at=NULL WHERE conversation_public_id=%s AND owner_ref=%s AND state NOT IN ('executing','unknown')", array( $conversation_id, $owner->reference() ) );
				$receipts   = $db->table( 'task_receipts' );
				$rows       = $db->rows( "SELECT t.task_id,r.state AS run_state,v.state AS task_state FROM $receipts t LEFT JOIN $runs r ON r.public_id=t.run_id AND r.owner_ref=t.owner_ref LEFT JOIN $tasks v ON v.public_id=t.task_id AND v.owner_ref=t.owner_ref WHERE t.owner_ref=%s AND t.state NOT IN ('completed','released') AND (r.conversation_id=%d OR v.conversation_public_id=%s)", array( $owner->reference(), $id, $conversation_id ) );
				$accounting = new \DirectoristAIPro\AI\TaskAccounting( $db );
				foreach ( $rows as $receipt ) {
					if ( 'discarded' === $receipt['task_state'] || 'cancelled' === $receipt['run_state'] ) {
						$accounting->finish( $owner, $receipt['task_id'], 'cancelled' );
					} else {
						$accounting->uncertain( $owner, $receipt['task_id'] );
					}
				}
			}
		);
	}

	/**
	 * Purge a bounded batch, preserving and reporting active/unknown effects.
	 *
	 * @param int $limit Maximum conversations inspected in this batch.
	 * @param int $after Last scanned internal conversation ID.
	 * @return array Purged/held conversation IDs and reasons.
	 * @throws StorageException Invalid batch size.
	 */
	public function cleanup( int $limit = 100, int $after = 0 ): array {
		$this->db->readiness()->assert_ready();
		if ( $limit < 1 || $limit > 100 || $after < 0 ) {
			throw new StorageException( 'Invalid cleanup bounds.' );
		}
		$db         = $this->db;
		$c          = $db->table( 'conversations' );
		$candidates = $db->rows( "SELECT id,public_id FROM $c WHERE id>%d AND (erasure_requested=1 OR expires_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT %d", array( $after, $limit ) );
		$report     = array(
			'purged'     => array(),
			'held'       => array(),
			'next_after' => $after,
			'has_more'   => count( $candidates ) === $limit,
		);
		foreach ( $candidates as $candidate ) {
			$report['next_after'] = (int) $candidate['id'];
			$outcome              = $db->transaction(
				function ( Database $db ) use ( $c, $candidate ): string {
					$id   = (int) $candidate['id'];
					$rows = $db->rows( "SELECT * FROM $c WHERE id=%d AND (erasure_requested=1 OR expires_at<=UTC_TIMESTAMP()) FOR UPDATE", array( $id ) );
					if ( ! $rows ) {
						return 'changed';
					}
					$runs       = $db->table( 'runs' );
					$operations = $db->table( 'operations' );
					$active     = (int) $db->scalar( "SELECT COUNT(*) FROM $runs WHERE conversation_id=%d AND state NOT IN ('succeeded','failed','cancelled','partial')", array( $id ) );
					$unknown    = (int) $db->scalar( "SELECT COUNT(*) FROM $operations o INNER JOIN $runs r ON r.id=o.run_id WHERE r.conversation_id=%d AND o.state NOT IN ('succeeded','failed','cancelled')", array( $id ) );
					if ( $active || $unknown || null !== $rows[0]['active_run_id'] ) {
						return 'active_or_unresolved';
					}
					$artifacts = $db->table( 'artifacts' );
					$approvals = $db->table( 'approvals' );
					// Preserve minimal deduplication/audit metadata, remove personal payloads.
					$db->query( "UPDATE $operations o INNER JOIN $runs r ON r.id=o.run_id SET o.receipt_json='{}' WHERE r.conversation_id=%d", array( $id ) );
					$db->query( "UPDATE $approvals p INNER JOIN $artifacts a ON a.public_id=p.artifact_id AND a.version=p.artifact_version SET p.review_json='{}',p.state='revoked' WHERE a.conversation_id=%d", array( $id ) );
					foreach ( array( 'steps', 'events', 'usage_reservations' ) as $child ) {
						$table = $db->table( $child );
						$db->query( "DELETE t FROM $table t INNER JOIN $runs r ON r.id=t.run_id WHERE r.conversation_id=%d", array( $id ) );
					}
					foreach ( array( 'turns', 'messages', 'artifacts', 'runs' ) as $child ) {
						$table = $db->table( $child );
						$db->query( "DELETE FROM $table WHERE conversation_id=%d", array( $id ) );
					}
					$db->query( "DELETE FROM $c WHERE id=%d", array( $id ) );
					return 'purged';
				}
			);
			if ( 'purged' === $outcome ) {
				$report['purged'][] = $candidate['public_id'];
			} elseif ( 'changed' !== $outcome ) {
				$report['held'][ $candidate['public_id'] ] = $outcome;
			}
		}
		return $report;
	}
	/**
	 * Expire bounded terminal-run progress records and old minimal audit data.
	 *
	 * @param int $audit_days Site audit-retention policy.
	 * @param int $limit Maximum records per collection per invocation.
	 * @return array Removed row counts by collection.
	 * @throws StorageException Invalid retention bounds.
	 */
	public function prune_records( int $audit_days = 90, int $limit = 100 ): array {
		$this->db->readiness()->assert_ready();
		if ( $audit_days < 1 || $audit_days > 365 || $limit < 1 || $limit > 1000 ) {
			throw new StorageException( 'Invalid retention bounds.' );
		}
		$db         = $this->db;
		$runs       = $db->table( 'runs' );
		$events     = $db->table( 'events' );
		$operations = $db->table( 'operations' );
		$approvals  = $db->table( 'approvals' );
		$usage      = $db->table( 'usage_reservations' );
		$windows    = $db->table( 'usage_windows' );
		$report     = array(
			'events'             => 0,
			'operations'         => 0,
			'approvals'          => 0,
			'usage_reservations' => 0,
			'usage_windows'      => 0,
		);
		// Terminal events can expire independently of the longer chat lifetime.
		$rows = $db->rows( "SELECT e.id FROM $events e LEFT JOIN $runs r ON r.id=e.run_id WHERE e.expires_at<=UTC_TIMESTAMP() AND (r.id IS NULL OR r.state IN ('succeeded','failed','cancelled')) AND NOT EXISTS (SELECT 1 FROM $operations o WHERE o.run_id=e.run_id AND o.state NOT IN ('succeeded','failed','cancelled')) ORDER BY e.id LIMIT %d", array( $limit ) );
		foreach ( $rows as $row ) {
			$db->query( "DELETE FROM $events WHERE id=%d AND expires_at<=UTC_TIMESTAMP()", array( (int) $row['id'] ) );
			$report['events'] += $db->affected();
		}
		$rows = $db->rows( "SELECT o.id FROM $operations o LEFT JOIN $runs r ON r.id=o.run_id WHERE o.state IN ('succeeded','failed','cancelled') AND o.updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY) AND (r.id IS NULL OR r.state IN ('succeeded','failed','cancelled')) ORDER BY o.id LIMIT %d", array( $audit_days, $limit ) );
		foreach ( $rows as $row ) {
			$locks = $db->table( 'target_locks' );
			$db->query( "DELETE FROM $locks WHERE operation_id=%d", array( (int) $row['id'] ) );
			$db->query( "DELETE FROM $operations WHERE id=%d AND state IN ('succeeded','failed','cancelled') AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY)", array( (int) $row['id'], $audit_days ) );
			$report['operations'] += $db->affected();
		}
		$rows = $db->rows( "SELECT p.id FROM $approvals p LEFT JOIN $operations o ON o.id=p.reserved_operation_id WHERE p.expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY) AND o.id IS NULL ORDER BY p.id LIMIT %d", array( $audit_days, $limit ) );
		foreach ( $rows as $row ) {
			$db->query( "DELETE FROM $approvals WHERE id=%d AND expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY)", array( (int) $row['id'], $audit_days ) );
			$report['approvals'] += $db->affected();
		}
		$rows = $db->rows( "SELECT u.id FROM $usage u INNER JOIN $runs r ON r.id=u.run_id WHERE u.settled_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY) AND r.state IN ('succeeded','failed','cancelled','partial') ORDER BY u.id LIMIT %d", array( $audit_days, $limit ) );
		foreach ( $rows as $row ) {
			$db->query( "DELETE FROM $usage WHERE id=%d AND settled_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY)", array( (int) $row['id'], $audit_days ) );
			$report['usage_reservations'] += $db->affected();
		}
		$db->query( "DELETE FROM $windows WHERE window_start<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY) LIMIT %d", array( $audit_days, $limit ) );
		$report['usage_windows'] += $db->affected();
		return $report;
	}
}
