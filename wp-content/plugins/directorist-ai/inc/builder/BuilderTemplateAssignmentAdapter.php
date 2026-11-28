<?php
/**
 * Trusted native template assignment boundary for a reviewed staged draft.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Installed integrations share this narrow contract.

/** Native adapters derive their target and condition from the owned stage, never model input. */
interface BuilderTemplateAssignmentAdapter {
	public function builder(): string;

	/**
	 * Return a closed native inspection object. before_native is a private, complete
	 * inverse snapshot; all other fields are bounded, public review facts. Required:
	 * builder, draft_id, source_id, directory_id, native_kind, before_hash,
	 * impact_hash, dependency_hash, inventory_hash, consumer_ids, consumer_count,
	 * before_native. Hashes are lowercase SHA-256 hex.
	 */
	public function inspect( int $draft_id, string $draft_operation_id, int $directory_id ): object;

	/**
	 * Apply only the exact inspected native transition. Call pre_commit immediately
	 * before the first native write while the adapter holds its native write fence.
	 * Return state, draft_id, source_id, operation_id, before_hash, after_hash,
	 * impact_hash and error_code (null on success). after_hash may be null only
	 * when the native outcome is failed or unknown.
	 */
	public function assign( int $draft_id, string $draft_operation_id, int $directory_id, string $operation_id, string $before_hash, string $impact_hash, callable $pre_commit ): object;

	/** Read-only inspection after an uncertain assignment; never retry the write. */
	public function reconcile( int $draft_id, string $draft_operation_id, int $directory_id, string $operation_id, string $before_hash, string $impact_hash ): object;

	/** Restore precisely the private inverse snapshot after a separate review. */
	public function restore( int $draft_id, string $draft_operation_id, int $directory_id, string $assignment_operation_id, string $restoration_operation_id, string $expected_after_hash, string $impact_hash, object $before_native, callable $pre_commit ): object;

	/** Read-only inspection after an uncertain restore; never retry the write. */
	public function reconcile_restore( int $draft_id, string $draft_operation_id, int $directory_id, string $assignment_operation_id, string $restoration_operation_id, string $expected_after_hash, string $impact_hash, object $before_native ): object;
}
