<?php
/** Optional safe dated replay of producer-attested plain visitor answers. */
namespace DirectoristAIPro\Runtime;
use DirectoristAIPro\Capabilities\Context;
interface PlainConversationHistoryVerifier {
	/** Return a verified plain display and evidence status, or null. */
	public function resolve_plain( Context $context, array $message, object $content, array $references, int $conversation_id ): ?array;
}
