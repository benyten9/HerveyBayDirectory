<?php
/**
 * Server-resolved identity without cached role authority.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Validation\ContractException;

/** Identity comes from authentication, never the model's role/profile label. */
final class Actor {
	/**
	 * WordPress principal ID, or zero for a guest.
	 *
	 * @var int
	 */
	private int $user_id;
	/**
	 * Verified guest digest, never the bearer token itself.
	 *
	 * @var string|null
	 */
	private ?string $guest_hash;
	/**
	 * Guest authorization expiry, checked again before result delivery.
	 *
	 * @var int|null
	 */
	private ?int $guest_expiry;

	/**
	 * Create an internal identity.
	 *
	 * @param int         $user_id WordPress user identity.
	 * @param string|null $guest_hash Verified guest digest.
	 * @param int|null    $guest_expiry Verified token expiry.
	 */
	private function __construct( int $user_id, ?string $guest_hash, ?int $guest_expiry = null ) {
		$this->user_id      = $user_id;
		$this->guest_hash   = $guest_hash;
		$this->guest_expiry = $guest_expiry;
	}

	/**
	 * Resolve an existing user for an authenticated request or owned worker.
	 *
	 * @param int $user_id Server-resolved initiating user ID.
	 * @return self
	 * @throws ContractException Missing/removed principal.
	 */
	public static function user( int $user_id ): self {
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			throw new ContractException( 'unauthorized', 'Authenticated principal is unavailable.' );
		}
		return new self( $user_id, null );
	}

	/**
	 * Construct only after the guest-session verifier has authenticated a token.
	 *
	 * @param string $hash Verified digest.
	 * @param int    $expiry Verified token expiry.
	 * @return self
	 * @throws ContractException Invalid digest.
	 */
	public static function verified_guest( string $hash, int $expiry ): self {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || $expiry <= time() ) {
			throw new ContractException( 'unauthorized', 'Guest principal is unavailable.' );
		}
		return new self( 0, $hash, $expiry );
	}

	/**
	 * Check current native capabilities without a persisted role snapshot.
	 *
	 * @param string $capability Actual native capability.
	 * @param mixed  ...$targets Native meta-capability arguments.
	 * @return bool
	 */
	public function can( string $capability, ...$targets ): bool {
		$user = $this->user_id ? get_userdata( $this->user_id ) : false;
		return $user && user_can( $user, $capability, ...$targets );
	}

	/**
	 * Return current identity's storage scope; it confers no native permission.
	 *
	 * @return Owner
	 */
	public function owner(): Owner {
		return $this->user_id ? Owner::user( $this->user_id ) : Owner::guest( $this->guest_hash );
	}

	/**
	 * Return the initiating user ID.
	 *
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Whether this is a verified guest.
	 *
	 * @return bool
	 */
	public function is_guest(): bool {
		return 0 === $this->user_id;
	}
	/**
	 * Recheck identity validity without retaining a privilege snapshot.
	 *
	 * @return bool
	 */
	public function valid(): bool {
		return $this->is_guest() ? $this->guest_expiry > time() : false !== get_userdata( $this->user_id );
	}
	/**
	 * Invalidate request-local authority cached before another request changed it.
	 *
	 * @return void
	 */
	public function refresh(): void {
		if ( $this->user_id ) {
			clean_user_cache( $this->user_id );
		}
	}

	/**
	 * Return guest authentication expiry for a server-owned worker checkpoint.
	 *
	 * @return int|null
	 */
	public function authorization_expires_at(): ?int {
		return $this->guest_expiry;
	}
}
