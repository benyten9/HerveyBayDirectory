<?php
/**
 * Trusted builder-owned validation for publishing an already staged page.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Narrow installed integration contract.

/** Reads current native state only; never edits, serializes or assigns a template. */
interface BuilderPagePublicationAdapter {

	public function builder(): string;

	/** Throw when ownership, native builder identity or required dependencies changed. */
	public function assert_ready( int $page_id, string $draft_operation_id ): void;

	/** Version current graph, directory, reference and media dependencies without exposing raw metadata. */
	public function dependency_version( int $page_id ): string;
}
