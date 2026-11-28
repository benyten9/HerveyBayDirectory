<?php
/**
 * Owner/run-bound event replay cursors.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Validation\ContractException;

/** A cursor is pagination state, never proof of access. */
final class EventCursor {
	/**
	 * Site secret used only for signing opaque cursor state.
	 *
	 * @var string
	 */
	private string $secret;
	/**
	 * Server-time source, injectable only for deterministic tests.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Bind the server secret.
	 *
	 * @param string        $secret Site-owned signing secret.
	 * @param callable|null $clock Optional server-time source.
	 * @throws \InvalidArgumentException Weak secret.
	 */
	public function __construct( string $secret, ?callable $clock = null ) {
		if ( strlen( $secret ) < 32 ) {
			throw new \InvalidArgumentException( 'Invalid cursor signing configuration.' );
		}
		$this->secret = $secret;
		$this->clock  = null === $clock ? static fn(): int => time() : \Closure::fromCallable( $clock );
	}

	/**
	 * Encode the next position for this exact owner and run.
	 *
	 * @param Owner  $owner Actual owner.
	 * @param string $run_id Opaque run identity.
	 * @param int    $sequence Last delivered sequence.
	 * @param int    $lifetime Cursor lifetime in seconds.
	 * @return string Signed opaque state.
	 * @throws ContractException Invalid bounds.
	 */
	public function encode( Owner $owner, string $run_id, int $sequence, int $lifetime = 604800 ): string {
		if ( $sequence < 0 || $lifetime < 1 || $lifetime > 604800 ) {
			throw new ContractException( 'invalid_input', 'Invalid event cursor bounds.' );
		}
		$payload = array(
			'owner' => hash( 'sha256', $owner->reference() ),
			'run'   => $run_id,
			'seq'   => $sequence,
			'exp'   => ( $this->clock )() + $lifetime,
		);
		$json    = wp_json_encode( $payload );
		$body    = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return $body . '.' . hash_hmac( 'sha256', $body, $this->secret );
	}

	/**
	 * Verify and return the stored position for an already authorized read.
	 *
	 * @param Owner  $owner Actual owner.
	 * @param string $run_id Requested run.
	 * @param string $cursor Supplied cursor.
	 * @return int Last delivered sequence.
	 * @throws ContractException Invalid, expired or differently scoped cursor.
	 */
	public function decode( Owner $owner, string $run_id, string $cursor ): int {
		if ( ! preg_match( '/^([A-Za-z0-9_-]{1,1024})\.([a-f0-9]{64})$/D', $cursor, $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[1], $this->secret ), $parts[2] ) ) {
			throw new ContractException( 'invalid_input', 'Event cursor is unavailable.' );
		}
		$padding = ( 4 - strlen( $parts[1] ) % 4 ) % 4;
		$json    = base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', $padding ), true );
		$data    = is_string( $json ) ? json_decode( $json, true ) : null;
		$now     = ( $this->clock )();
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'owner', 'run', 'seq', 'exp' ) || ! is_string( $data['owner'] ) || ! hash_equals( hash( 'sha256', $owner->reference() ), $data['owner'] ) || ! is_string( $data['run'] ) || ! hash_equals( $run_id, $data['run'] ) || ! is_int( $data['seq'] ) || $data['seq'] < 0 || ! is_int( $data['exp'] ) || $data['exp'] <= $now || $data['exp'] > $now + 604800 ) {
			throw new ContractException( 'invalid_input', 'Event cursor is unavailable.' );
		}
		return $data['seq'];
	}
}
