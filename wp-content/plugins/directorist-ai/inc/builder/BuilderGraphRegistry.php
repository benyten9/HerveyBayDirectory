<?php
/**
 * Trusted native builder graph provider registry.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact trusted registry.

use DirectoristAIPro\Validation\ContractException;

/** One provider owns each builder; all builders share builder.inspect. */
final class BuilderGraphRegistry {
	/** @var array<string,BuilderGraphProvider> */
	private array $providers = array();
	private bool $frozen     = false;

	public function register( BuilderGraphProvider $provider ): void {
		$builder = $provider->builder();
		GraphVersion::identifier( $provider->provider_id() );
		if ( $this->frozen || ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || isset( $this->providers[ $builder ] ) ) {
			throw new ContractException( 'scope_conflict', 'Builder graph provider registration conflicts.' );
		}
		$this->providers[ $builder ] = $provider;
	}

	public function freeze(): void {
		ksort( $this->providers, SORT_STRING );
		$this->frozen = true;
	}

	public function provider( string $builder ): ?BuilderGraphProvider {
		if ( ! $this->frozen ) {
			throw new ContractException( 'unsupported_contract', 'Builder graph registry is not frozen.' );
		}
		return $this->providers[ $builder ] ?? null;
	}

	public function available(): bool {
		return $this->frozen && ! empty( $this->providers );
	}
}
