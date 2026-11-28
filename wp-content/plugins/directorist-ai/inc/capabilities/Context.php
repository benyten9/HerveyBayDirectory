<?php
/**
 * Immutable request authority, separate from untrusted operation input.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

use DirectoristAIPro\Validation\ContractException;

/** Server construction binds principal, profile, transport and site. */
final class Context {
	/**
	 * Actual authenticated/verified principal.
	 *
	 * @var Actor
	 */
	private Actor $actor;
	/**
	 * Tool-surface restriction, never a privilege grant.
	 *
	 * @var string
	 */
	private string $profile;
	/**
	 * Request origin for policy/receipts.
	 *
	 * @var string
	 */
	private string $transport;
	/**
	 * Site at construction, checked before dispatch.
	 *
	 * @var int
	 */
	private int $site_id;
	/**
	 * Bound conversation identity for server-owned workflow effects.
	 *
	 * @var string|null
	 */
	private ?string $conversation_id;
	/**
	 * Bound run identity for server-owned workflow effects.
	 *
	 * @var string|null
	 */
	private ?string $run_id;
	/**
	 * Additional server-owned fence for one normalized initial factory call.
	 *
	 * @var object|null
	 */
	private ?object $builder_factory_authority = null;

	/**
	 * Build only from a server-resolved principal and trusted route/run state.
	 *
	 * @param Actor       $actor Authenticated/verified principal.
	 * @param string      $profile Bound conversation/route profile.
	 * @param string      $transport Actual transport.
	 * @param string|null $conversation_id Bound workflow conversation.
	 * @param string|null $run_id Bound workflow run.
	 * @throws ContractException Unsupported or privilege-expanding context.
	 */
	public function __construct( Actor $actor, string $profile, string $transport, ?string $conversation_id = null, ?string $run_id = null ) {
		if ( ! in_array( $profile, array( 'admin', 'owner', 'visitor', 'builder', 'external' ), true ) || ! in_array( $transport, array( 'ui', 'worker', 'ability', 'mcp' ), true ) || ( $actor->is_guest() && ( 'visitor' !== $profile || in_array( $transport, array( 'ability', 'mcp' ), true ) ) ) ) {
			throw new ContractException( 'unauthorized', 'Requested context is unavailable.' );
		}
		if ( ( null === $conversation_id ) !== ( null === $run_id ) || ( null !== $conversation_id && ( ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $conversation_id ) || ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $run_id ) ) ) ) {
			throw new ContractException( 'unauthorized', 'Workflow identity is unavailable.' );
		}
		$this->actor           = $actor;
		$this->profile         = $profile;
		$this->transport       = $transport;
		$this->site_id         = get_current_blog_id();
		$this->conversation_id = $conversation_id;
		$this->run_id          = $run_id;
	}

	/**
	 * Resolve an HTTP/native-ability context without trusting actor input.
	 *
	 * @param string      $profile Bound profile.
	 * @param string      $transport Trusted transport label.
	 * @param string|null $guest_token Verified through the native signing secret.
	 * @return self
	 */
	public static function current( string $profile, string $transport, ?string $guest_token = null ): self {
		$id    = get_current_user_id();
		$actor = $id ? Actor::user( $id ) : ( new GuestSessions( wp_salt( 'auth' ), get_current_blog_id() ) )->resolve( $guest_token ?? '' );
		return new self( $actor, $profile, $transport );
	}

	/**
	 * Return the authenticated actor.
	 *
	 * @return Actor
	 */
	public function actor(): Actor {
		return $this->actor;
	}

	/**
	 * Return the tool profile.
	 *
	 * @return string
	 */
	public function profile(): string {
		return $this->profile;
	}

	/**
	 * Return the real transport.
	 *
	 * @return string
	 */
	public function transport(): string {
		return $this->transport;
	}

	/** Return the server-bound conversation identity, when present. */
	public function conversation_id(): ?string {
		return $this->conversation_id;
	}

	/** Return the server-bound run identity, when present. */
	public function run_id(): ?string {
		return $this->run_id;
	}

	/**
	 * Add an immutable extra fence without granting any native capability.
	 *
	 * @param object $authority Server-stored admitted factory authority, never operation input.
	 * @return self Detached context for only the normalized factory proposal.
	 * @throws ContractException The server context cannot carry a factory fence.
	 */
	public function for_builder_factory( object $authority ): self {
		$this->assert_execution_identity();
		if ( 'builder' !== $this->profile || 'worker' !== $this->transport || $this->actor->is_guest() || null === $this->conversation_id || null === $this->run_id ) {
			throw new ContractException( 'unauthorized', 'An initial factory fence requires the current authenticated builder worker.' );
		}
		( new \DirectoristAIPro\Validation\JsonDecoder( 65536, 24 ) )->check( $authority );
		$copy                            = clone $this;
		$copy->builder_factory_authority = json_decode( json_encode( $authority, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 25, JSON_THROW_ON_ERROR );
		return $copy;
	}

	/**
	 * Return only a detached additional fence; it is never native permission.
	 *
	 * @return object|null Server-owned factory expectation, absent for ordinary calls.
	 */
	public function builder_factory_authority(): ?object {
		return null === $this->builder_factory_authority ? null : json_decode( json_encode( $this->builder_factory_authority, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 25, JSON_THROW_ON_ERROR );
	}

	/**
	 * Reject reuse after switch_to_blog or user deletion.
	 *
	 * @return void
	 * @throws ContractException Stale identity/site.
	 */
	public function assert_current(): void {
		if ( get_current_blog_id() !== $this->site_id || ! $this->actor->valid() ) {
			throw new ContractException( 'unauthorized', 'Request identity or site has changed.' );
		}
	}
	/**
	 * Detect native helpers leaking a different global execution identity.
	 *
	 * @return void
	 * @throws ContractException Changed execution identity.
	 */
	public function assert_execution_identity(): void {
		$this->assert_current();
		if ( get_current_user_id() !== $this->actor->user_id() ) {
			throw new ContractException( 'unauthorized', 'Execution identity has changed.' );
		}
	}
}
