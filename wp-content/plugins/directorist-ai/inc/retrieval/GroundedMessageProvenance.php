<?php
/** Exact server-produced message/plan attestation; never model provenance.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed producer boundary implements the documented runtime seam.
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Runtime\MessageProvenanceProducer;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Sign exact validated content only at its actual durable task position. */
final class GroundedMessageProvenance implements MessageProvenanceProducer {
	private Gateway $gateway;
	private ?Database $db;
	public function __construct( Gateway $gateway, ?Database $db = null ) {
		$this->gateway = $gateway;
		$this->db      = $db;
	}
	public function validate( object $content, object $checkpoint ): void {
		if ( ! isset( $content->grounded, $checkpoint->grounded_input, $checkpoint->grounded_hash ) || $content->text !== $content->grounded->message ) {
			throw new ContractException( 'invalid_output', 'Grounded provenance requires the exact producer result.' );
		}
		$this->gateway->validate_output( 'conversation.respond', $content->grounded );
		$this->gateway->validate_input( 'conversation.respond', wp_json_encode( $checkpoint->grounded_input ) );
		$intent = $checkpoint->reply ?? $checkpoint->intent;
		if ( ! hash_equals( $checkpoint->grounded_hash, $content->grounded->trace->delivered_hash ) || ! hash_equals( hash( 'sha256', $intent->text ), $content->grounded->trace->question_hash ) || $checkpoint->grounded_input->question !== $intent->text ) {
			throw new ContractException( 'invalid_output', 'Grounded provenance does not match its actual user task.' );
		}
	}
	public function seal( array $run, int $sequence, object $content, object $checkpoint ): object {
		$history = null === $this->db ? null : GroundedHistoryClock::capture( $this->gateway, $this->db, $run, $sequence, $content, $checkpoint );
		$seal    = (object) array(
			'contract'  => null === $history ? 'directorist-ai/grounded-message-v1' : 'directorist-ai/grounded-message-v2',
			'run_id'    => $run['public_id'],
			'signature' => self::signature( $run, $sequence, $content, $checkpoint, $history ),
		);
		if ( null !== $history ) {
			$seal->history_clock = $history; }
		return $seal;
	}
	public function verifies( array $run, int $sequence, object $content, object $checkpoint ): bool {
		$seal    = $content->provenance ?? null;
		$history = 'directorist-ai/grounded-message-v2' === ( $seal->contract ?? null );
		$keys    = $history ? array( 'contract', 'run_id', 'signature', 'history_clock' ) : array( 'contract', 'run_id', 'signature' );
		if ( ! $seal instanceof \stdClass || array_keys( get_object_vars( $seal ) ) !== $keys || ! in_array( $seal->contract, array( 'directorist-ai/grounded-message-v1', 'directorist-ai/grounded-message-v2' ), true ) || $run['public_id'] !== $seal->run_id || ! is_string( $seal->signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $seal->signature ) || ( $history && ! GroundedHistoryClock::verifies( $run, $sequence, $content ) ) ) {
			return false;
		}
		$this->validate( $content, $checkpoint );
		return hash_equals( self::signature( $run, $sequence, $content, $checkpoint, $history ? $seal->history_clock : null ), $seal->signature );
	}
	/** Verify only producer binding for recovery admission, never authorize old facts. */
	public static function attested( array $run, int $sequence, object $content, object $checkpoint ): bool {
		$seal = $content->provenance ?? null;
		$history = 'directorist-ai/grounded-message-v2' === ( $seal->contract ?? null );
		$keys = $history ? array( 'contract', 'run_id', 'signature', 'history_clock' ) : array( 'contract', 'run_id', 'signature' );
		if ( ! $seal instanceof \stdClass || array_keys( get_object_vars( $seal ) ) !== $keys || ! in_array( $seal->contract, array( 'directorist-ai/grounded-message-v1', 'directorist-ai/grounded-message-v2' ), true ) || $run['public_id'] !== $seal->run_id || ! is_string( $seal->signature ?? null ) || ! isset( $content->grounded, $checkpoint->grounded_input, $checkpoint->grounded_hash ) || ( $history && ! GroundedHistoryClock::verifies( $run, $sequence, $content ) ) ) { return false; }
		return hash_equals( self::signature( $run, $sequence, $content, $checkpoint, $history ? $seal->history_clock : null ), $seal->signature );
	}
	private static function signature( array $run, int $sequence, object $content, object $checkpoint, ?object $history = null ): string {
		$binding = array(
			'site'                   => get_current_blog_id(),
			'owner'                  => $run['owner_ref'],
			'conversation'           => (int) $run['conversation_id'],
			'conversation_public_id' => $run['conversation_public_id'],
			'profile'                => $run['profile'],
			'run'                    => $run['public_id'],
			'turn'                   => (int) $run['trigger_turn_id'],
			'sequence'               => $sequence,
			'content'                => (object) array(
				'text'     => $content->text,
				'grounded' => $content->grounded,
			),
			'plan'                   => $checkpoint->grounded_input,
			'evidence_hash'          => $checkpoint->grounded_hash,
		);
		if ( null !== $history ) {
			$binding['history_clock'] = $history; }
		return hash_hmac( 'sha256', wp_json_encode( $binding, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES ), wp_salt( 'auth' ) . '|grounded-message-v1' );
	}
}
