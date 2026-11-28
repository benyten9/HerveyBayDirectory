<?php
/**
 * Trusted operation definition registered once by server code.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Capabilities;

use DirectoristAIPro\Validation\ContractException;

/** No callback, permission rule or effect class is accepted from model input. */
final class Operation {
	/**
	 * Immutable definition values.
	 *
	 * @var array
	 */
	private array $definition;
	/**
	 * Native/domain permission rule.
	 *
	 * @var \Closure
	 */
	private \Closure $permission;
	/**
	 * Discovery eligibility rule.
	 *
	 * @var \Closure
	 */
	private \Closure $discovery;
	/**
	 * Live dependency availability rule.
	 *
	 * @var \Closure
	 */
	private \Closure $availability;
	/**
	 * Trusted implementation.
	 *
	 * @var \Closure
	 */
	private \Closure $handler;

	/**
	 * Register reviewed semantics and trusted closures.
	 *
	 * @param array    $definition Closed server-owned metadata.
	 * @param callable $permission Native target permission check.
	 * @param callable $discovery Context-scoped discovery check.
	 * @param callable $availability Current dependency check.
	 * @param callable $handler Trusted implementation.
	 * @throws ContractException Invalid registration.
	 */
	public function __construct( array $definition, callable $permission, callable $discovery, callable $availability, callable $handler ) {
		$definition += array(
			'execution'     => 'sync',
			'retry_policy'  => 'never',
			'limits_policy' => 'baseline-v1',
		);
		$fields      = array( 'id', 'revision', 'description', 'input_schema', 'output_schema', 'effect', 'profiles', 'allow_guest', 'execution', 'retry_policy', 'limits_policy' );
		$keys        = array_keys( $definition );
		sort( $fields );
		sort( $keys );
		if ( $keys !== $fields || ! is_string( $definition['id'] ) || ! preg_match( '/^[a-z][a-z0-9]*\.[a-z][a-z0-9_]*$/D', $definition['id'] ) || strlen( $definition['id'] ) > 40 || ! is_string( $definition['revision'] ) || '' === $definition['revision'] || ! is_string( $definition['description'] ) || ! is_string( $definition['input_schema'] ) || ! is_string( $definition['output_schema'] ) || ! in_array( $definition['effect'], array( 'read', 'private', 'write' ), true ) || ! in_array( $definition['execution'], array( 'sync', 'background', 'editor' ), true ) || ! in_array( $definition['retry_policy'], array( 'never', 'read_backoff', 'reconcile' ), true ) || 'baseline-v1' !== $definition['limits_policy'] || ! is_array( $definition['profiles'] ) || ! $definition['profiles'] || ! is_bool( $definition['allow_guest'] ) || ( $definition['allow_guest'] && 'read' !== $definition['effect'] ) ) {
			throw new ContractException( 'unsupported_contract', 'Invalid operation definition.' );
		}
		foreach ( $definition['profiles'] as $profile ) {
			if ( ! is_string( $profile ) || ! in_array( $profile, array( 'admin', 'owner', 'visitor', 'builder', 'external' ), true ) ) {
				throw new ContractException( 'unsupported_contract', 'Invalid operation profile.' );
			}
		}
		$this->definition   = $definition;
		$this->permission   = \Closure::fromCallable( $permission );
		$this->discovery    = \Closure::fromCallable( $discovery );
		$this->availability = \Closure::fromCallable( $availability );
		$this->handler      = \Closure::fromCallable( $handler );
	}

	/**
	 * Return immutable public metadata, never closures.
	 *
	 * @return array
	 */
	public function metadata(): array {
		return $this->definition;
	}

	/**
	 * Restrict surface and actor type without granting any native capability.
	 *
	 * @param Context $context Server context.
	 * @return bool
	 */
	public function in_scope( Context $context ): bool {
		return in_array( $context->profile(), $this->definition['profiles'], true ) && ( ! $context->actor()->is_guest() || $this->definition['allow_guest'] );
	}

	/**
	 * Check live native dependency availability.
	 *
	 * @param Context $context Server context.
	 * @return bool
	 */
	public function available( Context $context ): bool {
		try {
			return true === ( $this->availability )( $context );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Determine discovery eligibility without target execution.
	 *
	 * @param Context $context Server context.
	 * @return bool
	 */
	public function discoverable( Context $context ): bool {
		try {
			return $this->in_scope( $context ) && $this->available( $context ) && true === ( $this->discovery )( $context );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Recheck native target/field permissions on every invocation.
	 *
	 * @param object  $input Strict input.
	 * @param Context $context Server context.
	 * @return bool
	 * @throws ContractException Unavailable permission service.
	 */
	public function permitted( object $input, Context $context ): bool {
		try {
			return $this->in_scope( $context ) && true === ( $this->permission )( $input, $context );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'unsupported_dependency', 'Operation permission service is unavailable.' );
		}
	}

	/**
	 * Invoke only from the guarded gateway/effect executor.
	 *
	 * @param object  $input Strictly validated input.
	 * @param Context $context Revalidated context.
	 * @return mixed Trusted domain result.
	 */
	public function invoke( object $input, Context $context ) {
		return ( $this->handler )( $input, $context );
	}
}
