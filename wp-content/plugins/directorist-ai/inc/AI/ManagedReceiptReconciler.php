<?php
/** Settle accepted managed generations after their owning task has stopped.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Trusted receipt-only background adapter.

use DirectoristAIPro\Orchestration\{ManagedModelClient, ModelException, UsageBudget, UsageReservation};
use DirectoristAIPro\Storage\{Database, Owner};

/** Poll an existing request only. Never dispatch generation, tools or a chat reply. */
final class ManagedReceiptReconciler {

	private const HOOK = 'directorist_ai_pro_reconcile_receipt';
	private Database $db;
	private $poll;

	public function __construct( Database $db, ?callable $poll = null ) {
		$this->db   = $db;
		$this->poll = $poll ?? static fn( array $body, array $route ): array => ManagedService::inference( $body, $route, true );
	}

	public function hooks(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
		add_action( 'directorist_ai_pro_recover', array( $this, 'recover' ) );
	}

	public function schedule( string $task_id, string $owner_ref ): void {
		// Isolated native fixtures must not enqueue jobs against the real site database.
		global $wpdb;
		if ( ! function_exists( 'as_schedule_single_action' ) || $this->db->table( 'task_receipts' ) !== ( '`' . $wpdb->prefix . 'dai_task_receipts`' ) ) {
			return; }
		$args    = array(
			'task_id'   => $task_id,
			'owner_ref' => $owner_ref,
		);
		$pending = \ActionScheduler::store()->query_action(
			array(
				'hook'   => self::HOOK,
				'args'   => $args,
				'group'  => 'directorist-ai-pro',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		if ( null === $pending ) {
			as_schedule_single_action( time() + 5, self::HOOK, $args, 'directorist-ai-pro', false, 10 ); }
	}

	public function recover(): void {
		$rows = $this->db->rows( 'SELECT task_id,owner_ref,receipt_json FROM ' . $this->db->table( 'task_receipts' ) . " WHERE source='managed' AND state='uncertain' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 20" );
		foreach ( $rows as $row ) {
			if ( in_array( json_decode( $row['receipt_json'], true )['terminal_outcome'] ?? null, array( 'cancelled', 'failed' ), true ) ) {
				$this->schedule( $row['task_id'], $row['owner_ref'] ); }
		}
	}

	public function run( string $task_id, string $owner_ref ): void {
		try {
			$owner = str_starts_with( $owner_ref, 'user:' ) ? Owner::user( (int) substr( $owner_ref, 5 ) ) : Owner::guest( substr( $owner_ref, 6 ) );
			if ( ! $this->refresh( $owner, $task_id ) ) {
				$task = ( new TaskAccounting( $this->db ) )->get( $owner, $task_id );
				if ( strtotime( $task['created_at'] . ' UTC' ) > time() - 900 ) {
					$this->schedule( $task_id, $owner_ref ); }
			}
		} catch ( \Throwable $error ) {
			// The independent receipt stays uncertain. The bounded recovery sweep can retry a read.
		}
	}

	public function refresh( Owner $owner, string $task_id ): bool {
		$tasks = new TaskAccounting( $this->db );
		$task  = $tasks->get( $owner, $task_id );
		if ( 'managed' !== $task['source'] || ! in_array( $task['state'], array( 'uncertain', 'reserved' ), true ) || ! in_array( $task['terminal_outcome'] ?? null, array( 'cancelled', 'failed' ), true ) ) {
			return true; }
		foreach ( $task['calls'] as $call_id => $call ) {
			if ( str_starts_with( $call_id, 'image-call-' ) ) {
				if ( ! $this->image_call( $tasks, $owner, $task, $call_id, $call ) ) {
					return false;
				} continue; }
			if ( ! in_array( $call['state'], array( 'running', 'unknown' ), true ) ) {
				if ( str_starts_with( $call_id, 'model-' ) && is_array( $call['metadata'] ) ) {
					$this->settle_usage( $task, $owner, substr( $call_id, 6 ), $call['metadata'] );
				} continue; }
			if ( ! str_starts_with( $call_id, 'model-' ) ) {
				return false; }
			$request_id = substr( $call_id, 6 );
			$receipt    = null;
			try {
				$result = ( $this->poll )(
					array(
						'request_id'   => $request_id,
						'operation_id' => $task_id,
					),
					$task['route']
				);
				if ( ( $result['request_id'] ?? null ) !== $request_id || ( $result['operation_id'] ?? null ) !== $task_id ) {
					return false; }
				$receipt = ManagedService::receipt( $result )['managed_receipt'];
			} catch ( ModelException $error ) {
				$receipt = $error->telemetry['managed_receipt'] ?? null;
			}
			$original_id = $call['metadata']['managed_receipt']['id'] ?? null;
			if ( ! is_array( $receipt ) || ! in_array( $receipt['state'] ?? '', array( 'completed', 'released' ), true ) || ( null !== $original_id && $receipt['id'] !== $original_id ) ) {
				return false; }
			$reported = isset( $receipt['usage']['input_tokens'], $receipt['usage']['output_tokens'] );
			$metadata = array_merge(
				$call['metadata'] ?? array(),
				array(
					'source'          => 'managed',
					'managed_receipt' => $receipt,
					'usage_reported'  => $reported,
					'input_tokens'    => $reported ? ManagedModelClient::tokens( $receipt['usage']['input_tokens'] ) : 0,
					'output_tokens'   => $reported ? ManagedModelClient::tokens( $receipt['usage']['output_tokens'] ) : 0,
				)
			);
			$tasks->call_finished( $owner, $task_id, $call_id, $metadata, 'completed' === $receipt['state'] ? 'succeeded' : 'failed' );
			$this->settle_usage( $task, $owner, $request_id, $metadata );
		}
		$fresh = $tasks->get( $owner, $task_id );
		if ( array_filter( $fresh['calls'], static fn( $call ) => in_array( $call['state'], array( 'running', 'unknown' ), true ) ) ) {
			return false; }
		$tasks->reconcile( $owner, $task_id, $task['terminal_outcome'] );
		return true;
	}

	/** Inspect only the exact existing image request after its owning run stopped. */
	private function image_call( TaskAccounting $tasks, Owner $owner, array $task, string $call_id, array $call ): bool {
		$rows = $this->db->rows( 'SELECT id,public_id,checkpoint_json FROM ' . $this->db->table( 'runs' ) . " WHERE public_id=%s AND owner_ref=%s AND state IN ('cancelled','failed')", array( $task['run_id'], $owner->reference() ) );
		if ( ! $rows ) {
			return ! in_array( $call['state'], array( 'running', 'unknown' ), true ); }
		$run     = $rows[0];
		$cp      = json_decode( $run['checkpoint_json'] );
		$pending = $cp->managed_image_pending ?? null;
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare two exact server-owned image identities.
		if ( ! is_object( $pending ) || $pending->image_task !== $task['task_id'] || $pending->call_id !== $call_id || 'image-call-' . $pending->reservation_id !== $call_id || ( $task['parent_task_id'] ?? $task['task_id'] ) !== ( $cp->logical_task_id ?? $run['public_id'] ) ) {
			return false; }
		$reservations = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'usage_reservations' ) . ' WHERE public_id=%s AND run_id=%d', array( $pending->reservation_id, (int) $run['id'] ) );
		$steps        = $this->db->table( 'steps' );
		$previous     = $this->db->rows( "SELECT data_json FROM $steps WHERE id=%d AND run_id=%d", array( $pending->step_id, (int) $run['id'] ) );
		$data         = $previous ? json_decode( $previous[0]['data_json'], true ) : null;
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare exact stored server evidence on both sides.
		if ( ! $reservations || $pending->reservation_id !== ( $data['usage_reservation'] ?? null ) || 'image' !== ( $data['task'] ?? $data['modality'] ?? null ) ) {
			return false; }
		$receipt = $call['metadata']['managed_receipt'] ?? null;
		if ( in_array( $call['state'], array( 'running', 'unknown' ), true ) ) {
			try {
				$result = ( $this->poll )(
					array(
						'request_id'   => $pending->request_id,
						'operation_id' => $cp->logical_task_id ?? $run['public_id'],
						'type'         => 'image',
					),
					Settings::for_task( $task['route'], 'image' )
				);
				if ( ( $result['request_id'] ?? null ) !== $pending->request_id || ( $result['operation_id'] ?? null ) !== ( $cp->logical_task_id ?? $run['public_id'] ) ) {
					return false; }
				$receipt = ManagedService::receipt( $result )['managed_receipt'];
			} catch ( ModelException $error ) {
				$receipt = $error->telemetry['managed_receipt'] ?? null; }
		}
		if ( ! is_array( $receipt ) || 'image' !== ( $receipt['type'] ?? null ) || ! in_array( $receipt['state'] ?? null, array( 'completed', 'released' ), true ) || ( isset( $call['metadata']['managed_receipt']['id'] ) && $receipt['id'] !== $call['metadata']['managed_receipt']['id'] ) || ( 'completed' === $receipt['state'] && 1 !== (int) ( $receipt['usage']['images'] ?? 0 ) ) ) {
			return false; }
		$reported = isset( $receipt['usage']['input_tokens'], $receipt['usage']['output_tokens'] );
		$metadata = array_merge(
			$call['metadata'] ?? array(),
			array(
				'source'          => 'managed',
				'task'            => 'image',
				'managed_receipt' => $receipt,
				'usage_reported'  => $reported,
				'input_tokens'    => $reported ? ManagedModelClient::tokens( $receipt['usage']['input_tokens'] ) : null,
				'output_tokens'   => $reported ? ManagedModelClient::tokens( $receipt['usage']['output_tokens'] ) : null,
			)
		);
		$tasks->call_finished( $owner, $task['task_id'], $call_id, $metadata, 'completed' === $receipt['state'] ? 'succeeded' : 'failed' );
		$r = $reservations[0];
		( new UsageBudget( $this->db ) )->settle_image( new UsageReservation( $r['public_id'], (int) $run['id'], json_decode( $r['scope_keys_json'], true ), $r['window_start'], (int) $r['reserved_input'], (int) $r['reserved_output'] ), $metadata );
		if ( $previous ) {
			$data = json_decode( $previous[0]['data_json'], true );
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare two exact server-owned image identities.
			if ( $pending->reservation_id !== ( $data['usage_reservation'] ?? null ) || 'image' !== ( $data['task'] ?? $data['modality'] ?? null ) ) {
				return false; }
			$resolved = array_merge(
				$data,
				$metadata,
				array(
					'modality'         => 'image',
					'delivery_outcome' => $task['terminal_outcome'],
				)
			);
			if ( in_array( $resolved['code'] ?? null, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain' ), true ) ) {
				$resolved['resolved_status_codes'][] = $resolved['code'];
				unset( $resolved['code'] ); }
			unset( $resolved['error'] );
			$this->db->query( "UPDATE $steps SET state=%s,result_ref=%s,data_json=%s WHERE id=%d AND run_id=%d AND data_json=%s", array( 'completed' === $receipt['state'] ? 'succeeded' : 'failed', hash( 'sha256', $receipt['id'] ), wp_json_encode( $resolved ), $pending->step_id, (int) $run['id'], $previous[0]['data_json'] ) );
		}
		unset( $cp->managed_image_pending );
		$cp->managed_image_receipt_reconciled = true;
		$this->db->query( 'UPDATE ' . $this->db->table( 'runs' ) . " SET checkpoint_json=%s WHERE id=%d AND state IN ('cancelled','failed') AND checkpoint_json=%s", array( wp_json_encode( $cp ), (int) $run['id'], $run['checkpoint_json'] ) );
		return true;
	}

	private function settle_usage( array $task, Owner $owner, string $request_id, array $metadata ): void {
		$rows = $this->db->rows( 'SELECT id,checkpoint_json FROM ' . $this->db->table( 'runs' ) . " WHERE public_id=%s AND owner_ref=%s AND state IN ('cancelled','failed')", array( $task['run_id'], $owner->reference() ) );
		if ( ! $rows ) {
			return; } // Chat erasure does not erase its independent billing receipt.
		$run = $rows[0];
		$cp  = json_decode( $run['checkpoint_json'] );
		if ( ( $cp->managed_pending->request_id ?? null ) !== $request_id ) {
			return; }
		$reservations = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'usage_reservations' ) . ' WHERE public_id=%s AND run_id=%d', array( $cp->managed_pending->reservation_id, (int) $run['id'] ) );
		if ( $reservations && $metadata['usage_reported'] ) {
			$r = $reservations[0];
			( new UsageBudget( $this->db ) )->settle( new UsageReservation( $r['public_id'], (int) $run['id'], json_decode( $r['scope_keys_json'], true ), $r['window_start'], (int) $r['reserved_input'], (int) $r['reserved_output'] ), $metadata['input_tokens'], $metadata['output_tokens'] );
		}
		unset( $cp->managed_pending );
		$cp->managed_receipt_reconciled = true;
		$this->db->query( 'UPDATE ' . $this->db->table( 'runs' ) . " SET checkpoint_json=%s WHERE id=%d AND state IN ('cancelled','failed') AND checkpoint_json=%s", array( wp_json_encode( $cp ), (int) $run['id'], $run['checkpoint_json'] ) );
	}
}
