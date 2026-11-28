<?php
/**
 * Storage ownership identity; it does not grant native capabilities.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

/** A server-resolved user or verified guest identity. */
final class Owner {
	/**
	 * Canonical ownership reference.
	 *
	 * @var string
	 */
	private string $reference;

	/**
	 * Prevent construction from an arbitrary wire payload.
	 *
	 * @param string $reference Validated internal reference.
	 */
	private function __construct( string $reference ) {
		$this->reference = $reference;
	}

	/**
	 * Build an identity for a server-resolved WordPress user.
	 *
	 * @param int $user_id Actual principal ID.
	 * @return self
	 * @throws StorageException Invalid identity.
	 */
	public static function user( int $user_id ): self {
		if ( $user_id < 1 ) {
			throw new StorageException( 'Invalid ownership identity.' );
		}
		return new self( 'user:' . $user_id );
	}

	/**
	 * Build an identity from a verified guest session digest.
	 *
	 * @param string $session_hash Server-verified SHA-256 session digest.
	 * @return self
	 * @throws StorageException Invalid identity.
	 */
	public static function guest( string $session_hash ): self {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $session_hash ) ) {
			throw new StorageException( 'Invalid guest ownership identity.' );
		}
		return new self( 'guest:' . $session_hash );
	}

	/**
	 * Return the indexed ownership reference.
	 *
	 * @return string
	 */
	public function reference(): string {
		return $this->reference;
	}

	/**
	 * Return the native user ID, if authenticated.
	 *
	 * @return int|null
	 */
	public function user_id(): ?int {
		return 0 === strpos( $this->reference, 'user:' ) ? (int) substr( $this->reference, 5 ) : null;
	}

	/**
	 * Return the verified guest digest, if anonymous.
	 *
	 * @return string|null
	 */
	public function guest_hash(): ?string {
		return 0 === strpos( $this->reference, 'guest:' ) ? substr( $this->reference, 6 ) : null;
	}
}
