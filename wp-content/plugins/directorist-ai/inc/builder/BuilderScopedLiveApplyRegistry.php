<?php
/**
 * Trusted adapters for scoped native editor live confirmation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Small trusted bootstrap registry.

use DirectoristAIPro\Validation\ContractException;

/** A model or REST request cannot supply a native editor adapter. */
final class BuilderScopedLiveApplyRegistry {
	private array $adapters = array();
	private bool $frozen    = false;

	public static function installed(): self {
		$registry = new self();
		do_action( 'directorist_ai_pro_register_builder_scoped_live_adapters', $registry );
		$registry->freeze();
		return $registry;
	}

	public function register( BuilderScopedLiveApplyAdapter $adapter ): void {
		$builder = $adapter->builder();
		if ( $this->frozen || ! in_array( $builder, array( 'elementor', 'gutenberg', 'divi' ), true ) || isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'scope_conflict', 'Scoped live adapter registration conflicts.' );
		}
		$this->adapters[ $builder ] = $adapter;
	}

	public function freeze(): void {
		ksort( $this->adapters, SORT_STRING );
		$this->frozen = true;
	}

	public function available(): bool {
		return $this->frozen && ! empty( $this->adapters );
	}

	public function adapter( string $builder ): BuilderScopedLiveApplyAdapter {
		if ( ! $this->frozen || ! isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'unsupported_dependency', 'Scoped live native adapter is unavailable.' );
		}
		return $this->adapters[ $builder ];
	}
}
