<?php
/**
 * Database-authoritative dispatch, leases, fencing and cancellation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Chat\Operations;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Lifecycle;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Queue actions wake this coordinator; they do not prove work ownership. */
final class Dispatcher {
	/**
	 * Runtime database.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Replaceable wake-up transport.
	 *
	 * @var WakeQueue
	 */
	private WakeQueue $queue;
	/**
	 * Durable event/state reader.
	 *
	 * @var RunStore
	 */
	private RunStore $runs;
	/**
	 * Optional server-produced message attestation.
	 *
	 * @var MessageProvenanceProducer|null
	 */
	private ?MessageProvenanceProducer $provenance;
	/**
	 * Trusted no-tool prose attestor.
	 *
	 * @var SourceFreeMessageProvenance|null
	 */
	private ?SourceFreeMessageProvenance $source_free_provenance;

	/**
	 * Bind authoritative state and replaceable dispatch.
	 *
	 * @param Database                         $db Runtime database.
	 * @param WakeQueue                        $queue Wake-up transport.
	 * @param MessageProvenanceProducer|null   $provenance Trusted message producer.
	 * @param SourceFreeMessageProvenance|null $source_free_provenance Trusted no-tool prose producer.
	 */
	public function __construct( Database $db, WakeQueue $queue, ?MessageProvenanceProducer $provenance = null, ?SourceFreeMessageProvenance $source_free_provenance = null ) {
		$this->db                     = $db;
		$this->queue                  = $queue;
		$this->runs                   = new RunStore( $db );
		$this->provenance             = $provenance;
		$this->source_free_provenance = $source_free_provenance;
	}

	/** Register queue callbacks without starting work during bootstrap. */
	public function hooks(): void {
		add_action( 'directorist_ai_pro_run', array( $this, 'handle' ), 10, 1 );
		add_action( 'directorist_ai_pro_recover', array( $this, 'recover' ) );
		add_action( 'directorist_ai_pro_lifecycle', array( $this, 'lifecycle' ) );
		if ( did_action( 'action_scheduler_init' ) ) {
			$this->initialize_queue( false );
		} else {
			add_action( 'action_scheduler_init', array( $this, 'initialize_queue' ) );
		}
	}

	/**
	 * Ensure maintenance; late HTTP boot leaves recovery to its recurring sweep.
	 *
	 * @param bool $recover Whether to perform immediate startup recovery.
	 */
	public function initialize_queue( bool $recover = true ): void {
		try {
			$this->queue->ensure_recurring();
			if ( $recover ) {
				$this->recover();
			}
		} catch ( DispatchException $error ) {
			// Health remains unavailable; committed runs stay visible/recoverable.
		}
	}

	/**
	 * Wake one committed run; failure never rolls back its database record.
	 *
	 * @param string   $run_id Opaque run ID.
	 * @param int|null $timestamp Optional earliest Unix timestamp.
	 * @return bool Whether a wake exists.
	 */
	public function schedule( string $run_id, ?int $timestamp = null ): bool {
		if ( ! $this->db->readiness()->status()['effects_enabled'] ) {
			return false;
		}
		ConversationStore::identifier( $run_id );
		return null === $timestamp || $timestamp <= time() ? $this->queue->enqueue( $run_id ) : $this->queue->schedule( $run_id, $timestamp );
	}

	/**
	 * Re-enqueue due active runs missed after commit or lease expiry.
	 *
	 * @param int $limit Bounded scan size.
	 * @return array Scheduled and failed IDs.
	 * @throws DispatchException Invalid scan bound.
	 */
	public function recover( int $limit = 100 ): array {
		if ( $limit < 1 || $limit > 500 ) {
			throw new DispatchException( 'Invalid recovery sweep bound.' );
		}
		// An activated but not-yet-installed site can initialize the scheduler
		// before an administrator reaches the resumable storage installer.
		if ( ! $this->db->readiness()->status()['effects_enabled'] ) {
			return array(
				'scheduled' => array(),
				'failed'    => array(),
			);
		}
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows(
			"SELECT r.public_id FROM $runs r LEFT JOIN $conversations c ON c.id=r.conversation_id WHERE r.available_at<=UTC_TIMESTAMP() AND ((r.state='queued' AND r.cancel_requested=0) OR (r.state='running' AND r.lease_until<UTC_TIMESTAMP())) AND (r.conversation_id IS NULL OR (c.erasure_requested=0 AND c.active_run_id=r.id)) ORDER BY r.available_at,r.id LIMIT %d",
			array( $limit )
		);
		$result        = array(
			'scheduled' => array(),
			'failed'    => array(),
		);
		foreach ( $rows as $row ) {
			try {
				if ( $this->schedule( $row['public_id'] ) ) {
					$result['scheduled'][] = $row['public_id'];
				} else {
					$result['failed'][] = $row['public_id'];
				}
			} catch ( DispatchException $error ) {
				$result['failed'][] = $row['public_id'];
			}
		}
		return $result;
	}

	/**
	 * Claim a due active run or take over an expired worker lease.
	 *
	 * @param string $run_id Opaque run ID.
	 * @param string $worker_id Unique worker attempt ID.
	 * @param int    $lease_seconds Lease duration.
	 * @return Lease|null Claim, or null when no work is claimable.
	 * @throws DispatchException Invalid identity or duration.
	 */
	public function claim( string $run_id, string $worker_id, int $lease_seconds = 60 ): ?Lease {
		if ( ! $this->db->readiness()->status()['effects_enabled'] ) {
			return null;
		}
		ConversationStore::identifier( $run_id );
		ConversationStore::identifier( $worker_id );
		if ( $lease_seconds < 10 || $lease_seconds > 300 ) {
			throw new DispatchException( 'Invalid worker lease duration.' );
		}
		$result = $this->db->transaction(
			function ( Database $db ) use ( $run_id, $worker_id, $lease_seconds ): array {
				$runs          = $db->table( 'runs' );
				$conversations = $db->table( 'conversations' );
				$rows          = $db->rows( "SELECT r.*,c.active_run_id,c.erasure_requested FROM $runs r LEFT JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s FOR UPDATE", array( $run_id ) );
				if ( ! $rows ) {
					return array(
						'lease' => null,
						'next'  => null,
					);
				}
				$run = $rows[0];
				if ( null !== $run['conversation_id'] && ( (int) $run['active_run_id'] !== (int) $run['id'] || (int) $run['erasure_requested'] ) ) {
					return array(
						'lease' => null,
						'next'  => null,
					);
				}
				$expired_running = 'running' === $run['state'] && null !== $run['lease_until'] && strtotime( $run['lease_until'] . ' UTC' ) < time();
				if ( ( 'queued' !== $run['state'] && ! $expired_running ) || strtotime( $run['available_at'] . ' UTC' ) > time() ) {
					return array(
						'lease' => null,
						'next'  => null,
					);
				}
				$checkpoint    = json_decode( $run['checkpoint_json'] );
				$guest_expired = 0 === strpos( $run['owner_ref'], 'guest:' ) && isset( $checkpoint->guest_expiry ) && (int) $checkpoint->guest_expiry <= time();
				$recovery_superseded = true === ( $checkpoint->visitor_recovering_delivery ?? false ) && (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'turns' ) . ' WHERE conversation_id=%d AND id>%d', array( (int) $run['conversation_id'], (int) ( $checkpoint->latest_turn_id ?? $run['trigger_turn_id'] ) ) );
				if ( (int) $run['cancel_requested'] || $guest_expired || $recovery_superseded ) {
					$db->query( "UPDATE $runs SET state='cancelled',state_version=state_version+1,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( (int) $run['id'] ) );
					$this->runs->event_in_transaction(
						(int) $run['id'],
						'run.state',
						(object) array(
							'state'  => 'cancelled',
							'reason' => $recovery_superseded ? 'visitor_turn_superseded' : ( $guest_expired ? 'guest_session_expired' : 'cancel_requested' ),
						)
					);
					return array(
						'lease' => null,
						'next'  => $this->activate_next_in_transaction( $run ),
						'recovery_cancelled' => $recovery_superseded,
					);
				}
				$fence = (int) $run['fence'] + 1;
				$db->query( "UPDATE $runs SET state='running',state_version=state_version+1,lease_owner=%s,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),fence=%d,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( $worker_id, $lease_seconds, $fence, (int) $run['id'] ) );
				$this->runs->event_in_transaction(
					(int) $run['id'],
					'run.state',
					(object) array(
						'state' => 'running',
						'fence' => $fence,
					)
				);
				return array(
					'lease' => new Lease( $run_id, (int) $run['id'], $worker_id, $fence, (int) $run['state_version'] + 1 ),
					'next'  => null,
				);
			}
		);
		if ( $result['next'] ) {
			$this->safe_schedule( $result['next'] );
		}
		if ( ! empty( $result['recovery_cancelled'] ) ) { \DirectoristAIPro\AI\TaskRuntime::observe( $this->db, $run_id ); }
		return $result['lease'];
	}

	/**
	 * Renew only the current unexpired fence.
	 *
	 * @param Lease $lease Current fenced claim.
	 * @param int   $lease_seconds Renewal duration.
	 * @return bool Whether the current fence was renewed.
	 * @throws DispatchException Invalid renewal duration.
	 */
	public function renew( Lease $lease, int $lease_seconds = 60 ): bool {
		if ( $lease_seconds < 10 || $lease_seconds > 300 ) {
			throw new DispatchException( 'Invalid worker lease duration.' );
		}
		$runs = $this->db->table( 'runs' );
		$this->db->query( "UPDATE $runs SET lease_until=DATE_ADD(GREATEST(lease_until,UTC_TIMESTAMP()),INTERVAL %d SECOND),updated_at=UTC_TIMESTAMP() WHERE id=%d AND public_id=%s AND state='running' AND lease_owner=%s AND fence=%d AND lease_until>=UTC_TIMESTAMP()", array( $lease_seconds, $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence ) );
		return 1 === $this->db->affected();
	}

	/**
	 * Check cancellation without treating it as rollback of completed work.
	 *
	 * @param Lease $lease Current fenced claim.
	 * @return bool Whether cancellation was requested.
	 */
	public function cancellation_requested( Lease $lease ): bool {
		$runs = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows = $this->db->rows( "SELECT r.cancel_requested,c.erasure_requested,r.checkpoint_json,r.conversation_id,r.trigger_turn_id FROM $runs r LEFT JOIN $conversations c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.id=%d AND r.public_id=%s AND r.lease_owner=%s AND r.fence=%d", array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence ) );
		if ( ! $rows ) { return false; }
		if ( (int) $rows[0]['cancel_requested'] || (int) $rows[0]['erasure_requested'] ) { return true; }
		$checkpoint = json_decode( $rows[0]['checkpoint_json'] );
		return true === ( $checkpoint->visitor_recovering_delivery ?? false ) && (int) $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'turns' ) . ' WHERE conversation_id=%d AND id>%d', array( (int) $rows[0]['conversation_id'], (int) ( $checkpoint->latest_turn_id ?? $rows[0]['trigger_turn_id'] ) ) ) > 0;
	}

	/**
	 * Record a bounded public phase without releasing the current worker fence.
	 *
	 * @param Lease    $lease Current fenced worker claim.
	 * @param string   $stage Closed generation progress phase.
	 * @param int|null $initial_attempt Optional bounded initial preparation attempt.
	 * @throws DispatchException Invalid progress or lost worker fence.
	 */
	public function progress( Lease $lease, string $stage, ?int $initial_attempt = null ): void {
		if ( ! in_array( $stage, array( 'reading_layout', 'preparing_input', 'waiting_for_ai', 'validating_changes' ), true ) || ( null !== $initial_attempt && ( $initial_attempt < 1 || $initial_attempt > 2 ) ) ) {
			throw new DispatchException( 'Invalid generation progress.' );
		}
		$runs       = $this->db->table( 'runs' );
		$parameters = array( $stage, $lease->fence );
		$fields     = "'$.generation_stage',%s,'$.generation_progress_fence',%d";
		if ( null !== $initial_attempt ) {
			$fields      .= ",'$.initial_preparation_attempts',%d";
			$parameters[] = $initial_attempt;
		}
		$where    = "id=%d AND public_id=%s AND state='running' AND lease_owner=%s AND fence=%d AND lease_until>=UTC_TIMESTAMP()";
		$identity = array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence );
		$this->db->query( "UPDATE $runs SET checkpoint_json=JSON_SET(checkpoint_json,$fields),updated_at=UTC_TIMESTAMP() WHERE $where", array_merge( $parameters, $identity ) );
		if ( 1 !== $this->db->affected() && '1' !== (string) $this->db->scalar( "SELECT COUNT(*) FROM $runs WHERE $where", $identity ) ) {
			throw new DispatchException( 'Generation progress lost its worker fence.' );
		}
	}

	/** Update one real visitor phase while retaining the same live worker fence. */
	public function visitor_progress( Lease $lease, string $stage, int $started ): void {
		if ( ! in_array( $stage, \DirectoristAIPro\Chat\VisitorProgress::STAGES, true ) || $started < 1 ) { throw new DispatchException( 'Invalid visitor progress.' ); }
		$runs = $this->db->table( 'runs' );
		$where = "id=%d AND public_id=%s AND state='running' AND lease_owner=%s AND fence=%d AND lease_until>=UTC_TIMESTAMP()";
		$identity = array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence );
		$this->db->query( "UPDATE $runs SET checkpoint_json=JSON_SET(checkpoint_json,'$.visitor_progress_stage',%s,'$.visitor_progress_at',%d),updated_at=UTC_TIMESTAMP() WHERE $where", array_merge( array( $stage, $started ), $identity ) );
		if ( 1 !== $this->db->affected() && '1' !== (string) $this->db->scalar( "SELECT COUNT(*) FROM $runs WHERE $where", $identity ) ) { throw new DispatchException( 'Visitor progress lost its worker fence.' ); }
	}

	/**
	 * Commit a fenced checkpoint and release the worker on waits/terminal state.
	 *
	 * @param Lease       $lease Current fenced claim.
	 * @param string      $state Next allowed state.
	 * @param object      $checkpoint Bounded durable checkpoint.
	 * @param int|null    $available_at Optional requeue timestamp.
	 * @param object|null $visible_message Optional assistant message committed with state.
	 * @return array Updated state/version.
	 * @throws DispatchException Invalid transition or stale lease.
	 */
	public function checkpoint( Lease $lease, string $state, object $checkpoint, ?int $available_at = null, ?object $visible_message = null ): array {
		$allowed = array( 'queued', 'waiting_input', 'waiting_approval', 'waiting_browser', 'succeeded', 'failed', 'partial', 'unknown', 'cancelled' );
		if ( ! in_array( $state, $allowed, true ) || ( 'queued' === $state && ( null === $available_at || $available_at < time() || $available_at > time() + 31536000 ) ) ) {
			throw new DispatchException( 'Invalid checkpoint transition.' );
		}
		( new JsonDecoder( 1048576, 32 ) )->check( $checkpoint );
		if ( null !== $visible_message ) {
			$values = get_object_vars( $visible_message );
			$keys   = array_keys( $values );
			if ( ! in_array( $keys, array( array( 'text', 'source_refs' ), array( 'text', 'source_refs', 'task_summary' ), array( 'text', 'source_refs', 'task_summary', 'grounded' ), array( 'text', 'source_refs', 'task_summary', 'clarification' ), array( 'text', 'source_refs', 'task_summary', 'helper' ) ), true ) || ! is_string( $values['text'] ) || '' === trim( $values['text'] ) || strlen( $values['text'] ) > 65536 || ! is_array( $values['source_refs'] ) || count( $values['source_refs'] ) > 20 || ( isset( $values['task_summary'] ) && ! is_object( $values['task_summary'] ) ) || ( isset( $visible_message->clarification ) && ( true !== $visible_message->clarification || 'waiting_input' !== $state || $visible_message->source_refs || strlen( $visible_message->text ) > 2000 ) ) ) {
				throw new DispatchException( 'Invalid visible checkpoint message.' );
			}
			( new JsonDecoder( isset( $visible_message->grounded ) ? 262144 : 65536, 24 ) )->check( $visible_message );
			if ( isset( $checkpoint->source_free ) && ( 'succeeded' !== $state || isset( $visible_message->grounded ) || $visible_message->source_refs || null === $this->source_free_provenance ) ) {
				throw new DispatchException( 'Invalid source-free checkpoint message.' );
			}
			if ( isset( $visible_message->grounded ) && null !== $this->provenance ) {
				$this->provenance->validate(
					(object) array(
						'text'     => $visible_message->text,
						'grounded' => $visible_message->grounded,
					),
					$checkpoint
				);
			}
		}
		$result = $this->db->transaction(
			function ( Database $db ) use ( $lease, $state, $checkpoint, $available_at, $visible_message ): array {
				$runs = $db->table( 'runs' );
				$rows = $db->rows( "SELECT * FROM $runs WHERE id=%d AND public_id=%s FOR UPDATE", array( $lease->internal_id, $lease->run_id ) );
				if ( ! $rows || 'running' !== $rows[0]['state'] || $rows[0]['lease_owner'] !== $lease->worker_id || (int) $rows[0]['fence'] !== $lease->fence || strtotime( $rows[0]['lease_until'] . ' UTC' ) < time() ) {
					throw new DispatchException( 'Worker lease is stale.' );
				}
				$run        = $rows[0];
				$replacement = $checkpoint->visitor_delivery_replacement ?? null;
				$recovery_conversation = is_object( $replacement ) ? $db->rows( 'SELECT owner_ref,erasure_requested,active_run_id FROM ' . $db->table( 'conversations' ) . ' WHERE id=%d FOR UPDATE', array( (int) $run['conversation_id'] ) ) : array();
				$recovery_lost = is_object( $replacement ) && ( 1 !== count( $recovery_conversation ) || $recovery_conversation[0]['owner_ref'] !== $run['owner_ref'] || (int) $recovery_conversation[0]['erasure_requested'] || (int) $recovery_conversation[0]['active_run_id'] !== (int) $run['id'] || (int) $run['cancel_requested'] || (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'turns' ) . ' WHERE conversation_id=%d AND id>%d', array( (int) $run['conversation_id'], (int) ( $checkpoint->latest_turn_id ?? $run['trigger_turn_id'] ) ) ) );
				if ( $recovery_lost ) { $state = 'cancelled'; $visible_message = null; $checkpoint->visitor_recovering_delivery = true; }
				$next_state = (int) $run['cancel_requested'] && ! in_array( $state, array( 'succeeded', 'partial', 'unknown', 'failed' ), true ) ? 'cancelled' : $state;
				$terminal   = in_array( $next_state, array( 'succeeded', 'failed', 'partial', 'unknown', 'cancelled' ), true );
				$waiting    = 0 === strpos( $next_state, 'waiting_' );
				$available  = 'queued' === $next_state ? gmdate( 'Y-m-d H:i:s', $available_at ) : $run['available_at'];
				$db->query( "UPDATE $runs SET state=%s,state_version=state_version+1,checkpoint_json=%s,available_at=%s,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( $next_state, json_encode( $checkpoint, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), $available, (int) $run['id'] ) );
				if ( null !== $visible_message && 'succeeded' === $next_state && isset( $checkpoint->visitor_recovery_waiting_turn ) ) { unset( $checkpoint->visitor_recovery_waiting_turn ); $db->query( "UPDATE $runs SET checkpoint_json=%s WHERE id=%d", array( wp_json_encode( $checkpoint, JSON_PRESERVE_ZERO_FRACTION ), (int) $run['id'] ) ); }
				if ( null !== $visible_message && $next_state === $state && null !== $run['conversation_id'] && null !== $run['trigger_turn_id'] ) {
					$conversations = $db->table( 'conversations' );
					$conversation  = $db->rows( "SELECT public_id,profile,next_message_seq,retention_seconds FROM $conversations WHERE id=%d FOR UPDATE", array( (int) $run['conversation_id'] ) );
					if ( ! $conversation ) {
						throw new DispatchException( 'Conversation disappeared before message commit.' );
					}
					$messages = $db->table( 'messages' );
					$sequence = (int) $conversation[0]['next_message_seq'];
					$replace_row = null;
					if ( is_object( $replacement ) ) {
						$original = $db->rows( "SELECT * FROM $messages WHERE id=%d AND conversation_id=%d AND turn_id=%d AND role='assistant' FOR UPDATE", array( (int) $replacement->id, (int) $run['conversation_id'], (int) $run['trigger_turn_id'] ) );
						if ( 'visitor' !== $conversation[0]['profile'] || ! in_array( $state, array( 'succeeded', 'waiting_input', 'failed', 'partial', 'unknown' ), true ) || 1 !== count( $original ) || (int) $original[0]['sequence'] !== (int) $replacement->sequence || ! hash_equals( $replacement->content_hash, hash( 'sha256', $original[0]['content_json'] ) ) || ! hash_equals( $replacement->refs_hash, hash( 'sha256', $original[0]['source_refs_json'] ) ) ) { throw new DispatchException( 'Visitor recovery message binding changed.' ); }
						$replace_row = $original[0]; $sequence = (int) $replace_row['sequence'];
					}
					$content  = (object) array( 'text' => $visible_message->text );
					if ( isset( $visible_message->grounded ) ) {
						$content->grounded = $visible_message->grounded;
						if ( null !== $this->provenance ) {
							$run['profile']                = $conversation[0]['profile'];
							$run['conversation_public_id'] = $conversation[0]['public_id'];
							$content->provenance           = $this->provenance->seal( $run, $sequence, $content, $checkpoint );
						}
					}
					if ( isset( $checkpoint->source_free ) ) {
						$run['profile']                = $conversation[0]['profile'];
						$run['conversation_public_id'] = $conversation[0]['public_id'];
						$content->provenance           = $this->source_free_provenance->seal( $run, $sequence, $content, $checkpoint );
					}
					if ( isset( $visible_message->clarification ) ) {
						$run['profile']                = $conversation[0]['profile'];
						$run['conversation_public_id'] = $conversation[0]['public_id'];
						$content->provenance           = ( new ClarificationMessageProvenance() )->seal( $run, $sequence, $content->text );
					}
					if ( isset( $visible_message->helper ) ) {
						if ( 'visitor' !== $conversation[0]['profile'] || 'succeeded' !== $state || $visible_message->source_refs || ! is_string( $visible_message->helper ) ) {
							throw new DispatchException( 'Invalid native helper message.' );
						}
						$content->provenance = \DirectoristAIPro\Chat\VisitorHelp::seal( $run, $sequence, $content->text, $visible_message->helper );
					}
					if ( null !== $replace_row ) {
						$db->query( "UPDATE $messages SET content_json=%s,source_refs_json=%s WHERE id=%d AND conversation_id=%d AND turn_id=%d AND sequence=%d", array( json_encode( $content, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), json_encode( $visible_message->source_refs, JSON_THROW_ON_ERROR ), (int) $replace_row['id'], (int) $run['conversation_id'], (int) $run['trigger_turn_id'], $sequence ) );
						$message_id = (int) $replace_row['id'];
					} else {
					$db->query( "INSERT INTO $messages (conversation_id,turn_id,sequence,role,content_json,source_refs_json,created_at) VALUES (%d,%d,%d,'assistant',%s,%s,UTC_TIMESTAMP())", array( (int) $run['conversation_id'], (int) $run['trigger_turn_id'], $sequence, json_encode( $content, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), json_encode( $visible_message->source_refs, JSON_THROW_ON_ERROR ) ) );
					$message_id = $db->insert_id();
					}
					if ( isset( $visible_message->task_summary ) ) {
						$db->query( 'UPDATE ' . $conversations . ' SET next_message_seq=next_message_seq+%d,state_version=state_version+1,summary_json=%s,updated_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL retention_seconds SECOND) WHERE id=%d', array( null === $replace_row ? 1 : 0, json_encode( $visible_message->task_summary, JSON_THROW_ON_ERROR ), (int) $run['conversation_id'] ) );
					} else {
						$db->query( 'UPDATE ' . $conversations . ' SET next_message_seq=next_message_seq+%d,state_version=state_version+1,updated_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL retention_seconds SECOND) WHERE id=%d', array( null === $replace_row ? 1 : 0, (int) $run['conversation_id'] ) );
					}
					$event_payload = (object) array(
						'message_id' => $message_id,
						'sequence'   => $sequence,
					);
					if ( isset( $visible_message->grounded ) ) {
						$event_payload->result_kind = 'grounded';
						$event_payload->trace       = $visible_message->grounded->trace;
					}
					if ( null !== $replace_row && 'waiting_input' === $next_state ) {
						$stamp_run = $run; $stamp_run['conversation_public_id'] = $conversation[0]['public_id'];
						$questions = $checkpoint->visitor_recovery_questions ?? array();
						$questions[] = \DirectoristAIPro\Chat\VisitorEvidenceRecovery::question_stamp( $stamp_run, $message_id, $sequence, (int) $run['trigger_turn_id'], wp_json_encode( $content, JSON_PRESERVE_ZERO_FRACTION ) );
						$checkpoint->visitor_recovery_questions = array_slice( $questions, -3 );
					}
					if ( 'succeeded' === $next_state && isset( $checkpoint->visitor_recovery_questions ) ) {
						$stamp_run = $run; $stamp_run['conversation_public_id'] = $conversation[0]['public_id'];
						foreach ( $checkpoint->visitor_recovery_questions as $key => $stamp ) {
							if ( null !== $stamp->completed_turn || (int) ( $checkpoint->latest_turn_id ?? 0 ) <= $stamp->turn_id ) { continue; }
							$question_rows = $db->rows( "SELECT content_json FROM $messages WHERE id=%d AND conversation_id=%d AND turn_id=%d AND sequence=%d AND role='assistant'", array( $stamp->message_id, (int) $run['conversation_id'], $stamp->turn_id, $stamp->sequence ) );
							if ( 1 === count( $question_rows ) && hash_equals( $stamp->content_hash, hash( 'sha256', $question_rows[0]['content_json'] ) ) ) { $checkpoint->visitor_recovery_questions[ $key ] = \DirectoristAIPro\Chat\VisitorEvidenceRecovery::question_stamp( $stamp_run, $stamp->message_id, $stamp->sequence, $stamp->turn_id, $question_rows[0]['content_json'], (int) $checkpoint->latest_turn_id ); }
						}
						$db->query( "UPDATE $runs SET checkpoint_json=%s WHERE id=%d", array( wp_json_encode( $checkpoint, JSON_PRESERVE_ZERO_FRACTION ), (int) $run['id'] ) );
					}
					if ( null !== $replace_row ) {
						unset( $checkpoint->visitor_delivery_replacement );
						$checkpoint->visitor_recovering_delivery = false;
						if ( 'waiting_input' === $next_state ) { $checkpoint->visitor_recovery_waiting_turn = (int) ( $checkpoint->latest_turn_id ?? $run['trigger_turn_id'] ); }
						$db->query( "UPDATE $runs SET checkpoint_json=%s WHERE id=%d", array( wp_json_encode( $checkpoint, JSON_PRESERVE_ZERO_FRACTION ), (int) $run['id'] ) );
					}
					$this->runs->event_in_transaction(
						(int) $run['id'],
						'message.append',
						$event_payload
					);
				}
				$this->runs->event_in_transaction( (int) $run['id'], 'run.state', (object) array( 'state' => $next_state ) );
				return array(
					'state'         => $next_state,
					'state_version' => (int) $run['state_version'] + 1,
					'next'          => $terminal ? $this->activate_next_in_transaction( $run ) : null,
					'wake'          => 'queued' === $next_state ? $run['public_id'] : null,
					'terminal'      => $terminal,
					'waiting'       => $waiting,
				);
			}
		);
		if ( $result['terminal'] ) {
			$this->safe_unschedule( $lease->run_id );
		}
		if ( $result['wake'] ) {
			$this->safe_schedule( $result['wake'], $available_at );
		}
		if ( $result['next'] ) {
			$this->safe_schedule( $result['next'] );
		}
		unset( $result['next'], $result['wake'], $result['terminal'], $result['waiting'] );
		\DirectoristAIPro\AI\TaskRuntime::observe( $this->db, $lease->run_id );
		return $result;
	}

	/**
	 * Request cancellation under current ownership/version.
	 *
	 * @param Owner  $owner Actual owner.
	 * @param string $run_id Opaque run ID.
	 * @param int    $expected_version Current client-visible run version.
	 * @return array Observed resulting state/version.
	 */
	public function cancel( Owner $owner, string $run_id, int $expected_version ): array {
		ConversationStore::identifier( $run_id );
		$result = $this->db->transaction(
			function ( Database $db ) use ( $owner, $run_id, $expected_version ): array {
				$runs = $db->table( 'runs' );
				$rows = $db->rows( "SELECT * FROM $runs WHERE public_id=%s AND owner_ref=%s FOR UPDATE", array( $run_id, $owner->reference() ) );
				if ( ! $rows ) {
					throw new StorageException( 'Run is unavailable.' );
				}
				$run = $rows[0];
				$checkpoint = json_decode( $run['checkpoint_json'] );
				$turn_start = $checkpoint->visitor_turn_start_version ?? null;
				$terminal = in_array( $run['state'], array( 'succeeded', 'failed', 'partial', 'unknown', 'cancelled' ), true );
				$visitor_progress = $expected_version >= 1 && $expected_version < (int) $run['state_version'] &&
					'visitor' === $db->scalar( 'SELECT profile FROM ' . $db->table( 'conversations' ) . ' WHERE id=%d AND owner_ref=%s', array( (int) $run['conversation_id'], $owner->reference() ) ) &&
					( $terminal || ( is_int( $turn_start ) && $turn_start >= 1 && $turn_start <= (int) $run['state_version'] && $expected_version >= $turn_start ) );
				// Only progress within the same admitted visitor turn may drift. A
				// clarification reuses this run ID but starts a new version interval;
				// an older tab must not stop it. Legacy active runs without a marker
				// retain strict version checks. Terminal observations never mutate work.
				if ( (int) $run['state_version'] !== $expected_version && ! $visitor_progress ) {
					throw new ContractException( 'stale_version', 'Run state changed before cancellation.' );
				}
				if ( $terminal ) {
					return array(
						'state'         => $run['state'],
						'state_version' => (int) $run['state_version'],
						'next'          => null,
						'terminal'      => true,
					);
				}
				$running = 'running' === $run['state'];
				if ( $run['cancel_requested'] ) {
					return array( 'state' => $run['state'], 'state_version' => (int) $run['state_version'], 'next' => null, 'terminal' => false );
				}
				$state   = $running ? 'running' : 'cancelled';
				$db->query( "UPDATE $runs SET cancel_requested=1,state=%s,state_version=state_version+1,lease_owner=" . ( $running ? 'lease_owner' : 'NULL' ) . ',lease_until=' . ( $running ? 'lease_until' : 'NULL' ) . ',updated_at=UTC_TIMESTAMP() WHERE id=%d', array( $state, (int) $run['id'] ) );
				$this->runs->event_in_transaction(
					(int) $run['id'],
					'run.state',
					(object) array(
						'state'  => $state,
						'reason' => 'cancel_requested',
					)
				);
				return array(
					'state'         => $state,
					'state_version' => (int) $run['state_version'] + 1,
					'next'          => $running ? null : $this->activate_next_in_transaction( $run ),
					'terminal'      => ! $running,
				);
			}
		);
		if ( $result['terminal'] ) {
			$this->safe_unschedule( $run_id );
		}
		if ( $result['next'] ) {
			$this->safe_schedule( $result['next'] );
		}
		unset( $result['next'], $result['terminal'] );
		\DirectoristAIPro\AI\TaskRuntime::observe( $this->db, $run_id );
		return $result;
	}

	/**
	 * Decide an owned run's exact pending review and transition the run atomically.
	 * Standalone approval decisions remain in ApprovalService for non-run effects.
	 *
	 * @param Actor  $actor Authenticated human actor.
	 * @param string $run_id Exact public run identity.
	 * @param int    $expected_version Expected waiting run version.
	 * @param string $approval_id Exact public approval identity.
	 * @param string $review_hash Displayed immutable review hash.
	 * @param string $decision Approve or reject.
	 * @return array Review and resulting run state for the REST response.
	 * @throws ContractException Inaccessible, stale, mismatched or expired review.
	 */
	public function decide_review( Actor $actor, string $run_id, int $expected_version, string $approval_id, string $review_hash, string $decision ): array {
		if ( $actor->is_guest() || ! in_array( $decision, array( 'approve', 'reject' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $review_hash ) ) {
			throw new ContractException( 'unauthorized', 'Approval decision is unavailable.' );
		}
		ConversationStore::identifier( $run_id );
		ConversationStore::identifier( $approval_id );
		if ( 'approve' === $decision ) {
			$this->db->readiness()->assert_ready();
		}
		$owner  = $actor->owner();
		$result = $this->db->transaction(
			function ( Database $db ) use ( $owner, $run_id, $expected_version, $approval_id, $review_hash, $decision ): array {
				$runs          = $db->table( 'runs' );
				$conversations = $db->table( 'conversations' );
				$rows          = $db->rows( "SELECT r.*,c.active_run_id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.erasure_requested=0 FOR UPDATE", array( $run_id, $owner->reference(), $owner->reference() ) );
				if ( ! $rows || 'waiting_approval' !== $rows[0]['state'] || (int) $rows[0]['state_version'] !== $expected_version || (int) $rows[0]['active_run_id'] !== (int) $rows[0]['id'] ) {
					throw new ContractException( 'stale_version', 'Approval request is no longer current.' );
				}
				$run        = $rows[0];
				$checkpoint = json_decode( $run['checkpoint_json'], false, 64, JSON_THROW_ON_ERROR );
				if ( ! isset( $checkpoint->review->id, $checkpoint->review->review_hash ) || $checkpoint->review->id !== $approval_id || ! hash_equals( $checkpoint->review->review_hash, $review_hash ) ) {
					throw new ContractException( 'approval_mismatch', 'Run review reference changed.' );
				}
				$approvals = $db->table( 'approvals' );
				$artifacts = $db->table( 'artifacts' );
				$reviews   = $db->rows( "SELECT p.*,p.expires_at<=UTC_TIMESTAMP() AS is_expired FROM $approvals p INNER JOIN $artifacts a ON a.public_id=p.artifact_id AND a.version=p.artifact_version AND a.owner_ref=p.actor_ref WHERE p.public_id=%s AND p.actor_ref=%s AND a.conversation_id=%d FOR UPDATE", array( $approval_id, $owner->reference(), (int) $run['conversation_id'] ) );
				if ( ! $reviews || ! hash_equals( hash( 'sha256', $reviews[0]['review_json'] ), $review_hash ) ) {
					throw new ContractException( 'approval_mismatch', 'Displayed review no longer matches the approval record.' );
				}
				$review = $reviews[0];
				if ( 'expired' === $review['state'] || ( in_array( $review['state'], array( 'staged', 'pending', 'approved' ), true ) && (int) $review['is_expired'] ) ) {
					if ( 'pending' === $review['state'] || 'approved' === $review['state'] ) {
						$db->query( "UPDATE $approvals SET state='expired' WHERE id=%d AND state IN ('pending','approved')", array( (int) $review['id'] ) );
					}
					return array( 'expired' => true );
				}
				$state = 'approve' === $decision ? 'approved' : 'rejected';
				if ( 'pending' !== $review['state'] && $state !== $review['state'] ) {
					throw new ContractException( 'approval_mismatch', 'Approval is no longer pending.' );
				}
				if ( 'pending' === $review['state'] ) {
					$db->query( "UPDATE $approvals SET state=%s WHERE id=%d AND state='pending'", array( $state, (int) $review['id'] ) );
				}
				$review['state'] = $state;

				$public_review = array(
					'id'                    => $review['public_id'],
					'state'                 => $state,
					'expires_at'            => gmdate( DATE_RFC3339, strtotime( $review['expires_at'] . ' UTC' ) ),
					'review_hash'           => $review_hash,
					'review'                => json_decode( $review['review_json'] ),
					'reserved_operation_id' => $review['reserved_operation_public_id'],
				);
				if ( 'approve' === $decision ) {
					$checkpoint->step = 'apply';
					$db->query( "UPDATE $runs SET state='queued',state_version=state_version+1,checkpoint_json=%s,available_at=UTC_TIMESTAMP(),lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( json_encode( $checkpoint, JSON_THROW_ON_ERROR ), (int) $run['id'] ) );
					$this->runs->event_in_transaction(
						(int) $run['id'],
						'run.state',
						(object) array(
							'state'  => 'queued',
							'reason' => 'approval_granted',
						)
					);
					return array(
						'review' => $public_review,
						'run'    => array(
							'id'            => $run_id,
							'state'         => 'queued',
							'state_version' => $expected_version + 1,
						),
						'next'   => null,
					);
				}
				$db->query( "UPDATE $runs SET cancel_requested=1,state='cancelled',state_version=state_version+1,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( (int) $run['id'] ) );
				$this->runs->event_in_transaction(
					(int) $run['id'],
					'run.state',
					(object) array(
						'state'  => 'cancelled',
						'reason' => 'cancel_requested',
					)
				);
				return array(
					'review' => $public_review,
					'run'    => array(
						'state'         => 'cancelled',
						'state_version' => $expected_version + 1,
					),
					'next'   => $this->activate_next_in_transaction( $run ),
				);
			}
		);
		if ( isset( $result['expired'] ) ) {
			throw new ContractException( 'approval_expired', 'Approval review has expired.' );
		}
		if ( 'approve' === $decision ) {
			try {
				$result['run']['dispatch'] = $this->schedule( $run_id ) ? 'scheduled' : 'pending_recovery';
			} catch ( DispatchException $error ) {
				$result['run']['dispatch'] = 'pending_recovery';
			}
		} else {
			$this->safe_unschedule( $run_id );
			if ( $result['next'] ) {
				$this->safe_schedule( $result['next'] );
			}
		}
		unset( $result['next'] );
		return $result;
	}

	/**
	 * Action Scheduler callback: claim and delegate one fenced run.
	 *
	 * @param string $run_id Opaque run ID.
	 * @return void
	 */
	public function handle( string $run_id ): void {
		if ( ! has_action( 'directorist_ai_pro_process_run' ) ) {
			return;
		}
		$lease = $this->claim( $run_id, bin2hex( random_bytes( 16 ) ) );
		if ( ! $lease ) {
			return;
		}
		try {
			do_action( 'directorist_ai_pro_process_run', $lease, $this );
			$runs = $this->db->table( 'runs' );
			if ( 'running' === $this->db->scalar( "SELECT state FROM $runs WHERE id=%d AND lease_owner=%s AND fence=%d", array( $lease->internal_id, $lease->worker_id, $lease->fence ) ) ) {
				$this->checkpoint_processor_stop( $lease, 'failed', 'processor_returned_without_checkpoint' );
			}
		} catch ( \Throwable $error ) {
			try {
				$this->checkpoint_processor_stop( $lease, 'unknown', 'processor_exception_requires_reconciliation' );
			} catch ( \Throwable $checkpoint_error ) {
				// Lease expiry/replacement owns recovery; never replay from this worker.
			}
		}
	}

	/**
	 * Retain the last durable request identity when a processor unexpectedly stops.
	 *
	 * This records no exception text, retries no work and grants no new authority.
	 * The ordinary checkpoint transaction still verifies the current live fence.
	 *
	 * @param Lease  $lease Current worker claim.
	 * @param string $state Closed terminal fallback state.
	 * @param string $code Closed internal failure code.
	 * @return void
	 */
	private function checkpoint_processor_stop( Lease $lease, string $state, string $code ): void {
		$rows = $this->db->rows( 'SELECT checkpoint_json FROM ' . $this->db->table( 'runs' ) . " WHERE id=%d AND public_id=%s AND state='running' AND lease_owner=%s AND fence=%d AND lease_until>=UTC_TIMESTAMP()", array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence ) );
		if ( ! $rows ) {
			throw new DispatchException( 'Worker lease is stale.' );
		}
		$checkpoint = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['checkpoint_json'] );
		if ( ! $checkpoint instanceof \stdClass ) {
			throw new DispatchException( 'Stored worker checkpoint is unavailable.' );
		}
		$checkpoint->error = $code;
		$this->checkpoint( $lease, $state, $checkpoint );
	}

	/** Run bounded storage lifecycle maintenance from the shared queue. */
	public function lifecycle(): void {
		if ( ! $this->db->readiness()->status()['effects_enabled'] ) {
			return;
		}
		$lifecycle = new Lifecycle( $this->db );
		$lifecycle->cleanup( 100 );
		$lifecycle->prune_records( Operations::settings()['audit_retention_days'], 500 );
		( new \DirectoristAIPro\AI\TaskAccounting( $this->db ) )->purge_expired( 100 );
		( new \DirectoristAIPro\Chat\VisitorWorkflowStore( $this->db ) )->purge_expired( 100 );
	}

	/** Return queue diagnostics without exposing credentials or job payloads. */
	public function health(): array {
		return $this->queue->health();
	}

	/**
	 * Release a terminal active run and return the next queued public ID.
	 *
	 * @param array $run Locked run row.
	 * @return string|null Next active public ID.
	 */
	private function activate_next_in_transaction( array $run ): ?string {
		if ( null === $run['conversation_id'] ) {
			return null;
		}
		$conversations = $this->db->table( 'conversations' );
		$current       = $this->db->rows( "SELECT active_run_id FROM $conversations WHERE id=%d FOR UPDATE", array( (int) $run['conversation_id'] ) );
		if ( ! $current || (int) $current[0]['active_run_id'] !== (int) $run['id'] ) {
			return null;
		}
		$runs = $this->db->table( 'runs' );
		$next = $this->db->rows( "SELECT id,public_id FROM $runs WHERE conversation_id=%d AND state='queued' AND cancel_requested=0 AND id<>%d ORDER BY id LIMIT 1 FOR UPDATE", array( (int) $run['conversation_id'], (int) $run['id'] ) );
		$this->db->query( "UPDATE $conversations SET active_run_id=" . ( $next ? '%d' : 'NULL' ) . ',state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=%d', $next ? array( (int) $next[0]['id'], (int) $run['conversation_id'] ) : array( (int) $run['conversation_id'] ) );
		return $next ? $next[0]['public_id'] : null;
	}

	/**
	 * Scheduling failure is recovered by the recurring database sweep.
	 *
	 * @param string   $run_id Opaque run ID.
	 * @param int|null $timestamp Optional earliest Unix timestamp.
	 * @return void
	 */
	private function safe_schedule( string $run_id, ?int $timestamp = null ): void {
		try {
			$this->schedule( $run_id, $timestamp );
		} catch ( DispatchException $error ) {
			// Committed state remains the source for a later recovery sweep.
		}
	}

	/**
	 * Queue cleanup failure cannot invalidate an already committed terminal state.
	 *
	 * @param string $run_id Opaque run ID.
	 * @return void
	 */
	private function safe_unschedule( string $run_id ): void {
		try {
			$this->queue->unschedule( $run_id );
		} catch ( DispatchException $error ) {
			// Queue cleanup is best effort; database terminal state blocks claiming.
		}
	}
}
