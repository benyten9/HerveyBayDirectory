<?php
/**
 * Native workflow adapter seam; confirmation and task ownership belong to the runtime.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat\Workflows;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, Squiz.Commenting.VariableComment -- Methods implement the documented native workflow adapter seam.

use DirectoristAIPro\Capabilities\Context;

interface NativeWorkflowAdapter {
	public function id(): string;
	public function revision(): string;
	public function input_schema(): array;
	public function available( Context $context, int $directory, int $listing ): array;
	public function prepare( Context $context, int $directory, int $listing, object $values ): array;
	public function execute( Context $context, int $directory, int $listing, object $proposal, string $operation ): array;
	public function reconcile( Context $context, int $directory, int $listing, array $identity ): array;
}
