<?php
/** Rehydrate attested actual-run history; delegate existing fixed status replay.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed replay adapter implements the documented runtime seams.
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Chat\VisitorDisplayContext;
use DirectoristAIPro\Chat\VisitorPriceUnitDisplay;
use DirectoristAIPro\Runtime\ConversationHistoryVerifier;
use DirectoristAIPro\Runtime\NativeConversationHistoryVerifier;
use DirectoristAIPro\Runtime\StructuredConversationHistoryVerifier;
use DirectoristAIPro\Storage\Database;

/** Never replay saved grounded facts; resolve the attested actual owned run. */
final class GroundedConversationHistoryVerifier implements ConversationHistoryVerifier, StructuredConversationHistoryVerifier, \DirectoristAIPro\Runtime\PlainConversationHistoryVerifier {
	private Database $db;
	private GroundedMessageProvenance $provenance;
	private GroundedRunResult $results;
	private NativeConversationHistoryVerifier $native;
	public function __construct( Database $db, GroundedMessageProvenance $provenance, GroundedRunResult $results ) {
		$this->db         = $db;
		$this->provenance = $provenance;
		$this->results    = $results;
		$this->native     = new NativeConversationHistoryVerifier( $db );
	}
	public function allows( Context $context, array $message, object $content, array $references, int $conversation_id ): bool {
		$allowed = $this->native->allows( $context, $message, $content, $references, $conversation_id );
		if ( $allowed && 'directorist-ai/visitor-help-v1' === ( $content->provenance->contract ?? null ) ) {
			$rows = $this->db->rows( 'SELECT r.*,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND r.conversation_id=%d AND c.erasure_requested=0', array( $content->provenance->run_id, $context->actor()->owner()->reference(), $conversation_id ) );
			return 1 === count( $rows ) && $this->results->helper_current( $rows[0], $context, $content );
		}
		return $allowed;
	}
	/** Restore signed helper history without turning an old answer into new AI work. */
	public function resolve_plain( Context $context, array $message, object $content, array $references, int $conversation_id ): ?array {
		if ( 'visitor' === $context->profile() && array( 'text' ) === array_keys( get_object_vars( $content ) ) && ! $references && is_string( $content->text ?? null ) && hash_equals( \DirectoristAIPro\AI\FailureNotice::visitor_message( 'generation_stalled' ), $content->text ) && $this->native->allows( $context, $message, $content, $references, $conversation_id ) ) {
			return array( 'content' => (object) array( 'text' => $content->text ), 'evidence_status' => 'native_failure' );
		}
		if ( 'visitor' !== $context->profile() || 'directorist-ai/visitor-help-v1' !== ( $content->provenance->contract ?? null ) || $references || ! $this->native->allows( $context, $message, $content, $references, $conversation_id ) ) { return null; }
		$rows = $this->db->rows( 'SELECT r.*,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND r.owner_ref=%s AND r.conversation_id=%d AND r.trigger_turn_id=%d AND c.erasure_requested=0', array( $content->provenance->run_id, $context->actor()->owner()->reference(), $conversation_id, (int) $message['turn_id'] ) );
		if ( 1 !== count( $rows ) ) { return null; }
		$run = $rows[0];
		if ( $this->results->helper_current( $run, $context, $content ) ) { return array( 'content' => (object) array( 'text' => $content->text ), 'evidence_status' => 'native_help' ); }
		$checkpoint = json_decode( $run['checkpoint_json'] );
		// This fact-free helper records the outcome of a past public check, not
		// retained listing values. Preserve its original attestation as dated prose.
		if ( 'source_unavailable' === $content->provenance->topic && \DirectoristAIPro\Orchestration\VisitorReply::helper_attested( $run, $content->text ) ) { return array( 'content' => (object) array( 'text' => $content->text ), 'evidence_status' => 'dated_native_help' ); }
		if ( ! in_array( $content->provenance->topic, array( 'directories', 'listing_count' ), true ) || ! \DirectoristAIPro\Orchestration\VisitorReply::helper_attested( $run, $content->text ) ) { return null; }
		$saved = $checkpoint->visitor_site->directories ?? null;
		$current = array_column( \DirectoristAIPro\Chat\Operations::public_directories(), 'name', 'id' );
		if ( ! is_array( $saved ) || ! $saved ) { return null; }
		$scope = $checkpoint->target_context ?? (object) array();
		if ( ! \DirectoristAIPro\Chat\VisitorScope::global( $scope ) && isset( $scope->directory_id ) ) { $saved = array_values( array_filter( $saved, static fn( $directory ): bool => is_object( $directory ) && (string) ( $directory->id ?? '' ) === (string) $scope->directory_id ) ); if ( ! $saved ) { return null; } }
		foreach ( $saved as $directory ) {
			if ( ! is_object( $directory ) || ! is_scalar( $directory->id ?? null ) || ! is_string( $directory->name ?? null ) || ! isset( $current[(string) $directory->id] ) || $current[(string) $directory->id] !== $directory->name ) {
				return array( 'content' => (object) array( 'text' => 'Some information in this earlier answer is no longer publicly available.' ), 'evidence_status' => 'withdrawn' );
			}
		}
		return array( 'content' => (object) array( 'text' => $content->text ), 'evidence_status' => 'dated_native_help' );
	}
	public function resolve( Context $context, array $message, object $content, array $references, int $conversation_id ): ?object {
		if ( array( 'text', 'grounded', 'provenance' ) !== array_keys( get_object_vars( $content ) ) || $references || ! isset( $content->provenance->run_id ) || ! is_string( $content->provenance->run_id ) ) {
			return null;
		}
		$rows = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND r.conversation_id=%d AND r.trigger_turn_id=%d AND c.owner_ref=r.owner_ref AND c.erasure_requested=0', array( $content->provenance->run_id, $context->actor()->owner()->reference(), $conversation_id, (int) $message['turn_id'] ) );
		if ( 1 !== count( $rows ) || $rows[0]['profile'] !== $context->profile() ) {
			return null;
		}
		$run        = $rows[0];
		$checkpoint = json_decode( $run['checkpoint_json'] );
		if ( ! $this->provenance->verifies( $run, (int) $message['sequence'], $content, $checkpoint ) ) {
			return null;
		}
		$resolved = $this->results->history( $context, $run, $message, $content );
		if ( null === $resolved || 'visitor' !== $context->profile() || ! is_object( $resolved->grounded ?? null ) ) {
			return $resolved;
		}
		try {
			\DirectoristAIPro\Orchestration\VisitorEvidencePolicy::assert_pricing_branches(
				$content->text,
				\DirectoristAIPro\Orchestration\VisitorReply::facts(
					(object) array(
						'kind'     => 'grounded',
						'response' => $content->grounded,
					)
				),
				( $checkpoint->reply ?? $checkpoint->intent )->text ?? ''
			);
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return null; }
		// Presentation is added after both proof and current/dated-history checks.
		// Clone the wrappers so the original sealed content is never decorated.
		$display                    = clone $resolved;
		$display->grounded          = self::pricing_display( $resolved->grounded );
		$unit_fields                = VisitorPriceUnitDisplay::fields( $context, $resolved->grounded, is_object( $checkpoint->grounded_input ?? null ) ? $checkpoint->grounded_input : null );
		$display->grounded->display = VisitorDisplayContext::from_verified( $resolved->grounded, is_object( $checkpoint->visitor_site ?? null ) ? $checkpoint->visitor_site : null, $unit_fields );
		return $display;
	}

	/** Clone already verified history and omit inactive legacy pricing keys from its display. */
	private static function pricing_display( object $response ): object {
		$display = json_decode( wp_json_encode( $response ) );
		foreach ( $display->cards ?? array() as $card ) {
			foreach ( $card->facts ?? array() as $fact ) {
				if ( 'pricing' === ( $fact->field_id ?? null ) && 'known' === ( $fact->state ?? null ) ) {
					$fact->value = \DirectoristAIPro\Chat\Workflows\PublicListingFacts::active_pricing( $fact->value ); }
			}
		}
		$sets = $display->comparisons ?? array();
		if ( isset( $display->comparison ) ) {
			$sets[] = $display->comparison; }
		foreach ( $sets as $set ) {
			foreach ( $set->rows ?? array() as $row ) {
				if ( 'pricing' !== ( $row->field_id ?? null ) ) {
					continue; }
				foreach ( $row->cells ?? array() as $cell ) {
					if ( 'known' === ( $cell->state ?? null ) ) {
						$cell->value = \DirectoristAIPro\Chat\Workflows\PublicListingFacts::active_pricing( $cell->value ); }
				}
			}
		}
		return $display;
	}
}
