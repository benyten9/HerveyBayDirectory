<?php
/**
 * Private listing discussions, separate from executable form snapshots.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment -- Small private history repository methods share the class contract.
/** Retained messages are historical data, never approval or current listing facts. */
final class ListingHistory {
	public function __construct( private Database $db ) {}

	public static function area( object $form ): string {
		if ( isset( $form->media ) ) {
			return 'media:' . $form->media->slot . ':' . ( $form->media->replace_id ?? 0 );
		}
		if ( isset( $form->term_field ) ) {
			return 'field:' . $form->term_field;
		}
		return 'create_draft' !== $form->mode && 1 === count( $form->field_ids ) ? 'field:' . $form->field_ids[0] : 'whole';
	}

	public static function scope( object $form, object $input ): object {
		$area = $input->history_area ?? self::area( $form );
		if ( ! is_string( $area ) || ( self::area( $form ) !== $area && 'whole' !== $area ) ) {
			throw new ContractException( 'scope_mismatch', 'Listing discussion belongs to another area.' );
		}
		if ( 'whole' === $area ) {
			$ids  = $form->field_ids;
			$keys = array_keys( get_object_vars( $form->values ) );
			sort( $ids );
			sort( $keys );
			if ( isset( $form->media ) || isset( $form->term_field ) || $ids !== $keys ) {
				throw new ContractException( 'scope_mismatch', 'Whole-listing discussion requires the whole active field scope.' );
			}
		}
		$session = $form->listing_id ? null : ( $input->history_session ?? substr( hash( 'sha256', $input->request_id ), 0, 32 ) );
		if ( null !== $session && ( ! is_string( $session ) || ! preg_match( '/^[a-f0-9]{32}$/D', $session ) ) ) {
			throw new ContractException( 'invalid_input', 'The new-listing discussion session is invalid.' );
		}
		return (object) array(
			'listing_id'   => $form->listing_id,
			'directory_id' => $form->directory_id,
			'area'         => $area,
			'session'      => $session,
		);
	}

	public static function prefix( object $scope ): string {
		return 'lh-' . hash( 'sha256', wp_json_encode( $scope ) ) . '-';
	}

	/** Indexed new discussions plus a bounded compatibility lookup for old native chats. */
	public function lookup( Context $context, object $scope, int $before = 0 ): array {
		$owner  = $context->actor()->owner();
		$table  = $this->db->table( 'conversations' );
		$link   = $this->generation_link( $scope, $owner );
		$legacy = $scope->listing_id ? "(JSON_EXTRACT(context_json,'$.listing_history') IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.listing_form.listing_id'))=%s)" : '0=1';
		$args   = array( $owner->reference(), $context->profile(), self::prefix( $scope ) . '%', $link );
		if ( $scope->listing_id ) {
			$args[] = (string) $scope->listing_id;
		}
		$args[]  = $before ? $before : PHP_INT_MAX;
		$rows    = $this->db->rows( "SELECT * FROM $table WHERE owner_ref=%s AND profile=%s AND erasure_requested=0 AND expires_at>UTC_TIMESTAMP() AND (request_id LIKE %s OR public_id=%s OR $legacy) AND id<%d ORDER BY id DESC LIMIT 61", $args );
		$entries = array();
		foreach ( $rows as $row ) {
			if ( ! $this->matches( $row, $scope, $link ) ) {
				continue;
			}
			$entry = $this->entry( $row, $owner );
			if ( ! $entry['messages'] && ! $entry['suggestions'] ) {
				continue;
			}
			$entries[] = $entry;
			if ( count( $entries ) >= 6 ) {
				break;
			}
		}
		$more    = count( $entries ) > 5 || count( $rows ) === 61;
		$entries = array_slice( $entries, 0, 5 );
		$last    = $entries ? end( $entries )['cursor'] : ( $rows ? (int) end( $rows )['id'] : 0 );
		return array(
			'entries'  => $entries,
			'has_more' => $more,
			'before'   => $last,
		);
	}

	/** Existing native operation markers link old and new generated drafts alike. */
	private function generation_link( object $scope, Owner $owner ): string {
		if ( ! $scope->listing_id || 'whole' !== $scope->area ) {
			return '';
		}
		$operation = (string) get_post_meta( $scope->listing_id, '_directorist_ai_form_operation', true );
		$hash      = (string) get_post_meta( $scope->listing_id, '_directorist_ai_form_hash', true );
		if ( ! $operation || ! $hash ) {
			return '';
		}
		$operations    = $this->db->table( 'operations' );
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT c.public_id,o.receipt_json FROM $operations o JOIN $runs r ON r.id=o.run_id JOIN $conversations c ON c.id=r.conversation_id WHERE o.public_id=%s AND o.actor_ref=%s AND c.owner_ref=%s AND o.proposal_hash=%s AND o.capability='listing.draft_create' AND o.state='succeeded' LIMIT 1", array( $operation, $owner->reference(), $owner->reference(), $hash ) );
		if ( $rows ) {
			$receipt = json_decode( $rows[0]['receipt_json'] );
			foreach ( (array) ( $receipt->targets ?? array() ) as $target ) {
				if ( ( $target->effects->listing_id ?? 0 ) === $scope->listing_id ) {
					return $rows[0]['public_id'];
				}
			}
		}
		return '';
	}

	private function matches( array $row, object $scope, string $link ): bool {
		$stored = json_decode( $row['context_json'] );
		$form   = $stored->listing_form ?? null;
		if ( ! $form instanceof \stdClass || (int) $form->directory_id !== $scope->directory_id ) {
			return false;
		}
		if ( $link && $row['public_id'] === $link && ! $form->listing_id && 'create_draft' === $form->mode ) {
			return true;
		}
		$prior = $stored->listing_history ?? null;
		if ( $prior instanceof \stdClass ) {
			return self::prefix( $scope ) === self::prefix( $this->identity( $prior ) );
		}
		return $scope->listing_id && (int) $form->listing_id === $scope->listing_id && self::area( $form ) === $scope->area;
	}

	private function identity( object $scope ): object {
		return (object) array(
			'listing_id'   => $scope->listing_id,
			'directory_id' => $scope->directory_id,
			'area'         => $scope->area,
			'session'      => $scope->session,
		);
	}

	/** Plain text and suggestion excerpts only. No review IDs or executable effects. */
	private function entry( array $row, Owner $owner ): array {
		$messages = $this->db->table( 'messages' );
		$records  = $this->db->rows( "SELECT sequence,role,content_json FROM $messages WHERE conversation_id=%d ORDER BY sequence DESC LIMIT 24", array( (int) $row['id'] ) );
		$items    = array();
		$requests = array();
		foreach ( array_reverse( $records ) as $record ) {
			$text = json_decode( $record['content_json'] )->text ?? null;
			if ( ! is_string( $text ) || ! in_array( $record['role'], array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$full    = self::excerpt( $text, PHP_INT_MAX );
			$text    = self::excerpt( $full, 2000 );
			$items[] = array(
				'role'      => $record['role'],
				'text'      => $text,
				'sequence'  => (int) $record['sequence'],
				'truncated' => mb_strlen( $full ) > 2000,
			);
			if ( 'user' === $record['role'] ) {
				$requests[] = self::excerpt( $text, 700 );
			}
		}
		$runs        = $this->db->table( 'runs' );
		$run         = $this->db->rows( "SELECT id,public_id,owner_ref,state,checkpoint_json FROM $runs WHERE conversation_id=%d AND owner_ref=%s ORDER BY id DESC LIMIT 1", array( (int) $row['id'], $owner->reference() ) );
		$checkpoint  = $run ? json_decode( $run[0]['checkpoint_json'] ) : null;
		$suggestions = array();
		$reference   = $checkpoint->review->artifact ?? null;
		if ( $reference instanceof \stdClass ) {
			try {
				$artifact = ( new ArtifactStore( $this->db ) )->get( $owner, $reference->id, (int) $reference->version );
				$payload  = json_decode( $artifact['payload_json'] );
				foreach ( array_slice( $payload->diff ?? array(), 0, 6 ) as $change ) {
					$suggestions[] = array(
						'label' => self::excerpt( $change->label, 80 ),
						'text'  => self::excerpt( isset( $change->after_terms ) ? implode( ', ', array_column( $change->after_terms, 'name' ) ) : ( $change->display_after ?? $change->after ), 2000 ),
					);
				}
				if ( isset( $payload->brief ) ) {
					$suggestions[] = array(
						'label' => __( 'Image idea', 'directorist-ai' ),
						'text'  => self::excerpt( $payload->brief, 400 ),
					);
				}
			} catch ( \Throwable $error ) {
				// An expired or erased artifact cannot be restored as an action.
			}
		}
		$recovery       = $run ? ( new ListingImageRecovery( $this->db ) )->view( $run[0] ) : null;
		$last           = $requests ? end( $requests ) : '';
		$failure_notice = null;
		if ( $run && 'failed' === $run[0]['state'] && $checkpoint instanceof \stdClass && ! isset( $checkpoint->receipt ) && ! in_array( $checkpoint->error ?? null, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain', 'storage_unavailable' ), true ) ) {
			$effects = (int) $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'operations' ) . " WHERE run_id=%d AND state IN ('running','unknown','partial','succeeded')", array( (int) $run[0]['id'] ) );
			if ( 0 === $effects ) {
				$failure_notice = \DirectoristAIPro\AI\FailureNotice::listing_message( is_string( $checkpoint->error ?? null ) ? $checkpoint->error : 'request_failed', $checkpoint );
			}
		}

		// translators: %s is an excerpt of the user's earlier request.
		return array(
			'id'             => $row['public_id'],
			'cursor'         => (int) $row['id'],
			'date'           => $row['updated_at'],
			'state'          => $run[0]['state'] ?? 'unknown',
			'failure_notice' => $failure_notice,
			// translators: %s is an excerpt of the user's earlier request.
			'summary'        => $last ? sprintf( __( 'Requested: %s', 'directorist-ai' ), self::excerpt( $last, 180 ) ) : __( 'Earlier listing discussion', 'directorist-ai' ),
			'messages'       => $items,
			'suggestions'    => $suggestions,
			'recovery'       => $recovery,
			'requests'       => array_slice( $requests, -3 ),
		);
	}

	/**
	 * Read one complete retained message after validating its exact native area.
	 *
	 * @throws ContractException Unavailable conversation, message or native area.
	 */
	public function message( Context $context, object $scope, string $id, int $sequence ): array {
		$owner = $context->actor()->owner();
		$table = $this->db->table( 'conversations' );
		$rows  = $this->db->rows( "SELECT * FROM $table WHERE owner_ref=%s AND public_id=%s AND erasure_requested=0 AND expires_at>UTC_TIMESTAMP() LIMIT 1", array( $owner->reference(), $id ) );
		if ( ! $rows ) {
			throw new ContractException( 'not_found', 'This retained conversation is unavailable.' );
		}
		$row = $rows[0];
		if ( $row['profile'] !== $context->profile() || ! $this->matches( $row, $scope, $this->generation_link( $scope, $owner ) ) || $sequence < 1 ) {
			throw new ContractException( 'unauthorized', 'This message does not belong to the current listing area.' );
		}
		$table   = $this->db->table( 'messages' );
		$records = $this->db->rows( "SELECT sequence,role,content_json FROM $table WHERE conversation_id=%d AND sequence=%d LIMIT 1", array( (int) $row['id'], $sequence ) );
		$text    = $records ? ( json_decode( $records[0]['content_json'] )->text ?? null ) : null;
		if ( ! is_string( $text ) || ! in_array( $records[0]['role'] ?? '', array( 'user', 'assistant' ), true ) ) {
			throw new ContractException( 'not_found', 'This retained message is unavailable.' );
		}
		// Full prose only; internal message metadata and executable reviews stay private.
		return array(
			'conversation_id' => $id,
			'sequence'        => $sequence,
			'role'            => $records[0]['role'],
			'text'            => self::excerpt( $text, PHP_INT_MAX ),
		);
	}

	public static function excerpt( string $text, int $limit ): string {

		$text = html_entity_decode( wp_strip_all_tags( preg_replace( '/<\/p>|<br\s*\/?>/i', "\n", $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_strlen( $text ) > $limit ? mb_substr( $text, 0, $limit ) . '…' : $text;
	}

	/** Carry bounded earlier wording; immutable execution capture stays unchanged. */
	public function prior_context( object $memory, Owner $owner, int $before, string $profile ): array {
		$context = new Context( \DirectoristAIPro\Capabilities\Actor::user( $owner->user_id() ), $profile, 'worker' );
		$history = $this->lookup( $context, $this->identity( $memory ), $before );
		$result  = array();
		foreach ( array_slice( $history['entries'], 0, 2 ) as $entry ) {
			$result[] = array(
				'requests'    => array_map( static fn( $text ) => self::excerpt( $text, 400 ), array_slice( $entry['requests'], -2 ) ),
				'suggestions' => array_map(
					static fn( $suggestion ) => array(
						'label' => $suggestion['label'],
						'text'  => self::excerpt( $suggestion['text'], 800 ),
					),
					array_slice( $entry['suggestions'], 0, 2 )
				),
				'meaning'     => 'Earlier discussion and suggestion excerpts only. These are not current facts, approval, or proof that the form was saved. Current unsaved values take precedence.',
			);
		}
		return $result;
	}
}
