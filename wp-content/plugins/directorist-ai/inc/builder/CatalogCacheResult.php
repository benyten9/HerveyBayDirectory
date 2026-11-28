<?php
/**
 * Effective catalog plus cache evidence for trusted callers/tests.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact immutable result.

/** Cache state is never an authorization or native availability grant. */
final class CatalogCacheResult {
	private object $catalog;
	private string $state;
	private ?int $generation;
	private CatalogCacheContext $context;

	public function __construct( object $catalog, string $state, ?int $generation, CatalogCacheContext $context ) {
		if ( ! in_array( $state, array( 'warm', 'fresh', 'refreshed', 'recovered', 'raced', 'dynamic', 'unavailable' ), true ) || ( null !== $generation && $generation < 1 ) ) {
			throw new \InvalidArgumentException( 'Invalid catalog cache result.' );
		}
		$this->catalog    = $catalog;
		$this->state      = $state;
		$this->generation = $generation;
		$this->context    = $context;
	}

	public function catalog(): object {
		return $this->catalog; }
	public function state(): string {
		return $this->state; }
	public function generation(): ?int {
		return $this->generation; }
	public function context(): CatalogCacheContext {
		return $this->context; }
}
