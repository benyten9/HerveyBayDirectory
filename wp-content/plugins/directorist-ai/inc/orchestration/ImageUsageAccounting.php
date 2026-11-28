<?php
/**
 * Typed image capacity and evidence-backed legacy estimate reconciliation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\AI\ManagedService;
use DirectoristAIPro\Runtime\Lease;
use DirectoristAIPro\Runtime\RunStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Image receipts resolve image capacity; missing token counters are never invented. */
final class ImageUsageAccounting {
	/**
	 * Site-scoped runtime storage.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind the existing site-scoped connection.
	 *
	 * @param Database $db Runtime storage.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Admit only one image under the exact owning run and logical image task.
	 *
	 * @param Lease  $lease Fenced active run.
	 * @param string $owner Server-owned actor reference.
	 * @param string $task_id Immutable logical image task identity.
	 * @throws ContractException If ownership, task state or image allowance differs.
	 */
	public function assert_task( Lease $lease, string $owner, string $task_id ): void {
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'task_receipts' ) . ' WHERE task_id=%s AND run_id=%s AND owner_ref=%s', array( $task_id, $lease->run_id, $owner ) );
		$data = $rows ? json_decode( $rows[0]['receipt_json'], true ) : null;
		if ( ! $rows || 'image_generation' !== $rows[0]['logical_task'] || ! in_array( $rows[0]['state'], array( 'admitted', 'reserved' ), true ) || 1 !== ( $data['classification']['images']['quantity'] ?? null ) ) {
			throw new ContractException( 'scope_conflict', 'Image capacity requires its current owned single-image task.' );
		}
		foreach ( $this->db->rows( 'SELECT capacity_json FROM ' . $this->db->table( 'usage_reservations' ) . " WHERE run_id=%d AND modality='image'", array( $lease->internal_id ) ) as $row ) {
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare exact server-produced identities and evidence.
			if ( $task_id === ( json_decode( $row['capacity_json'] ?? '{}', true )['task_id'] ?? null ) ) {
				throw new ContractException( 'budget_exhausted', 'This image task already has its bounded image request.', array( 'budget_scope_image_task', 'budget_metric_images' ) );
			}
		}
	}

	/**
	 * Resolve known image work tied to its original recorded call.
	 *
	 * @param UsageReservation $reservation Original durable capacity reservation.
	 * @param array            $telemetry Server-received completion evidence.
	 * @throws ContractException If legacy continuation evidence differs.
	 */
	public function settle( UsageReservation $reservation, array $telemetry ): void {
		$legacy = null;
		$rows   = $this->db->rows( 'SELECT u.modality,r.state,r.checkpoint_json FROM ' . $this->db->table( 'usage_reservations' ) . ' u JOIN ' . $this->db->table( 'runs' ) . ' r ON r.id=u.run_id WHERE u.public_id=%s AND u.run_id=%d', array( $reservation->id, $reservation->run_id ) );
		if ( $rows && 'text' === $rows[0]['modality'] ) {
			$pending = json_decode( $rows[0]['checkpoint_json'], true )['managed_image_pending'] ?? null;
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare exact server-produced identities and evidence.
			if ( ! in_array( $rows[0]['state'], array( 'running', 'cancelled', 'failed' ), true ) || ! is_array( $pending ) || $reservation->id !== ( $pending['reservation_id'] ?? null ) || 'image-call-' . $reservation->id !== ( $pending['call_id'] ?? null ) ) {
				throw new ContractException( 'scope_conflict', 'Legacy image continuation lacks its exact pending request evidence.' );
			}
			$steps = $this->db->rows( 'SELECT data_json FROM ' . $this->db->table( 'steps' ) . " WHERE id=%d AND run_id=%d AND state IN ('running','failed') AND kind='model'", array( $pending['step_id'], $reservation->run_id ) );
			$data  = $steps ? json_decode( $steps[0]['data_json'], true ) : null;
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare exact server-produced identities and evidence.
			if ( $reservation->id !== ( $data['usage_reservation'] ?? null ) || 'image' !== ( $data['task'] ?? $data['modality'] ?? null ) ) {
				throw new ContractException( 'scope_conflict', 'Legacy image continuation step differs from its reservation.' );
			}
			$legacy = array(
				'step_id'                   => (int) $pending['step_id'],
				'previous_modality'         => 'text',
				'reserved_input_preserved'  => $reservation->input_tokens,
				'reserved_output_preserved' => $reservation->output_tokens,
				'continuation_request_id'   => $pending['request_id'],
			);
		}
		$this->settle_record( $reservation, $telemetry, $legacy );
	}

	/**
	 * Reconcile older estimates using matching stored receipt, call and step evidence.
	 *
	 * @param Lease $lease Current fenced run authority.
	 * @throws ContractException If the active worker no longer owns the run.
	 */
	public function reconcile_completed( Lease $lease ): void {
		$runs = $this->db->table( 'runs' );
		if ( ! $this->db->scalar( "SELECT id FROM $runs WHERE id=%d AND public_id=%s AND state='running' AND lease_owner=%s AND fence=%d AND lease_until>=UTC_TIMESTAMP()", array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence ) ) ) {
			throw new ContractException( 'cancelled', 'Run authority changed before image capacity reconciliation.' );
		}
		$reservations = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'usage_reservations' ) . " WHERE run_id=%d AND modality='text' AND settled_at IS NULL ORDER BY id LIMIT 16", array( $lease->internal_id ) );
		if ( ! $reservations ) {
			return;
		}
		$steps = $this->db->rows( 'SELECT id,state,result_ref,data_json FROM ' . $this->db->table( 'steps' ) . " WHERE run_id=%d AND kind='model' AND state IN ('succeeded','failed') ORDER BY sequence LIMIT 16", array( $lease->internal_id ) );
		foreach ( $reservations as $row ) {
			foreach ( $steps as $step ) {
				$data    = json_decode( $step['data_json'], true );
				$receipt = $data['managed_receipt'] ?? null;
				// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare exact server-produced identities and evidence.
				if ( 'image' !== ( $data['modality'] ?? null ) || $row['public_id'] !== ( $data['usage_reservation'] ?? null ) || ! is_array( $receipt ) || 'image' !== ( $receipt['type'] ?? null ) || 'completed' !== ( $receipt['state'] ?? null ) || 1 !== (int) ( $receipt['usage']['images'] ?? 0 ) || ( 'succeeded' === $step['state'] && ! hash_equals( hash( 'sha256', $receipt['id'] ?? '' ), $step['result_ref'] ?? '' ) ) ) {
					continue;
				}
				$reservation = new UsageReservation( $row['public_id'], $lease->internal_id, json_decode( $row['scope_keys_json'], true ), $row['window_start'], (int) $row['reserved_input'], (int) $row['reserved_output'] );
				$this->settle_record(
					$reservation,
					$data,
					array(
						'step_id'                   => (int) $step['id'],
						'previous_modality'         => 'text',
						'reserved_input_preserved'  => (int) $row['reserved_input'],
						'reserved_output_preserved' => (int) $row['reserved_output'],
					)
				);
				break;
			}
		}
	}

	/**
	 * Append a settlement while preserving the original reservation and actual unknown usage.
	 *
	 * @param UsageReservation $reservation Original durable reservation.
	 * @param array            $telemetry Current authoritative receipt metadata.
	 * @param array|null       $legacy Exact evidence for a prior image token estimate.
	 * @throws ContractException If reservation, receipt, call or settlement differs.
	 */
	private function settle_record( UsageReservation $reservation, array $telemetry, ?array $legacy ): void {
		$this->db->transaction(
			function ( Database $db ) use ( $reservation, $telemetry, $legacy ): void {
				$runs   = $db->table( 'runs' );
				$run    = $db->rows( "SELECT public_id,owner_ref,budget_json FROM $runs WHERE id=%d FOR UPDATE", array( $reservation->run_id ) );
				$table  = $db->table( 'usage_reservations' );
				$stored = $db->rows( "SELECT * FROM $table WHERE public_id=%s AND run_id=%d FOR UPDATE", array( $reservation->id, $reservation->run_id ) );
				if ( ! $run || ! $stored ) {
					throw new ContractException( 'invalid_output', 'Image capacity reservation is unavailable.' );
				}
				$row = $stored[0];
				if ( (int) $row['reserved_input'] !== $reservation->input_tokens || (int) $row['reserved_output'] !== $reservation->output_tokens || $row['window_start'] !== $reservation->window_start || json_decode( $row['scope_keys_json'], true ) !== $reservation->scope_keys ) {
					throw new ContractException( 'scope_conflict', 'Image reservation evidence differs from its original capacity.' );
				}
				$receipt = $telemetry['managed_receipt'] ?? null;
				$managed = is_array( $receipt );
				if ( $managed ) {
					$receipt = ManagedService::receipt( array( 'receipt' => $receipt ) )['managed_receipt'];
					if ( 'image' !== $receipt['type'] || ! in_array( $receipt['state'], array( 'completed', 'released' ), true ) || ( 'completed' === $receipt['state'] && 1 !== (int) ( $receipt['usage']['images'] ?? $telemetry['validated_images'] ?? 0 ) ) ) {
						throw new ContractException( 'invalid_output', 'Only a resolved single-image receipt can settle image capacity.' );
					}
				} elseif ( null !== $legacy || 1 !== ( $telemetry['validated_images'] ?? null ) ) {
					throw new ContractException( 'invalid_output', 'Image completion evidence is unavailable.' );
				}
				$decoded_capacity = json_decode( $row['capacity_json'] ?? '{}', true );
				$capacity         = is_array( $decoded_capacity ) ? $decoded_capacity : array();
				if ( null !== $row['settled_at'] && null === $legacy && is_array( $capacity['legacy_reconciliation'] ?? null ) ) {
					$legacy = $capacity['legacy_reconciliation'];
				}
				if ( 'image' !== $row['modality'] && null === $legacy ) {
					throw new ContractException( 'scope_conflict', 'A text reservation cannot be relabelled as an image.' );
				}
				$task_id = $capacity['task_id'] ?? null;
				$tasks   = $db->rows( 'SELECT task_id,source,receipt_json FROM ' . $db->table( 'task_receipts' ) . ' WHERE run_id=%s AND owner_ref=%s', array( $run[0]['public_id'], $run[0]['owner_ref'] ) );
				$matched = null;
				foreach ( $tasks as $task ) {
					if ( null !== $task_id && $task_id !== $task['task_id'] ) {
						continue;
					}
					$data = json_decode( $task['receipt_json'], true );
					$call = $data['calls'][ 'image-call-' . $reservation->id ] ?? null;
					// phpcs:ignore WordPress.WP.CapitalPDangit -- Persisted source identifier is lower-case.
					if ( ! is_array( $call ) || ! in_array( $call['state'], array( 'succeeded', 'failed' ), true ) || 'image' !== ( $call['metadata']['task'] ?? null ) || ( $managed ? ( 'managed' !== $task['source'] || wp_json_encode( $call['metadata']['managed_receipt'] ?? null ) !== wp_json_encode( $receipt ) ) : 'wordpress' !== $task['source'] ) ) {
						continue;
					}
					// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare exact stored server evidence on both sides.
					if ( ( true === ( $call['metadata']['usage_reported'] ?? false ) ) !== ( true === ( $telemetry['usage_reported'] ?? false ) ) || ( true === ( $telemetry['usage_reported'] ?? false ) && ( $call['metadata']['input_tokens'] !== ( $telemetry['input_tokens'] ?? null ) || $call['metadata']['output_tokens'] !== ( $telemetry['output_tokens'] ?? null ) ) ) ) {
						continue;
					}
					$matched = $task['task_id'];
					break;
				}
				if ( null === $matched ) {
					throw new ContractException( 'scope_conflict', 'Image receipt does not match its original owned provider call.' );
				}
				$reported = true === ( $telemetry['usage_reported'] ?? false );
				$input    = $reported ? ( $telemetry['input_tokens'] ?? null ) : null;
				$output   = $reported ? ( $telemetry['output_tokens'] ?? null ) : null;
				if ( $reported && ( ! is_int( $input ) || ! is_int( $output ) || $input < 0 || $output < 0 ) ) {
					throw new ContractException( 'invalid_output', 'Reported image token usage is invalid.' );
				}
				if ( $managed && $reported && ( ! isset( $receipt['usage']['input_tokens'], $receipt['usage']['output_tokens'] ) || ManagedModelClient::tokens( $receipt['usage']['input_tokens'] ) !== $input || ManagedModelClient::tokens( $receipt['usage']['output_tokens'] ) !== $output ) ) {
					throw new ContractException( 'invalid_output', 'Image token counters differ from their authoritative receipt.' );
				}
				$evidence = array(
					'version'               => 1,
					'state'                 => $managed ? $receipt['state'] : 'completed',
					'task_id'               => $matched,
					'image_quantity'        => 1,
					'usage_reported'        => $reported,
					'receipt'               => $receipt,
					'legacy_reconciliation' => $legacy,
				);
				if ( null !== $row['settled_at'] ) {
					$expected = $capacity;
					unset( $expected['settled_at'] );
					if ( wp_json_encode( $expected ) !== wp_json_encode( $evidence ) || ( null === $input ? null !== $row['actual_input'] : $input !== (int) $row['actual_input'] ) || ( null === $output ? null !== $row['actual_output'] : $output !== (int) $row['actual_output'] ) ) {
						throw new ContractException( 'scope_conflict', 'Completed image capacity cannot be settled differently.' );
					}
					return;
				}
				$input_delta  = ( $input ?? 0 ) - (int) $row['reserved_input'];
				$output_delta = ( $output ?? 0 ) - (int) $row['reserved_output'];
				$settlement_scopes = $reservation->scope_keys;
				sort( $settlement_scopes, SORT_STRING );
				foreach ( $settlement_scopes as $scope ) {
					$db->query( 'UPDATE ' . $db->table( 'usage_windows' ) . ' SET input_tokens=GREATEST(0,input_tokens+%d),output_tokens=GREATEST(0,output_tokens+%d),updated_at=UTC_TIMESTAMP() WHERE scope_key=%s AND window_start=%s', array( $input_delta, $output_delta, $scope, $row['window_start'] ) );
				}
				$budget                  = json_decode( $run[0]['budget_json'], true );
				$budget['input_tokens']  = max( 0, (int) $budget['input_tokens'] + $input_delta );
				$budget['output_tokens'] = max( 0, (int) $budget['output_tokens'] + $output_delta );
				if ( null !== $legacy ) {
					$budget['image_requests'] = (int) ( $budget['image_requests'] ?? 0 ) + 1;
				}
				if ( 'completed' === $evidence['state'] ) {
					$budget['images_completed'] = (int) ( $budget['images_completed'] ?? 0 ) + 1;
				}
				$evidence['settled_at'] = gmdate( 'Y-m-d H:i:s' );
				$db->query( "UPDATE $table SET modality='image',image_quantity=1,capacity_json=%s,actual_input=NULLIF(%s,''),actual_output=NULLIF(%s,''),settled_at=UTC_TIMESTAMP() WHERE public_id=%s AND settled_at IS NULL", array( wp_json_encode( $evidence ), null === $input ? '' : (string) $input, null === $output ? '' : (string) $output, $reservation->id ) );
				$db->query( "UPDATE $runs SET budget_json=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( wp_json_encode( $budget ), $reservation->run_id ) );
				( new RunStore( $db ) )->event_in_transaction( $reservation->run_id, 'usage.updated', (object) $budget );
			}
		);
	}
}
