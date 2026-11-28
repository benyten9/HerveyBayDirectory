<?php
/**
 * Native editor ticket contract for a separately reviewed scoped live edit.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Interface methods carry their closed native semantics inline.

/** Native implementations own the editor session, Save boundary and readback. */
interface BuilderScopedLiveApplyAdapter {
	public function builder(): string;

	/** Inspect one unchanged owned stage in the current native editor opening. */
	public function inspect( int $source_id, int $draft_id, int $directory_id, string $editor_binding_id, object $reviewed_stage ): object;

	/** Issue a single-use native ticket, without changing the source document. */
	public function issue( object $inspection, string $operation_id, string $impact_hash, callable $pre_issue ): object;

	/** Read only the native Save marker and source; never redispatch an edit. */
	public function reconcile( object $inspection, string $operation_id, string $impact_hash ): object;
}
