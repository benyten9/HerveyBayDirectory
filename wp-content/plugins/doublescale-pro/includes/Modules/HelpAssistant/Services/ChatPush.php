<?php
/**
 * Live chat over DoubleScale Pro's real-time relay (wss://relay.doublescale.io).
 *
 * Polling stays the way the chat and the ticket screen keep up; these pushes
 * only make them read at once. Every event carries ids and a word, never
 * what anyone wrote: the reader asks the usual routes for the rest, with the
 * usual checks.
 *
 * - The visitor's chat subscribes to `chat.<conversation id>`; the token is
 *   in the updates reply ({@see visitor_client()}), so only the browser that
 *   holds the conversation gets one. Event `chat.update`, data `what`:
 *   message | typing | seen | status.
 * - The team's ticket screen listens on Pro's `staff` channel. Event
 *   `support.ticket`, data `ticket_id` + `what`: new | reply | typing |
 *   seen (the team opened it) | read (the customer read the team's replies).
 *
 * Off (every method a no-op) without Pro's real-time core or an active
 * licence: the polling alone carries on.
 *
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ChatPush class.
 */
final class ChatPush {

	/**
	 * Pro's real-time core.
	 */
	private const REALTIME = '\\DoubleScale\\Pro\\Core\\Realtime\\Realtime';

	/**
	 * The visitor's token lives this long; each updates read hands out a fresh one.
	 */
	private const VISITOR_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Pushes already queued in this request (one per channel + word is enough).
	 *
	 * @var array<string, true>
	 */
	private static array $sent = array();

	/**
	 * @return void
	 */
	public static function register(): void {
		// The team marked it solved or closed, or opened it again.
		add_action( 'doublescale_support_ticket_updated', array( self::class, 'on_ticket_updated' ), 20, 3 );
		add_action( 'doublescale_support_ticket_merged', array( self::class, 'on_ticket_merged' ), 20, 2 );
		// A new chat for the team: the header's chat icon lights up at once.
		add_action( 'doublescale_help_chat_handed_off', array( self::class, 'on_handed_off' ), 20, 2 );
	}

	/**
	 * @param mixed                     $ticket       New ticket.
	 * @param array<string, mixed>|null $conversation Conversation.
	 * @return void
	 */
	public static function on_handed_off( $ticket, $conversation = null ): void {
		unset( $ticket );
		if ( is_array( $conversation ) && ! empty( $conversation['id'] ) ) {
			self::to_team( (int) $conversation['id'], 'new' );
		}
	}

	/**
	 * @return bool
	 */
	public static function available(): bool {
		$realtime = self::REALTIME;
		return class_exists( $realtime ) && $realtime::available();
	}

	/**
	 * @param int $conversation_id Conversation id.
	 * @return string
	 */
	public static function channel( int $conversation_id ): string {
		return 'chat.' . $conversation_id;
	}

	/**
	 * What the visitor's chat needs to listen: the relay address and a token
	 * for its own conversation. Null when real-time is off.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return array<string, mixed>|null
	 */
	public static function visitor_client( array $conversation ): ?array {
		if ( ! self::available() || empty( $conversation['id'] ) ) {
			return null;
		}
		$realtime = self::REALTIME;
		$client   = $realtime::client_config( array() );
		$token    = $realtime::channel_token( self::channel( (int) $conversation['id'] ), self::VISITOR_TTL );
		if ( null === $client || null === $token ) {
			return null;
		}

		return array(
			'url'     => (string) $client['url'],
			'channel' => $token,
		);
	}

	/**
	 * Tell the visitor's chat to read again.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $what            message | typing | seen | status.
	 * @return void
	 */
	public static function to_visitor( int $conversation_id, string $what ): void {
		if ( $conversation_id <= 0 ) {
			return;
		}
		self::send( self::channel( $conversation_id ), 'chat.update', array( 'what' => $what ) );
	}

	/**
	 * Tell the team's ticket screens to read the conversation's ticket again.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $what            reply | typing | seen.
	 * @return void
	 */
	public static function to_team( int $conversation_id, string $what ): void {
		if ( $conversation_id <= 0 || ! self::available() ) {
			return;
		}
		$conversation = ConversationStore::find_by_id( $conversation_id );
		$ticket       = (int) ( $conversation['ticket_id'] ?? 0 );
		if ( $ticket <= 0 ) {
			return;
		}
		self::send(
			'staff',
			'support.ticket',
			array(
				'ticket_id' => $ticket,
				'what'      => $what,
			)
		);
	}

	/**
	 * @param mixed $ticket    Ticket after.
	 * @param mixed $effective Changed fields.
	 * @param mixed $before    Ticket before.
	 * @return void
	 */
	public static function on_ticket_updated( $ticket, $effective = null, $before = null ): void {
		unset( $effective );
		if ( ! is_object( $ticket ) || ! self::available() ) {
			return;
		}
		if ( is_object( $before ) && (string) ( $before->status ?? '' ) === (string) ( $ticket->status ?? '' ) ) {
			return;
		}
		$conversation = ConversationStore::find_by_ticket( (int) $ticket->id );
		if ( null !== $conversation ) {
			self::to_visitor( (int) $conversation['id'], 'status' );
		}
	}

	/**
	 * @param mixed $merged Ticket merged away.
	 * @param mixed $target Ticket it went into.
	 * @return void
	 */
	public static function on_ticket_merged( $merged, $target = null ): void {
		unset( $target );
		if ( ! is_object( $merged ) || ! self::available() ) {
			return;
		}
		$conversation = ConversationStore::find_by_ticket( (int) $merged->id );
		if ( null !== $conversation ) {
			self::to_visitor( (int) $conversation['id'], 'status' );
		}
	}

	/**
	 * @param string               $channel Channel.
	 * @param string               $event   Event.
	 * @param array<string, mixed> $data    Data (ids and words only).
	 * @return void
	 */
	private static function send( string $channel, string $event, array $data ): void {
		$key = $channel . '|' . $event . '|' . wp_json_encode( $data );
		if ( isset( self::$sent[ $key ] ) || ! self::available() ) {
			return;
		}
		self::$sent[ $key ] = true;
		$realtime           = self::REALTIME;
		$realtime::publish( $channel, $event, $data );
	}

	/**
	 * Tests start each request afresh.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$sent = array();
	}
}
