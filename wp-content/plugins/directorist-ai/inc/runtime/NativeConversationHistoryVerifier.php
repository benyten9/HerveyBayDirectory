<?php
/**
 * Exact first-party status replay; unknown assistant prose remains private.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed deterministic replay boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;

/** Recheck fixed status receipts and producer-attested source-free prose on replay. */
final class NativeConversationHistoryVerifier implements ConversationHistoryVerifier {
	private Database $db;
	private SourceFreeMessageProvenance $source_free;

	public function __construct( Database $db, ?SourceFreeMessageProvenance $source_free = null ) {
		$this->db          = $db;
		$this->source_free = $source_free ?? new SourceFreeMessageProvenance( $db );
	}

	public function allows( Context $context, array $message, object $content, array $references, int $conversation_id ): bool {
		if ( ! isset( $content->text ) || ! is_string( $content->text ) || $conversation_id < 1 ) {
			return false;
		}
		$keys = array_keys( get_object_vars( $content ) );
		if ( 'builder' === $context->profile() && ! $context->actor()->is_guest() && array( 'text' ) === $keys && ! $references && 'Those optional settings are not available on the selected elements. They were skipped and your existing layout is unchanged.' === $content->text && $this->optional_settings_current( $context, $message, $conversation_id ) ) { return true; }
		if ( array( 'text' ) === $keys && ! $references && $this->runtime_failure_current( $context, $message, $content->text, $conversation_id ) ) {
			return true;
		}
		if ( array( 'text', 'provenance' ) === $keys ) {
			$contract = $content->provenance->contract ?? null;
			if ( 'visitor' === $context->profile() && 'directorist-ai/visitor-help-v1' === $contract && ! $references ) {
				$rows = $this->db->rows( 'SELECT r.* FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . " c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.conversation_id=%d AND r.trigger_turn_id=%d AND r.state='succeeded'", array( $content->provenance->run_id ?? '', $context->actor()->owner()->reference(), $conversation_id, (int) ( $message['turn_id'] ?? 0 ) ) );
				return 1 === count( $rows ) && \DirectoristAIPro\Chat\VisitorHelp::verifies( $rows[0], (int) $message['sequence'], $content );
			}
			if ( 'directorist-ai/clarification-message-v1' === $contract ) {
				return $this->clarification_current( $context, $message, $content, $references, $conversation_id );
			}
			if ( ! $context->actor()->is_guest() && 'directorist-ai/source-free-message-v1' === $contract ) {
				return $this->source_free_current( $context, $message, $content, $references, $conversation_id );
			}
			return false;
		}
		if ( $context->actor()->is_guest() || array( 'text' ) !== $keys ) {
			return false;
		}
		if ( 'builder' === $context->profile() && array() === $references && $this->current_builder_question( $context, $message, $content->text, $conversation_id ) ) {
			return true;
		}
		$review_messages = array(
			'Your layout changes are ready for the editor.' => array( 'builder.stage', 'builder_draft' ),
			'A native listing form proposal is ready for your review.' => array( 'listing.form_fill', 'proposal' ),
			'A private native builder draft is ready for your review.' => array( 'builder.stage', 'builder_draft' ),
			'A page publication proposal is ready for your review.' => array( 'builder.publish', 'proposal' ),
			'A page restoration proposal is ready for your review.' => array( 'builder.restore', 'proposal' ),
			'A native template assignment proposal is ready for your review.' => array( 'builder.template_assign', 'proposal' ),
			'A template assignment restoration proposal is ready for your review.' => array( 'builder.template_restore', 'proposal' ),
			'A scoped live editor proposal is ready for your review.' => array( 'builder.live_apply', 'proposal' ),
			'A listing change proposal is ready for your review.' => array( 'listing.apply', 'proposal' ),
			'A new listing proposal is ready for your review.' => array( 'listing.create', 'proposal' ),
			'A private image suggestion is ready for your review.' => array( 'media.import', 'media_suggestion' ),
		);
		if ( 'A native listing image proposal is ready for your review.' === $content->text ) {
			return $this->review_current( $context, $references, $conversation_id, 'listing.media_import', 'media_suggestion' ); }
		if ( 'A native listing form proposal is ready for your review.' === $content->text ) {
			return $this->review_current( $context, $references, $conversation_id, 'listing.form_fill', 'proposal' ) || $this->review_current( $context, $references, $conversation_id, 'listing.term_fill', 'proposal' ) || $this->review_current( $context, $references, $conversation_id, 'listing.draft_create', 'proposal' );
		}
		if ( isset( $review_messages[ $content->text ] ) ) {
			return $this->review_current( $context, $references, $conversation_id, ...$review_messages[ $content->text ] );
		}
		$receipt_messages = array(
			'succeeded' => array(
				'Your changes are ready for this page. The editor will apply them for your review.' => array( 'builder.stage' ),
				'The reviewed listing form result is ready.' => array( 'listing.form_fill', 'listing.term_fill', 'listing.draft_create', 'listing.media_import' ),
				'The approved listing changes were applied.' => array( 'listing.apply' ),
				'The approved listing was submitted through Directorist.' => array( 'listing.create' ),
				'The approved image was imported into the listing through WordPress.' => array( 'media.import' ),
			),
			'failed'    => array(
				'These changes could not be prepared. Your open page is unchanged.' => array( 'builder.stage' ),
				'The approved listing changes could not be applied.' => array( 'listing.apply' ),
				'The approved listing could not be submitted.' => array( 'listing.create' ),
				'The approved listing effect could not be applied.' => array( 'listing.create', 'media.import' ),
			),
			'partial'   => array( 'The listing result requires review before any retry.' => array( 'listing.apply', 'listing.create', 'media.import' ) ),
			'unknown'   => array( 'The listing result requires review before any retry.' => array( 'listing.apply', 'listing.create', 'media.import' ) ),
		);
		foreach ( array( 'failed', 'partial', 'unknown' ) as $form_state ) {
			$receipt_messages[ $form_state ]['The listing form result requires review before any retry.'] = array( 'listing.form_fill', 'listing.term_fill', 'listing.draft_create', 'listing.media_import' );
		}
		$states = array(
			'builder.live_apply'       => array(
				'succeeded' => 'The approved native editor change was confirmed.',
				'failed'    => 'The reviewed live editor change could not be applied.',
				'partial'   => 'The approved live editor change is awaiting native Save confirmation.',
				'unknown'   => 'The approved live editor change is awaiting native Save confirmation.',
			),
			'listing.apply'            => array(
				'succeeded' => 'The approved listing changes were applied.',
				'failed'    => 'The approved listing changes could not be applied.',
				'partial'   => 'The listing result requires review before any retry.',
				'unknown'   => 'The listing result requires review before any retry.',
			),
			'builder.stage'            => array(
				'succeeded' => 'The approved private builder draft is ready to open in its native editor.',
				'failed'    => 'The approved builder draft could not be created.',
				'partial'   => 'The builder draft result requires review before any retry.',
				'unknown'   => 'The builder draft result requires review before any retry.',
			),
			'builder.publish'          => array(
				'succeeded' => 'The separately approved native page was published.',
				'failed'    => 'The reviewed page could not be published.',
				'partial'   => 'The page publication result requires review before any retry.',
				'unknown'   => 'The page publication result requires review before any retry.',
			),
			'builder.restore'          => array(
				'succeeded' => 'The separately approved native page was returned to its prior draft state.',
				'failed'    => 'The reviewed page could not be restored.',
				'partial'   => 'The page restoration result requires review before any retry.',
				'unknown'   => 'The page restoration result requires review before any retry.',
			),
			'builder.template_assign'  => array(
				'succeeded' => 'The separately approved native template was assigned.',
				'failed'    => 'The reviewed native template could not be assigned.',
				'partial'   => 'The template assignment result requires review before any retry.',
				'unknown'   => 'The template assignment result requires review before any retry.',
			),
			'builder.template_restore' => array(
				'succeeded' => 'The separately approved native template assignment was restored.',
				'failed'    => 'The reviewed template assignment could not be restored.',
				'partial'   => 'The template restoration result requires review before any retry.',
				'unknown'   => 'The template restoration result requires review before any retry.',
			),
		);
		foreach ( $states as $capability => $messages ) {
			if ( ! str_starts_with( $capability, 'builder.' ) ) {
				continue;
			}
			foreach ( $messages as $state => $text ) {
				$receipt_messages[ $state ][ $text ] = array( $capability );
			}
		}
		foreach ( $receipt_messages as $state => $messages ) {
			if ( isset( $messages[ $content->text ] ) && $this->operation_current( $context, $references, $conversation_id, $state, $messages[ $content->text ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** The exact server-produced no-change receipt is history, never an executable proposal. */
	private function optional_settings_current( Context $context, array $message, int $conversation_id ): bool {
		if ( 'assistant' !== ( $message['role'] ?? null ) || ! isset( $message['turn_id'], $message['sequence'], $message['content_json'], $message['source_refs_json'] ) ) { return false; }
		$rows = $this->db->rows( 'SELECT r.checkpoint_json FROM ' . $this->db->table( 'runs' ) . ' r INNER JOIN ' . $this->db->table( 'conversations' ) . " c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref AND c.profile='builder' AND c.erasure_requested=0 INNER JOIN " . $this->db->table( 'messages' ) . " m ON m.conversation_id=r.conversation_id AND m.turn_id=r.trigger_turn_id AND m.role='assistant' WHERE r.conversation_id=%d AND r.owner_ref=%s AND r.trigger_turn_id=%d AND r.state='succeeded' AND m.sequence=%d AND m.content_json=%s AND m.source_refs_json=%s ORDER BY r.id DESC LIMIT 1", array( $conversation_id, $context->actor()->owner()->reference(), (int) $message['turn_id'], (int) $message['sequence'], $message['content_json'], $message['source_refs_json'] ) );
		$checkpoint = $rows ? json_decode( $rows[0]['checkpoint_json'] ) : null;
		$results = $checkpoint->tool_results ?? array();
		$last = $results ? end( $results ) : null;
		return true === ( $checkpoint->optional_settings_skipped ?? false ) && 'builder.draft_propose' === ( $last->response->operation ?? null ) && 'optional_controls_unavailable' === ( $last->response->code ?? null );
	}

	/** Only a fixed server notice in the original owned failed turn may replay. */
	private function runtime_failure_current( Context $context, array $message, string $text, int $conversation_id ): bool {
		if ( 'assistant' !== ( $message['role'] ?? null ) || ! isset( $message['turn_id'] ) ) {
			return false;
		}
		$rows = $this->db->rows( 'SELECT r.id,r.checkpoint_json,c.context_json FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . " c ON c.id=r.conversation_id WHERE r.conversation_id=%d AND r.trigger_turn_id=%d AND r.owner_ref=%s AND r.state='failed' AND c.owner_ref=r.owner_ref AND c.profile=%s AND c.erasure_requested=0", array( $conversation_id, (int) $message['turn_id'], $context->actor()->owner()->reference(), $context->profile() ) );
		if ( 1 !== count( $rows ) ) {
			return false;
		}
		$checkpoint = json_decode( $rows[0]['checkpoint_json'] );
		// Later requests can advance the conversation; check the original failed turn.
		$stored = $this->db->rows( 'SELECT sequence,content_json FROM ' . $this->db->table( 'messages' ) . " WHERE conversation_id=%d AND turn_id=%d AND role='assistant' ORDER BY sequence DESC LIMIT 1", array( $conversation_id, (int) $message['turn_id'] ) );
		if ( ! is_string( $checkpoint->error ?? null ) || ! $stored || (int) ( $message['sequence'] ?? 0 ) !== (int) $stored[0]['sequence'] || ( $message['content_json'] ?? null ) !== $stored[0]['content_json'] ) {
			return false;
		}
		$data  = $this->db->scalar( 'SELECT data_json FROM ' . $this->db->table( 'steps' ) . ' WHERE run_id=%d ORDER BY sequence DESC LIMIT 1', array( (int) $rows[0]['id'] ) );
		$task  = json_decode( $data ?? '' )->task ?? 'text';
		$route = json_decode( wp_json_encode( $checkpoint->ai_route ?? array() ), true );
		$code  = $checkpoint->error;
		$known = array( \DirectoristAIPro\AI\FailureNotice::runtime_message( $code, $route, $task ) );
		if ( 'visitor' !== $context->profile() ) {
			$known[] = \DirectoristAIPro\AI\FailureNotice::runtime_message( $code, $route, $task, (array) ( $checkpoint->wordpress_error ?? array() ) );
		}
		$selection = json_decode( $rows[0]['context_json'] );
		if ( in_array( $context->profile(), array( 'admin', 'owner' ), true ) && ( $selection->listing_form ?? null ) instanceof \stdClass ) {
			$known[] = \DirectoristAIPro\AI\FailureNotice::listing_message( $code, $checkpoint );
		}
		if ( 'visitor' === $context->profile() ) {
			$known[] = \DirectoristAIPro\AI\FailureNotice::visitor_message( $code );
		}
		if ( 'builder' === $context->profile() && 'invalid_output' === $code && ! isset( $checkpoint->review ) && 0 === (int) $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'operations' ) . ' WHERE run_id=%d', array( (int) $rows[0]['id'] ) ) ) {
			$known[] = \DirectoristAIPro\AI\FailureNotice::layout_planning_message();
			$known[] = \DirectoristAIPro\AI\FailureNotice::editor_planning_message();
		}
		if ( 'builder' === $context->profile() && 'builder_layout_unavailable' === $code && ! isset( $checkpoint->review ) ) {
			$known[] = 'This layout could not be prepared with the available editor controls. Your current page is unchanged. Try a simpler change or choose a supported editable area.';
		}
		if ( 'context_unavailable' === $code ) {
			$known[] = 'I could not assemble a current, authorized context for this request.';
		}
		if ( in_array( $code, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain' ), true ) ) {
			$known[] = 'The managed request is still unconfirmed. Its receipt is retained for reconciliation. Do not repeat the request until its status is resolved.';
		}
		return in_array( $text, $known, true );
	}

	/** Current clarification text must match the exact trusted tool result in the active run. */
	private function current_builder_question( Context $context, array $message, string $text, int $conversation_id ): bool {
		$turn_id = $message['turn_id'] ?? null;
		if ( 'assistant' !== ( $message['role'] ?? null ) || ( ! is_int( $turn_id ) && ( ! is_string( $turn_id ) || ! ctype_digit( $turn_id ) ) ) || (int) $turn_id < 1 || '' === trim( $text ) ) {
			return false;
		}
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.checkpoint_json FROM $runs r INNER JOIN $conversations c ON c.active_run_id=r.id WHERE c.id=%d AND c.owner_ref=%s AND c.profile='builder' AND r.owner_ref=%s AND r.state='waiting_input' LIMIT 1", array( $conversation_id, $context->actor()->owner()->reference(), $context->actor()->owner()->reference() ) );
		if ( 1 !== count( $rows ) ) {
			return false;
		}
		try {
			$checkpoint = json_decode( $rows[0]['checkpoint_json'], false, 32, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return false;
		}
		$results  = $checkpoint->tool_results ?? null;
		$last     = is_array( $results ) && $results ? end( $results ) : null;
		$question = $last instanceof \stdClass ? ( $last->response->data->question ?? null ) : null;
		return (int) ( $checkpoint->latest_turn_id ?? 0 ) === (int) $turn_id && $last instanceof \stdClass && true === ( $last->response->ok ?? null ) && 'conversation.clarify' === ( $last->response->operation ?? null ) && is_string( $question ) && hash_equals( $question, $text );
	}

	/** Rebind a source-free seal to the actual owned run and original turn. */
	private function source_free_current( Context $context, array $message, object $content, array $references, int $conversation_id ): bool {
		if ( $references || ! isset( $content->provenance->run_id ) || ! is_string( $content->provenance->run_id ) || ! isset( $message['turn_id'], $message['sequence'] ) ) {
			return false;
		}
		$rows = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND r.conversation_id=%d AND r.trigger_turn_id=%d AND c.owner_ref=r.owner_ref AND c.erasure_requested=0', array( $content->provenance->run_id, $context->actor()->owner()->reference(), $conversation_id, (int) $message['turn_id'] ) );
		if ( 1 !== count( $rows ) || $rows[0]['profile'] !== $context->profile() || 'succeeded' !== $rows[0]['state'] ) {
			return false;
		}
		$checkpoint = json_decode( $rows[0]['checkpoint_json'] );
		return $checkpoint instanceof \stdClass && $this->source_free->verifies( $rows[0], (int) $message['sequence'], $content, $checkpoint );
	}

	/** Verify one producer-sealed question against its owned original run. */
	private function clarification_current( Context $context, array $message, object $content, array $references, int $conversation_id ): bool {
		if ( $references || ! isset( $content->provenance->run_id ) || ! is_string( $content->provenance->run_id ) || ! isset( $message['turn_id'], $message['sequence'] ) ) {
			return false;
		}
		$rows = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND r.conversation_id=%d AND r.trigger_turn_id=%d AND c.owner_ref=r.owner_ref AND c.erasure_requested=0', array( $content->provenance->run_id, $context->actor()->owner()->reference(), $conversation_id, (int) $message['turn_id'] ) );
		return 1 === count( $rows ) && $rows[0]['profile'] === $context->profile() && ( new ClarificationMessageProvenance() )->verifies( $rows[0], (int) $message['sequence'], $content );
	}

	private function review_current( Context $context, array $references, int $conversation_id, string $capability, string $artifact_kind ): bool {
		if ( 2 !== count( $references ) || ! $this->exact_reference( $references[0], array( 'id', 'kind' ) ) || 'review' !== $references[0]->kind || ! $this->exact_reference( $references[1], array( 'id', 'kind', 'version' ) ) || 'artifact' !== $references[1]->kind || ! is_int( $references[1]->version ) || $references[1]->version < 1 ) {
			return false;
		}
		$review_id   = $references[0]->id;
		$artifact_id = $references[1]->id;
		if ( ! $this->identifier( $review_id ) || ! $this->identifier( $artifact_id ) ) {
			return false;
		}
		$rows       = $this->db->rows( 'SELECT p.artifact_id,p.artifact_version,p.proposal_hash,p.capability,p.state,p.expires_at FROM ' . $this->db->table( 'approvals' ) . ' p JOIN ' . $this->db->table( 'artifacts' ) . ' a ON a.public_id=p.artifact_id AND a.version=p.artifact_version WHERE p.public_id=%s AND p.actor_ref=%s AND a.conversation_id=%d', array( $review_id, $context->actor()->owner()->reference(), $conversation_id ) );
		$state      = $rows[0]['state'] ?? null;
		$historical = in_array( $state, array( 'approved', 'reserved', 'consumed', 'rejected' ), true );
		$active     = 'pending' === $state && strtotime( $rows[0]['expires_at'] . ' UTC' ) > time();
		if ( 1 !== count( $rows ) || $rows[0]['capability'] !== $capability || $rows[0]['artifact_id'] !== $artifact_id || (int) $rows[0]['artifact_version'] !== $references[1]->version || ( ! $active && ! $historical ) ) {
			return false;
		}
		try {
			$artifact = ( new ArtifactStore( $this->db ) )->get( $context->actor()->owner(), $artifact_id, $references[1]->version );
		} catch ( \Throwable $error ) {
			return false;
		}
		return (int) $artifact['conversation_id'] === $conversation_id && $artifact_kind === $artifact['kind'] && hash_equals( $rows[0]['proposal_hash'], $artifact['content_hash'] );
	}

	private function operation_current( Context $context, array $references, int $conversation_id, string $state, array $capabilities ): bool {
		if ( 1 !== count( $references ) || ! $this->exact_reference( $references[0], array( 'id', 'kind', 'state' ) ) || 'operation' !== $references[0]->kind || $state !== $references[0]->state || ! $this->identifier( $references[0]->id ) ) {
			return false;
		}
		$rows = $this->db->rows( 'SELECT o.capability,o.state,o.receipt_json FROM ' . $this->db->table( 'operations' ) . ' o JOIN ' . $this->db->table( 'runs' ) . ' r ON r.id=o.run_id WHERE o.public_id=%s AND o.actor_ref=%s AND r.conversation_id=%d', array( $references[0]->id, $context->actor()->owner()->reference(), $conversation_id ) );
		if ( 1 !== count( $rows ) || ! in_array( $rows[0]['capability'], $capabilities, true ) || $state !== $rows[0]['state'] || null === $rows[0]['receipt_json'] ) {
			return false;
		}
		try {
			$receipt = json_decode( $rows[0]['receipt_json'], true, 64, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return false;
		}
		return is_array( $receipt ) && ( $receipt['state'] ?? null ) === $state;
	}

	private function exact_reference( $reference, array $expected ): bool {
		if ( ! $reference instanceof \stdClass ) {
			return false;
		}
		$keys = array_keys( get_object_vars( $reference ) );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected && isset( $reference->id, $reference->kind ) && is_string( $reference->id ) && is_string( $reference->kind );
	}

	private function identifier( string $value ): bool {
		try {
			ConversationStore::identifier( $value );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}
}
