<?php
/**
 * Bounded memory assembled from current owned runtime records.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Runtime\ClarificationMessageProvenance;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;

/** Stored summaries and retrieved content are labeled data, never authority. */
final class ContextAssembler {
	/**
	 * Only a server-built packet without source-bearing context supports source-free replay.
	 *
	 * @param string $prompt Exact assembled provider packet.
	 * @return bool Whether no server source entered the prompt.
	 */
	public static function source_free_prompt( string $prompt ): bool {
		$prefix = "BEGIN_UNTRUSTED_DATA\n";
		$suffix = "\nEND_UNTRUSTED_DATA";
		if ( ! str_starts_with( $prompt, $prefix ) || ! str_ends_with( $prompt, $suffix ) ) {
			return false;
		}
		try {
			$packet = json_decode( substr( $prompt, strlen( $prefix ), -strlen( $suffix ) ), false, 32, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return false;
		}
		if ( ! $packet instanceof \stdClass || array( 'profile', 'target_context', 'task_summary', 'recent_messages', 'clarification_exchange', 'attached_artifacts', 'fresh_tool_results', 'grounded_context' ) !== array_keys( get_object_vars( $packet ) ) || ! in_array( $packet->profile, array( 'admin', 'owner' ), true ) || ! $packet->target_context instanceof \stdClass || array( 'directory_id', 'target' ) !== array_keys( get_object_vars( $packet->target_context ) ) || null !== $packet->target_context->target || ( null !== $packet->target_context->directory_id && ! is_string( $packet->target_context->directory_id ) ) || null !== $packet->task_summary || null !== $packet->clarification_exchange || null !== $packet->grounded_context || ! is_array( $packet->attached_artifacts ) || $packet->attached_artifacts || ! is_array( $packet->fresh_tool_results ) || $packet->fresh_tool_results || ! is_array( $packet->recent_messages ) ) {
			return false;
		}
		foreach ( $packet->recent_messages as $message ) {
			if ( ! $message instanceof \stdClass || 'user' !== ( $message->role ?? null ) || ! isset( $message->content->text ) || ! is_string( $message->content->text ) || ! isset( $message->source_refs ) || ! is_array( $message->source_refs ) || $message->source_refs ) {
				return false;
			}
		}
		return true;
	}
	/**
	 * Runtime storage.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind site-scoped runtime storage.
	 *
	 * @param Database $db Dedicated runtime connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Assemble recent turns, summary, attached artifacts and fresh tool results.
	 *
	 * @param array $run Current owned run row.
	 * @param Owner $owner Current server-resolved owner.
	 * @param array $tool_results Fresh results from this fenced run only.
	 * @param callable|null $visitor_projection Trusted visitor-only projection before applying the packet bound.
	 * @return string Delimited JSON data packet.
	 * @throws StorageException Owned context is missing or exceeds its bound.
	 * @throws ContractException The complete bounded prompt cannot fit this request.
	 */
	public function assemble( array $run, Owner $owner, array $tool_results = array(), ?callable $visitor_projection = null ): string {
		$conversations = $this->db->table( 'conversations' );
		$conversation  = $this->db->rows( "SELECT * FROM $conversations WHERE id=%d AND owner_ref=%s AND erasure_requested=0", array( (int) $run['conversation_id'], $owner->reference() ) );
		if ( ! $conversation ) {
			throw new StorageException( 'Conversation is unavailable for context assembly.' );
		}
		$checkpoint   = json_decode( $run['checkpoint_json'] );
		$target_scope = $checkpoint->target_context ?? json_decode( $conversation[0]['context_json'] );
		$turns        = $this->db->table( 'turns' );
		$messages     = $this->db->table( 'messages' );
		if ( 'builder' === $conversation[0]['profile'] ) {
			$runs   = $this->db->table( 'runs' );
			$recent = $this->db->rows( "SELECT m.sequence,m.role,m.content_json,m.source_refs_json,t.input_json FROM $messages m JOIN $turns t ON t.id=m.turn_id LEFT JOIN $runs r ON r.id=t.run_id AND r.conversation_id=m.conversation_id AND r.owner_ref=%s WHERE m.conversation_id=%d AND m.role='user' AND ((t.run_id IS NULL) OR (r.id IS NOT NULL AND r.state<>'cancelled' AND r.cancel_requested=0)) ORDER BY m.sequence DESC LIMIT 200", array( $owner->reference(), (int) $run['conversation_id'] ) );
		} else {
			$recent = $this->db->rows( "SELECT m.sequence,m.role,m.content_json,m.source_refs_json,t.input_json FROM $messages m JOIN $turns t ON t.id=m.turn_id WHERE m.conversation_id=%d AND m.role='user' ORDER BY m.sequence DESC LIMIT 200", array( (int) $run['conversation_id'] ) );
		}
		if ( 'visitor' === $conversation[0]['profile'] && ! \DirectoristAIPro\Chat\VisitorScope::global( $target_scope ) ) {
			$legacy_directory = json_decode( $conversation[0]['context_json'] )->directory_id ?? null;
			$recent           = array_values( array_filter( $recent, static fn( array $message ): bool => ( json_decode( $message['input_json'] )->directory_id ?? $legacy_directory ) === ( $target_scope->directory_id ?? null ) ) );
		}
		$previous_summary = json_decode( $conversation[0]['summary_json'] ?? 'null' );
		$memory_scope = hash( 'sha256', wp_json_encode( array( $conversation[0]['profile'], $target_scope->directory_id ?? null, $target_scope->builder_document ?? null, $target_scope->builder_selection ?? null ) ) );
		$prior_memory = $previous_summary->conversation_memory ?? null;
		if ( 'builder' === $conversation[0]['profile'] && is_array( $prior_memory->entries ?? null ) ) {
			$sequences = array_slice( array_values( array_filter( array_map( static fn( $entry ): int => (int) ( $entry->sequence ?? 0 ), $prior_memory->entries ) ) ), 0, 20 );
			if ( $sequences ) {
				$placeholders = implode( ',', array_fill( 0, count( $sequences ), '%d' ) );
				$eligible = $this->db->rows( "SELECT m.sequence,m.content_json FROM $messages m JOIN $turns t ON t.id=m.turn_id LEFT JOIN $runs r ON r.id=t.run_id AND r.conversation_id=m.conversation_id AND r.owner_ref=%s WHERE m.conversation_id=%d AND m.role='user' AND m.sequence IN ($placeholders) AND ((t.run_id IS NULL) OR (r.id IS NOT NULL AND r.state<>'cancelled' AND r.cancel_requested=0))", array_merge( array( $owner->reference(), (int) $run['conversation_id'] ), $sequences ) );
				$hashes = array(); foreach ( $eligible as $row ) { $hashes[ (int) $row['sequence'] ] = hash( 'sha256', $row['content_json'] ); }
				$prior_memory->entries = array_values( array_filter( $prior_memory->entries, static fn( $entry ): bool => isset( $hashes[ $entry->sequence ] ) && hash_equals( $hashes[ $entry->sequence ], $entry->source_hash ) ) );
			}
		}
		$conversation_memory = ConversationMemory::roll( array_reverse( array_slice( $recent, 12 ) ), ( $prior_memory->scope_hash ?? null ) === $memory_scope ? $prior_memory : null );
		if ( null !== $conversation_memory ) { $conversation_memory->scope_hash = $memory_scope; }
		$recent = array_slice( $recent, 0, 12 );
		$recent = array_reverse( $recent );
		foreach ( $recent as &$message ) {
			$message['sequence']    = (int) $message['sequence'];
			$message['content']     = json_decode( $message['content_json'] );
			$message['source_refs'] = json_decode( $message['source_refs_json'] );
			unset( $message['content_json'], $message['source_refs_json'], $message['input_json'] );
		}
		unset( $message );
		$checkpoint    = json_decode( $run['checkpoint_json'] );
		$intent        = $checkpoint->reply ?? $checkpoint->intent ?? (object) array();
		$clarification = $this->clarification_exchange( $run, $checkpoint );
		$artifacts     = array();
		$bytes         = 0;
		foreach ( $intent->artifact_refs ?? array() as $reference ) {
			try {
				$row = ( new ArtifactStore( $this->db ) )->get( $owner, $reference->id, (int) $reference->version );
				if ( 'native_snapshot' === $row['kind'] || ! hash_equals( $row['content_hash'], $reference->hash ) || $bytes + strlen( $row['payload_json'] ) > 16384 ) {
					continue;
				}
				$bytes      += strlen( $row['payload_json'] );
				$artifacts[] = array(
					'id'      => $row['public_id'],
					'version' => (int) $row['version'],
					'hash'    => $row['content_hash'],
					'kind'    => $row['kind'],
					'payload' => json_decode( $row['payload_json'] ),
				);
			} catch ( StorageException $error ) {
				// Revoked, erased, expired or inaccessible artifacts are excluded.
			}
		}
		$target_context = 'visitor' === $conversation[0]['profile'] ? ( $checkpoint->target_context ?? json_decode( $conversation[0]['context_json'] ) ) : json_decode( $conversation[0]['context_json'] );
		if ( 'builder' === $conversation[0]['profile'] && null !== $owner->user_id() && function_exists( 'get_terms' ) && defined( 'ATBDP_DIRECTORY_TYPE' ) ) {
			// Fresh public taxonomy identities guide natural multi-directory requests.
			// These names grant no source, field, query or mutation authority.
			$terms                                 = get_terms(
				array(
					'taxonomy'   => ATBDP_DIRECTORY_TYPE,
					'hide_empty' => false,
					'number'     => 100,
				)
			);
			$target_context->available_directories = is_wp_error( $terms ) ? array() : array_map(
				static fn( \WP_Term $term ): array => array(
					'id'   => (string) $term->term_id,
					'name' => mb_substr( wp_strip_all_tags( $term->name ), 0, 160 ),
				),
				$terms
			);
		}
		if ( 'visitor' === $conversation[0]['profile'] ) {
			$workflows                                      = new \DirectoristAIPro\Chat\VisitorWorkflowService( $this->db );
			$target_context->visitor_workflow_input_schemas = $workflows->input_schemas();
			$target_context->visitor_request                = $intent->text ?? '';
			$target_context->visitor_original_request       = $checkpoint->intent->text ?? $target_context->visitor_request;
			$target_context->visitor_site                   = $checkpoint->visitor_site ?? \DirectoristAIPro\Chat\VisitorSiteContext::profile( $target_scope );
			$target_context->visitor_results                = $checkpoint->visitor_results ?? array();
			$target_context->visitor_focus                  = $checkpoint->visitor_focus ?? null;
			$target_context->visitor_reference_catalog_changed = true === ( $checkpoint->visitor_reference_catalog_changed ?? false );
			if ( isset( $checkpoint->visitor_plan_feedback ) ) {
				$target_context->visitor_plan_feedback = $checkpoint->visitor_plan_feedback;
			}
			if ( \DirectoristAIPro\Chat\VisitorScope::global( $target_context ) ) {
				$target_context->available_directories = \DirectoristAIPro\Chat\Operations::public_directories();
			}
			if ( isset( $intent->visitor_location ) ) {
				$target_context->visitor_location = $intent->visitor_location;
			}
		}
		if ( 'visitor' === $conversation[0]['profile'] && ( isset( $intent->selected_listing_ids ) || isset( $intent->page_listing_id ) ) ) {
			$intent->selected_listing_ids      = $intent->selected_listing_ids ?? array();
			$global                            = \DirectoristAIPro\Chat\VisitorScope::global( $target_context );
			$actor                             = $owner->user_id() ? \DirectoristAIPro\Capabilities\Actor::user( $owner->user_id() ) : \DirectoristAIPro\Capabilities\Actor::verified_guest( $owner->guest_hash(), (int) ( $checkpoint->guest_expiry ?? 0 ) );
			$visitor                           = new \DirectoristAIPro\Capabilities\Context( $actor, 'visitor', 'worker' );
			$directory                         = $global ? null : \DirectoristAIPro\Chat\ListingActions::directory( $target_context->directory_id ?? null );
			$target_context->visitor_selection = $global ? \DirectoristAIPro\Chat\VisitorScope::selection( $visitor, $intent->selected_listing_ids ) : \DirectoristAIPro\Chat\ListingActions::selection( $visitor, $directory, $intent->selected_listing_ids );
			$target_context->visitor_page      = null;
			if ( isset( $intent->page_listing_id ) ) {
				$page_selection               = $global ? \DirectoristAIPro\Chat\VisitorScope::selection( $visitor, array( $intent->page_listing_id ) ) : \DirectoristAIPro\Chat\ListingActions::selection( $visitor, $directory, array( $intent->page_listing_id ) );
				$page                         = $page_selection->listings[0];
				$target_context->visitor_page = (object) array(
					'id'           => $page->id,
					'directory_id' => $page->directory_id ?? $directory,
					'title'        => $page->fields->title->value ?? $page->title ?? '',
				);
			}
			$target_context->visitor_actions            = array();
			$target_context->visitor_workflow_contracts = array();
			$action_ids                                 = array_values( array_unique( array_merge( $intent->selected_listing_ids, isset( $intent->page_listing_id ) ? array( $intent->page_listing_id ) : array(), isset( $checkpoint->visitor_focus->id ) ? array( $checkpoint->visitor_focus->id ) : array(), array_map( static fn( $item ): int => (int) ( is_array( $item ) ? $item['id'] : $item->id ), array_slice( $checkpoint->visitor_results ?? array(), 0, 4 ) ) ) ) );
			foreach ( $action_ids as $listing_id ) {
				$listing_directory                          = $global ? \DirectoristAIPro\Chat\ListingActions::directory( (string) directorist_get_listing_directory( $listing_id ) ) : $directory;
				$target_context->visitor_actions[]          = \DirectoristAIPro\Chat\ListingActions::describe( $visitor, $listing_directory, $listing_id );
				$target_context->visitor_workflow_contracts = array_merge( $target_context->visitor_workflow_contracts, $workflows->describe( $visitor, $listing_directory, $listing_id ) );
			}
			$target_context->visitor_workflows = array_values( array_filter( $workflows->list_current( $visitor, $conversation[0]['public_id'] )['blocks'], static fn( array $block ): bool => $global ? in_array( (string) $block['listing']['directory_id'], array_column( \DirectoristAIPro\Chat\Operations::public_directories(), 'id' ), true ) : $block['listing']['directory_id'] === $directory ) );
		}
		if ( isset( $target_context->listing_import ) ) {
			$actor           = \DirectoristAIPro\Capabilities\Actor::user( $owner->user_id() );
			$mapping_context = new \DirectoristAIPro\Capabilities\Context( $actor, $conversation[0]['profile'], 'worker', $conversation[0]['public_id'], $run['public_id'] );
			$target_context  = (object) array( 'listing_import' => ( new \DirectoristAIPro\Import\ImportModelBridge( $this->db ) )->prompt( $mapping_context ) );
		}
		if ( isset( $target_context->directory_builder ) ) {
			$form = $target_context->directory_builder;
			// Fresh directory.inspect supplies field relationships and editable controls.
			// Native widget internals and guard hashes stay in storage, outside the prompt.
			$target_context->directory_builder = (object) array(
				'directory_id' => $form->directory_id,
				'mode'         => $form->mode,
				'area'         => $form->area,
			);
		}
		if ( isset( $target_context->listing_history ) && in_array( $conversation[0]['profile'], array( 'admin', 'owner' ), true ) ) {
			$target_context->listing_history = (object) array(
				'earlier_discussion' => ( new \DirectoristAIPro\Listing\ListingHistory( $this->db ) )->prior_context( $target_context->listing_history, $owner, (int) $conversation[0]['id'], $conversation[0]['profile'] ),
			);
		}
		// Server-signed recovery grouping is not task context or model authority.
		unset( $target_context->builder_editor_origin );
		if ( isset( $target_context->builder_selection ) ) {
			// The server freshness stamp is not a graph-node locator fingerprint.
			unset( $target_context->builder_selection->source_stamp );
		}
		if ( isset( $target_context->builder_area_memory ) && 'builder' === $conversation[0]['profile'] ) {
			$memory                              = $target_context->builder_area_memory;
			$target_context->builder_area_memory = (object) array(
				'role'               => $memory->role,
				'capabilities'       => $memory->capabilities,
				'earlier_discussion' => ( new \DirectoristAIPro\Builder\BuilderAreaHistory( $this->db ) )->prior_requests( $memory, $owner ),
			);
		}
		$summary = null === $conversation[0]['summary_json'] ? null : json_decode( $conversation[0]['summary_json'] );
		if ( 'visitor' === $conversation[0]['profile'] && true === ( $checkpoint->visitor_reference_catalog_changed ?? false ) && is_object( $summary ) ) {
			// Retain user discussion, but never carry obsolete selectors or the old
			// schema-bound token into a fresh plan. Current IDs/titles are separate.
			unset( $summary->grounded_context );
		}
		if ( ! isset( $target_context->listing_import ) && ( null !== $conversation_memory || isset( $summary->conversation_memory ) ) ) {
			$summary = $summary ?? (object) array();
			if ( null !== $conversation_memory ) {
				$summary->conversation_memory = $conversation_memory;
			} else {
				// A changed scope with no eligible older messages cannot reuse its prior archive.
				unset( $summary->conversation_memory );
			}
			$this->db->query( "UPDATE $conversations SET summary_json=%s WHERE id=%d AND owner_ref=%s AND erasure_requested=0 AND active_run_id=%d", array( wp_json_encode( $summary ), (int) $run['conversation_id'], $owner->reference(), (int) $run['id'] ) );
		}
		if ( isset( $target_context->listing_import ) ) {
			// A mapping prompt consists of its freshly rebuilt safe input and owned conversation, never saved artifact payloads or internal summary hashes.
			$artifacts = array();
			$summary   = null;
		}
		if ( 'visitor' === $conversation[0]['profile'] && ! \DirectoristAIPro\Chat\VisitorScope::global( $target_scope ) && ( $target_scope->directory_id ?? null ) !== (string) ( $summary->grounded_context->task->directory_id ?? '' ) ) {
			$summary = null;
		}
		if ( 'builder' === $conversation[0]['profile'] ) {
			// Filtered multi-target inspections must receive the same bounded native
			// value view as a selected single target before the raw packet guard.
			$tool_results = BuilderControlPrompt::project( $tool_results );
		}
		$packet = array(
			'profile'                => $conversation[0]['profile'],
			'target_context'         => $target_context,
			'task_summary'           => $summary,
			'recent_messages'        => $recent,
			'clarification_exchange' => $clarification,
			'attached_artifacts'     => $artifacts,
			'fresh_tool_results'     => $tool_results,
			'grounded_context'       => $summary->grounded_context ?? null,
		);
		$json   = json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		// Public directory schemas may repeat large native contracts. Size the
		// actual visitor request, after its existing lossless field projection,
		// rather than rejecting an intermediate packet that is never dispatched.
		if ( null !== $visitor_projection ) {
			if ( 'visitor' !== $conversation[0]['profile'] ) {
				throw new ContractException( 'scope_conflict', 'Visitor projection requires a visitor conversation.' );
			}
			$projected = $visitor_projection( "BEGIN_UNTRUSTED_DATA\n" . $json . "\nEND_UNTRUSTED_DATA" );
			if ( ! is_string( $projected ) || strlen( $projected ) > 262144 || ! str_starts_with( $projected, "BEGIN_UNTRUSTED_DATA\n" ) || ! str_ends_with( $projected, "\nEND_UNTRUSTED_DATA" ) ) {
				throw new ContractException( 'input_context_too_large', 'The current public search context could not be prepared. Its request and messages were retained.', array( 'context_packet_limit' ) );
			}
			return $projected;
		}
		if ( strlen( $json ) > 262144 ) {
			foreach ( array( 8, 4, 1 ) as $keep ) {
				$compact = ConversationContext::compact( "BEGIN_UNTRUSTED_DATA\n" . $json . "\nEND_UNTRUSTED_DATA", $keep );
				if ( null !== $compact && strlen( $compact ) - strlen( "BEGIN_UNTRUSTED_DATA\n\nEND_UNTRUSTED_DATA" ) <= 262144 ) {
					return $compact;
				}
			}
			throw new ContractException( 'input_context_too_large', 'The current layout needs a smaller selected area before AI can continue. Existing content and messages were retained.', array( 'context_packet_limit' ) );
		}
		return "BEGIN_UNTRUSTED_DATA\n" . $json . "\nEND_UNTRUSTED_DATA";
	}

	/**
	 * Recover the exact signed question paired with the accepted reply.
	 *
	 * @param array  $run Current owned run.
	 * @param object $checkpoint Current checkpoint.
	 * @return array|null Labeled untrusted question/reply pair.
	 * @throws StorageException Missing or altered clarification evidence.
	 */
	private function clarification_exchange( array $run, object $checkpoint ): ?array {
		if ( ! isset( $checkpoint->clarification_turn_id ) ) {
			return null;
		}
		$turn_id = $checkpoint->clarification_turn_id;
		if ( ! is_int( $turn_id ) || 1 > $turn_id || ( $checkpoint->latest_turn_id ?? null ) !== $turn_id || ! isset( $checkpoint->reply->text ) || ! is_string( $checkpoint->reply->text ) || ( $checkpoint->reply->reply_to_run_id ?? null ) !== $run['public_id'] ) {
			throw new StorageException( 'Clarification reply identity is unavailable.' );
		}
		$messages = $this->db->table( 'messages' );
		$turns    = $this->db->table( 'turns' );
		$replies  = $this->db->rows( "SELECT m.sequence,m.content_json,m.source_refs_json,t.run_id,t.reply_to_run_id FROM $messages m JOIN $turns t ON t.id=m.turn_id WHERE m.conversation_id=%d AND m.turn_id=%d AND m.role='user' AND t.conversation_id=m.conversation_id", array( (int) $run['conversation_id'], $turn_id ) );
		if ( 1 !== count( $replies ) || (int) $replies[0]['run_id'] !== (int) $run['id'] || (int) $replies[0]['reply_to_run_id'] !== (int) $run['id'] || '[]' !== $replies[0]['source_refs_json'] ) {
			throw new StorageException( 'Clarification reply is unavailable.' );
		}
		$answer = json_decode( $replies[0]['content_json'] );
		if ( ! $answer instanceof \stdClass || ! isset( $answer->text ) || ! is_string( $answer->text ) || $answer->text !== $checkpoint->reply->text ) {
			throw new StorageException( 'Clarification reply has changed.' );
		}
		$questions = $this->db->rows( "SELECT sequence,content_json,source_refs_json FROM $messages WHERE conversation_id=%d AND turn_id=%d AND role='assistant' AND sequence<%d ORDER BY sequence DESC LIMIT 1", array( (int) $run['conversation_id'], (int) $run['trigger_turn_id'], (int) $replies[0]['sequence'] ) );
		if ( 1 !== count( $questions ) || '[]' !== $questions[0]['source_refs_json'] ) {
			throw new StorageException( 'Clarification question is unavailable.' );
		}
		$question = json_decode( $questions[0]['content_json'] );
		if ( ! $question instanceof \stdClass || array( 'text', 'provenance' ) !== array_keys( get_object_vars( $question ) ) || ! ( new ClarificationMessageProvenance() )->verifies( $run, (int) $questions[0]['sequence'], $question ) ) {
			throw new StorageException( 'Clarification question has changed.' );
		}
		return array(
			'question' => $question->text,
			'answer'   => $answer->text,
		);
	}
}
