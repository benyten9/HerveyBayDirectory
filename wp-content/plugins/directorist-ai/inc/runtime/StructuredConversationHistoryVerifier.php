<?php
/** Optional richer replay without widening the text provenance contract.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;
use DirectoristAIPro\Capabilities\Context;

interface StructuredConversationHistoryVerifier {
	/**
	 * Verify exact provenance and replace saved facts with current evidence.
	 *
	 * @param Context $context Current actor/profile.
	 * @param array   $message Exact persisted message row.
	 * @param object  $content Closed stored producer content.
	 * @param array   $references Closed stored refs.
	 * @param int     $conversation_id Actual owned conversation.
	 * @return object|null Current safe content or withheld.
	 */
	public function resolve( Context $context, array $message, object $content, array $references, int $conversation_id ): ?object;
}
