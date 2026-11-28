<?php
/**
 * Optional editor-aware cheap fingerprint boundary.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Interface contract is documented at the method boundary.

/** Editor registry changes participate in a reusable cache key. */
interface EditorAwareCatalogFingerprintProvider extends CatalogFingerprintProvider {
	/** Return source fingerprints including the bound editor registry. */
	public function cache_fingerprint_with_editor( string $document_kind, CatalogEditorSnapshot $editor, CatalogEnvironment $environment, ?object $directory_facts ): ProviderCacheFingerprint;
}
