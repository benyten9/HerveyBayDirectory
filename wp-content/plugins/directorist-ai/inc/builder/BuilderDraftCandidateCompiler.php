<?php
/**
 * Trusted native draft compilation from a validated semantic candidate.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- The compiler contract is intentionally narrow.

/** The installed builder owns native schema/default/serialization rules. */
interface BuilderDraftCandidateCompiler {
	/** Stable builder identity. */
	public function builder(): string;

	/** Return exact private native content and its hash without writing. */
	public function compile( string $source_content, object $graph, array $normalized_changes ): object;
}
