<?php
/**
 * Owner-only native live ticket read and read-only Save reconciliation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed REST result boundary.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ExecutionPermit;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Effects\EffectLedger;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Never starts another native write or reissues an uncertain ticket. */
final class BuilderScopedLiveResultApi {
	private Database $db;
	private BuilderScopedLiveApply $handler;

	public function __construct( Database $db, BuilderScopedLiveApply $handler ) {
		$this->db      = $db;
		$this->handler = $handler;
	}

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/builder/live/(?P<operation_id>[A-Za-z0-9_.:-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'read' ),
			)
		);
	}

	public function read( \WP_REST_Request $request ): array|\WP_Error {
		try {
			$operation = (string) $request['operation_id'];
			GraphVersion::identifier( $operation );
			$user = get_current_user_id();
			if ( $user < 1 ) {
				throw new ContractException( 'not_found', 'The owned live ticket is unavailable.' );
			}
			$operations      = $this->db->table( 'operations' );
			$approvals_table = $this->db->table( 'approvals' );
			$rows            = $this->db->rows( "SELECT o.state,o.receipt_json,o.approval_id,a.public_id AS approval_public_id FROM $operations o INNER JOIN $approvals_table a ON a.id=o.approval_id AND a.actor_ref=o.actor_ref WHERE o.public_id=%s AND o.actor_ref=%s AND o.capability='builder.live_apply' AND o.capability_revision='1' LIMIT 2", array( $operation, 'user:' . $user ) );
			if ( 1 !== count( $rows ) || ! is_string( $rows[0]['receipt_json'] ) || strlen( $rows[0]['receipt_json'] ) > 1048576 ) {
				throw new ContractException( 'not_found', 'The owned live ticket is unavailable.' );
			}
			$stored    = ( new JsonDecoder( 1048576, 48 ) )->decode( $rows[0]['receipt_json'] );
			$plan      = $this->plan( $stored, $operation, $user, $rows[0]['approval_id'] );
			$approvals = new ApprovalService( $this->db );
			$actor     = Actor::user( $user );
			$approval  = $approvals->get( $actor, $rows[0]['approval_public_id'] );
			if ( ! in_array( $approval['state'], array( 'reserved', 'consumed' ), true ) || ( $approval['review']->plan->operation_id ?? null ) !== $operation || ( $approval['review']->plan->capability ?? null ) !== 'builder.live_apply' || ( $approval['review']->artifact->hash ?? null ) !== $plan->proposal_hash ) {
				throw new ContractException( 'not_found', 'The owned live approval is unavailable.' );
			}
			$ticket  = $this->handler->ticket( $plan );
			$ledger  = new EffectLedger( $this->db );
			$receipt = 'unknown' === $rows[0]['state'] ? $ledger->reconcile( $plan, $this->handler ) : $ledger->receipt( $operation, $actor->owner()->reference() );
			$approvals->complete( new ExecutionPermit( $plan, $rows[0]['approval_public_id'], $approval['review_hash'] ), $receipt );
			$parts   = $receipt['targets'][ $plan->targets[0]->key ] ?? null;
			$status  = is_array( $parts ) ? ( $parts['status'] ?? 'unknown' ) : 'unknown';
			$current = false;
			if ( 'succeeded' === $status ) {
				try {
					$fresh   = $this->handler->reconcile( $plan, $plan->targets[0], 0 );
					$current = 'succeeded' === $fresh->status && is_string( $fresh->after_version ) && hash_equals( $plan->targets[0]->effect_hash, $fresh->after_version );
				} catch ( \Throwable $error ) {
					$current = false;
				}
			}
			return array(
				'operation_id'           => $operation,
				'state'                  => $receipt['state'],
				'native_status'          => $status,
				'reviewed_state_current' => $current,
				'ticket'                 => 'unknown' === $receipt['state'] ? $ticket : null,
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'not_found', 'The owned live ticket is unavailable.', array( 'status' => 404 ) );
		}
	}

	private function plan( object $receipt, string $operation, int $user, $approval_id ): EffectPlan {
		$stored = $receipt->plan ?? null;
		if ( ! $stored instanceof \stdClass || ( $receipt->operation_id ?? null ) !== $operation || ( $stored->operation_id ?? null ) !== $operation || ( $stored->actor_ref ?? null ) !== 'user:' . $user || ( $stored->capability ?? null ) !== 'builder.live_apply' || ( $stored->capability_revision ?? null ) !== '1' || ! is_array( $stored->targets ?? null ) || 1 !== count( $stored->targets ) || (int) $approval_id < 1 ) {
			throw new ContractException( 'not_found', 'The exact live plan is unavailable.' );
		}
		$target = $stored->targets[0];
		$plan   = new EffectPlan( $operation, $stored->actor_ref, 'builder.live_apply', '1', $stored->proposal_hash, array( new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), $stored->run_id, (int) $approval_id );
		if ( ( $receipt->plan_hash ?? null ) !== $plan->hash() || ( $receipt->proposal_hash ?? null ) !== $plan->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $plan->target_set_hash() ) {
			throw new ContractException( 'not_found', 'The live receipt changed.' );
		}
		return $plan;
	}
}
