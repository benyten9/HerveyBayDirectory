<?php
/**
 * Owned, receipt-backed readback of a native template assignment.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Narrow read-only native result boundary.

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\JsonDecoder;

/** Result links expose only the current owned marker, not a model-supplied target. */
final class BuilderTemplateAssignmentResult {
	private Database $db;
	private BuilderTemplateAssignmentRegistry $adapters;

	public function __construct( Database $db, ?BuilderTemplateAssignmentRegistry $adapters = null ) {
		$this->db       = $db;
		$this->adapters = $adapters ?? BuilderTemplateAssignmentRegistry::installed();
	}

	public function read( \WP_REST_Request $request ): array|\WP_Error {
		$operation = (string) $request['operation_id'];
		$user      = get_current_user_id();
		if ( $user < 1 || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $operation ) ) {
			return $this->unavailable();
		}
		try {
			$operations    = $this->db->table( 'operations' );
			$runs          = $this->db->table( 'runs' );
			$conversations = $this->db->table( 'conversations' );
			$rows          = $this->db->rows( "SELECT o.receipt_json FROM $operations o INNER JOIN $runs r ON r.id=o.run_id AND r.owner_ref=o.actor_ref INNER JOIN $conversations c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE o.public_id=%s AND o.actor_ref=%s AND ((o.capability='builder.template_assign' AND o.capability_revision='1') OR (o.capability='builder.template_restore' AND o.capability_revision='1')) AND o.state='succeeded' AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) LIMIT 2", array( $operation, 'user:' . $user ) );
			if ( 1 !== count( $rows ) || ! is_string( $rows[0]['receipt_json'] ) ) {
				return $this->unavailable();
			}
			$receipt = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['receipt_json'] );
			$plan    = $receipt->plan ?? null;
			$targets = $receipt->targets ?? null;
			$parts   = $targets instanceof \stdClass ? array_values( get_object_vars( $targets ) ) : array();
			$outcome = 1 === count( $parts ) ? $parts[0] : null;
			$draft   = $outcome->effects->draft_id ?? null;
			if ( ! $plan instanceof \stdClass || ! is_array( $plan->targets ?? null ) || 1 !== count( $plan->targets ) || ! $outcome instanceof \stdClass || ! is_int( $draft ) || $draft < 1 || ( $outcome->status ?? null ) !== 'succeeded' || ( $receipt->state ?? null ) !== 'succeeded' || ( $receipt->operation_id ?? null ) !== $operation || ( $plan->operation_id ?? null ) !== $operation || ( $plan->actor_ref ?? null ) !== 'user:' . $user || ! in_array( $plan->capability ?? null, array( 'builder.template_assign', 'builder.template_restore' ), true ) || ( $plan->capability_revision ?? null ) !== '1' || ( $outcome->key ?? null ) !== 'builder-template:' . $draft || ( $plan->targets[0]->key ?? null ) !== $outcome->key || ! is_string( $outcome->after_version ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $outcome->after_version ) ) {
				return $this->unavailable();
			}
			$target = $plan->targets[0];
			$exact  = new EffectPlan( $operation, 'user:' . $user, $plan->capability, '1', $plan->proposal_hash, array( new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), $plan->run_id, $plan->approval_id );
			if ( ( $receipt->plan_hash ?? null ) !== $exact->hash() || ( $receipt->proposal_hash ?? null ) !== $exact->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $exact->target_set_hash() || ( $outcome->before_version ?? null ) !== $target->expected_version || ( $outcome->effect_hash ?? null ) !== $target->effect_hash || ( $outcome->expected_version ?? null ) !== $target->expected_version ) {
				return $this->unavailable();
			}
			$row              = ( new ArtifactStore( $this->db ) )->get( Actor::user( $user )->owner(), $operation, 1 );
			$payload          = ( new JsonDecoder( 5242880, 64 ) )->decode( $row['payload_json'] );
			$restore          = 'builder.template_restore' === $plan->capability;
			$source_authority = is_int( $payload->source_id ?? null ) && 0 < $payload->source_id && current_user_can( 'edit_post', $payload->source_id );
			if ( 0 === ( $payload->source_id ?? null ) && 'gutenberg' === ( $payload->builder ?? null ) && ( $payload->inspection->source_id ?? null ) === 0 && ( $payload->inspection->before_native->mode ?? null ) === 'native_fallback' ) {
				$source_ref       = $payload->inspection->before_native->source->requested_ref ?? null;
				$source_authority = is_string( $source_ref ) && preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $source_ref, $source_parts ) && get_stylesheet() === $source_parts[2] && ( 'wp_template' === $source_parts[1] || in_array( $source_parts[3], array( 'header', 'footer' ), true ) ) && get_post_meta( $draft, '_directorist_ai_draft_source_ref', true ) === $source_ref && get_post_type( $draft ) === $source_parts[1] && 0 === (int) get_post_field( 'post_parent', $draft );
				if ( $source_authority && 'wp_template_part' === $source_parts[1] ) {
					$area_terms       = wp_get_object_terms( $draft, 'wp_template_part_area', array( 'fields' => 'names' ) );
					$source_authority = ! is_wp_error( $area_terms ) && array( $source_parts[3] ) === $area_terms;
				}
			}
			if ( 'proposal' !== $row['kind'] || ! hash_equals( $row['content_hash'], hash( 'sha256', $row['payload_json'] ) ) || $exact->proposal_hash !== $row['content_hash'] || ! $payload instanceof \stdClass || ( $restore ? 'builder_template_restoration' : 'builder_template_assignment' ) !== ( $payload->kind ?? null ) || ( $payload->draft_id ?? null ) !== $draft || ( $payload->source_id ?? null ) !== ( $outcome->effects->source_id ?? null ) || ( $payload->directory_id ?? null ) !== ( $outcome->effects->directory_id ?? null ) || ( $payload->builder ?? null ) !== ( $outcome->effects->builder ?? null ) || ( $payload->draft_operation_id ?? null ) !== ( $outcome->effects->draft_operation_id ?? null ) || ( $payload->inspection->impact_hash ?? null ) !== ( $outcome->effects->impact_hash ?? null ) || ( $restore ? ( $payload->expected_after_hash ?? null ) : ( $payload->inspection->before_hash ?? null ) ) !== $target->expected_version || ( $restore ? ( $payload->inspection->before_hash ?? null ) : ( $payload->inspection->impact_hash ?? null ) ) !== $target->effect_hash || ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', $draft ) || ! $source_authority || (int) get_post_field( 'post_author', $draft ) !== $user || $this->adapters->qualify( $draft, $payload->draft_operation_id ) !== $payload->builder ) {
				return $this->unavailable();
			}
			$adapter = $this->adapters->adapter( $payload->builder );
			try {
				$native = $restore ? $adapter->reconcile_restore( $draft, $payload->draft_operation_id, $payload->directory_id, $payload->assignment_operation_id, $operation, $payload->expected_after_hash, $payload->inspection->impact_hash, $payload->inspection->before_native ) : $adapter->reconcile( $draft, $payload->draft_operation_id, $payload->directory_id, $operation, $payload->inspection->before_hash, $payload->inspection->impact_hash );
			} catch ( \Throwable $error ) {
				// A later native edit makes this receipt historical; it does not erase its owned proof.
				$native = null;
			}
			$current = ( $native->state ?? null ) === 'succeeded' && ( $native->operation_id ?? null ) === $operation && ( $native->after_hash ?? null ) === $outcome->after_version && ( $native->before_hash ?? null ) === $target->expected_version && ( $native->impact_hash ?? null ) === $payload->inspection->impact_hash;
			return array(
				'operation_id'           => $operation,
				'draft_id'               => $draft,
				'source_id'              => $payload->source_id,
				'directory_id'           => $payload->directory_id,
				'builder'                => $payload->builder,
				'assigned_at_operation'  => ! $restore,
				'restored_at_operation'  => $restore,
				'reviewed_state_current' => $current,
				'after_hash'             => $outcome->after_version,
				'before_hash'            => $target->expected_version,
				'impact_hash'            => $payload->inspection->impact_hash,
				'consumer_count'         => $payload->inspection->consumer_count,
			);
		} catch ( \Throwable $error ) {
			return $this->unavailable();
		}
	}

	/** Elementor after-save guard checks the exact settled receipt, never model text. */
	public function elementor_receipt_valid( bool $valid, int $draft_id, string $assignment_operation_id, $marker ): bool {
		if ( $valid || ! is_array( $marker ) || $draft_id < 1 ) {
			return $valid;
		}
		$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/assignments/' . $assignment_operation_id );
		$request->set_param( 'operation_id', $assignment_operation_id );
		$result = $this->read( $request );
		return is_array( $result ) && $result['draft_id'] === $draft_id && true === $result['assigned_at_operation'] && ( $marker['state'] ?? null ) === 'assigned' && ! isset( $marker['restoration_operation_id'] ) && ( $marker['operation_id'] ?? null ) === $assignment_operation_id && ( $marker['before_hash'] ?? null ) === $result['before_hash'] && ( $marker['impact_hash'] ?? null ) === $result['impact_hash'] && ( $marker['after_hash'] ?? null ) === $result['after_hash'];
	}

	private function unavailable(): \WP_Error {
		return new \WP_Error( 'not_found', 'The owned native template assignment result is unavailable.', array( 'status' => 404 ) );
	}
}
