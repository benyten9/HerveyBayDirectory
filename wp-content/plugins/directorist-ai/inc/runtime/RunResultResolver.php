<?php
/**
 * Optional current-source result delivery for owned event polling.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Storage\Owner;

/** Saved event markers do not authorize replaying factual payloads. */
interface RunResultResolver {
	/**
	 * Revalidate the exact owner's server-produced result before delivery.
	 *
	 * @param Owner  $owner Verified polling actor.
	 * @param string $run_id Owned run identity.
	 * @param object $trace Server-produced event trace selector.
	 * @return object Available current result or explicit withheld state.
	 */
	public function resolve( Owner $owner, string $run_id, object $trace ): object;
}
