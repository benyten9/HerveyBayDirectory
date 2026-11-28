<?php
/** Durable provenance for an isolated, server-accepted clarification question.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

/** Seal the exact assistant question at its committed conversation position. */
final class ClarificationMessageProvenance {
	private const CONTRACT = 'directorist-ai/clarification-message-v1';

	/**
	 * Sign only the trusted producer's isolated clarification handoff.
	 *
	 * @param array  $run Actual locked run and conversation identity.
	 * @param int    $sequence Allocated message sequence.
	 * @param string $question Validated ability output.
	 * @return object Closed attestation.
	 */
	public function seal( array $run, int $sequence, string $question ): object {
		return (object) array(
			'contract'      => self::CONTRACT,
			'run_id'        => $run['public_id'],
			'question_hash' => hash( 'sha256', $question ),
			'signature'     => $this->signature( $run, $sequence, $question ),
		);
	}

	/**
	 * Verify a saved question against the owned run and exact message position.
	 *
	 * @param array  $run Actual owned run and conversation identity.
	 * @param int    $sequence Stored message sequence.
	 * @param object $content Stored content with attestation.
	 * @return bool Whether this exact message was produced by the trusted path.
	 */
	public function verifies( array $run, int $sequence, object $content ): bool {
		$proof = $content->provenance ?? null;
		if ( ! $proof instanceof \stdClass || array( 'contract', 'run_id', 'question_hash', 'signature' ) !== array_keys( get_object_vars( $proof ) ) || self::CONTRACT !== $proof->contract || $run['public_id'] !== $proof->run_id || ! is_string( $proof->question_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $proof->question_hash ) || ! is_string( $proof->signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $proof->signature ) || ! isset( $content->text ) || ! is_string( $content->text ) || '' === trim( $content->text ) || strlen( $content->text ) > 2000 ) {
			return false;
		}
		return hash_equals( hash( 'sha256', $content->text ), $proof->question_hash ) && hash_equals( $this->signature( $run, $sequence, $content->text ), $proof->signature );
	}

	/**
	 * Bind the question to site, owner, conversation, run, original turn and row.
	 *
	 * @param array  $run Actual owned run and conversation identity.
	 * @param int    $sequence Stored message sequence.
	 * @param string $question Exact validated question.
	 * @return string Private message signature.
	 */
	private function signature( array $run, int $sequence, string $question ): string {
		$binding = array(
			'site'                   => get_current_blog_id(),
			'owner'                  => $run['owner_ref'],
			'conversation'           => (int) $run['conversation_id'],
			'conversation_public_id' => $run['conversation_public_id'],
			'profile'                => $run['profile'],
			'run'                    => $run['public_id'],
			'turn'                   => (int) $run['trigger_turn_id'],
			'sequence'               => $sequence,
			'ability'                => 'conversation.clarify@1',
			'question'               => $question,
		);
		return hash_hmac( 'sha256', wp_json_encode( $binding, JSON_UNESCAPED_SLASHES ), wp_salt( 'auth' ) . '|clarification-message-v1' );
	}
}
