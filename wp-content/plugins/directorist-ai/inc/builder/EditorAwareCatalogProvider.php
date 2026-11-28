<?php
/**
 * Optional editor-aware provider boundary.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Interface contract is documented at the method boundary.

/** Providers opt in only when a current server-bound editor snapshot exists. */
interface EditorAwareCatalogProvider extends CatalogProvider {
	/** Read effective sources with detached editor registry evidence. */
	public function read_with_editor( string $document_kind, CatalogEditorSnapshot $editor, CatalogEnvironment $environment, ?object $directory_facts ): ProviderSnapshot;
}
