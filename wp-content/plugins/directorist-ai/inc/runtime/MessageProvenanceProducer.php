<?php
/** Trusted producer seam; validation occurs before the message transaction.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

interface MessageProvenanceProducer {
	/**
	 * Validate the closed producer result and owned plan outside a transaction.
	 *
	 * @param object $content Exact server-produced visible content.
	 * @param object $checkpoint Actual owned task checkpoint.
	 */
	public function validate( object $content, object $checkpoint ): void;
	/**
	 * Sign the validated content at its allocated durable message position.
	 *
	 * This method must perform no retrieval/provider work.
	 *
	 * @param array  $run Actual locked run/conversation binding.
	 * @param int    $sequence Allocated message sequence.
	 * @param object $content Validated server-produced content.
	 * @param object $checkpoint Actual owned task checkpoint.
	 * @return object Producer attestation.
	 */
	public function seal( array $run, int $sequence, object $content, object $checkpoint ): object;
}
