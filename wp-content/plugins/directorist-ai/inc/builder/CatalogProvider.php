<?php
/**
 * Builder integration source-reader boundary.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Interface contract is described at the method boundary.

/** Implementations read effective native registries after negotiation succeeds. */
interface CatalogProvider {
	/** Return trusted bootstrap metadata without scanning component sources. */
	public function registration(): ProviderRegistration;

	/**
	 * Read an effective source snapshot for one request context.
	 *
	 * Directory facts are already permission-filtered. The provider never receives
	 * an actor object, raw request variables or an arbitrary filesystem location.
	 */
	public function read( string $document_kind, ?string $editor_fingerprint, CatalogEnvironment $environment, ?object $directory_facts ): ProviderSnapshot;
}
