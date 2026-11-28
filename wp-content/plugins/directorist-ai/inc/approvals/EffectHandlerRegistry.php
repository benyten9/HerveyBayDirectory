<?php
/**
 * Trusted effect implementations keyed by capability identity.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Approvals;

use DirectoristAIPro\Validation\ContractException;

/** Registration occurs during bootstrap, never from model input. */
final class EffectHandlerRegistry {
	/**
	 * Trusted handlers keyed by capability.
	 *
	 * @var array<string,EffectHandler>
	 */
	private array $handlers = array();

	/**
	 * Register one implementation for a capability.
	 *
	 * @param string        $capability Exact capability ID.
	 * @param EffectHandler $handler Trusted native handler.
	 * @throws ContractException Capability is malformed or already registered.
	 */
	public function register( string $capability, EffectHandler $handler ): void {
		if ( ! preg_match( '/^[a-z][a-z0-9]*\.[a-z][a-z0-9_]*$/D', $capability ) || isset( $this->handlers[ $capability ] ) ) {
			throw new ContractException( 'unsupported_contract', 'Invalid or duplicate effect handler.' );
		}
		$this->handlers[ $capability ] = $handler;
	}

	/**
	 * Resolve only a server-registered exact implementation.
	 *
	 * @param string $capability Exact capability ID.
	 * @throws ContractException Handler is unavailable.
	 */
	public function get( string $capability ): EffectHandler {
		if ( ! isset( $this->handlers[ $capability ] ) ) {
			throw new ContractException( 'unsupported_dependency', 'Native effect handler is unavailable.' );
		}
		return $this->handlers[ $capability ];
	}
}
