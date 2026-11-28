<?php
/**
 * Read-only native Save check for one reserved scoped live approval.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed receipt/approval comparison.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\JsonDecoder;

/** A transient ticket or stage receipt cannot authorize native Save alone. */
final class BuilderScopedLiveTicketVerifier {
	private Database $db;
	private BuilderScopedLiveApply $handler;

	public function __construct( Database $db, BuilderScopedLiveApply $handler ) {
		$this->db      = $db;
		$this->handler = $handler;
	}

	public function valid( bool $valid, int $source_id, string $operation_id, string $impact_hash, object $inspection ): bool {
		if ( $valid || get_current_user_id() < 1 || $source_id < 1 || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation_id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $impact_hash ) ) {
			return false;
		}
		try {
			$user            = get_current_user_id();
			$operations      = $this->db->table( 'operations' );
			$approvals_table = $this->db->table( 'approvals' );
			$rows            = $this->db->rows( "SELECT o.receipt_json,o.approval_id,a.public_id AS approval_public_id FROM $operations o INNER JOIN $approvals_table a ON a.id=o.approval_id AND a.actor_ref=o.actor_ref WHERE o.public_id=%s AND o.actor_ref=%s AND o.capability='builder.live_apply' AND o.capability_revision='1' AND o.state='unknown' LIMIT 2", array( $operation_id, 'user:' . $user ) );
			if ( 1 !== count( $rows ) || ! is_string( $rows[0]['receipt_json'] ) || strlen( $rows[0]['receipt_json'] ) > 1048576 || (int) $rows[0]['approval_id'] < 1 || ! is_string( $rows[0]['approval_public_id'] ) ) {
				return false;
			}
			$receipt = ( new JsonDecoder( 1048576, 48 ) )->decode( $rows[0]['receipt_json'] );
			$stored  = $receipt->plan ?? null;
			if ( ! $stored instanceof \stdClass || ( $receipt->state ?? null ) !== 'unknown' || ( $receipt->operation_id ?? null ) !== $operation_id || ( $stored->operation_id ?? null ) !== $operation_id || ( $stored->actor_ref ?? null ) !== 'user:' . $user || ( $stored->capability ?? null ) !== 'builder.live_apply' || ( $stored->capability_revision ?? null ) !== '1' || ! is_array( $stored->targets ?? null ) || 1 !== count( $stored->targets ) ) {
				return false;
			}
			$target = $stored->targets[0];
			$plan   = new EffectPlan( $operation_id, $stored->actor_ref, 'builder.live_apply', '1', $stored->proposal_hash, array( new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), $stored->run_id, (int) $rows[0]['approval_id'] );
			$part   = $receipt->targets->{$target->key} ?? null;
			if ( ( $receipt->plan_hash ?? null ) !== $plan->hash() || ( $receipt->proposal_hash ?? null ) !== $plan->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $plan->target_set_hash() || ( $part->status ?? null ) !== 'unknown' || ( $part->diagnostic ?? null ) !== 'awaiting_native_confirmation' ) {
				return false;
			}
			$actor    = Actor::user( $user );
			$approval = ( new ApprovalService( $this->db ) )->get( $actor, $rows[0]['approval_public_id'] );
			if ( 'reserved' !== $approval['state'] || ! is_string( $approval['reserved_operation_id'] ) || ! hash_equals( $operation_id, $approval['reserved_operation_id'] ) || ! is_string( $approval['review']->plan->operation_id ?? null ) || ! hash_equals( $operation_id, $approval['review']->plan->operation_id ) || 'builder.live_apply' !== ( $approval['review']->plan->capability ?? null ) || ! is_string( $approval['review']->artifact->hash ?? null ) || ! hash_equals( $plan->proposal_hash, $approval['review']->artifact->hash ) ) {
				return false;
			}
			return $this->handler->ticket_matches( $plan, $source_id, $impact_hash, $inspection );
		} catch ( \Throwable $error ) {
			return false;
		}
	}
}
