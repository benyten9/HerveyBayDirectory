<?php
/**
 * Atomic turn/run allocation, continuations and durable ordered events.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\SchemaRegistry;

/** A run is durable state, never an HTTP stream or provider session. */
final class RunStore {
	/**
	 * Dedicated database.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Authoritative turn-input validator.
	 *
	 * @var ContractValidator
	 */
	private ContractValidator $validator;
	/**
	 * Signed replay cursor codec.
	 *
	 * @var EventCursor
	 */
	private EventCursor $cursors;

	/**
	 * Bind a site connection and the closed turn contract.
	 *
	 * @param Database         $db Runtime connection.
	 * @param EventCursor|null $cursors Optional test/site cursor codec.
	 */
	public function __construct( Database $db, ?EventCursor $cursors = null ) {
		$this->db      = $db;
		$this->cursors = $cursors ?? new EventCursor( wp_salt( 'auth' ) );
		$schemas       = new SchemaRegistry();
		$schemas->register( 'turn.input', '{"type":"object","properties":{"text":{"type":"string","minLength":1,"maxLength":16384},"artifact_refs":{"type":"array","maxItems":10,"items":{"type":"object","properties":{"id":{"type":"string","minLength":1,"maxLength":128},"version":{"type":"integer","minimum":1},"hash":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["id","version","hash"],"additionalProperties":false}},"reply_to_run_id":{"type":["string","null"],"minLength":1,"maxLength":128},"expected_run_version":{"type":["integer","null"],"minimum":1},"chat_language":{"type":"string","maxLength":35},"chat_language_confirmed":{"type":"boolean"},"chat_language_origin":{"type":"string","enum":["site_starter"]},"builder_action":{"type":"string","enum":["draft","publish","restore","template_assign","template_restore","live"]},"directory_id":{"type":["string","null"],"pattern":"^[1-9][0-9]*$"},"visitor_location":{"type":"object","properties":{"latitude":{"type":"number","minimum":-90,"maximum":90},"longitude":{"type":"number","minimum":-180,"maximum":180},"accuracy_m":{"type":"number","minimum":0,"maximum":100000}},"required":["latitude","longitude"],"additionalProperties":false},"page_listing_id":{"type":"integer","minimum":1},"selected_listing_ids":{"type":"array","maxItems":4,"uniqueItems":true,"items":{"type":"integer","minimum":1}}},"required":["text","artifact_refs","reply_to_run_id","expected_run_version"],"additionalProperties":false}' );
		$this->validator = new ContractValidator( $schemas );
	}

	/**
	 * Accept a turn, its message, run linkage and initial event in one commit.
	 *
	 * @param Context $context Server actor/profile.
	 * @param string  $conversation_id Owned conversation.
	 * @param string  $request_id Idempotency identity.
	 * @param string  $json Original turn JSON.
	 * @return array Turn and linked run.
	 * @throws ContractException Invalid input/scope or stale continuation.
	 */
	public function accept( Context $context, string $conversation_id, string $request_id, string $json ): array {
		$this->db->readiness()->assert_ready();
		$context->actor()->refresh();
		$context->assert_current();
		$input = $this->validator->decode( $json, 'turn.input' );
		if ( ( null === $input->reply_to_run_id ) !== ( null === $input->expected_run_version ) ) {
			throw new ContractException( 'invalid_input', 'Continuation identity and version must be supplied together.' );
		}
		$owner        = $context->actor()->owner();
		$conversation = ( new ConversationStore( $this->db ) )->get( $owner, $conversation_id );
		if ( $context->profile() !== $conversation['profile'] ) {
			throw new ContractException( 'scope_conflict', 'Conversation profile cannot change on retry.' );
		}
		$scope = json_decode( $conversation['context_json'] );
		if ( isset( $input->chat_language ) || isset( $input->chat_language_confirmed ) || isset( $input->chat_language_origin ) ) {
			if ( 'visitor' !== $context->profile() ) { throw new ContractException( 'invalid_input', 'Only visitor chat can choose a conversation language.' ); }
			if ( isset( $input->chat_language ) ) { \DirectoristAIPro\AI\LanguagePolicy::preference( $input->chat_language ); }
		}
		if ( property_exists( $input, 'directory_id' ) ) {
			if ( 'visitor' !== $context->profile() ) {
				throw new ContractException( 'invalid_input', 'Only visitor turns can choose their current directory.' );
			}
			if ( null !== $input->directory_id ) {
				\DirectoristAIPro\Chat\ListingActions::directory( $input->directory_id );
			} elseif ( ! \DirectoristAIPro\Chat\Operations::public_directories() ) {
				throw new ContractException( 'unauthorized', 'No searchable directories are available.' );
			}
			$scope->directory_id  = $input->directory_id;
			$scope->global_search = null === $input->directory_id;
		}
		if ( isset( $input->visitor_location ) && 'visitor' !== $context->profile() ) {
			throw new ContractException( 'invalid_input', 'Only a visitor turn can provide its current location.' );
		}
		if ( isset( $input->page_listing_id ) ) {
			if ( 'visitor' !== $context->profile() ) {
				throw new ContractException( 'invalid_input', 'Page context is only available to visitor chat.' ); }
			if ( \DirectoristAIPro\Chat\VisitorScope::global( $scope ) ) {
				\DirectoristAIPro\Chat\VisitorScope::selection( $context, array( $input->page_listing_id ) ); } else {
				\DirectoristAIPro\Chat\ListingActions::selection( $context, (int) $scope->directory_id, array( $input->page_listing_id ) ); }
		}
		if ( isset( $input->selected_listing_ids ) ) {
			if ( \DirectoristAIPro\Chat\VisitorScope::global( $scope ) ) {
				\DirectoristAIPro\Chat\VisitorScope::selection( $context, $input->selected_listing_ids );
			} else {
				$directory = \DirectoristAIPro\Chat\ListingActions::directory( $scope->directory_id ?? null );
				\DirectoristAIPro\Chat\ListingActions::selection( $context, $directory, $input->selected_listing_ids );
			}
		}
		$artifacts = new ArtifactStore( $this->db );
		foreach ( $input->artifact_refs as $reference ) {
			$artifact = $artifacts->get( $owner, $reference->id, $reference->version );
			if ( 'native_snapshot' === $artifact['kind'] ) {
				throw new ContractException( 'unauthorized', 'Private native source cannot be attached to a conversation turn.' );
			}
			if ( ! hash_equals( $artifact['content_hash'], $reference->hash ) ) {
				throw new ContractException( 'stale_version', 'Referenced artifact has changed.' );
			}
		}
		$turn = \DirectoristAIPro\UI\EditorCaptureSlots::admit(
			$this->db,
			$conversation,
			$request_id,
			fn(): array => $this->db->transaction(
				function ( Database $db ) use ( $context, $owner, $conversation_id, $request_id, $input, $scope ): array {
					$allocation   = ( new ConversationStore( $db ) )->append_in_transaction( $owner, $conversation_id, $request_id, $input );
					$turn         = $allocation['turn'];
					$conversation = $allocation['conversation'];
					if ( 'builder' === $conversation['profile'] && isset( json_decode( $conversation['context_json'] )->builder_preparation_cancelled ) ) {
						throw new ContractException( 'request_conflict', 'This editor preparation was cancelled. Start a new request from the current editor.' );
					}
					$turn_id = (int) $turn['id'];
					if ( ! $allocation['inserted'] ) {
						return $turn;
					}
					if ( $context->profile() !== $conversation['profile'] ) {
						throw new ContractException( 'scope_conflict', 'A turn cannot change the bound conversation profile.' );
					}
					if ( 'visitor' === $context->profile() ) {
						$saved_context = json_decode( $conversation['context_json'] );
						// Ordinary turns may come from stale tabs. The locked server state
						// wins; only an explicit first local choice or owned offer can set it.
						$language_state = \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $saved_context );
						foreach ( $language_state as $key => $value ) { $scope->$key = $value; }
						if ( 1 === (int) $turn['sequence'] && ! $language_state['chat_language_resolved'] && true === ( $input->chat_language_confirmed ?? false ) && isset( $input->chat_language ) ) {
							$saved_context = \DirectoristAIPro\AI\LanguagePolicy::explicit_context( $saved_context, $input->chat_language );
							foreach ( \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $saved_context ) as $key => $value ) { $scope->$key = $value; }
							$db->query( 'UPDATE ' . $db->table( 'conversations' ) . ' SET context_json=%s WHERE id=%d', array( wp_json_encode( $saved_context ), (int) $conversation['id'] ) );
						}
					}
					$runs          = $db->table( 'runs' );
					$turns         = $db->table( 'turns' );
					$conversations = $db->table( 'conversations' );
					if ( null !== $input->reply_to_run_id ) {
						$rows = $db->rows( "SELECT * FROM $runs WHERE public_id=%s AND conversation_id=%d AND owner_ref=%s FOR UPDATE", array( $input->reply_to_run_id, (int) $conversation['id'], $owner->reference() ) );
						if ( ! $rows || (int) $rows[0]['id'] !== (int) $conversation['active_run_id'] || (int) $rows[0]['state_version'] !== $input->expected_run_version || ! in_array( $rows[0]['state'], array( 'waiting_input', 'waiting_approval', 'waiting_browser' ), true ) ) {
							throw new ContractException( 'stale_version', 'The requested clarification is no longer current.' );
						}
						$bound = json_decode( $rows[0]['checkpoint_json'] )->target_context ?? json_decode( $conversation['context_json'] );
						if ( 'visitor' === $context->profile() && ( $bound->directory_id ?? null ) !== ( $scope->directory_id ?? null ) ) {
							throw new ContractException( 'scope_conflict', 'Finish this clarification in its original directory.' );
						}
						$run        = $rows[0];
						$checkpoint = json_decode( $run['checkpoint_json'], false, 64, JSON_THROW_ON_ERROR );
						if ( 'waiting_input' === $run['state'] && true === ( $checkpoint->clarification_pending ?? false ) ) {
							$checkpoint->clarification_turn_id = $turn_id;
						} else {
							unset( $checkpoint->clarification_turn_id );
						}
						unset( $checkpoint->clarification_pending );
						if ( 'waiting_input' !== $run['state'] ) {
							$approvals = $db->table( 'approvals' );
							$artifacts = $db->table( 'artifacts' );
							$db->query( "UPDATE $approvals p INNER JOIN $artifacts a ON a.public_id=p.artifact_id AND a.version=p.artifact_version SET p.state='revoked' WHERE a.conversation_id=%d AND p.state IN ('staged','pending','approved')", array( (int) $conversation['id'] ) );
							$checkpoint->step            = 'model';
							$checkpoint->render_evidence = null;
							// Refinement after a completed proposal is new logical AI work.
							$checkpoint->logical_task_id = $run['public_id'] . '-turn-' . $turn_id;
						}
						$checkpoint->latest_turn_id = $turn_id;
						if ( 'visitor' === $context->profile() ) {
							$checkpoint->visitor_turn_start_version = (int) $run['state_version'] + 1;
							$checkpoint->visitor_progress_stage = 'preparing_request';
							$checkpoint->visitor_progress_at = time();
						}
						$checkpoint->reply          = $input;
						if ( 'visitor' === $context->profile() && isset( $checkpoint->language_offer->code ) ) {
							$choice = true === ( $input->chat_language_confirmed ?? false ) && isset( $input->chat_language ) ? $input->chat_language : \DirectoristAIPro\AI\LanguagePolicy::offer_choice( $input->text, $checkpoint->language_offer->code, $scope->chat_language ?? 'site' );
							if ( null !== $choice ) {
								$saved_context = json_decode( $conversation['context_json'] );
								$saved_context = \DirectoristAIPro\AI\LanguagePolicy::explicit_context( $saved_context, $choice );
								foreach ( \DirectoristAIPro\AI\LanguagePolicy::conversation_state( $saved_context ) as $key => $value ) { $scope->$key = $value; }
								$db->query( 'UPDATE ' . $db->table( 'conversations' ) . ' SET context_json=%s WHERE id=%d', array( wp_json_encode( $saved_context ), (int) $conversation['id'] ) );
							}
						}
						$checkpoint->response_language = \DirectoristAIPro\AI\LanguagePolicy::snapshot( $scope, new \stdClass(), $context->profile() );
						unset( $checkpoint->language_offer );
						$db->query( "UPDATE $runs SET state='queued',state_version=state_version+1,checkpoint_json=%s,available_at=UTC_TIMESTAMP(),lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( json_encode( $checkpoint, JSON_THROW_ON_ERROR ), (int) $run['id'] ) );
						$db->query( "UPDATE $turns SET run_id=%d,reply_to_run_id=%d,state='accepted' WHERE id=%d", array( (int) $run['id'], (int) $run['id'], $turn_id ) );
						$this->event_in_transaction(
							(int) $run['id'],
							'run.state',
							(object) array(
								'state'  => 'queued',
								'reason' => 'clarification_received',
							)
						);
						return $db->rows( "SELECT * FROM $turns WHERE id=%d", array( $turn_id ) )[0];
					}
					$pending = (int) $db->scalar( "SELECT COUNT(*) FROM $runs WHERE conversation_id=%d AND state='queued' AND id<>COALESCE(%d,0)", array( (int) $conversation['id'], (int) $conversation['active_run_id'] ) );
					if ( $pending >= 5 ) {
						throw new ContractException( 'busy', 'Conversation queue is full.' );
					}
					$checkpoint = (object) array(
						'version'         => 1,
						'step'            => 'model',
						'initial_turn_id' => $turn_id,
						'latest_turn_id'  => $turn_id,
						'intent'          => $input,
						'target_context'  => $scope,
						'guest_expiry'    => $context->actor()->authorization_expires_at(),
						'response_language' => \DirectoristAIPro\AI\LanguagePolicy::snapshot( $scope, new \stdClass(), $context->profile() ),
						'ai_route'        => \DirectoristAIPro\AI\Settings::snapshot( $context->profile(), $scope ),
					);
					if ( 'visitor' === $context->profile() ) {
						$checkpoint->visitor_turn_start_version = 1;
						$checkpoint->visitor_progress_stage = 'preparing_request';
						$checkpoint->visitor_progress_at = time();
					}
					$db->query( "INSERT INTO $runs (public_id,owner_ref,conversation_id,trigger_turn_id,state,checkpoint_json,available_at,budget_json,created_at,updated_at) VALUES (%s,%s,%d,%d,'queued',%s,UTC_TIMESTAMP(),%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())", array( bin2hex( random_bytes( 16 ) ), $owner->reference(), (int) $conversation['id'], $turn_id, json_encode( $checkpoint, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), '{"model_rounds":0,"input_tokens":0,"output_tokens":0,"repair_rounds":0}' ) );
					$run_id = $db->insert_id();
					$db->query( "UPDATE $turns SET run_id=%d,state='accepted' WHERE id=%d", array( $run_id, $turn_id ) );
					if ( null === $conversation['active_run_id'] ) {
						$db->query( "UPDATE $conversations SET active_run_id=%d WHERE id=%d", array( $run_id, (int) $conversation['id'] ) );
					}
					$this->event_in_transaction( $run_id, 'run.state', (object) array( 'state' => 'queued' ) );
					return $db->rows( "SELECT * FROM $turns WHERE id=%d", array( $turn_id ) )[0];
				}
			)
		);
		return array(
			'turn' => $turn,
			'run'  => $this->get_by_id( $owner, (int) $turn['run_id'] ),
		);
	}

	/**
	 * Read a run through current owner and conversation visibility.
	 *
	 * @param Owner  $owner Actual owner.
	 * @param string $public_id Opaque run identity.
	 * @return array Owned run.
	 * @throws StorageException Inaccessible run.
	 */
	public function get( Owner $owner, string $public_id ): array {
		ConversationStore::identifier( $public_id );
		$runs = $this->db->table( 'runs' );
		$rows = $this->db->rows( "SELECT id FROM $runs WHERE public_id=%s AND owner_ref=%s", array( $public_id, $owner->reference() ) );
		if ( ! $rows ) {
			throw new StorageException( 'Run is unavailable.' );
		}
		return $this->get_by_id( $owner, (int) $rows[0]['id'] );
	}

	/**
	 * Record an observed existing native receipt without executing another effect.
	 *
	 * @param Context $context Current authenticated workflow authority.
	 * @param string  $run_id Exact owned run.
	 * @param int     $expected_version Last observed run version.
	 * @param string  $operation_id Existing reviewed operation.
	 * @return array Current owned run after reconciliation.
	 * @throws ContractException Stale or mismatched receipt/approval identity.
	 */
	public function reconcile_effect_receipt( Context $context, string $run_id, int $expected_version, string $operation_id ): array {
		$context->actor()->refresh();
		$context->assert_current();
		$owner = $context->actor()->owner();
		$this->get( $owner, $run_id );
		ConversationStore::identifier( $operation_id );
		$this->db->transaction(
			function ( Database $db ) use ( $context, $owner, $run_id, $expected_version, $operation_id ): void {
				$runs = $db->table( 'runs' );
				$rows = $db->rows( "SELECT * FROM $runs WHERE public_id=%s AND owner_ref=%s FOR UPDATE", array( $run_id, $owner->reference() ) );
				if ( ! $rows ) {
					throw new StorageException( 'Run is unavailable.' );
				}
				$run           = $rows[0];
				$conversations = $db->table( 'conversations' );
				$conversation  = $db->rows( "SELECT profile,context_json,summary_json FROM $conversations WHERE id=%d AND owner_ref=%s AND erasure_requested=0 AND expires_at>UTC_TIMESTAMP()", array( (int) $run['conversation_id'], $owner->reference() ) );
				$checkpoint    = json_decode( $run['checkpoint_json'] );
				$review_ref    = $checkpoint->review ?? null;
				if ( ! $conversation || $conversation[0]['profile'] !== $context->profile() || ! isset( json_decode( $conversation[0]['context_json'] )->listing_form ) || ! in_array( $run['state'], array( 'unknown', 'partial', 'failed', 'succeeded' ), true ) || ! isset( $review_ref->id, $review_ref->review_hash, $review_ref->operation_id, $review_ref->capability ) || $review_ref->operation_id !== $operation_id || ! in_array( $review_ref->capability, array( 'listing.draft_create', 'listing.form_fill', 'listing.term_fill', 'listing.media_import' ), true ) ) {
					throw new ContractException( 'scope_conflict', 'This run has no matching native result to reconcile.' );
				}
				$operations = $db->table( 'operations' );
				$operation  = $db->rows( "SELECT * FROM $operations WHERE public_id=%s AND actor_ref=%s AND run_id=%d FOR UPDATE", array( $operation_id, $owner->reference(), (int) $run['id'] ) );
				$approvals  = $db->table( 'approvals' );
				$approval   = $db->rows( "SELECT * FROM $approvals WHERE public_id=%s AND actor_ref=%s", array( $review_ref->id, $owner->reference() ) );
				if ( ! $operation || ! $approval || (int) $operation[0]['approval_id'] !== (int) $approval[0]['id'] || ! in_array( $approval[0]['state'], array( 'reserved', 'consumed' ), true ) || $approval[0]['reserved_operation_public_id'] !== $operation_id || ! hash_equals( hash( 'sha256', $approval[0]['review_json'] ), $review_ref->review_hash ) ) {
					throw new ContractException( 'approval_mismatch', 'The original native approval could not be verified.' );
				}
				$review    = json_decode( $approval[0]['review_json'] );
				$targets   = array_map( static fn( object $target ): \DirectoristAIPro\Effects\EffectTarget => new \DirectoristAIPro\Effects\EffectTarget( $target->key, $target->expected_version, $target->effect_hash ), $review->plan->targets );
				$plan      = new \DirectoristAIPro\Effects\EffectPlan( $operation_id, $owner->reference(), $review->plan->capability, $review->plan->capability_revision, $review->artifact->hash, $targets, (int) $run['id'], (int) $approval[0]['id'] );
				$receipt   = json_decode( $operation[0]['receipt_json'] );
				$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
				if ( $plan->capability !== $review_ref->capability || $operation[0]['capability'] !== $plan->capability || $operation[0]['capability_revision'] !== $plan->capability_revision || (int) $review->plan->run_id !== (int) $run['id'] || ! isset( $receipt->plan, $receipt->plan_hash, $receipt->state, $receipt->operation_id ) || $canonical->encode( $receipt->plan ) !== $canonical->encode( (object) $plan->data() ) || $receipt->operation_id !== $operation_id || ! hash_equals( $plan->hash(), $receipt->plan_hash ) || ! hash_equals( $plan->proposal_hash, $operation[0]['proposal_hash'] ) || ! hash_equals( $plan->target_set_hash(), $operation[0]['target_set_hash'] ) || $operation[0]['state'] !== $receipt->state || ! in_array( $receipt->state, array( 'succeeded', 'failed', 'partial', 'unknown' ), true ) ) {
					throw new ContractException( 'approval_mismatch', 'The native receipt does not match its reviewed result.' );
				}
				if ( $run['state'] === $receipt->state && isset( $checkpoint->receipt ) && wp_json_encode( $checkpoint->receipt ) === wp_json_encode( $receipt ) ) {
					return;
				}
				if ( (int) $run['state_version'] !== $expected_version ) {
					throw new ContractException( 'stale_version', 'The request changed before its result was reconciled.' );
				}
				$checkpoint->receipt       = $receipt;
				$checkpoint->step          = 'completed';
				$checkpoint->reconciled_at = gmdate( DATE_RFC3339 );
				if ( 'succeeded' === $receipt->state ) {
					unset( $checkpoint->error, $checkpoint->failure_reason, $checkpoint->failure_boundary );
				}
				$db->query( "UPDATE $runs SET state=%s,state_version=state_version+1,checkpoint_json=%s,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( $receipt->state, wp_json_encode( $checkpoint ), (int) $run['id'] ) );
				$summary = json_decode( $conversation[0]['summary_json'] ?? 'null' );
				if ( $summary instanceof \stdClass && property_exists( $summary, 'status' ) ) {
					$summary->status = $receipt->state;
					$db->query( "UPDATE $conversations SET summary_json=%s,state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND (active_run_id IS NULL OR active_run_id=%d) AND NOT EXISTS (SELECT 1 FROM $runs newer WHERE newer.conversation_id=%d AND newer.id>%d)", array( wp_json_encode( $summary ), (int) $run['conversation_id'], (int) $run['id'], (int) $run['conversation_id'], (int) $run['id'] ) );
				}
				$this->event_in_transaction(
					(int) $run['id'],
					'operation.receipt',
					(object) array(
						'operation_id' => $operation_id,
						'state'        => $receipt->state,
					)
				);
				$this->event_in_transaction(
					(int) $run['id'],
					'run.state',
					(object) array(
						'state'  => $receipt->state,
						'reason' => 'native_receipt_reconciled',
					)
				);
			}
		);
		\DirectoristAIPro\AI\TaskRuntime::observe( $this->db, $run_id );
		return $this->get( $owner, $run_id );
	}

	/**
	 * Read a bounded event page; transports sign owner/run-bound cursors.
	 *
	 * @param Owner  $owner Actual owner.
	 * @param string $run_id Opaque run identity.
	 * @param int    $after Last received sequence.
	 * @param int    $limit Maximum events.
	 * @return array Events, owner-bound public conversation ID and state for reconnect/resync.
	 * @throws ContractException Invalid cursor/range.
	 */
	public function events( Owner $owner, string $run_id, int $after = 0, int $limit = 100 ): array {
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new ContractException( 'invalid_input', 'Invalid event bounds.' );
		}
		$run = $this->get( $owner, $run_id );
		if ( $after >= (int) $run['next_event_seq'] ) {
			throw new ContractException( 'invalid_input', 'Cursor is beyond the recorded event stream.' );
		}
		$table        = $this->db->table( 'events' );
		$first        = $this->db->scalar( "SELECT MIN(sequence) FROM $table WHERE run_id=%d AND expires_at>UTC_TIMESTAMP()", array( (int) $run['id'] ) );
		$resync       = ( null === $first && (int) $run['next_event_seq'] > $after + 1 ) || ( null !== $first && (int) $first > $after + 1 );
		$rows         = $resync ? array() : $this->db->rows( "SELECT sequence,type,payload_json,created_at FROM $table WHERE run_id=%d AND sequence>%d AND expires_at>UTC_TIMESTAMP() ORDER BY sequence LIMIT %d", array( (int) $run['id'], $after, $limit ) );
		$failure_code = null;
		if ( 'failed' === $run['state'] || ( 'visitor' === ( $run['profile'] ?? null ) && in_array( $run['state'], array( 'queued', 'running' ), true ) ) ) {
			$checkpoint = json_decode( $run['checkpoint_json'] );
			$code       = $checkpoint instanceof \stdClass ? self::generation_failure_code( $checkpoint ) : null;
			$allowed    = array( 'generation_stalled', 'provider_unavailable', 'managed_unavailable', 'provider_configuration_changed', 'unsupported_provider_capability', 'budget_exhausted', 'input_context_too_large', 'builder_layout_unavailable', 'output_truncated', 'usage_bound_exceeded', 'invalid_output', 'grounded_response_required', 'stale_evidence', 'storage_unavailable', 'context_unavailable', 'unsupported_dependency', 'evidence_stale', 'stale_version', 'scope_conflict', 'unauthorized', 'not_found', 'unknown_outcome', 'builder_preparation_interrupted', 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain', 'managed_failed', 'managed_request_rejected', 'builder_field_unavailable', 'builder_composition_unavailable' );
			if ( is_string( $code ) && in_array( $code, $allowed, true ) && ( 'failed' === $run['state'] || in_array( $code, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain' ), true ) ) ) {
				$failure_code = $code;
			}
		}
		return array(
			'events'          => $rows,
			'conversation_id' => $run['conversation_public_id'],
			'state'           => $run['state'],
			'state_version'   => (int) $run['state_version'],
			'failure_code'    => $failure_code,
			'progress'        => 'visitor' === ( $run['profile'] ?? null ) ? \DirectoristAIPro\Chat\VisitorProgress::present( $run ) : self::generation_progress( $run ),
			'advance_ready'   => self::generation_advance_ready( $run ),
			'retry_question'  => 'visitor' === ( $run['profile'] ?? null ) ? self::retry_question( $run ) : null,
			'resync'          => $resync,
			'next_sequence'   => $resync ? (int) $run['next_event_seq'] - 1 : ( $rows ? (int) end( $rows )['sequence'] : $after ),
		);
	}

	/**
	 * Project due generation eligibility without exposing its private lease.
	 *
	 * This is a read hint only. The advance endpoint and dispatcher still enforce
	 * current native authority and claim the run atomically.
	 *
	 * @param array    $run Already owner-verified durable row and conversation.
	 * @param int|null $now Optional deterministic UTC timestamp for boundary tests.
	 * @return bool Whether an explicit generation advance can attempt a claim.
	 */
	public static function generation_advance_ready( array $run, ?int $now = null ): bool {
		$id = (int) ( $run['id'] ?? 0 );
		if ( $id < 1 || (int) ( $run['conversation_id'] ?? 0 ) < 1 || (int) ( $run['conversation_active_run_id'] ?? 0 ) !== $id || ! in_array( $run['cancel_requested'] ?? null, array( 0, '0' ), true ) || ! preg_match( '/^user:[1-9][0-9]*$/D', $run['owner_ref'] ?? '' ) || ! in_array( $run['profile'] ?? null, array( 'builder', 'admin', 'owner' ), true ) ) {
			return false;
		}
		$context = json_decode( $run['conversation_context_json'] ?? 'null' );
		if ( ! $context instanceof \stdClass ) {
			return false;
		}
		if ( 'builder' === $run['profile'] ) {
			$document = $context->builder_document ?? null;
			if ( ! $document instanceof \stdClass || ! in_array( $document->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! is_string( $document->native_id ?? null ) || ( $context->target ?? null ) !== $document->native_id ) {
				return false;
			}
		} else {
			$form = $context->listing_form ?? null;
			if ( ! $form instanceof \stdClass || ! is_int( $form->listing_id ?? null ) || $form->listing_id < 0 || ( $context->target ?? null ) !== ( $form->listing_id ? 'listing:' . $form->listing_id : 'listing:new' ) ) {
				return false;
			}
		}
		$timestamp = static function ( $value ): ?int {
			if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) {
				return null;
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
			return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $date->getTimestamp() : null;
		};
		$now       = $now ?? time();
		$available = $timestamp( $run['available_at'] ?? null );
		if ( null === $available || $available > $now ) {
			return false;
		}
		if ( 'queued' === ( $run['state'] ?? null ) ) {
			return true;
		}
		$until = $timestamp( $run['lease_until'] ?? null );
		return 'running' === ( $run['state'] ?? null ) && null !== $until && $until < $now;
	}

	/**
	 * Public progress from an already owner-verified run, without private payloads.
	 *
	 * @param array $run Owned durable row.
	 * @return array Closed client-facing phase and admitted request count.
	 */
	public static function generation_progress( array $run ): array {
		$checkpoint = json_decode( $run['checkpoint_json'] ?? '{}' );
		$budget     = json_decode( $run['budget_json'] ?? '{}', true );
		$state      = $run['state'] ?? 'queued';
		$stage      = 'preparing';
		if ( ! in_array( $state, array( 'queued', 'running' ), true ) ) {
			$stage = in_array( $state, array( 'waiting_input', 'waiting_approval', 'waiting_browser', 'succeeded', 'failed', 'cancelled', 'partial', 'unknown' ), true ) ? $state : 'preparing';
		} elseif ( 'apply' === ( $checkpoint->step ?? null ) ) {
			$stage = 'applying_review';
		} elseif ( in_array( $checkpoint->generation_stage ?? null, array( 'reading_layout', 'preparing_input', 'waiting_for_ai', 'validating_changes' ), true ) ) {
			$stage = $checkpoint->generation_stage;
		} elseif ( isset( $checkpoint->managed_pending ) || isset( $checkpoint->managed_image_pending ) ) {
			$stage = 'waiting_for_ai';
		} elseif ( ! empty( $checkpoint->tool_results ) ) {
			$last  = end( $checkpoint->tool_results );
			$stage = 'reading_layout';
		}
		$turn = $checkpoint->latest_turn_id ?? $run['trigger_turn_id'] ?? null;
		if ( 'builder' === ( $run['profile'] ?? null ) && in_array( $state, array( 'queued', 'running' ), true ) && is_int( $checkpoint->builder_repair_progress_turn ?? null ) && (int) $turn > 0 && $checkpoint->builder_repair_progress_turn === (int) $turn && 'applying_review' !== $stage && ( 'queued' === $state || 'validating_changes' !== $stage ) ) {
			$stage = 'repairing_changes';
		}
		return array(
			'stage'        => $stage,
			'model_rounds' => max( 0, min( 8, (int) ( $budget['model_rounds'] ?? 0 ) ) ),
			'updated_at'   => $run['updated_at'] ?? null,
		);
	}

	/**
	 * Explain a terminal rejected proposal using fixed server markers only.
	 *
	 * Caller retains its closed public code whitelist. This never changes the
	 * durable outcome, retry eligibility, permissions or underlying error.
	 *
	 * @param object $checkpoint Owned durable checkpoint.
	 * @return string|null Error category without private diagnostics or values.
	 */
	public static function generation_failure_code( object $checkpoint ): ?string {
		$code = $checkpoint->error ?? null;
		if ( 'invalid_output' !== $code || ! is_array( $checkpoint->tool_results ?? null ) || ! $checkpoint->tool_results ) {
			return is_string( $code ) ? $code : null;
		}
		$entry  = end( $checkpoint->tool_results );
		$result = $entry->response ?? null;
		if ( ! $result instanceof \stdClass || false !== ( $result->ok ?? null ) || 'builder.draft_propose' !== ( $result->operation ?? null ) || ! in_array( $result->code ?? null, array( 'invalid_input', 'relationship_invalid' ), true ) ) {
			return $code;
		}
		$markers = $result->repair_codes ?? array();
		if ( array( 'initial_target_card_field_unavailable' ) === $markers ) {
			return 'builder_field_unavailable';
		}
		if ( array( 'native_page_composition_invalid' ) === $markers ) {
			return 'builder_composition_unavailable';
		}
		return $code;
	}

	/**
	 * Recover the original goal and accepted clarification for an owned retry.
	 *
	 * @param array $run Owner-verified run row.
	 * @return string|null Bounded visitor question.
	 */
	public static function retry_question( array $run ): ?string {
		if ( 'failed' !== $run['state'] ) {
			return null; }
		$checkpoint = json_decode( $run['checkpoint_json'] );
		$question   = $checkpoint->intent->text ?? null;
		if ( ! is_string( $question ) ) {
			return null; }
		$reply    = $checkpoint->reply->text ?? null;
		$combined = is_string( $reply ) && $reply !== $question ? $question . "\n\n" . $reply : $question;
		return mb_strlen( $combined ) <= 2000 ? $combined : $question;
	}

	/**
	 * Replay using an owner/run-bound signed cursor and issue the next cursor.
	 *
	 * @param Owner       $owner Actual owner.
	 * @param string      $run_id Opaque run identity.
	 * @param string|null $cursor Previous cursor or null for the start.
	 * @param int         $limit Maximum events.
	 * @return array Event page including the next signed cursor.
	 */
	public function replay( Owner $owner, string $run_id, ?string $cursor = null, int $limit = 100 ): array {
		$after               = null === $cursor ? 0 : $this->cursors->decode( $owner, $run_id, $cursor );
		$page                = $this->events( $owner, $run_id, $after, $limit );
		$page['next_cursor'] = $this->cursors->encode( $owner, $run_id, $page['next_sequence'] );
		return $page;
	}

	/**
	 * Queue an exact approved review for effect execution.
	 *
	 * @param Owner  $owner Current authenticated owner.
	 * @param string $run_id Waiting run identity.
	 * @param int    $expected_version Current visible run version.
	 * @param string $approval_id Exact approval identity.
	 * @param string $review_hash Exact displayed review hash.
	 * @return array Updated owned run.
	 * @throws ContractException Stale, mismatched or unapproved review.
	 */
	public function resume_approval( Owner $owner, string $run_id, int $expected_version, string $approval_id, string $review_hash ): array {
		$this->db->readiness()->assert_ready();
		ConversationStore::identifier( $run_id );
		ConversationStore::identifier( $approval_id );
		$this->db->transaction(
			function ( Database $db ) use ( $owner, $run_id, $expected_version, $approval_id, $review_hash ): void {
				$runs          = $db->table( 'runs' );
				$conversations = $db->table( 'conversations' );
				$rows          = $db->rows( "SELECT r.*,c.active_run_id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.erasure_requested=0 FOR UPDATE", array( $run_id, $owner->reference() ) );
				if ( ! $rows || 'waiting_approval' !== $rows[0]['state'] || (int) $rows[0]['state_version'] !== $expected_version || (int) $rows[0]['active_run_id'] !== (int) $rows[0]['id'] ) {
					throw new ContractException( 'stale_version', 'Approval request is no longer current.' );
				}
				$run        = $rows[0];
				$checkpoint = json_decode( $run['checkpoint_json'] );
				if ( ! isset( $checkpoint->review->id, $checkpoint->review->review_hash ) || $checkpoint->review->id !== $approval_id || ! hash_equals( $checkpoint->review->review_hash, $review_hash ) ) {
					throw new ContractException( 'approval_mismatch', 'Run review reference changed.' );
				}
				$approvals = $db->table( 'approvals' );
				$approval  = $db->rows( "SELECT state,review_json FROM $approvals WHERE public_id=%s AND actor_ref=%s FOR UPDATE", array( $approval_id, $owner->reference() ) );
				if ( ! $approval || 'approved' !== $approval[0]['state'] || ! hash_equals( hash( 'sha256', $approval[0]['review_json'] ), $review_hash ) ) {
					throw new ContractException( 'approval_required', 'Exact human approval has not been granted.' );
				}
				$checkpoint->step = 'apply';
				$db->query( "UPDATE $runs SET state='queued',state_version=state_version+1,checkpoint_json=%s,available_at=UTC_TIMESTAMP(),lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( json_encode( $checkpoint, JSON_THROW_ON_ERROR ), (int) $run['id'] ) );
				$this->event_in_transaction(
					(int) $run['id'],
					'run.state',
					(object) array(
						'state'  => 'queued',
						'reason' => 'approval_granted',
					)
				);
			}
		);
		return $this->get( $owner, $run_id );
	}

	/**
	 * Allocate an event while the caller holds this run's transaction lock.
	 *
	 * @param int    $run_id Internal run key.
	 * @param string $type Trusted event type.
	 * @param object $payload Typed event payload.
	 * @return void
	 * @throws StorageException Missing run.
	 */
	public function event_in_transaction( int $run_id, string $type, object $payload ): void {
		if ( ! $this->db->in_transaction() ) {
			throw new StorageException( 'Events must be committed with their state transaction.' );
		}
		if ( ! in_array( $type, array( 'run.state', 'message.append', 'artifact.ready', 'review.required', 'input.required', 'browser.required', 'usage.updated', 'operation.receipt', 'diagnostic', 'visitor.delivered', 'visitor.evidence_refresh' ), true ) ) {
			throw new StorageException( 'Unsupported event type.' );
		}
		( new \DirectoristAIPro\Validation\JsonDecoder() )->check( $payload );
		$runs = $this->db->table( 'runs' );
		$rows = $this->db->rows( "SELECT next_event_seq,state_version FROM $runs WHERE id=%d FOR UPDATE", array( $run_id ) );
		if ( ! $rows ) {
			throw new StorageException( 'Run is unavailable.' );
		}
		$events = $this->db->table( 'events' );
		$this->db->query(
			"INSERT INTO $events (run_id,sequence,type,payload_json,created_at,expires_at) VALUES (%d,%d,%s,%s,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))",
			array(
				$run_id,
				(int) $rows[0]['next_event_seq'],
				$type,
				json_encode(
					(object) array(
						'state_version' => (int) $rows[0]['state_version'],
						'data'          => $payload,
					),
					JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
				),
			)
		);
		$this->db->query( "UPDATE $runs SET next_event_seq=next_event_seq+1 WHERE id=%d", array( $run_id ) );
	}

	/**
	 * Internal lookup with the same ownership/visibility constraints.
	 *
	 * @param Owner $owner Actual owner.
	 * @param int   $id Internal key.
	 * @return array Run record.
	 * @throws StorageException Missing, expired or erased data.
	 */
	private function get_by_id( Owner $owner, int $id ): array {
		$runs = $this->db->table( 'runs' );
		$c    = $this->db->table( 'conversations' );
		$rows = $this->db->rows( "SELECT r.*,c.public_id AS conversation_public_id,c.profile,c.active_run_id AS conversation_active_run_id,c.context_json AS conversation_context_json FROM $runs r LEFT JOIN $c c ON c.id=r.conversation_id WHERE r.id=%d AND r.owner_ref=%s AND (r.conversation_id IS NULL OR (c.owner_ref=%s AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL)))", array( $id, $owner->reference(), $owner->reference() ) );
		if ( ! $rows ) {
			throw new StorageException( 'Run is unavailable.' );
		}
		return $rows[0];
	}
}
