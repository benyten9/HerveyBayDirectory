<?php
/** Explicit delivery POST recovery for the latest accepted read-only visitor turn. */
namespace DirectoristAIPro\Chat;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Runtime\RunStore;
use DirectoristAIPro\Storage\Database;

/** No admission from GET, no new turn, no native action, no provider replay. */
final class VisitorDeliveryRecoveryStore {
	public function __construct( private Database $db ) {}

	/**
	 * Acknowledge verified delivery or atomically recover its exact stale slot.
	 * The caller verifies the producer attestation before entering this method.
	 */
	public function delivery( Context $context, array $observed, array $message, bool $stale ): bool {
		$context->assert_execution_identity();
		$owner = $context->actor()->owner()->reference();
		return $this->db->transaction( function ( Database $db ) use ( $context, $owner, $observed, $message, $stale ): bool {
			$runs = $db->table( 'runs' ); $conversations = $db->table( 'conversations' ); $messages = $db->table( 'messages' );
			$rows = $db->rows( "SELECT r.*,c.profile,c.active_run_id,c.erasure_requested,c.public_id AS conversation_public_id FROM $runs r JOIN $conversations c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.id=%d AND r.owner_ref=%s FOR UPDATE", array( (int) $observed['id'], $owner ) );
			if ( 1 !== count( $rows ) ) { return false; }
			$run = $rows[0]; $checkpoint = json_decode( $run['checkpoint_json'] );
			if ( 'visitor' !== $context->profile() || 'visitor' !== $run['profile'] || $run['public_id'] !== $observed['public_id'] || (int) $run['state_version'] !== (int) $observed['state_version'] || (int) $run['erasure_requested'] || null !== $run['active_run_id'] || ! VisitorEvidenceRecovery::eligible( $run, $checkpoint ) ) { return false; }
			$latest_turn = (int) ( $checkpoint->latest_turn_id ?? $run['trigger_turn_id'] );
			if ( (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'turns' ) . ' WHERE conversation_id=%d AND id>%d', array( (int) $run['conversation_id'], $latest_turn ) ) || (int) $db->scalar( "SELECT COUNT(*) FROM $runs WHERE conversation_id=%d AND id>%d", array( (int) $run['conversation_id'], (int) $run['id'] ) ) || (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'operations' ) . ' WHERE run_id=%d', array( (int) $run['id'] ) ) ) { return false; }
			$events = $db->table( 'events' );
			if ( (int) $db->scalar( "SELECT COUNT(*) FROM $events WHERE run_id=%d AND type='visitor.delivered'", array( (int) $run['id'] ) ) ) { return false; }
			$stored = $db->rows( "SELECT * FROM $messages WHERE conversation_id=%d AND sequence=%d AND turn_id=%d AND role='assistant' FOR UPDATE", array( (int) $run['conversation_id'], (int) $message['sequence'], (int) $run['trigger_turn_id'] ) );
			if ( 1 !== count( $stored ) || $stored[0]['content_json'] !== $message['content_json'] || $stored[0]['source_refs_json'] !== $message['source_refs_json'] || (int) $db->scalar( "SELECT COUNT(*) FROM $messages WHERE conversation_id=%d AND sequence>%d", array( (int) $run['conversation_id'], (int) $message['sequence'] ) ) ) { return false; }
			$context->actor()->refresh(); $context->assert_execution_identity();
			$event_store = new RunStore( $db );
			if ( ! $stale ) {
				$event_store->event_in_transaction( (int) $run['id'], 'visitor.delivered', (object) array( 'sequence' => (int) $message['sequence'], 'content_hash' => hash( 'sha256', $message['content_json'] ) ) );
				return false;
			}
			$cycle = 1 + (int) ( $checkpoint->visitor_evidence_refreshes ?? 0 );
			$parent_task = $checkpoint->visitor_recovery_parent_task_id ?? $checkpoint->logical_task_id ?? $run['public_id'];
			$continuation = ( new \DirectoristAIPro\AI\TaskAccounting( $db ) )->admit_visitor_recovery( $context, $run['public_id'], $cycle, json_decode( wp_json_encode( $checkpoint->ai_route ), true ) );
			// This narrowly defined delivery transition is the ONLY exception to
			// terminal immutability. Original receipts/steps/budget/events remain.
			$snapshot_json = wp_json_encode( array( 'checkpoint' => $checkpoint, 'message' => $stored[0] ), JSON_PRESERVE_ZERO_FRACTION );
			$snapshot_hash = hash( 'sha256', $snapshot_json );
			$compressed = gzencode( $snapshot_json, 6 );
			if ( false === $compressed || strlen( $compressed ) > 1572864 ) { throw new \DirectoristAIPro\Storage\StorageException( 'Visitor delivery audit is unavailable.' ); }
			$steps = $db->table( 'steps' );
			$db->query( "INSERT INTO $steps (run_id,sequence,kind,input_hash,attempt,state,data_json) VALUES (%d,%d,'evidence_snapshot',%s,0,'succeeded',%s)", array( (int) $run['id'], (int) $run['next_step_seq'], $snapshot_hash, wp_json_encode( (object) array( 'contract' => 'directorist-ai/visitor-delivery-snapshot-v1', 'codec' => 'gzip+base64', 'payload' => base64_encode( $compressed ), 'sha256' => $snapshot_hash, 'state_version' => (int) $run['state_version'] ) ) ) );
			$archive = $checkpoint->visitor_delivery_recovery_archive ?? array();
			$archive[] = (object) array( 'step_id' => $db->insert_id(), 'sha256' => $snapshot_hash, 'state_version' => (int) $run['state_version'] );
			$checkpoint->visitor_delivery_recovery_archive = array_slice( $archive, -VisitorEvidenceRecovery::MAX_REFRESHES );
			$checkpoint->visitor_recovery_plan = $checkpoint->grounded_input ?? null;
			$original_content = json_decode( $stored[0]['content_json'] );
			$checkpoint->visitor_recovery_helper_topic = 'directorist-ai/visitor-help-v1' === ( $original_content->provenance->contract ?? null ) ? $original_content->provenance->topic : null;
			$checkpoint->visitor_delivery_replacement = (object) array( 'id' => (int) $stored[0]['id'], 'sequence' => (int) $stored[0]['sequence'], 'turn_id' => (int) $stored[0]['turn_id'], 'content_hash' => hash( 'sha256', $stored[0]['content_json'] ), 'refs_hash' => hash( 'sha256', $stored[0]['source_refs_json'] ) );
			$checkpoint->visitor_evidence_refreshes = 1 + (int) ( $checkpoint->visitor_evidence_refreshes ?? 0 );
			$checkpoint->visitor_recovering_delivery = true;
			$checkpoint->visitor_recovery_parent_task_id = $parent_task;
			$checkpoint->visitor_recovery_task_id = $continuation['task_id'];
			$checkpoint->visitor_recovery_task_cycle = $cycle;
			$checkpoint->logical_task_id = $continuation['task_id'];
			$checkpoint->step = 'model';
			$checkpoint->visitor_progress_stage = 'checking_details'; $checkpoint->visitor_progress_at = time();
			unset( $checkpoint->visitor_delivery, $checkpoint->visitor_reply, $checkpoint->visitor_site, $checkpoint->visitor_site_turn, $checkpoint->grounded_input, $checkpoint->grounded_hash, $checkpoint->grounded_plan_hash, $checkpoint->error, $checkpoint->visitor_reply_feedback, $checkpoint->visitor_plan_feedback );
			( new \DirectoristAIPro\Validation\JsonDecoder( 1048576, 32 ) )->check( $checkpoint );
			$db->query( "UPDATE $runs SET state='queued',state_version=state_version+1,next_step_seq=next_step_seq+1,checkpoint_json=%s,available_at=UTC_TIMESTAMP(),lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state='succeeded' AND state_version=%d AND cancel_requested=0", array( wp_json_encode( $checkpoint, JSON_PRESERVE_ZERO_FRACTION ), (int) $run['id'], (int) $run['state_version'] ) );
			if ( 1 !== $db->affected() ) { throw new \DirectoristAIPro\Storage\StorageConflictException( 'Visitor recovery compare-and-swap changed.' ); }
			$db->query( "UPDATE $conversations SET active_run_id=%d,state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND active_run_id IS NULL AND erasure_requested=0", array( (int) $run['id'], (int) $run['conversation_id'] ) );
			if ( 1 !== $db->affected() ) { throw new \DirectoristAIPro\Storage\StorageConflictException( 'Visitor delivery changed before recovery.' ); }
			$event_store->event_in_transaction( (int) $run['id'], 'visitor.evidence_refresh', (object) array( 'sequence' => (int) $message['sequence'], 'attempt' => $checkpoint->visitor_evidence_refreshes ) );
			return true;
		} );
	}
}
