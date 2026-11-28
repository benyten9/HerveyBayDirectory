<?php
/**
 * Trusted current effective-catalog source for task context.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Storage\Owner;

/** Catalogs are resolved under the current actor/run, never accepted from model input. */
interface BuilderTaskContextCatalogProvider {
	/**
	 * Read one current owner- and run-bound catalog snapshot.
	 *
	 * @param Owner  $owner Server-resolved owner.
	 * @param string $run_id Exact current workflow run.
	 */
	public function read( Owner $owner, string $run_id ): BuilderTaskContextCatalogSnapshot;
}
