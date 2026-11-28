<?php
/** Durable visitor proposals and native receipts, independent of chat erasure.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment -- Repository methods implement the task state contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Never hold a database transaction across native extension code. */
final class VisitorWorkflowStore {
	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	public function conversation( Context $context, string $id ): array {
		$context->assert_execution_identity();
		$this->db->readiness()->assert_ready();
		$row = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $id );
		if ( 'visitor' !== $context->profile() || 'visitor' !== $row['profile'] ) {
			throw new ContractException( 'unauthorized', 'This customer workflow is unavailable.' );
		}
		return $row;
	}

	public function create( Context $context, string $conversation, string $request, string $adapter, string $revision, int $directory, int $listing, object $inputs ): array {
		ConversationStore::identifier( $request );
		$this->conversation( $context, $conversation );
		$hash = hash( 'sha256', wp_json_encode( array( $conversation, $adapter, $revision, $directory, $listing, $inputs ) ) );
		return $this->db->transaction(
			function ( Database $db ) use ( $context, $conversation, $request, $adapter, $revision, $directory, $listing, $hash ): array {
				$table = $db->table( 'visitor_tasks' );
				$db->query( "INSERT INTO $table (public_id,owner_ref,conversation_public_id,run_public_id,request_id,input_hash,adapter_id,adapter_revision,directory_id,listing_id,state,operation_key,prepared_json,created_at,updated_at,expires_at) VALUES (%s,%s,%s,NULLIF(%s,''),%s,%s,%s,%s,%d,%d,'draft',%s,'{}',UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 365 DAY)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", array( bin2hex( random_bytes( 16 ) ), $context->actor()->owner()->reference(), $conversation, $context->run_id() ?? '', $request, $hash, $adapter, $revision, $directory, $listing, bin2hex( random_bytes( 32 ) ) ) );
				$rows = $db->rows( "SELECT * FROM $table WHERE owner_ref=%s AND request_id=%s FOR UPDATE", array( $context->actor()->owner()->reference(), $request ) );
				if ( ! $rows || ! hash_equals( $rows[0]['input_hash'], $hash ) ) {
					throw new ContractException( 'request_conflict', 'This workflow request belongs to different input.' );
				}
				return $rows[0];
			}
		);
	}

	public function get( Context $context, string $id ): array {
		ConversationStore::identifier( $id );
		$context->assert_execution_identity();
		$this->db->readiness()->assert_ready();
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'visitor_tasks' ) . ' WHERE public_id=%s AND owner_ref=%s AND expires_at>UTC_TIMESTAMP()', array( $id, $context->actor()->owner()->reference() ) );
		if ( ! $rows ) {
			throw new ContractException( 'unauthorized', 'This customer workflow is unavailable.' );
		}
		return $rows[0];
	}

	public function list_current( Context $context, string $conversation ): array {
		$this->conversation( $context, $conversation );
		return array_reverse( $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'visitor_tasks' ) . ' WHERE conversation_public_id=%s AND owner_ref=%s AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 6', array( $conversation, $context->actor()->owner()->reference() ) ) );
	}

	public function prepared( array $row, array $prepared ): array {
		$proposal = $prepared['proposal'] ?? null;
		$hash     = null === $proposal ? '' : hash( 'sha256', wp_json_encode( $proposal, JSON_PRESERVE_ZERO_FRACTION ) );
		$quote    = 'ready' === $prepared['status'] ? bin2hex( random_bytes( 16 ) ) : '';
		$this->db->query( 'UPDATE ' . $this->db->table( 'visitor_tasks' ) . " SET state=%s,state_version=state_version+1,prepared_json=%s,proposal_hash=NULLIF(%s,''),quote_id=NULLIF(%s,''),quote_expires_at=IF(%s='',NULL,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state_version=%d", array( $prepared['status'], wp_json_encode( $prepared, JSON_PRESERVE_ZERO_FRACTION ), $hash, $quote, $quote, (int) $row['id'], (int) $row['state_version'] ) );
		$this->assert_updated();
		return $this->reload( $row );
	}

	public function state( array $row, string $state, ?array $receipt = null ): array {
		$this->db->query( 'UPDATE ' . $this->db->table( 'visitor_tasks' ) . ' SET state=%s,state_version=state_version+1,receipt_json=COALESCE(NULLIF(%s,\'\'),receipt_json),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state_version=%d', array( $state, null === $receipt ? '' : wp_json_encode( $receipt, JSON_PRESERVE_ZERO_FRACTION ), (int) $row['id'], (int) $row['state_version'] ) );
		$this->assert_updated();
		return $this->reload( $row );
	}

	/**
	 * One connection lock protects repeated clicks; a durable state fences crashes.
	 *
	 * @throws ContractException When the task is already being processed.
	 */
	public function locked( Context $context, string $id, callable $callback ) {
		$lock = 'dai-task-' . substr( hash( 'sha256', $this->db->table( 'visitor_tasks' ) . $id ), 0, 40 );
		if ( '1' !== (string) $this->db->scalar( 'SELECT GET_LOCK(%s, 2)', array( $lock ) ) ) {
			throw new ContractException( 'busy', 'This request is being processed. Refresh its status.' );
		}
		try {
			return $callback( $this->get( $context, $id ) );
		} finally {
			$this->db->scalar( 'SELECT RELEASE_LOCK(%s)', array( $lock ) );
		}
	}

	private function assert_updated(): void {
		if ( 1 !== $this->db->affected() ) {
			throw new ContractException( 'stale_version', 'This workflow changed. Refresh before continuing.' );
		}
	}

	/** Keep unresolved native operations for explicit reconciliation.
	 *
	 * @param int $limit Maximum resolved rows.
	 * @return int Removed rows.
	 * @throws ContractException Invalid cleanup bound.
	 */
	public function purge_expired( int $limit = 100 ): int {
		if ( $limit < 1 || $limit > 100 ) {
			throw new ContractException( 'invalid_input', 'Invalid workflow cleanup bound.' );
		}
		$this->db->readiness()->assert_ready();
		$this->db->query( 'DELETE FROM ' . $this->db->table( 'visitor_tasks' ) . " WHERE expires_at<=UTC_TIMESTAMP() AND state NOT IN ('executing','unknown') ORDER BY expires_at LIMIT %d", array( $limit ) );
		return $this->db->affected();
	}

	private function reload( array $row ): array {
		return $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'visitor_tasks' ) . ' WHERE id=%d AND owner_ref=%s', array( (int) $row['id'], $row['owner_ref'] ) )[0];
	}
}
