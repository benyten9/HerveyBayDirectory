<?php
/**
 * Server-bound editor registry evidence for builder discovery.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

/** Editor facts are optional discovery evidence and never certify writes. */
interface CatalogEditorSnapshot extends EditorBinding {
	/** Exact document kind bound when the server issued this evidence. */
	public function document_kind(): string;

	/** Exact directory scope, or null for an unscoped document. */
	public function directory_id(): ?int;

	/** Return a detached, bounded registry snapshot. */
	public function snapshot(): object;
}
