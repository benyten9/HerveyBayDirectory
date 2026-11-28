<?php
/**
 * Frozen trusted native draft adapters supplied by installed builder plugins.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Narrow trusted bootstrap registry.

use DirectoristAIPro\Validation\ContractException;

/** A model or request cannot register draft compilers, facts or native writers. */
final class BuilderDraftAdapterRegistry {
	private array $adapters = array();
	private bool $frozen    = false;

	public function register( BuilderDraftCandidateCompiler $compiler, BuilderCandidateRelationshipFactsFactory $facts, BuilderNativeDraftWriter $writer ): void {
		$builder = $compiler->builder();
		if ( $this->frozen || ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || $facts->builder() !== $builder || $writer->builder() !== $builder || isset( $this->adapters[ $builder ] ) ) {
			throw new ContractException( 'scope_conflict', 'Native draft adapter registration conflicts.' );
		}
		$this->adapters[ $builder ] = (object) array(
			'compiler' => $compiler,
			'facts'    => $facts,
			'writer'   => $writer,
		);
	}

	public function freeze(): void {
		ksort( $this->adapters, SORT_STRING );
		$this->frozen = true;
	}

	public function adapter( string $builder ): ?object {
		if ( ! $this->frozen ) {
			throw new ContractException( 'unsupported_contract', 'Native draft adapters must be frozen before use.' );
		}
		return $this->adapters[ $builder ] ?? null;
	}
}
