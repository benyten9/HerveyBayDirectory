<?php
/**
 * Authorized directory-fact boundary for builder catalogs.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Interface contract is described at the method boundary.

use DirectoristAIPro\Capabilities\Context;

/** Prevents builder providers from reading directory state through ambient input. */
interface DirectoryFactsReader {
	/** Return a bounded, actor-visible directory descriptor. */
	public function read( Context $context, int $directory_id ): object;
}
