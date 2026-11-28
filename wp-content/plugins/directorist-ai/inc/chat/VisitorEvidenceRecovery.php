<?php
/** Read-only answer freshness policy; no model, storage or native effect authority. */
namespace DirectoristAIPro\Chat;

/** A refreshed fact packet stays inside its accepted visitor request. */
final class VisitorEvidenceRecovery {
	public const CONTRACT = 'directorist-ai/visitor-delivery-v1';
	/** Churn/no-progress safety, not a credit or model allowance. */
	public const MAX_REFRESHES = 3;

	/** Compare precisely the facts supplied to the writer, not token/clock metadata. */
	public static function semantic_hash( object $delivery ): string {
		$facts = $delivery->facts ?? \DirectoristAIPro\Orchestration\VisitorReply::facts( $delivery );
		return hash( 'sha256', wp_json_encode( array( $delivery->kind, $delivery->topic ?? null, $facts ), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES ) );
	}

	/** A linked attempt must never enter an operation/effect/uncertain continuation. */
	public static function read_only( object $checkpoint ): bool {
		if ( isset( $checkpoint->managed_pending ) || isset( $checkpoint->managed_image_pending ) || isset( $checkpoint->operation ) || isset( $checkpoint->approval ) || isset( $checkpoint->source_free ) ) {
			return false; }
		$delivery = $checkpoint->visitor_delivery ?? null;
		if ( isset( $delivery->input ) && in_array( $delivery->input->intent ?? null, array( 'action', 'workflow' ), true ) ) {
			return false; }
		$input = $checkpoint->grounded_input ?? $delivery->input ?? null;
		if ( is_object( $input ) && ( null !== ( $input->action ?? null ) || in_array( $input->intent ?? null, array( 'action', 'workflow' ), true ) ) ) {
			return false; }
		return 'action' !== ( $delivery->response->task->kind ?? null ) && 'action' !== ( $checkpoint->visitor_reply->task_kind ?? null );
	}

	/** Only newly marked, unfinished delivery may recover after its terminal commit. */
	public static function eligible( array $run, object $checkpoint ): bool {
		return 'succeeded' === ( $run['state'] ?? null ) && ! (int) ( $run['cancel_requested'] ?? 0 ) && self::CONTRACT === ( $checkpoint->visitor_delivery_contract ?? null ) && isset( $checkpoint->visitor_reply ) && self::read_only( $checkpoint );
	}
	/** Bind an exact server-validated recovered question without retaining its prose. */
	public static function question_stamp( array $run, int $message_id, int $sequence, int $turn_id, string $content_json, ?int $completed_turn = null ): object {
		$stamp = (object) array( 'message_id' => $message_id, 'sequence' => $sequence, 'turn_id' => $turn_id, 'content_hash' => hash( 'sha256', $content_json ), 'completed_turn' => $completed_turn );
		$stamp->signature = self::question_signature( $run, $stamp );
		return $stamp;
	}

	/** Completed questions allow a neutral note only, never old factual prose. */
	public static function completed_question( array $run, array $message, object $stamp ): bool {
		return 'visitor' === ( $run['profile'] ?? null ) && 'succeeded' === ( $run['state'] ?? null )
			&& array( 'message_id', 'sequence', 'turn_id', 'content_hash', 'completed_turn', 'signature' ) === array_keys( get_object_vars( $stamp ) )
			&& is_int( $stamp->completed_turn ) && $stamp->completed_turn > $stamp->turn_id
			&& (int) $message['id'] === $stamp->message_id && (int) $message['sequence'] === $stamp->sequence && (int) $message['turn_id'] === $stamp->turn_id
			&& hash_equals( $stamp->content_hash, hash( 'sha256', $message['content_json'] ) )
			&& is_string( $stamp->signature ) && hash_equals( self::question_signature( $run, $stamp ), $stamp->signature );
	}

	private static function question_signature( array $run, object $stamp ): string {
		$copy = clone $stamp; unset( $copy->signature );
		return hash_hmac( 'sha256', wp_json_encode( array( get_current_blog_id(), $run['owner_ref'], $run['public_id'], (int) $run['conversation_id'], $run['conversation_public_id'], $copy ), JSON_UNESCAPED_SLASHES ), wp_salt( 'auth' ) . '|visitor-recovery-question-v1' );
	}

}
