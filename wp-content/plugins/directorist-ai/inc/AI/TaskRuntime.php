<?php
/** Connect durable run outcomes to independent logical-task receipts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment -- Observer performs only owned repository work.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;

/** Never move native confirmation or provider dispatch into an outcome observer. */
final class TaskRuntime {
	public static function observe( Database $db, string $run_id ): void {
		$rows    = $db->rows( 'SELECT owner_ref,state,checkpoint_json FROM ' . $db->table( 'runs' ) . ' WHERE public_id=%s', array( $run_id ) );
		$task_id = $rows ? ( json_decode( $rows[0]['checkpoint_json'] )->logical_task_id ?? $run_id ) : $run_id;
		if ( ! $rows || ! $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'task_receipts' ) . ' WHERE task_id=%s AND owner_ref=%s', array( $task_id, $rows[0]['owner_ref'] ) ) ) {
			return;
		}
		$owner = str_starts_with( $rows[0]['owner_ref'], 'user:' ) ? Owner::user( (int) substr( $rows[0]['owner_ref'], 5 ) ) : Owner::guest( substr( $rows[0]['owner_ref'], 6 ) );
		$tasks = new TaskAccounting( $db );
		$row   = $tasks->get( $owner, $task_id );
		if ( in_array( $row['state'], array( 'completed', 'released' ), true ) ) {
			return;
		}
		$state = $rows[0]['state'];
		if ( in_array( $state, array( 'unknown', 'partial' ), true ) ) {
			$tasks->uncertain( $owner, $task_id );
			return;
		}
		$outcomes = array(
			'succeeded'        => 'response_completed',
			'failed'           => 'failed',
			'cancelled'        => 'cancelled',
			'waiting_approval' => 'proposal_ready',
		);
		if ( isset( $outcomes[ $state ] ) ) {
			$receipt = $tasks->finish( $owner, $task_id, $outcomes[ $state ] );
			if ( 'managed' === $receipt['source'] && 'uncertain' === $receipt['state'] && in_array( $state, array( 'cancelled', 'failed' ), true ) ) {
				try {
					( new ManagedReceiptReconciler( $db ) )->schedule( $task_id, $owner->reference() );
				} catch ( \Throwable $error ) {
					// Cancellation is already committed. Recovery can retry receipt scheduling.
				}
			}
		}
	}
}
