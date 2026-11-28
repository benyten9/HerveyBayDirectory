<?php
/**
 * Independent logical-task receipts, never the token limiter or a real wallet.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.WP.CapitalPDangit -- Compact independent repository; persisted source is lower-case.

/** Minimal retained metadata survives conversation erasure; future backend is authoritative. */
final class TaskAccounting {
	private Database $db;
	private TaskRatePolicy $policy;

	public function __construct( Database $db, ?TaskRatePolicy $policy = null ) {
		$this->db     = $db;
		$this->policy = $policy ?? new TaskRatePolicy();
	}

	/** The context must already pass the route's native scope validation. */
	public function admit( Context $context, string $task_id, string $run_id, ?object $native_context, array $route, bool $uses_ai = true ): array {
		$context->assert_current();
		return $this->record( $context->actor()->owner(), $task_id, $run_id, TaskPolicy::classify( $context->profile(), $native_context, $uses_ai ), $route, null );
	}

	/** Explicit additional images get their own stable child identity and immutable source. */
	public function admit_image( Context $context, string $task_id, string $run_id, string $parent_id, int $quantity = 1, ?string $size = null, ?string $quality = null ): array {
		$context->assert_current();
		$parent = $this->get( $context->actor()->owner(), $parent_id );
		if ( ! $parent['classification']['uses_ai'] || in_array( $parent['state'], array( 'released', 'uncertain' ), true ) ) {
			throw new ContractException( 'scope_conflict', 'Image addon parent is unavailable.' );
		}
		return $this->record( $context->actor()->owner(), $task_id, $run_id, TaskPolicy::image( $parent['feature'], $quantity, $size, $quality ), Settings::for_task( $parent['route'], 'image' ), $parent_id );
	}

	/** A trusted read-only delivery refresh continues one accepted visitor task. */
	public function admit_visitor_recovery( Context $context, string $run_id, int $cycle, array $route ): array {
		$context->assert_execution_identity();
		return $this->atomic( function () use ( $context, $run_id, $cycle, $route ): array {
			$runs = $this->db->table( 'runs' ); $conversations = $this->db->table( 'conversations' );
			$rows = $this->db->rows( "SELECT r.*,c.profile,c.erasure_requested FROM $runs r JOIN $conversations c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND r.owner_ref=%s FOR UPDATE", array( $run_id, $context->actor()->owner()->reference() ) );
			if ( 1 !== count( $rows ) || 'visitor' !== $context->profile() || 'visitor' !== $rows[0]['profile'] || (int) $rows[0]['erasure_requested'] || (int) $rows[0]['cancel_requested'] || $cycle < 1 || $cycle > \DirectoristAIPro\Chat\VisitorEvidenceRecovery::MAX_REFRESHES + 1 ) { throw new ContractException( 'scope_conflict', 'Visitor evidence continuation is unavailable.' ); }
			$run = $rows[0]; $checkpoint = json_decode( $run['checkpoint_json'] );
			$id = 'visitor-evidence-' . hash( 'sha256', $run_id . ':' . $cycle );
			$initial = \DirectoristAIPro\Chat\VisitorEvidenceRecovery::eligible( $run, $checkpoint ) && $cycle === 1 + (int) ( $checkpoint->visitor_evidence_refreshes ?? 0 );
			$clarified = is_int( $checkpoint->visitor_recovery_waiting_turn ?? null ) && (int) ( $checkpoint->latest_turn_id ?? 0 ) > $checkpoint->visitor_recovery_waiting_turn;
			$existing = in_array( $run['state'], array( 'queued', 'running' ), true ) && ( true === ( $checkpoint->visitor_recovering_delivery ?? false ) || $clarified ) && $cycle === ( $checkpoint->visitor_recovery_task_cycle ?? null ) && $id === ( $checkpoint->visitor_recovery_task_id ?? null ) && $id === ( $checkpoint->logical_task_id ?? null );
			if ( ( ! $initial && ! $existing ) || ! \DirectoristAIPro\Chat\VisitorEvidenceRecovery::read_only( $checkpoint ) || (int) $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'operations' ) . ' WHERE run_id=%d', array( (int) $run['id'] ) ) ) { throw new ContractException( 'scope_conflict', 'Only an authenticated read-only delivery may continue.' ); }
			$parent_id = $checkpoint->visitor_recovery_parent_task_id ?? $checkpoint->logical_task_id ?? $run_id;
			$parent = $this->get( $context->actor()->owner(), $parent_id );
			if ( 'completed' !== $parent['state'] || 'response_completed' !== $parent['outcome'] || $parent['run_id'] !== $run_id || null !== $parent['parent_task_id'] || 'visitor' !== $parent['feature'] || 'chat_turn' !== $parent['logical_task'] || ! $parent['classification']['uses_ai'] || self::route( $parent['route'] ) !== self::route( $route ) ) { throw new ContractException( 'scope_conflict', 'The original visitor receipt cannot authorize this continuation.' ); }
			foreach ( $parent['calls'] as $call ) { if ( in_array( $call['state'], array( 'running', 'unknown' ), true ) ) { throw new ContractException( 'scope_conflict', 'An uncertain provider receipt cannot be replayed.' ); } }
			if ( $clarified && $existing ) { $child = $this->get( $context->actor()->owner(), $id ); if ( ! in_array( $child['state'], array( 'admitted', 'reserved' ), true ) || $child['parent_task_id'] !== $parent_id || $child['run_id'] !== $run_id ) { throw new ContractException( 'scope_conflict', 'Only the still-active accepted clarification may continue.' ); } }
			$classification = $parent['classification'];
			$classification['continuation'] = 'visitor_evidence_v1';
			$classification['evidence_cycle'] = $cycle;
			return $this->record( $context->actor()->owner(), $id, $run_id, $classification, $route, $parent_id );
		} );
	}

	private function record( Owner $owner, string $task_id, string $run_id, array $classification, array $route, ?string $parent_id ): array {
		self::identifier( $task_id );
		self::identifier( $run_id );
		if ( null !== $parent_id ) {
			self::identifier( $parent_id ); }
		$route = self::route( $route );
		if ( $route['feature'] !== $classification['feature'] ) {
			throw new ContractException( 'scope_conflict', 'Task feature differs from its captured AI route.' );
		}
		$identity = self::task_identity( $owner->reference(), $run_id, $parent_id, $classification, $route );
		return $this->atomic(
			function () use ( $owner, $task_id, $run_id, $classification, $route, $parent_id, $identity ): array {
				$this->db->readiness()->assert_ready();
				$table = $this->db->table( 'task_receipts' );
				// Acquire the admission row exclusively; duplicate INSERT IGNORE holds a shared lock.
				// Two shared holders upgrading through SELECT FOR UPDATE can deadlock.
				$meta = $this->db->table( 'meta' );
				$this->db->query( "INSERT INTO $meta (name,value) VALUES ('task_allowance_lock','1') ON DUPLICATE KEY UPDATE value=value" );
				$this->db->scalar( "SELECT value FROM $meta WHERE name='task_allowance_lock' FOR UPDATE" );
				$existing = $this->db->rows( "SELECT * FROM $table WHERE task_id=%s FOR UPDATE", array( $task_id ) );
				if ( $existing ) {
					if ( ! self::existing_identity_matches( $existing[0], $owner->reference(), $run_id, $parent_id, $classification, $route, $identity ) ) {
						throw new ContractException( 'scope_conflict', 'Task identity cannot be relabelled or moved between owners and sources.' );
					}
					return self::decode( $existing[0] );
				}
				$quote    = $this->policy->quote( $classification, $route['source'] );
				$settings = \DirectoristAIPro\Chat\Operations::settings();
				$days     = $settings['task_receipt_retention_days'];
				if ( $classification['uses_ai'] && 'unavailable' !== $quote['mode'] && 'visitor_evidence_v1' !== ( $classification['continuation'] ?? null ) ) {
					// Serialize logical-task allowance admission, independently of token reservations.
					$site_count    = (int) $this->db->scalar( "SELECT COUNT(*) FROM $table WHERE uses_ai=1 AND billing_mode<>'unavailable' AND NOT (parent_task_id IS NOT NULL AND feature='visitor' AND logical_task='chat_turn' AND task_id LIKE 'visitor-evidence-%%') AND created_at>=UTC_DATE()" );
					$visitor_count = 'visitor' === $classification['feature'] ? (int) $this->db->scalar( "SELECT COUNT(*) FROM $table WHERE uses_ai=1 AND billing_mode<>'unavailable' AND feature='visitor' AND owner_ref=%s AND NOT (parent_task_id IS NOT NULL AND feature='visitor' AND logical_task='chat_turn' AND task_id LIKE 'visitor-evidence-%%') AND created_at>=UTC_DATE()", array( $owner->reference() ) ) : 0;
					if ( \DirectoristAIPro\Chat\UsageLimits::exceeded( $settings['site_ai_tasks'], $site_count + 1 ) || ( 'visitor' === $classification['feature'] && \DirectoristAIPro\Chat\UsageLimits::exceeded( $settings['visitor_ai_tasks'], $visitor_count + 1 ) ) ) {
						throw new ContractException( 'budget_exhausted', 'Daily logical AI task allowance is exhausted.', array( \DirectoristAIPro\Chat\UsageLimits::exceeded( $settings['site_ai_tasks'], $site_count + 1 ) ? 'budget_scope_task_site_day' : 'budget_scope_task_visitor_day', 'budget_metric_tasks' ) );
					}
				}
				$data = array( 'classification' => $classification, 'route' => $route, 'policy' => $quote, 'calls' => array(), 'reserved_units' => null, 'settled_units' => null, 'reconciliation' => 'not_required' );
				$this->db->query( "INSERT INTO $table (task_id,owner_ref,run_id,parent_task_id,identity_hash,logical_task,uses_ai,feature,source,billing_mode,state,state_version,outcome,receipt_json,created_at,updated_at,expires_at) VALUES (%s,%s,%s,NULLIF(%s,''),%s,%s,%d,%s,%s,%s,'admitted',1,'pending',%s,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d DAY))", array( $task_id, $owner->reference(), $run_id, $parent_id ?? '', $identity, $classification['kind'], (int) $classification['uses_ai'], $classification['feature'], $route['source'], $quote['mode'], self::json( $data ), $days ) );
				return $this->get( $owner, $task_id );
			}
		);
	}

	public function get( Owner $owner, string $task_id ): array {
		self::identifier( $task_id );
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'task_receipts' ) . ' WHERE task_id=%s AND owner_ref=%s', array( $task_id, $owner->reference() ) );
		if ( ! $rows ) {
			throw new ContractException( 'unauthorized', 'Task receipt is unavailable.' ); }
		return self::decode( $rows[0] );
	}

	/** Call only after the current native execution fence allows provider dispatch. */
	public function call_started( Owner $owner, string $task_id, string $call_id, bool $repair = false ): array {
		self::identifier( $call_id );
		return $this->change(
			$owner,
			$task_id,
			function ( array $row, array $data ) use ( $call_id, $repair ): array {
				if ( isset( $data['calls'][ $call_id ] ) ) {
					if ( $repair !== $data['calls'][ $call_id ]['repair'] ) {
						throw new ContractException( 'scope_conflict', 'Call identity cannot change repair classification.' ); }
					return array( $row, $data );
				}
				if ( ! $data['classification']['uses_ai'] || in_array( $row['state'], array( 'completed', 'released', 'uncertain' ), true ) ) {
					throw new ContractException( 'scope_conflict', 'Task no longer admits an AI call.' );
				}
				if ( 'unavailable' === $data['policy']['mode'] ) {
					throw new ContractException( 'managed_unavailable', ManagedService::status()['message'] ); }
				if ( null !== $data['policy']['quote_expires_at'] && 'admitted' === $row['state'] && $data['policy']['quote_expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) {
					throw new ContractException( 'quote_expired', 'Mock task quote has expired. Obtain a new task quote.' );
				}
				$repairs = count( array_filter( $data['calls'], static fn( array $call ): bool => $call['repair'] ) );
				if ( \DirectoristAIPro\Chat\UsageLimits::exceeded( $data['policy']['max_calls'], count( $data['calls'] ) + 1 ) || ( $repair && \DirectoristAIPro\Chat\UsageLimits::exceeded( $data['policy']['max_repairs'], $repairs + 1 ) ) ) {
					throw new ContractException( 'budget_exhausted', 'Task rounds exceed its immutable bounded tier.', array( 'budget_scope_task', $repair && \DirectoristAIPro\Chat\UsageLimits::exceeded( $data['policy']['max_repairs'], $repairs + 1 ) ? 'budget_metric_repair_rounds' : 'budget_metric_model_rounds' ) );
				}
				$data['reserved_units']    = $data['policy']['quoted_units'];
				$data['calls'][ $call_id ] = array( 'repair' => $repair, 'state' => 'running', 'metadata' => null );
				$row['state']              = 'reserved';
				return array( $row, $data );
			}
		);
	}

	/** Copy safe telemetry into the independent receipt, without altering model steps. */
	public function call_finished( Owner $owner, string $task_id, string $call_id, array $telemetry, string $state ): array {
		self::identifier( $call_id );
		if ( ! in_array( $state, array( 'succeeded', 'failed', 'unknown' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Call outcome is unsupported.' ); }
		return $this->change(
			$owner,
			$task_id,
			function ( array $row, array $data ) use ( $call_id, $telemetry, $state ): array {
				if ( ! isset( $data['calls'][ $call_id ] ) ) {
					throw new ContractException( 'scope_conflict', 'Call was not admitted before provider dispatch.' ); }
				$metadata = self::telemetry( $telemetry, $data['route'] );
				$call     = $data['calls'][ $call_id ];
				if ( ! isset( $metadata['managed_receipt'] ) && isset( $call['metadata']['managed_receipt'] ) ) {
					$metadata['managed_receipt'] = $call['metadata']['managed_receipt']; }
				if ( null !== $call['metadata'] ) {
					if ( $call['state'] === $state && $call['metadata'] === $metadata ) {
						return array( $row, $data ); }
					if ( 'unknown' !== $call['state'] ) {
						throw new ContractException( 'scope_conflict', 'Completed call evidence cannot be overwritten.' ); }
				}
				if ( 'image' === ( $call['metadata']['task'] ?? $metadata['task'] ?? null ) && isset( $call['metadata']['managed_receipt'], $metadata['managed_receipt'] ) ) {
					$old_receipt = $call['metadata']['managed_receipt'];
					if ( ( $old_receipt['id'] ?? null ) !== ( $metadata['managed_receipt']['id'] ?? null ) || ( $old_receipt['mode'] ?? null ) !== ( $metadata['managed_receipt']['mode'] ?? null ) || 'image' !== ( $metadata['managed_receipt']['type'] ?? null ) ) {
						throw new ContractException( 'scope_conflict', 'An image continuation cannot replace its original service receipt.' ); }
					if ( $old_receipt !== $metadata['managed_receipt'] ) {
						$history                                      = $call['receipt_history'] ?? array();
						$history[]                                    = $old_receipt;
						$data['calls'][ $call_id ]['receipt_history'] = count( $history ) > 8 ? array_merge( array_slice( $history, 0, 1 ), array_slice( $history, -7 ) ) : $history; }
				}
				$data['calls'][ $call_id ]['state']    = $state;
				$data['calls'][ $call_id ]['metadata'] = $metadata;
				if ( 'unknown' !== $state && 'uncertain' === $row['state'] && ! array_filter( $data['calls'], static fn( array $call ): bool => 'unknown' === $call['state'] ) ) {
					$row['state']           = 'reserved';
					$data['reconciliation'] = 'resolved'; }
				if ( 'unknown' === $state ) {
					$row['state']           = 'uncertain';
					$data['reconciliation'] = 'required'; }
				return array( $row, $data );
			}
		);
	}

	/** Known task completion settles once; failures release unused mock reservation. */
	public function finish( Owner $owner, string $task_id, string $outcome ): array {
		return $this->complete( $owner, $task_id, $outcome, false );
	}

	public function uncertain( Owner $owner, string $task_id ): array {
		return $this->change(
			$owner,
			$task_id,
			static function ( array $row, array $data ): array {
				if ( in_array( $row['state'], array( 'completed', 'released' ), true ) ) {
					return array( $row, $data ); }
				$row['state']           = 'uncertain';
				$row['outcome']         = 'unknown';
				$data['reconciliation'] = 'required';
				return array( $row, $data );
			}
		);
	}

	/** Trusted reconciler supplies a known result; this never contacts a wallet. */
	public function reconcile( Owner $owner, string $task_id, string $outcome ): array {
		return $this->complete( $owner, $task_id, $outcome, true );
	}

	private function complete( Owner $owner, string $task_id, string $outcome, bool $reconciled ): array {
		if ( ! in_array( $outcome, array( 'response_completed', 'native_operation_completed', 'proposal_ready', 'inspection_completed', 'failed', 'unsupported', 'cancelled' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Task completion outcome is unsupported.' ); }
		return $this->change(
			$owner,
			$task_id,
			static function ( array $row, array $data ) use ( $outcome, $reconciled ): array {
				if ( in_array( $row['state'], array( 'completed', 'released' ), true ) ) {
					if ( $row['outcome'] !== $outcome ) {
						throw new ContractException( 'scope_conflict', 'Terminal task receipt cannot be settled differently.' ); }
					return array( $row, $data );
				}
				$pending = array_filter( $data['calls'], static fn( array $call ): bool => in_array( $call['state'], array( 'running', 'unknown' ), true ) );
				if ( ! $reconciled && ( 'uncertain' === $row['state'] || $pending ) ) {
					$data['terminal_outcome'] = $outcome;
					$row['state']             = 'uncertain';
					$row['outcome']           = 'unknown';
					$data['reconciliation']   = 'required';
					return array( $row, $data );
				}
				$success = ! in_array( $outcome, array( 'failed', 'unsupported', 'cancelled' ), true );
				if ( $success && 'unavailable' === $data['policy']['mode'] ) {
					throw new ContractException( 'managed_unavailable', 'Unavailable managed work cannot be reported completed.' ); }
				$charged = array_filter(
					$data['calls'],
					static function ( array $call ): bool {
						$receipt = $call['metadata']['managed_receipt'] ?? array();
						return 'completed' === ( $receipt['state'] ?? null ) && is_string( $receipt['charged_microusd'] ?? null ) && 1 === preg_match( '/^[0-9]+$/D', $receipt['charged_microusd'] ) && '' !== ltrim( $receipt['charged_microusd'], '0' );
					}
				);
				// A delivered-generation charge is independent of a later answer-validation failure.
				$row['state']           = $success || $charged ? 'completed' : 'released';
				$row['outcome']         = $outcome;
				$data['settled_units']  = in_array( $data['policy']['mode'], array( 'unavailable', 'managed_usage' ), true ) ? null : ( $success && $data['calls'] ? $data['policy']['quoted_units'] : 0 );
				$data['reserved_units'] = in_array( $data['policy']['mode'], array( 'unavailable', 'managed_usage' ), true ) ? null : 0;
				$data['reconciliation'] = $reconciled ? 'resolved' : 'not_required';
				return array( $row, $data );
			}
		);
	}

	private function change( Owner $owner, string $task_id, callable $mutation ): array {
		self::identifier( $task_id );
		return $this->atomic(
			function () use ( $owner, $task_id, $mutation ): array {
				$this->db->readiness()->assert_ready();
				$table = $this->db->table( 'task_receipts' );
				$rows  = $this->db->rows( "SELECT * FROM $table WHERE task_id=%s AND owner_ref=%s FOR UPDATE", array( $task_id, $owner->reference() ) );
				if ( ! $rows ) {
					throw new ContractException( 'unauthorized', 'Task receipt is unavailable.' ); }
				$row                = $rows[0];
				$data               = json_decode( $row['receipt_json'], true, 32, JSON_THROW_ON_ERROR );
				[ $next, $payload ] = $mutation( $row, $data );
				if ( $next !== $row || $payload !== $data ) {
					$this->db->query( "UPDATE $table SET state=%s,outcome=%s,receipt_json=%s,state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE task_id=%s AND owner_ref=%s", array( $next['state'], $next['outcome'], self::json( $payload ), $task_id, $owner->reference() ) );
				}
				return $this->get( $owner, $task_id );
			}
		);
	}

	/** Independent expiry applies only to resolved receipts, not uncertain work. */
	public function purge_expired( int $limit = 100 ): int {
		if ( 1 > $limit || 500 < $limit ) {
			throw new ContractException( 'invalid_input', 'Task receipt cleanup bound is unsupported.' ); }
		$this->db->query( 'DELETE FROM ' . $this->db->table( 'task_receipts' ) . " WHERE expires_at<=UTC_TIMESTAMP() AND state IN ('completed','released') ORDER BY expires_at LIMIT %d", array( $limit ) );
		return $this->db->affected();
	}

	private function atomic( callable $callback ) {
		return $this->db->in_transaction() ? $callback() : $this->db->transaction( $callback );
	}

	/** Database JSON operations may reorder object keys without changing the route. */
	private static function task_identity( string $owner, string $run, ?string $parent, array $classification, array $route ): string {
		$value = array( $owner, $run, $parent, $classification, $route );
		self::assert_identity_types( $value );
		return hash( 'sha256', "logical-task-identity-v2\0" . ( new JsonCanonicalizer() )->encode( $value ) );
	}

	/** Task identities have no floating-point or coercible values. */
	private static function assert_identity_types( array $value ): void {
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				self::assert_identity_types( $child );
			} elseif ( null !== $child && ! is_string( $child ) && ! is_int( $child ) && ! is_bool( $child ) ) {
				throw new ContractException( 'invalid_input', 'Task identity contains unsupported value types.' );
			}
		}
	}

	/** Authenticate legacy ordered hashes before admitting the same immutable task. */
	private static function existing_identity_matches( array $row, string $owner, string $run, ?string $parent, array $classification, array $route, string $identity ): bool {
		if ( $row['owner_ref'] !== $owner || $row['run_id'] !== $run || $row['parent_task_id'] !== $parent || $row['logical_task'] !== $classification['kind'] || (int) $row['uses_ai'] !== (int) $classification['uses_ai'] || $row['feature'] !== $classification['feature'] || $row['source'] !== $route['source'] ) {
			return false;
		}
		try {
			$data = json_decode( $row['receipt_json'], true, 32, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data['classification'] ?? null ) || ! is_array( $data['route'] ?? null ) ) {
				return false;
			}
			$stored_route = self::route( $data['route'] );
			// Canonical equality retains every typed authority value. It never
			// authorizes a changed owner, source, provider, model or task branch.
			if ( ! hash_equals( $identity, self::task_identity( $owner, $run, $parent, $data['classification'], $stored_route ) ) ) {
				return false;
			}
			$legacy = hash( 'sha256', self::json( array( $owner, $run, $parent, $data['classification'], $stored_route ) ) );
			return hash_equals( $row['identity_hash'], $identity ) || hash_equals( $row['identity_hash'], $legacy );
		} catch ( \JsonException | ContractException $error ) {
			return false;
		}
	}

	private static function route( array $route ): array {
		if ( array_diff( array_keys( $route ), array( 'version', 'feature', 'source', 'wordpress', 'managed_fingerprint', 'task_routes' ) ) || ! in_array( $route['version'] ?? null, array( 1, 2 ), true ) || ! in_array( $route['feature'] ?? '', array( 'listing', 'builder', 'visitor', 'admin' ), true ) || ! in_array( $route['source'] ?? '', array( 'wordpress', 'managed' ), true ) || ! is_array( $route['wordpress'] ?? null ) || 3 !== count( $route['wordpress'] ) ) {
			throw new ContractException( 'invalid_input', 'Task requires a closed server AI route snapshot.' );
		}
		$result = array( 'version' => $route['version'], 'feature' => $route['feature'], 'source' => $route['source'], 'wordpress' => array() );
		foreach ( array( 'text', 'image', 'vision' ) as $task ) {
			$selected = $route['wordpress'][ $task ] ?? null;
			if ( ! is_array( $selected ) || 2 !== count( $selected ) || ! isset( $selected['provider_id'], $selected['model_id'] ) || ! is_string( $selected['provider_id'] ) || ! is_string( $selected['model_id'] ) || ( '' !== $selected['provider_id'] && null === self::identity( $selected['provider_id'], true ) ) || ( '' !== $selected['model_id'] && ( '' === $selected['provider_id'] || null === self::identity( $selected['model_id'] ) ) ) ) {
				throw new ContractException( 'invalid_input', 'Task AI route contains unsupported identifiers.' );
			}
			$result['wordpress'][ $task ] = $selected;
		}
		if ( 2 === $route['version'] ) {
			if ( ! is_array( $route['task_routes'] ?? null ) || count( $route['task_routes'] ) !== 3 ) {
				throw new ContractException( 'invalid_input', 'Captured task source policy is unavailable.' );
			}
			foreach ( $result['wordpress'] as $task => $selected ) {
				$choice = $route['task_routes'][ $task ] ?? null;
				if ( ! is_array( $choice ) || 3 !== count( $choice ) || ! in_array( $choice['source'] ?? '', array( 'wordpress', 'managed' ), true ) || ( $choice['provider_id'] ?? null ) !== $selected['provider_id'] || ( $choice['model_id'] ?? null ) !== $selected['model_id'] ) {
					throw new ContractException( 'invalid_input', 'Captured task choice differs from its provider binding.' );
				}
			}
			$result['task_routes'] = $route['task_routes'];
		} elseif ( isset( $route['task_routes'] ) ) {
			throw new ContractException( 'invalid_input', 'Legacy policy cannot acquire new task choices.' );
		}
		if ( array_key_exists( 'managed_fingerprint', $route ) ) {
			if ( ! is_string( $route['managed_fingerprint'] ) || ( '' !== $route['managed_fingerprint'] && ! preg_match( '/^[a-f0-9]{64}$/D', $route['managed_fingerprint'] ) ) ) {
				throw new ContractException( 'invalid_input', 'Invalid managed connection binding.' ); }
			$result['managed_fingerprint'] = $route['managed_fingerprint'];
		}
		return $result;
	}

	private static function telemetry( array $input, array $route ): array {
		if ( isset( $input['source'] ) && $route['source'] !== $input['source'] ) {
			throw new ContractException( 'scope_conflict', 'Provider call cannot silently cross the task source.' ); }
		$reported = true === ( $input['usage_reported'] ?? false ) && isset( $input['input_tokens'], $input['output_tokens'] ) && is_int( $input['input_tokens'] ) && is_int( $input['output_tokens'] ) && 0 <= $input['input_tokens'] && 0 <= $input['output_tokens'];
		$result   = array( 'source' => $route['source'], 'task' => 'image' === ( $input['task'] ?? $input['modality'] ?? '' ) ? 'image' : 'text', 'usage_reported' => $reported, 'input_tokens' => $reported ? $input['input_tokens'] : null, 'output_tokens' => $reported ? $input['output_tokens'] : null, 'provider_cost' => null, 'duration_ms' => isset( $input['duration_ms'] ) && is_int( $input['duration_ms'] ) && 0 <= $input['duration_ms'] ? $input['duration_ms'] : null );
		foreach ( array( 'requested_provider', 'requested_model', 'provider_id', 'model_id', 'reported_model' ) as $key ) {
			$result[ $key ] = self::identity( $input[ $key ] ?? null, in_array( $key, array( 'requested_provider', 'provider_id' ), true ) );
		}
		if ( 'managed' === $route['source'] && isset( $input['managed_receipt'] ) ) {
			$result = array_merge( $result, ManagedService::receipt( array( 'receipt' => $input['managed_receipt'] ) ) ); }
		return $result;
	}

	private static function decode( array $row ): array {
		$data = json_decode( $row['receipt_json'], true, 32, JSON_THROW_ON_ERROR );
		return array( 'task_id' => $row['task_id'], 'run_id' => $row['run_id'], 'parent_task_id' => $row['parent_task_id'], 'logical_task' => $row['logical_task'], 'feature' => $row['feature'], 'source' => $row['source'], 'state' => $row['state'], 'state_version' => (int) $row['state_version'], 'outcome' => $row['outcome'], 'created_at' => $row['created_at'], 'expires_at' => $row['expires_at'] ) + $data;
	}

	private static function identifier( string $value ): void {
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value ) ) {
			throw new ContractException( 'invalid_input', 'Task or call identity is invalid.' ); }
	}

	private static function identity( $value, bool $provider = false ): ?string {
		return is_string( $value ) && preg_match( $provider ? '/^[a-z0-9_-]{1,128}$/D' : '/^[A-Za-z0-9._:\\/@+-]{1,191}$/D', $value ) ? $value : null;
	}

	private static function json( array $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	}
}
