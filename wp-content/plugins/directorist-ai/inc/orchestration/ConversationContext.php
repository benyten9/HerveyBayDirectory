<?php
/**
 * Bounded user history projection without changing current native evidence.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Local history compaction grants no authoring, billing or native evidence authority. */
final class ConversationContext {

	/**
	 * Read only a server-assembled, explicitly delimited context packet.
	 *
	 * @param string $prompt Server packet.
	 * @return object|null Decoded packet.
	 */
	private static function packet( string $prompt ): ?object {
		$prefix = "BEGIN_UNTRUSTED_DATA\n";
		$suffix = "\nEND_UNTRUSTED_DATA";
		if ( ! str_starts_with( $prompt, $prefix ) || ! str_ends_with( $prompt, $suffix ) ) {
			return null;
		}
		try {
			$packet = json_decode( substr( $prompt, strlen( $prefix ), -strlen( $suffix ) ), false, 64, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return null;
		}
		return $packet instanceof \stdClass ? $packet : null;
	}

	/**
	 * Quote earlier preferences to the visitor planner/writer, never as facts or authority.
	 *
	 * @param string $prompt Server packet.
	 * @param bool   $include_recent Include recent requests when not already projected.
	 * @return object|null Bounded quoted user context.
	 */
	public static function visitor_memory( string $prompt, bool $include_recent = true ): ?object {
		$packet = self::packet( $prompt );
		if ( 'visitor' !== ( $packet->profile ?? null ) || ! is_array( $packet->recent_messages ?? null ) ) {
			return null;
		}
		$memory = $packet->task_summary->conversation_memory ?? null;
		$quotes = array();
		if ( 'quoted_earlier_user_context' === ( $memory->kind ?? null ) && 'none_fetch_current_native_evidence' === ( $memory->authority ?? null ) && is_array( $memory->entries ?? null ) ) {
			$entries = $memory->entries;
			usort( $entries, static fn( $a, $b ): int => (int) ( $b->preference ?? false ) <=> (int) ( $a->preference ?? false ) );
			$bytes = 0;
			foreach ( array_slice( $entries, 0, 20 ) as $entry ) {
				if ( ! is_string( $entry->quote ?? null ) || ! is_int( $entry->sequence ?? null ) || ! is_string( $entry->source_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $entry->source_hash ) ) {
					continue;
				}
				$quote = mb_substr( $entry->quote, 0, 700 );
				$size  = strlen( json_encode( $quote, JSON_UNESCAPED_UNICODE ) );
				if ( '' === trim( $quote ) || $bytes + $size > 6144 ) {
					continue;
				}
				$quotes[ $entry->sequence ] = $quote;
				$bytes                     += $size;
			}
			ksort( $quotes );
		}
		$result = (object) array(
			'authority'        => 'user_preferences_only_fetch_current_native_evidence',
			'earlier_requests' => array_values( $quotes ),
		);
		if ( $include_recent ) {
			$result->recent_requests = self::recent_requests( $packet->recent_messages );
		}
		return $result;
	}

	/**
	 * Prefer more recent user requests while bounding multilingual serialized size.
	 *
	 * @param array $messages Eligible user messages.
	 * @return array Bounded user quotations.
	 */
	public static function recent_requests( array $messages ): array {
		$result = array();
		$bytes  = 0;
		foreach ( array_reverse( array_slice( $messages, -12 ) ) as $message ) {
			if ( 'user' !== ( $message->role ?? null ) || ! is_string( $message->content->text ?? null ) ) {
				continue;
			}
			$text = mb_substr( $message->content->text, 0, 500 );
			$size = strlen( json_encode( $text, JSON_UNESCAPED_UNICODE ) );
			if ( $bytes + $size > 6144 ) {
				break;
			}
			$result[] = $text;
			$bytes   += $size;
		}
		return array_reverse( $result );
	}

	/**
	 * Compact only old user messages. Current requests, contracts and tool evidence stay exact.
	 *
	 * @param string $prompt Server packet.
	 * @param int    $keep Recent messages to preserve exactly.
	 * @return string|null Smaller packet or no eligible compaction.
	 */
	public static function compact( string $prompt, int $keep ): ?string {
		$packet = self::packet( $prompt );
		if ( $keep >= 1 && null !== $packet && ! isset( $packet->profile ) && 'user_preferences_only_fetch_current_native_evidence' === ( $packet->conversation_context->authority ?? null ) ) {
			return self::compact_visitor( $packet, $prompt, $keep );
		}
		if ( $keep < 1 || ! in_array( $packet->profile ?? null, array( 'admin', 'owner', 'builder', 'visitor' ), true ) || ! is_array( $packet->recent_messages ?? null ) || count( $packet->recent_messages ) <= $keep || isset( $packet->target_context->listing_import ) ) {
			return null;
		}
		$earlier = array();
		foreach ( array_slice( $packet->recent_messages, 0, -$keep ) as $message ) {
			if ( 'user' !== ( $message->role ?? null ) || ! is_int( $message->sequence ?? null ) || ! is_string( $message->content->text ?? null ) ) {
				return null;
			}
			$earlier[] = array(
				'sequence'     => $message->sequence,
				'content_json' => json_encode( $message->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ),
			);
		}
		if ( null !== ( $packet->task_summary ?? null ) && ! $packet->task_summary instanceof \stdClass ) {
			return null;
		}
		if ( null !== ( $packet->task_summary->conversation_memory ?? null ) && ! $packet->task_summary->conversation_memory instanceof \stdClass ) {
			return null;
		}
		$packet->task_summary                      = $packet->task_summary ?? (object) array();
		$packet->task_summary->conversation_memory = ConversationMemory::roll( $earlier, $packet->task_summary->conversation_memory ?? null );
		$packet->recent_messages                   = array_slice( $packet->recent_messages, -$keep );
		$compacted                                 = "BEGIN_UNTRUSTED_DATA\n" . json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) . "\nEND_UNTRUSTED_DATA";
		return strlen( $compacted ) < strlen( $prompt ) ? $compacted : null;
	}

	/**
	 * Fit a projected visitor request by shortening only optional earlier user context.
	 *
	 * @param object $packet Planner or final writer packet.
	 * @param string $prompt Original serialized packet.
	 * @param int    $keep Recent requests to keep.
	 * @return string|null Smaller packet or no eligible compaction.
	 */
	private static function compact_visitor( object $packet, string $prompt, int $keep ): ?string {
		$changed = false;
		foreach ( array( $packet, $packet->conversation_context ) as $context ) {
			foreach ( array( 'recent_requests', 'earlier_requests' ) as $key ) {
				$quotes = $context->$key ?? null;
				if ( ! is_array( $quotes ) || count( $quotes ) <= $keep || count( array_filter( $quotes, 'is_string' ) ) !== count( $quotes ) ) {
					continue;
				}
				// Retain an early preference alongside the newest archive quotations.
				$context->$key = 'earlier_requests' === $key ? array_merge( array_slice( $quotes, 0, 1 ), $keep > 1 ? array_slice( $quotes, 1 - $keep ) : array() ) : array_slice( $quotes, -$keep );
				$changed       = true;
			}
		}
		if ( ! $changed ) {
			return null;
		}
		$compacted = "BEGIN_UNTRUSTED_DATA\n" . json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) . "\nEND_UNTRUSTED_DATA";
		return strlen( $compacted ) < strlen( $prompt ) ? $compacted : null;
	}
}
