<?php
/**
 * Cheap builder source fingerprint boundary for catalog caching.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Interface contract is described at the method boundary.

/** Implement separately from full source/control discovery. */
interface CatalogFingerprintProvider {
	/** Return current cheap fingerprints or declare the context uncacheable. */
	public function cache_fingerprint( string $document_kind, ?string $editor_fingerprint, CatalogEnvironment $environment, ?object $directory_facts ): ProviderCacheFingerprint;
}
