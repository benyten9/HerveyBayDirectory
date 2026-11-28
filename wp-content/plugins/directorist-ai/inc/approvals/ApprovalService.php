<?php
/**
 * Human review, decision, expiry, reservation and consumption lifecycle.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Approvals;

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageConflictException;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;

/** Model text and external-client confirmation cannot create approval state. */
final class ApprovalService {
	/**
	 * Dedicated runtime connection.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind the approval repository.
	 *
	 * @param Database $db Dedicated runtime connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Create a pending review from an owned immutable proposal artifact.
	 *
	 * @param Actor        $actor Authenticated human principal.
	 * @param ApprovalSpec $spec Trusted domain review specification.
	 * @return array Safe review record.
	 * @throws ContractException Invalid ownership or stale review material.
	 */
	public function create( Actor $actor, ApprovalSpec $spec ): array {
		return $this->create_with_state( $actor, $spec, 'pending' );
	}

	/**
	 * Persist a proposal review that cannot yet be approved or reserved.
	 *
	 * @param Actor        $actor Authenticated proposal owner.
	 * @param ApprovalSpec $spec Exact trusted review body.
	 * @return array Non-actionable staged review.
	 */
	public function create_staged( Actor $actor, ApprovalSpec $spec ): array {
		return $this->create_with_state( $actor, $spec, 'staged' );
	}

	/**
	 * Activate the exact staged review only after the caller's final authority fence.
	 *
	 * @param Actor  $actor Authenticated proposal owner.
	 * @param string $approval_id Staged review identity.
	 * @param string $review_hash Exact staged review hash.
	 * @return array Pending/previously activated review.
	 * @throws ContractException Missing, changed or expired staged review.
	 */
	public function activate_staged( Actor $actor, string $approval_id, string $review_hash ): array {
		if ( $actor->is_guest() || ! preg_match( '/^[a-f0-9]{64}$/D', $review_hash ) ) {
			throw new ContractException( 'unauthorized', 'Approval activation requires its authenticated owner.' );
		}
		ConversationStore::identifier( $approval_id );
		$table = $this->db->table( 'approvals' );
		return $this->db->transaction(
			function ( Database $db ) use ( $actor, $approval_id, $review_hash, $table ): array {
				$artifacts     = $db->table( 'artifacts' );
				$conversations = $db->table( 'conversations' );
				$rows          = $db->rows( "SELECT p.* FROM $table p INNER JOIN $artifacts a ON a.public_id=p.artifact_id AND a.version=p.artifact_version INNER JOIN $conversations c ON c.id=a.conversation_id AND c.owner_ref=p.actor_ref WHERE p.public_id=%s AND p.actor_ref=%s AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) FOR UPDATE", array( $approval_id, $actor->owner()->reference() ) );
				if ( ! $rows || ! hash_equals( hash( 'sha256', $rows[0]['review_json'] ), $review_hash ) || ! in_array( $rows[0]['state'], array( 'staged', 'pending', 'approved' ), true ) ) {
					throw new ContractException( 'approval_mismatch', 'Staged builder review changed before activation.' );
				}
				if ( strtotime( $rows[0]['expires_at'] . ' UTC' ) <= time() ) {
					$db->query( "UPDATE $table SET state='expired' WHERE id=%d AND state='staged'", array( (int) $rows[0]['id'] ) );
					throw new ContractException( 'approval_expired', 'Staged builder review expired before activation.' );
				}
				if ( 'staged' === $rows[0]['state'] ) {
					$db->query( "UPDATE $table SET state='pending' WHERE id=%d AND state='staged'", array( (int) $rows[0]['id'] ) );
					$rows = $db->rows( "SELECT * FROM $table WHERE id=%d FOR UPDATE", array( (int) $rows[0]['id'] ) );
				}
				return $this->public_record( $rows[0] );
			}
		);
	}

	/**
	 * Revoke only a non-actionable staged recipe review when its trusted source changes.
	 *
	 * @param Actor  $actor       Exact review owner.
	 * @param string $approval_id Review identity.
	 * @param string $review_hash Exact review hash.
	 * @throws ContractException Review is absent, changed, or already activated.
	 */
	public function revoke_unreserved( Actor $actor, string $approval_id, string $review_hash ): void {
		if ( $actor->is_guest() || ! preg_match( '/^[a-f0-9]{64}$/D', $review_hash ) ) {
			throw new ContractException( 'unauthorized', 'Recipe review revocation requires its owner.' );
		}
		ConversationStore::identifier( $approval_id );
		$this->db->transaction(
			function ( Database $db ) use ( $actor, $approval_id, $review_hash ): void {
				$table = $db->table( 'approvals' );
				$rows  = $db->rows( "SELECT id,state,review_json,reserved_operation_public_id FROM $table WHERE public_id=%s AND actor_ref=%s FOR UPDATE", array( $approval_id, $actor->owner()->reference() ) );
				if ( ! $rows || null !== $rows[0]['reserved_operation_public_id'] || ! hash_equals( hash( 'sha256', $rows[0]['review_json'] ), $review_hash ) || ! in_array( $rows[0]['state'], array( 'staged', 'revoked' ), true ) ) {
					throw new ContractException( 'approval_mismatch', 'Recipe review cannot be revoked after activation or change.' );
				}
				if ( 'revoked' !== $rows[0]['state'] ) {
					$db->query( "UPDATE $table SET state='revoked' WHERE id=%d AND state='staged'", array( (int) $rows[0]['id'] ) );
				}
			}
		);
	}

	/**
	 * Create one exact review in its requested trusted lifecycle state.
	 *
	 * @param Actor        $actor Authenticated proposal owner.
	 * @param ApprovalSpec $spec Exact trusted review body.
	 * @param string       $state Pending or staged initial state.
	 * @return array Stored review.
	 * @throws ContractException Invalid owner, artifact or state.
	 */
	private function create_with_state( Actor $actor, ApprovalSpec $spec, string $state ): array {
		$this->db->readiness()->assert_ready();
		if ( ! in_array( $state, array( 'pending', 'staged' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Approval initial state is invalid.' );
		}
		if ( $actor->is_guest() || $actor->owner()->reference() !== $spec->plan->actor_ref || null !== $spec->plan->approval_id ) {
			throw new ContractException( 'unauthorized', 'Approval review requires the authenticated proposal owner.' );
		}
		$artifact = ( new ArtifactStore( $this->db ) )->get( $actor->owner(), $spec->artifact_id, $spec->artifact_version );
		if ( ! hash_equals( $artifact['content_hash'], $spec->artifact_hash ) || ! hash_equals( $spec->plan->proposal_hash, $spec->artifact_hash ) || ! hash_equals( $artifact['environment_hash'], $spec->environment_hash ) ) {
			throw new ContractException( 'stale_version', 'Proposal artifact or environment changed before review.' );
		}
		$table           = $this->db->table( 'approvals' );
		$public_id       = bin2hex( random_bytes( 16 ) );
		$review_json     = json_encode( $spec->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		$review_identity = hash( 'sha256', $review_json );
		$base_hash       = $this->base_versions_hash( $spec->plan->targets );
		$row             = $this->db->transaction(
			function ( Database $db ) use ( $table, $public_id, $spec, $base_hash, $review_json, $review_identity, $state ): array {
				$db->query(
					"INSERT INTO $table (public_id,actor_ref,capability,proposal_hash,artifact_id,artifact_version,target_set_hash,base_versions_hash,environment_hash,state,expires_at,review_json,review_identity,created_at) VALUES (%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),%s,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
					array( $public_id, $spec->plan->actor_ref, $spec->plan->capability, $spec->plan->proposal_hash, $spec->artifact_id, $spec->artifact_version, $spec->plan->target_set_hash(), $base_hash, $spec->environment_hash, $state, $review_json, $review_identity )
				);
				$rows = $db->rows( "SELECT * FROM $table WHERE id=%d FOR UPDATE", array( $db->insert_id() ) );
				if ( ! $rows || $rows[0]['actor_ref'] !== $spec->plan->actor_ref || ! hash_equals( $rows[0]['review_json'], $review_json ) ) {
					throw new StorageConflictException( 'Proposal already has a different review record.' );
				}
				return $rows[0];
			}
		);
		return $this->public_record( $row );
	}

	/**
	 * Return the exact owned review payload and server lifecycle state.
	 *
	 * @param Actor  $actor Authenticated principal.
	 * @param string $approval_id Opaque approval ID.
	 * @return array Safe review record.
	 * @throws StorageException Approval is unavailable to this actor.
	 */
	public function get( Actor $actor, string $approval_id ): array {
		ConversationStore::identifier( $approval_id );
		$this->expire_due( $approval_id, $actor->owner()->reference() );
		$table = $this->db->table( 'approvals' );
		$rows  = $this->db->rows( "SELECT * FROM $table WHERE public_id=%s AND actor_ref=%s", array( $approval_id, $actor->owner()->reference() ) );
		if ( ! $rows ) {
			throw new StorageException( 'Approval is unavailable.' );
		}
		return $this->public_record( $rows[0] );
	}

	/**
	 * Record an explicit authenticated approve/reject decision.
	 *
	 * @param Actor  $actor Authenticated human principal.
	 * @param string $approval_id Opaque approval ID.
	 * @param string $review_hash Exact displayed review hash.
	 * @param string $decision Approve or reject.
	 * @return array Updated review record.
	 * @throws ContractException Decision is unauthorized, expired or mismatched.
	 */
	public function decide( Actor $actor, string $approval_id, string $review_hash, string $decision ): array {
		if ( $actor->is_guest() || ! in_array( $decision, array( 'approve', 'reject' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $review_hash ) ) {
			throw new ContractException( 'unauthorized', 'Approval decision is unavailable.' );
		}
		if ( 'approve' === $decision ) {
			$this->db->readiness()->assert_ready();
		}
		ConversationStore::identifier( $approval_id );
		$this->expire_due( $approval_id, $actor->owner()->reference() );
		$expired = false;
		$row     = $this->db->transaction(
			function ( Database $db ) use ( $actor, $approval_id, $review_hash, $decision, &$expired ): array {
				$table = $db->table( 'approvals' );
				$rows  = $db->rows( "SELECT * FROM $table WHERE public_id=%s AND actor_ref=%s FOR UPDATE", array( $approval_id, $actor->owner()->reference() ) );
				if ( ! $rows ) {
					throw new StorageException( 'Approval is unavailable.' );
				}
				$row  = $rows[0];
				$hash = hash( 'sha256', $row['review_json'] );
				if ( ! hash_equals( $hash, $review_hash ) ) {
					throw new ContractException( 'approval_mismatch', 'Displayed review no longer matches the approval record.' );
				}
				if ( 'approve' === $decision && in_array( $row['capability'], array( 'listing.create', 'media.import' ), true ) ) {
					throw new ContractException( 'approval_required', 'This effect requires acknowledgement in the active run review.' );
				}
				if ( 'expired' === $row['state'] ) {
					throw new ContractException( 'approval_expired', 'Approval review has expired.' );
				}
				if ( in_array( $row['state'], array( 'staged', 'pending', 'approved' ), true ) && strtotime( $row['expires_at'] . ' UTC' ) <= time() ) {
					$db->query( "UPDATE $table SET state='expired' WHERE id=%d AND state IN ('staged','pending','approved')", array( (int) $row['id'] ) );
					$row['state'] = 'expired';
					$expired      = true;
					return $row;
				}
				$state = 'approve' === $decision ? 'approved' : 'rejected';
				if ( $row['state'] === $state ) {
					return $row;
				}
				if ( 'pending' !== $row['state'] ) {
					throw new ContractException( 'approval_mismatch', 'Approval already has a different terminal decision.' );
				}
				$db->query( "UPDATE $table SET state=%s WHERE id=%d AND state='pending'", array( $state, (int) $row['id'] ) );
				return $db->rows( "SELECT * FROM $table WHERE id=%d", array( (int) $row['id'] ) )[0];
			}
		);
		if ( $expired ) {
			throw new ContractException( 'approval_expired', 'Approval review has expired.' );
		}
		return $this->public_record( $row );
	}

	/**
	 * Reserve one operation and reconstruct the exact approved plan.
	 *
	 * @param Actor  $actor Current authenticated principal.
	 * @param string $approval_id Opaque approval ID.
	 * @param string $review_hash Exact approved review hash.
	 * @param string $operation_id Stable operation identity.
	 * @param string $current_environment_hash Handler-derived current environment.
	 * @return ExecutionPermit Server-only permit.
	 * @throws ContractException Approval is absent, expired, changed or already used.
	 */
	public function reserve( Actor $actor, string $approval_id, string $review_hash, string $operation_id, string $current_environment_hash ): ExecutionPermit {
		$this->db->readiness()->assert_ready();
		if ( $actor->is_guest() || ! preg_match( '/^[a-f0-9]{64}$/D', $review_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $current_environment_hash ) ) {
			throw new ContractException( 'unauthorized', 'Approval reservation is unavailable.' );
		}
		ConversationStore::identifier( $operation_id );
		ConversationStore::identifier( $approval_id );
		$this->expire_due( $approval_id, $actor->owner()->reference() );
		$expired = false;
		$permit  = $this->db->transaction(
			function ( Database $db ) use ( $actor, $approval_id, $review_hash, $operation_id, $current_environment_hash, &$expired ): ?ExecutionPermit {
				$table = $db->table( 'approvals' );
				$rows  = $db->rows( "SELECT * FROM $table WHERE public_id=%s AND actor_ref=%s FOR UPDATE", array( $approval_id, $actor->owner()->reference() ) );
				if ( ! $rows ) {
					throw new StorageException( 'Approval is unavailable.' );
				}
				$row = $rows[0];
				if ( 'expired' === $row['state'] ) {
					throw new ContractException( 'approval_expired', 'Approval has expired before effect reservation.' );
				}
				if ( in_array( $row['state'], array( 'staged', 'pending', 'approved' ), true ) && strtotime( $row['expires_at'] . ' UTC' ) <= time() ) {
					$db->query( "UPDATE $table SET state='expired' WHERE id=%d AND state IN ('staged','pending','approved')", array( (int) $row['id'] ) );
					$expired = true;
					return null;
				}
				if ( ! hash_equals( hash( 'sha256', $row['review_json'] ), $review_hash ) || ! hash_equals( $row['environment_hash'], $current_environment_hash ) ) {
					throw new ContractException( 'approval_mismatch', 'Approval review or environment changed before effect reservation.' );
				}
				if ( null !== $row['reserved_operation_public_id'] && $row['reserved_operation_public_id'] !== $operation_id ) {
					throw new ContractException( 'approval_mismatch', 'Approval is already reserved for another operation.' );
				}
				if ( ! in_array( $row['state'], array( 'approved', 'reserved', 'consumed' ), true ) ) {
					throw new ContractException( 'approval_required', 'Approval has not been granted.' );
				}
				if ( 'approved' === $row['state'] ) {
					$db->query( "UPDATE $table SET state='reserved',reserved_operation_public_id=%s WHERE id=%d AND state='approved'", array( $operation_id, (int) $row['id'] ) );
				}
				$review = json_decode( $row['review_json'] );
				if ( $review->plan->operation_id !== $operation_id ) {
					throw new ContractException( 'approval_mismatch', 'Operation identity differs from the reviewed plan.' );
				}
				$targets   = array_map( static fn( object $target ): EffectTarget => new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ), $review->plan->targets );
				$base_hash = $this->base_versions_hash( $targets );
				$plan      = new EffectPlan( $operation_id, $row['actor_ref'], $review->plan->capability, $review->plan->capability_revision, $row['proposal_hash'], $targets, $review->plan->run_id, (int) $row['id'] );
				if ( $review->plan->actor_ref !== $row['actor_ref'] || $review->plan->capability !== $row['capability'] || $review->plan->proposal_hash !== $row['proposal_hash'] || $review->artifact->id !== $row['artifact_id'] || (int) $review->artifact->version !== (int) $row['artifact_version'] || $review->artifact->hash !== $row['proposal_hash'] || ! hash_equals( $row['target_set_hash'], $plan->target_set_hash() ) || ! hash_equals( $row['base_versions_hash'], $base_hash ) ) {
					throw new ContractException( 'approval_mismatch', 'Persisted approval fields differ from the reviewed plan.' );
				}
				return new ExecutionPermit( $plan, $approval_id, $review_hash );
			}
		);
		if ( $expired || null === $permit ) {
			throw new ContractException( 'approval_expired', 'Approval has expired before effect reservation.' );
		}
		return $permit;
	}

	/**
	 * Consume only a reconciled known operation; unknown remains reserved.
	 *
	 * @param ExecutionPermit $permit Server-issued execution permit.
	 * @param array           $receipt Durable effect receipt.
	 * @throws ContractException Receipt cannot complete this permit.
	 */
	public function complete( ExecutionPermit $permit, array $receipt ): void {
		if ( ! isset( $receipt['operation_id'], $receipt['state'] ) || $receipt['operation_id'] !== $permit->plan->operation_id || ! in_array( $receipt['state'], array( 'succeeded', 'failed', 'partial', 'unknown' ), true ) ) {
			throw new ContractException( 'unknown_outcome', 'Effect receipt cannot complete this approval.' );
		}
		if ( 'unknown' === $receipt['state'] ) {
			return;
		}
		$this->db->transaction(
			function ( Database $db ) use ( $permit ): void {
				$table = $db->table( 'approvals' );
				$rows  = $db->rows( "SELECT state,reserved_operation_public_id FROM $table WHERE public_id=%s FOR UPDATE", array( $permit->approval_id ) );
				if ( ! $rows || $rows[0]['reserved_operation_public_id'] !== $permit->plan->operation_id || ! in_array( $rows[0]['state'], array( 'reserved', 'consumed' ), true ) ) {
					throw new StorageConflictException( 'Approval could not be consumed for this operation.' );
				}
				if ( 'reserved' === $rows[0]['state'] ) {
					$db->query( "UPDATE $table SET state='consumed' WHERE public_id=%s AND reserved_operation_public_id=%s AND state='reserved'", array( $permit->approval_id, $permit->plan->operation_id ) );
				}
			}
		);
	}

	/**
	 * Persist expiry before lifecycle decisions inspect a row.
	 *
	 * @param string $approval_id Opaque approval ID.
	 * @param string $actor_ref Server-resolved actor reference.
	 */
	private function expire_due( string $approval_id, string $actor_ref ): void {
		$table = $this->db->table( 'approvals' );
		$this->db->query( "UPDATE $table SET state='expired' WHERE public_id=%s AND actor_ref=%s AND state IN ('staged','pending','approved') AND expires_at<=UTC_TIMESTAMP()", array( $approval_id, $actor_ref ) );
	}

	/**
	 * Hash exact target base versions independently from proposed effects.
	 *
	 * @param EffectTarget[] $targets Exact reviewed targets.
	 */
	private function base_versions_hash( array $targets ): string {
		return hash(
			'sha256',
			json_encode(
				array_map(
					static fn( EffectTarget $target ): array => array(
						'key'     => $target->key,
						'version' => $target->expected_version,
					),
					$targets
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Convert a private database row to the exact review surface.
	 *
	 * @param array $row Private database row.
	 */
	private function public_record( array $row ): array {
		return array(
			'id'                    => $row['public_id'],
			'state'                 => $row['state'],
			'expires_at'            => gmdate( DATE_RFC3339, strtotime( $row['expires_at'] . ' UTC' ) ),
			'review_hash'           => hash( 'sha256', $row['review_json'] ),
			'review'                => json_decode( $row['review_json'] ),
			'reserved_operation_id' => $row['reserved_operation_public_id'],
		);
	}
}
