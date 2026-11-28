<?php
/**
 * Durable operation identities, target fences, receipts and reconciliation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Effects;

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageConflictException;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;

/** This service is not exposed until approval produces an execution permit. */
final class EffectLedger {
	/**
	 * Dedicated runtime connection.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind the runtime database.
	 *
	 * @param Database $db Dedicated runtime connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Execute a new exact plan once, reconciling response loss before returning.
	 *
	 * @param EffectPlan   $plan Server-created reviewed plan.
	 * @param NativeEffect $native Adapter-owned atomic native boundary.
	 * @return array Durable operation receipt.
	 * @throws ContractException Unknown outcome requires explicit reconciliation.
	 */
	public function execute( EffectPlan $plan, NativeEffect $native ): array {
		$this->db->readiness()->assert_ready();
		$reservation = $this->reserve( $plan );
		if ( in_array( $reservation['state'], array( 'succeeded', 'failed', 'partial' ), true ) ) {
			return $reservation['receipt'];
		}
		if ( in_array( $reservation['state'], array( 'running', 'unknown' ), true ) ) {
			throw new ContractException( 'unknown_outcome', 'Operation requires reconciliation before another write.' );
		}
		$this->start_operation( $reservation['operation_id'] );
		$receipt = $reservation['receipt'];
		foreach ( $plan->targets as $target ) {
			$fence = $reservation['fences'][ $target->key ];
			try {
				$this->db->readiness()->assert_ready();
				$outcome = $native->commit( $plan, $target, $fence );
			} catch ( \Throwable $error ) {
				try {
					$outcome = $native->reconcile( $plan, $target, $fence );
				} catch ( \Throwable $reconciliation_error ) {
					$outcome = new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_outcome_unavailable' );
				}
			}
			$receipt = $this->record_target( $reservation['operation_id'], $plan, $target, $fence, $outcome );
			if ( 'unknown' === $outcome->status ) {
				break;
			}
		}
		return $this->finalize( $reservation['operation_id'], $plan, $receipt );
	}

	/**
	 * Observe every target after a crash/lost response; never call commit here.
	 *
	 * @param EffectPlan   $plan Exact original plan.
	 * @param NativeEffect $native Adapter reconciliation implementation.
	 * @return array Updated durable receipt.
	 */
	public function reconcile( EffectPlan $plan, NativeEffect $native ): array {
		$reservation = $this->reserve( $plan, true );
		if ( in_array( $reservation['state'], array( 'succeeded', 'failed', 'partial' ), true ) ) {
			return $reservation['receipt'];
		}
		$receipt = $reservation['receipt'];
		foreach ( $plan->targets as $target ) {
			$fence = $reservation['fences'][ $target->key ];
			try {
				$outcome = $native->reconcile( $plan, $target, $fence );
			} catch ( \Throwable $error ) {
				$outcome = new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'native_outcome_unavailable' );
			}
			$receipt = $this->record_target( $reservation['operation_id'], $plan, $target, $fence, $outcome );
		}
		return $this->finalize( $reservation['operation_id'], $plan, $receipt );
	}

	/**
	 * Read a receipt through exact operation and actor identity.
	 *
	 * @param string $operation_id Opaque operation ID.
	 * @param string $actor_ref Server-resolved actor reference.
	 * @return array Durable receipt.
	 * @throws StorageException Missing/inaccessible operation.
	 */
	public function receipt( string $operation_id, string $actor_ref ): array {
		$table = $this->db->table( 'operations' );
		$rows  = $this->db->rows( "SELECT state,receipt_json FROM $table WHERE public_id=%s AND actor_ref=%s", array( $operation_id, $actor_ref ) );
		if ( ! $rows ) {
			throw new StorageException( 'Operation is unavailable.' );
		}
		return json_decode( $rows[0]['receipt_json'], true, 64, JSON_THROW_ON_ERROR );
	}

	/**
	 * Reserve the operation identity and every target in deterministic order.
	 *
	 * @param EffectPlan $plan Exact reviewed plan.
	 * @param bool       $reconciliation Whether this is a fenced reconciliation takeover.
	 * @return array Operation row, receipt and target fences.
	 */
	private function reserve( EffectPlan $plan, bool $reconciliation = false ): array {
		return $this->db->transaction(
			function ( Database $db ) use ( $plan, $reconciliation ): array {
				$existing_only = ! $db->readiness()->status()['effects_enabled'];
				if ( $existing_only && ! $reconciliation ) {
					$db->readiness()->assert_ready();
				}
				$operations = $db->table( 'operations' );
				$initial    = array(
					'operation_id'     => $plan->operation_id,
					'plan_hash'        => $plan->hash(),
					'proposal_hash'    => $plan->proposal_hash,
					'target_set_hash'  => $plan->target_set_hash(),
					'state'            => 'pending',
					'plan'             => $plan->data(),
					'targets'          => array(),
					'external_effects' => array(),
					'observed_at'      => gmdate( DATE_RFC3339 ),
				);
				if ( ! $existing_only ) {
					$db->query(
						"INSERT INTO $operations (public_id,run_id,actor_ref,capability,capability_revision,proposal_hash,target_set_hash,approval_id,state,reconciliation_key,receipt_json,created_at,updated_at) VALUES (%s,NULLIF(%d,0),%s,%s,%s,%s,%s,NULLIF(%d,0),'pending',%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
						array( $plan->operation_id, $plan->run_id ?? 0, $plan->actor_ref, $plan->capability, $plan->capability_revision, $plan->proposal_hash, $plan->target_set_hash(), $plan->approval_id ?? 0, $plan->hash(), json_encode( $initial, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) )
					);
				}
				$rows = $db->rows( "SELECT * FROM $operations WHERE public_id=%s FOR UPDATE", array( $plan->operation_id ) );
				if ( ! $rows ) {
					throw new ContractException( 'storage_unavailable', 'Only an existing owned operation can be reconciled while runtime storage is incompatible.' );
				}
				$operation = $rows[0];
				$receipt   = json_decode( $operation['receipt_json'], true, 64, JSON_THROW_ON_ERROR );
				if ( $operation['actor_ref'] !== $plan->actor_ref || $operation['capability'] !== $plan->capability || $operation['capability_revision'] !== $plan->capability_revision || ! hash_equals( $operation['proposal_hash'], $plan->proposal_hash ) || ! hash_equals( $operation['target_set_hash'], $plan->target_set_hash() ) || ! hash_equals( $receipt['plan_hash'], $plan->hash() ) ) {
					throw new StorageConflictException( 'Operation identity conflicts with an earlier plan.' );
				}
				if ( in_array( $operation['state'], array( 'succeeded', 'failed', 'partial' ), true ) ) {
					return array(
						'operation_id' => (int) $operation['id'],
						'state'        => $operation['state'],
						'receipt'      => $receipt,
						'fences'       => array(),
					);
				}
				if ( in_array( $operation['state'], array( 'running', 'unknown' ), true ) && ! $reconciliation ) {
					return array(
						'operation_id' => (int) $operation['id'],
						'state'        => $operation['state'],
						'receipt'      => $receipt,
						'fences'       => array(),
					);
				}
				$fences = array();
				$locks  = $db->table( 'target_locks' );
				foreach ( $plan->targets as $target ) {
					$existing = $db->rows( "SELECT l.*,o.state AS operation_state FROM $locks l LEFT JOIN $operations o ON o.id=l.operation_id WHERE l.target_key=%s FOR UPDATE", array( $target->key ) );
					if ( $existing && (int) $existing[0]['operation_id'] !== (int) $operation['id'] ) {
						$known_terminal = in_array( $existing[0]['operation_state'], array( 'succeeded', 'failed', 'partial', 'cancelled' ), true );
						$expired        = strtotime( $existing[0]['expires_at'] . ' UTC' ) < time();
						if ( ! $known_terminal || ! $expired ) {
							throw new StorageConflictException( 'Target has another active or unresolved operation.' );
						}
						$db->query( "DELETE FROM $locks WHERE target_key=%s", array( $target->key ) );
						$existing = array();
					}
					if ( $existing ) {
						$expired = strtotime( $existing[0]['expires_at'] . ' UTC' ) < time();
						$fence   = $reconciliation || $expired ? (int) $existing[0]['fence'] + 1 : (int) $existing[0]['fence'];
						$db->query( "UPDATE $locks SET fence=%d,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE target_key=%s AND operation_id=%d", array( $fence, $target->key, (int) $operation['id'] ) );
					} else {
						$fence = 1;
						$db->query( "INSERT INTO $locks (target_key,operation_id,fence,expires_at) VALUES (%s,%d,%d,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))", array( $target->key, (int) $operation['id'], $fence ) );
					}
					$fences[ $target->key ] = $fence;
				}
				return array(
					'operation_id' => (int) $operation['id'],
					'state'        => $operation['state'],
					'receipt'      => $receipt,
					'fences'       => $fences,
				);
			}
		);
	}

	/**
	 * Atomically claim a pending operation before native work.
	 *
	 * @param int $operation_id Internal operation key.
	 * @return void
	 * @throws ContractException Another worker already started this operation.
	 */
	private function start_operation( int $operation_id ): void {
		$table = $this->db->table( 'operations' );
		$this->db->query( "UPDATE $table SET state='running',updated_at=UTC_TIMESTAMP() WHERE id=%d AND state='pending'", array( $operation_id ) );
		if ( 1 !== $this->db->affected() ) {
			throw new ContractException( 'unknown_outcome', 'Another worker already started this operation. Reconcile before retry.' );
		}
	}

	/**
	 * Persist one observed target before attempting the next target.
	 *
	 * @param int           $operation_id Internal operation key.
	 * @param EffectPlan    $plan Exact original plan.
	 * @param EffectTarget  $target Observed target.
	 * @param int           $fence Target fencing token.
	 * @param EffectOutcome $outcome Independently observed outcome.
	 * @return array Updated receipt.
	 */
	private function record_target( int $operation_id, EffectPlan $plan, EffectTarget $target, int $fence, EffectOutcome $outcome ): array {
		return $this->db->transaction(
			function ( Database $db ) use ( $operation_id, $plan, $target, $fence, $outcome ): array {
				$operations = $db->table( 'operations' );
				$locks      = $db->table( 'target_locks' );
				$lock       = $db->rows( "SELECT operation_id,fence FROM $locks WHERE target_key=%s FOR UPDATE", array( $target->key ) );
				if ( ! $lock || (int) $lock[0]['operation_id'] !== $operation_id || (int) $lock[0]['fence'] !== $fence ) {
					throw new ContractException( 'unknown_outcome', 'Target fence changed before receipt persistence.' );
				}
				$rows    = $db->rows( "SELECT receipt_json FROM $operations WHERE id=%d FOR UPDATE", array( $operation_id ) );
				$receipt = json_decode( $rows[0]['receipt_json'], true, 64, JSON_THROW_ON_ERROR );
				if ( ! hash_equals( $receipt['plan_hash'], $plan->hash() ) ) {
					throw new StorageConflictException( 'Persisted operation plan changed.' );
				}
				$receipt['targets'][ $target->key ] = array_merge( $target->data(), array( 'fence' => $fence ), $outcome->data() );
				$receipt['external_effects']        = array_values( array_merge( $receipt['external_effects'], $outcome->external_effects ) );
				$receipt['observed_at']             = gmdate( DATE_RFC3339 );
				$db->query( "UPDATE $operations SET receipt_json=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( json_encode( $receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ), $operation_id ) );
				return $receipt;
			}
		);
	}

	/**
	 * Derive aggregate state and release only fully known targets.
	 *
	 * @param int        $operation_id Internal operation key.
	 * @param EffectPlan $plan Exact original plan.
	 * @param array      $receipt Current receipt.
	 * @return array Final receipt.
	 */
	private function finalize( int $operation_id, EffectPlan $plan, array $receipt ): array {
		$statuses = array();
		foreach ( $plan->targets as $target ) {
			$statuses[] = $receipt['targets'][ $target->key ]['status'] ?? 'unknown';
		}
		if ( in_array( 'unknown', $statuses, true ) ) {
			$state = 'unknown';
		} else {
			$successes = count( array_filter( $statuses, static fn( string $status ): bool => 'succeeded' === $status ) );
			$state     = count( $statuses ) === $successes ? 'succeeded' : ( $successes ? 'partial' : 'failed' );
		}
		$receipt['state']       = $state;
		$receipt['observed_at'] = gmdate( DATE_RFC3339 );
		$this->db->transaction(
			function ( Database $db ) use ( $operation_id, $state, $receipt ): void {
				$operations = $db->table( 'operations' );
				$db->query( "UPDATE $operations SET state=%s,receipt_json=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( $state, json_encode( $receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ), $operation_id ) );
				if ( 'unknown' !== $state ) {
					$db->query( 'DELETE FROM ' . $db->table( 'target_locks' ) . ' WHERE operation_id=%d', array( $operation_id ) );
				}
			}
		);
		return $receipt;
	}
}
