<?php
/**
 * Site-bound signed guest identities; quota enforcement is a separate service.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

use DirectoristAIPro\Validation\ContractException;

/** Verifies credentials before deriving an anonymous storage owner. */
final class GuestSessions {
	/**
	 * WordPress-owned signing secret.
	 *
	 * @var string
	 */
	private string $secret;
	/**
	 * Blog binding prevents token reuse across sites.
	 *
	 * @var int
	 */
	private int $site_id;

	/**
	 * Bind to native WordPress identity configuration.
	 *
	 * @param string $secret Server-side signing secret.
	 * @param int    $site_id Current site ID.
	 * @throws \InvalidArgumentException Weak or missing configuration.
	 */
	public function __construct( string $secret, int $site_id ) {
		if ( strlen( $secret ) < 32 || $site_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid guest identity configuration.' );
		}
		$this->secret  = $secret;
		$this->site_id = $site_id;
	}

	/**
	 * Issue a token only from a policy-authorized session creation surface.
	 *
	 * @param int $lifetime Lifetime in seconds, no more than seven days.
	 * @return string Bearer token; never log or persist as a chat/artifact field.
	 * @throws ContractException Invalid expiry policy.
	 */
	public function issue( int $lifetime = 604800 ): string {
		if ( $lifetime < 1 || $lifetime > 604800 ) {
			throw new ContractException( 'invalid_input', 'Invalid session lifetime.' );
		}
		$body = bin2hex( random_bytes( 32 ) ) . '.' . ( time() + $lifetime );
		return $body . '.' . $this->signature( $body );
	}

	/**
	 * Authenticate a site-bound unexpired token and return its limited actor.
	 *
	 * @param string $token Cookie or trusted session transport credential.
	 * @return Actor Verified guest.
	 * @throws ContractException Invalid signature, shape, site or expiry.
	 */
	public function resolve( string $token ): Actor {
		if ( ! preg_match( '/^([a-f0-9]{64})\.([0-9]{1,12})\.([a-f0-9]{64})$/D', $token, $parts ) ) {
			throw new ContractException( 'unauthorized', 'Guest session is unavailable.' );
		}
		$body = $parts[1] . '.' . $parts[2];
		if ( ! hash_equals( $this->signature( $body ), $parts[3] ) || (int) $parts[2] <= time() || (int) $parts[2] > time() + 604800 ) {
			throw new ContractException( 'unauthorized', 'Guest session is unavailable.' );
		}
		return Actor::verified_guest( hash( 'sha256', $parts[1] ), (int) $parts[2] );
	}

	/**
	 * Sign an exact site-bound token body.
	 *
	 * @param string $body Token body.
	 * @return string HMAC digest.
	 */
	private function signature( string $body ): string {
		return hash_hmac( 'sha256', 'directorist-ai-guest|' . $this->site_id . '|' . $body, $this->secret );
	}
}
