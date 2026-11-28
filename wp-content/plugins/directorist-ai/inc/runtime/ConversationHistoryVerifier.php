<?php
/**
 * Trusted, current replay evidence for assistant conversation messages.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Capabilities\Context;

/** An implementation must establish both source access and complete provenance. */
interface ConversationHistoryVerifier {
	/**
	 * Decide whether the exact stored assistant text and all refs remain safe.
	 *
	 * Empty refs do not prove source-free prose. Unknown producers or reference
	 * kinds must return false. This is called on selection and before delivery.
	 *
	 * @param Context $context Current server-bound actor/profile.
	 * @param array   $message Exact stored message row.
	 * @param object  $content Decoded closed assistant content.
	 * @param array   $references Decoded stored source references.
	 * @param int     $conversation_id Exact owned conversation ID.
	 * @return bool True only for current and fully proven content.
	 */
	public function allows( Context $context, array $message, object $content, array $references, int $conversation_id ): bool;
}
