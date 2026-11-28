<?php
/**
 * Native isolated draft save and marker-only reconciliation contract.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Trusted installed adapter contract.

/** The builder integration owns its native save and marker semantics. */
interface BuilderNativeDraftWriter {
	public function builder(): string;

	/** Invoke the trusted final authority fence under the native operation lock. */
	public function stage( int|string $source_identifier, string $source_content, object $graph, array $normalized_changes, string $operation_id, callable $pre_commit ): object;

	/** Read operation markers only; never retry an ambiguous native save. */
	public function reconcile( int|string $source_identifier, string $source_hash, string $draft_content_hash, string $operation_id ): object;
}
