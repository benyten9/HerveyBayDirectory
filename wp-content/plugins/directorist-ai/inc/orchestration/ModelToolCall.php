<?php
/**
 * One content-channel function request returned by the model.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Validation\JsonDecoder;

/** A call remains untrusted until matched to the exact current allowlist. */
final class ModelToolCall {
	/**
	 * Server-only exact native factory observation.
	 *
	 * @var object|null
	 */
	private ?object $factory_authority = null;

	/**
	 * Original model-owned values, retained only for an unsuccessful native repair.
	 *
	 * @var object|null
	 */
	private ?object $factory_repair_candidate = null;

	/**
	 * Carry advisory values privately without changing the expanded native call.
	 *
	 * @param object|null $candidate Bounded original model values, never authority.
	 * @return self Detached call with the same public arguments and native fence.
	 */
	public function with_builder_factory_repair_candidate( ?object $candidate ): self {
		if ( null !== $candidate ) {
			( new JsonDecoder( 17408, 36 ) )->check( $candidate );
		}
		$copy                           = clone $this;
		$copy->factory_repair_candidate = null === $candidate ? null : json_decode( json_encode( $candidate, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 36, JSON_THROW_ON_ERROR );
		return $copy;
	}

	/**
	 * Return a detached advisory candidate; it is never serialized publicly.
	 *
	 * @return object|null Original values when safely retained by the transport.
	 */
	public function builder_factory_repair_candidate(): ?object {
		return null === $this->factory_repair_candidate ? null : json_decode( json_encode( $this->factory_repair_candidate, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 36, JSON_THROW_ON_ERROR );
	}

	/**
	 * Carry a server-created fence without adding model-owned operation fields.
	 *
	 * @param object $authority Exact bounded server-owned native observation.
	 * @return self Detached call retaining the exact private authority.
	 */
	public function with_builder_factory_authority( object $authority ): self {
		( new JsonDecoder( 65536, 32 ) )->check( $authority );
		$copy                    = clone $this;
		$copy->factory_authority = json_decode( json_encode( $authority, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
		return $copy;
	}

	/**
	 * Return a detached request-local fence, never model arguments.
	 *
	 * @return object|null Exact private observation, when one was attached.
	 */
	public function builder_factory_authority(): ?object {
		return null === $this->factory_authority ? null : json_decode( json_encode( $this->factory_authority, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
	}

	/**
	 * Provider call identity.
	 *
	 * @var string
	 */
	public string $id;
	/**
	 * Declared function name.
	 *
	 * @var string
	 */
	public string $name;
	/**
	 * Untrusted raw arguments.
	 *
	 * @var mixed
	 */
	public $arguments;

	/**
	 * Capture bounded untrusted call data.
	 *
	 * @param string $id Provider call identity.
	 * @param string $name Declared function name.
	 * @param mixed  $arguments Raw arguments.
	 */
	public function __construct( string $id, string $name, $arguments ) {
		( new JsonDecoder( 1048576, 32 ) )->check( $arguments );
		$this->id        = '' === $id ? hash( 'sha256', $name . wp_json_encode( $arguments ) ) : substr( $id, 0, 128 );
		$this->name      = substr( $name, 0, 128 );
		$this->arguments = $arguments;
	}
}
