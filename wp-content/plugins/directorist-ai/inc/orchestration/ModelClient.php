<?php
/**
 * Replaceable model boundary backed by the WordPress AI Client in production.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Tests supply deterministic clients without registering provider credentials. */
interface ModelClient {
	/**
	 * Generate one bounded model round.
	 *
	 * @param ModelRequest $request Closed provider request.
	 * @return ModelResponse Content-channel output and usage only.
	 * @throws ModelException Provider or response failure.
	 */
	public function generate( ModelRequest $request ): ModelResponse;
}
