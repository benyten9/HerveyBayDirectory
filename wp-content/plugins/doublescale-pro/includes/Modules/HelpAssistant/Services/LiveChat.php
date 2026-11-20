<?php
/**
 * A person answers in the same chat (Intercom / Chatway style), when the owner
 * turned it on (HelpSettings live_chat, off by default; {@see enabled()}).
 * Off: a handed-off conversation is only a ticket answered by email, the
 * assistant keeps the chat, and nothing here touches it.
 *
 * A conversation handed to the team is a ticket (HandoffService), and the
 * chat stays open on it:
 * - While the ticket is not closed, the visitor's messages are the ticket's
 *   replies from the customer (source "chat"): the assistant stays silent.
 * - A team member's reply in the ticket (free's `doublescale_support_reply_created`)
 *   is stored in the conversation as an "agent" message with their first
 *   name; the customer's own reply by email or portal as a "customer" one.
 *   Internal notes do not fire that hook, so they never reach the chat; a
 *   reply that came from the chat itself is not stored twice.
 * - The chat reads what arrived through RestHelpPublicChatController's
 *   updates route. Once the ticket is closed, the conversation is the
 *   assistant's again.
 * - Solved: the visitor's closing words ("no, fine, it's solved") reach the
 *   ticket without opening it again; anything else they write opens it.
 * - Rated: once the team marks it solved or closes it, the visitor rates
 *   the help 1-5 (and may say why); kept on the conversation, and written on
 *   the ticket as an internal note for the team.
 * - Seen, both ways: a team member who has the ticket's conversation on
 *   screen has seen everything in it (the visitor's chat says "Seen"); a
 *   visitor who has the chat on screen has seen the team's replies, marked on
 *   the ticket replies themselves (free's ClientSeen), so the ticket screen
 *   shows it like a reply read in the portal.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Portal\Services\ClientSeen;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\AttachmentModel;
use DoubleScale\Modules\Support\Services\AttachmentService;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\EmailNotifications;
use DoubleScale\Modules\Support\Services\TicketService;
use DoubleScale\Pro\Modules\Support\Services\SatisfactionRecorder;
use WP_Error;

/**
 * LiveChat class.
 */
final class LiveChat {

	/**
	 * Longest message kept from a ticket reply, in characters.
	 */
	private const MAX_CHARS = 4000;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'doublescale_support_reply_created', array( self::class, 'on_reply' ), 20, 2 );
		// In the chat first; by email only when not seen there in time.
		add_filter( 'doublescale_support_reply_email_now', array( self::class, 'hold_reply_email' ), 10, 3 );
		add_action( self::EMAIL_HOOK, array( self::class, 'send_unseen_reply' ), 10, 2 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'on_ticket_read' ), 10, 3 );
		// A ticket merged into another of the same customer: its chat goes on
		// with the main ticket (the team replies there).
		add_action( 'doublescale_support_ticket_merged', array( self::class, 'on_ticket_merged' ), 10, 2 );
	}

	/**
	 * Background check that emails a team reply the visitor did not see.
	 */
	public const EMAIL_HOOK = 'doublescale_help_reply_email_check';

	/**
	 * How long a reply waits to be seen in the chat before it is emailed.
	 */
	public const EMAIL_AFTER = 5 * MINUTE_IN_SECONDS;

	/**
	 * Set while the late email goes out, so it is not held again.
	 *
	 * @var bool
	 */
	private static $emailing_late = false;

	/**
	 * A team reply in a live chat: shown in the chat now, its email held and
	 * checked in five minutes (like Intercom: an unread reply reaches the
	 * customer by email, a read one does not). Not live, or no background
	 * scheduler: the email goes now.
	 *
	 * @param bool  $now      Email now.
	 * @param mixed $activity The reply.
	 * @param mixed $ticket   The ticket.
	 * @return bool
	 */
	public static function hold_reply_email( $now, $activity, $ticket ): bool {
		if ( ! $now || self::$emailing_late || ! is_object( $activity ) || ! is_object( $ticket ) || ! self::enabled() || ! function_exists( 'as_schedule_single_action' ) ) {
			return (bool) $now;
		}
		$conversation = ConversationStore::find_by_ticket( (int) $ticket->id );
		if ( null === $conversation || ! self::active( $conversation ) ) {
			return true;
		}
		as_schedule_single_action( time() + self::EMAIL_AFTER, self::EMAIL_HOOK, array( (int) $activity->id, (int) $ticket->id ), 'doublescale' );
		// The ticket screen: "Emailed at 2:49 pm if not read in the chat".
		self::mark( (int) $activity->id, 'email_held_until', gmdate( 'Y-m-d H:i:s', time() + self::EMAIL_AFTER ) );

		return false;
	}

	/**
	 * Five minutes on: email the reply unless the visitor saw it in the chat.
	 *
	 * @param int $activity_id The reply.
	 * @param int $ticket_id   Its ticket.
	 * @return void
	 */
	public static function send_unseen_reply( $activity_id, $ticket_id ): void {
		$conversation = ConversationStore::find_by_ticket( (int) $ticket_id );
		$activity     = class_exists( ActivityModel::class ) ? ActivityModel::find( (int) $activity_id ) : null;
		$ticket       = TicketModel::find( (int) $ticket_id );
		if ( ! $activity || ! $ticket ) {
			return;
		}
		// Decided now, either way: no longer waiting.
		self::unmark( (int) $activity_id, 'email_held_until' );
		if ( null !== $conversation && ConversationStore::reply_seen( (int) $conversation['id'], (int) $activity_id ) ) {
			return;
		}
		$notifications = function_exists( 'doublescale_resolve' ) ? doublescale_resolve( EmailNotifications::class ) : null;
		if ( ! $notifications instanceof EmailNotifications ) {
			return;
		}
		self::$emailing_late = true;
		try {
			$notifications->on_reply_created( $activity, $ticket );
		} finally {
			self::$emailing_late = false;
		}
	}

	/**
	 * Where a team reply on this ticket goes, for the ticket screen:
	 * chat_then_email (live: the chat now, email if unseen in five minutes)
	 * or email (the chat is off, or back with the assistant).
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return array<string, mixed>
	 */
	public static function delivery( array $conversation ): array {
		$on = self::enabled();

		return array(
			'delivery'     => $on && self::active( $conversation ) ? 'chat_then_email' : 'email',
			'chat_off'     => ! $on,
			'email'        => (string) ( $conversation['visitor_email'] ?? '' ),
			'minutes'      => (int) ( self::EMAIL_AFTER / MINUTE_IN_SECONDS ),
			'settings_url' => admin_url( 'admin.php?page=doublescale&path=support/settings/live-chat' ),
		);
	}

	/**
	 * The customer's chat received these ticket replies (the ticket screen's
	 * "Delivered"). Not when someone of the team is looking.
	 *
	 * @param array<int, array<string, mixed>> $messages Messages the chat just got.
	 * @param array<string, mixed>             $conversation Conversation row.
	 * @return void
	 */
	public static function delivered( array $messages, array $conversation ): void {
		if ( class_exists( ClientSeen::class ) && ClientSeen::is_team_viewer() ) {
			return;
		}
		$ids = array();
		foreach ( $messages as $message ) {
			if ( 'agent' === ( $message['role'] ?? '' ) && ! empty( $message['id'] ) ) {
				$ids[] = (int) $message['id'];
			}
		}
		$any = false;
		foreach ( ConversationStore::reply_ids( (int) $conversation['id'], $ids ) as $activity_id ) {
			$any = self::mark( $activity_id, 'chat_delivered_at' ) || $any;
		}
		if ( $any ) {
			ChatPush::to_team( (int) $conversation['id'], 'read' );
		}
	}

	/**
	 * Write a step of a reply's way to the customer once (free's
	 * ReplyDelivery; nothing with an older free).
	 *
	 * @param int    $activity_id The reply.
	 * @param string $key         Data key.
	 * @param string $value       '' for now.
	 * @return bool Whether it was written now.
	 */
	private static function mark( int $activity_id, string $key, string $value = '' ): bool {
		$class = '\\DoubleScale\\Modules\\Support\\Services\\ReplyDelivery';
		return class_exists( $class ) ? $class::stamp( $activity_id, $key, $value ) : false;
	}

	/**
	 * Remove a step (a held email that was decided).
	 *
	 * @param int    $activity_id The reply.
	 * @param string $key         Data key.
	 * @return void
	 */
	private static function unmark( int $activity_id, string $key ): void {
		$class = '\\DoubleScale\\Modules\\Support\\Services\\ReplyDelivery';
		if ( class_exists( $class ) ) {
			$class::clear( $activity_id, $key );
		}
	}

	/**
	 * Whether the team answers in the chat (the owner's switch, off by default).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		// A helpdesk channel of its own: it works with or without the AI
		// assistant (without it, the chat goes straight to the team). Every
		// plan; a licence is needed.
		return ! empty( HelpSettings::get()['live_chat'] ) && LiveChatPlan::allowed();
	}

	/**
	 * A team member read a ticket's conversation (the ticket screen, also
	 * every few seconds while it stays open): the chat it came from is seen.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed The response, unchanged.
	 */
	public static function on_ticket_read( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof \WP_REST_Request || 'GET' !== $request->get_method() || is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! preg_match( '#^/doublescale/v1/support/tickets/(\d+)/conversation$#', (string) $request->get_route(), $found ) ) {
			return $response;
		}
		if ( $response instanceof \WP_REST_Response && $response->get_status() >= 400 ) {
			return $response;
		}
		$conversation = ConversationStore::find_by_ticket( (int) $found[1] );
		if ( null !== $conversation && get_current_user_id() > 0 ) {
			ConversationStore::team_saw( (int) $conversation['id'] );
			// The ticket screen's own read says the team member is typing
			// (no extra request), and gets back whether the visitor is.
			if ( $request->get_param( 'typing' ) ) {
				ConversationStore::mark_typing( (int) $conversation['id'], 'team', get_current_user_id() );
			}
			if ( $response instanceof \WP_REST_Response ) {
				$data = $response->get_data();
				if ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
					$data['meta']['live_chat'] = array_merge(
						array( 'visitor_typing' => ConversationStore::is_typing( (int) $conversation['id'], 'visitor' ) ),
						self::delivery( $conversation )
					);
					$response->set_data( $data );
				}
			}
		}

		return $response;
	}

	/**
	 * @param mixed $merged The merged ticket.
	 * @param mixed $target The main ticket.
	 * @return void
	 */
	public static function on_ticket_merged( $merged, $target ): void {
		if ( ! is_object( $merged ) || ! is_object( $target ) ) {
			return;
		}
		$conversation = ConversationStore::find_by_ticket( (int) $merged->id );
		if ( null === $conversation ) {
			return;
		}
		// The visitor's address was typed and never proven: following the
		// merge would show them the other ticket's replies and files (the
		// ticket of whoever owns that address). The conversation is closed
		// and detached; it stops receiving replies.
		if ( UnverifiedContact::is( $merged ) || UnverifiedContact::is( $target ) ) {
			ConversationStore::update(
				(int) $conversation['id'],
				array(
					'status'    => 'closed',
					'ticket_id' => null,
				)
			);
			return;
		}
		ConversationStore::update( (int) $conversation['id'], array( 'ticket_id' => (int) $target->id ) );
	}

	/**
	 * The visitor had the chat on screen with the team's replies up to $upto:
	 * those ticket replies are seen by the client. A team member looking at
	 * the chat is not the client.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @param int                  $upto         Last message the chat shows.
	 * @return void
	 */
	public static function visitor_saw( array $conversation, int $upto ): void {
		$seen = ConversationStore::visitor_saw( (int) $conversation['id'], $upto );
		if ( array() === $seen || ! class_exists( ClientSeen::class ) || ! class_exists( ActivityModel::class ) || ClientSeen::is_team_viewer() ) {
			return;
		}
		foreach ( $seen as $activity_id ) {
			$activity = ActivityModel::find( $activity_id );
			if ( $activity ) {
				ClientSeen::activity_seen( $activity, 'chat' );
			}
		}
	}

	/**
	 * Live: handed over, and its ticket is not closed. A closed ticket gives
	 * the conversation back to the assistant.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return bool
	 */
	public static function active( array $conversation ): bool {
		return null !== self::open_ticket( $conversation );
	}

	/**
	 * The ticket of a live conversation; null (and the conversation given
	 * back to the assistant) once it is closed or gone. One lookup.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return TicketModel|null
	 */
	private static function open_ticket( array $conversation ) {
		if ( ! self::enabled() ) {
			return null;
		}
		if ( 'handed_off' !== (string) ( $conversation['status'] ?? '' ) || empty( $conversation['ticket_id'] ) || ! class_exists( TicketModel::class ) ) {
			return null;
		}

		$ticket = TicketModel::find( (int) $conversation['ticket_id'] );
		if ( ! $ticket || TicketStatus::CLOSED === (string) $ticket->status ) {
			ConversationStore::update( (int) $conversation['id'], array( 'status' => 'closed' ) );
			return null;
		}

		return $ticket;
	}

	/**
	 * "live" while the team has it, "resolved" once they marked it solved (a
	 * reply still reaches them), "closed" once its ticket is closed, else
	 * "assistant".
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return string
	 */
	public static function status( array $conversation ): string {
		if ( ! self::enabled() ) {
			return 'assistant';
		}
		$ticket = self::open_ticket( $conversation );
		if ( null !== $ticket ) {
			// Solved: the team thinks it is done; a reply still reaches them
			// and opens it again unless it only says "thanks, it's solved".
			return TicketStatus::RESOLVED === (string) $ticket->status ? 'resolved' : 'live';
		}

		return ! empty( $conversation['ticket_id'] ) ? 'closed' : 'assistant';
	}

	/**
	 * The visitor rates the team's help: only once the team marked the
	 * ticket solved or closed it. A second rating (or the words that follow
	 * the faces) replaces the first, and their words stay when only the face
	 * changes. The ticket holds one note with the rating, kept up to date.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @param int                  $score        1 to 5.
	 * @param string               $comment      '' for none.
	 * @return true|WP_Error
	 */
	public static function rate( array $conversation, int $score, string $comment ) {
		if ( ! in_array( self::status( $conversation ), array( 'resolved', 'closed' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$comment = mb_substr( trim( sanitize_textarea_field( $comment ) ), 0, 1000, 'UTF-8' );
		$before  = isset( $conversation['satisfaction'] ) ? (int) $conversation['satisfaction'] : 0;
		$said    = trim( (string) ( $conversation['satisfaction_comment'] ?? '' ) );
		// Only the face changed: their words stay.
		if ( '' === $comment ) {
			$comment = $said;
		}
		ConversationStore::satisfaction( (int) $conversation['id'], $score, $comment );
		// The same rating on the ticket (source chat): the support reports read
		// every rating, email or chat, from there. The chat keeps its own note.
		$rated_ticket = class_exists( SatisfactionRecorder::class ) && \DoubleScale\Pro\Modules\Support\Services\SatisfactionSettings::available() && ! empty( $conversation['ticket_id'] ) ? TicketModel::find( (int) $conversation['ticket_id'] ) : null;
		if ( $rated_ticket ) {
			SatisfactionRecorder::record( $rated_ticket, $score, $comment, 'chat', false );
		}
		if ( $before === $score && ( '' === $comment || $comment === $said ) ) {
			return true;
		}

		/* translators: 1: a face and a word (e.g. "🙂 Good"), 2: the score out of five */
		$note = sprintf( __( 'Chat rating: %1$s (%2$d/5)', 'doublescale' ), self::score_label( $score ), $score );
		if ( '' !== $comment ) {
			$note .= "\n\n" . $comment;
		}
		$content = wpautop( esc_html( $note ) );

		// One note per conversation: the face first, their words a moment
		// later, a change of mind; the team reads the latest in one place.
		$existing = ! empty( $conversation['satisfaction_note_id'] ) && class_exists( ActivityModel::class ) ? ActivityModel::find( (int) $conversation['satisfaction_note_id'] ) : null;
		if ( $existing ) {
			$data            = is_array( $existing->data ) ? $existing->data : (array) json_decode( (string) $existing->data, true );
			$data['content'] = $content;
			global $wpdb;
			// Straight to the row, like ClientSeen: no "note added" hooks again.
			$wpdb->update( $wpdb->prefix . 'doublescale_activities', array( 'data' => wp_json_encode( $data ) ), array( 'id' => (int) $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return true;
		}

		$added = ( new TicketService( new ContactResolver() ) )->add_note(
			(int) $conversation['ticket_id'],
			array(
				'content'        => $content,
				// The visitor said it, not someone signed in.
				'author_user_id' => null,
			)
		);
		if ( is_object( $added ) && ! empty( $added->id ) ) {
			ConversationStore::satisfaction_note( (int) $conversation['id'], (int) $added->id );
		}

		return true;
	}

	/**
	 * @param int $score 1 to 5.
	 * @return string A face and a word.
	 */
	public static function score_label( int $score ): string {
		$labels = array(
			1 => '😠 ' . __( 'Terrible', 'doublescale' ),
			2 => '🙁 ' . __( 'Bad', 'doublescale' ),
			3 => '😐 ' . __( 'OK', 'doublescale' ),
			4 => '🙂 ' . __( 'Good', 'doublescale' ),
			5 => '😍 ' . __( 'Great', 'doublescale' ),
		);

		return $labels[ $score ] ?? '';
	}

	/**
	 * The visitor wrote in a live conversation: their reply on the ticket,
	 * with the files they uploaded for it. A message of files only names them.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @param string               $text         Message ('' for files only).
	 * @param string[]             $files        Hashes of files uploaded for it.
	 * @return array{id: int, attachments: array<int, array<string, mixed>>}|WP_Error
	 */
	public static function customer_message( array $conversation, string $text, array $files = array() ) {
		$ticket = (int) $conversation['ticket_id'];
		if ( '' === trim( $text ) ) {
			$names = AttachmentModel::query()
				->forType( AttachmentModel::ATTACHABLE_TYPE )
				->where( 'status', 'temp' )
				->whereIn( 'file_hash', array_values( $files ) )
				->pluck( 'file_name' )
				->all();
			$text  = array() !== $names ? '📎 ' . implode( ', ', array_map( 'strval', $names ) ) : '';
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'help_empty_question', __( 'Please type a question.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Solved, and the visitor only says "thanks, it's solved": kept on the
		// ticket without putting it back in the queue. Anything else opens it.
		$solved  = 'resolved' === self::status( $conversation );
		$closing = array() === $files && $solved && SmallTalk::closing( $text );

		$reply = ( new TicketService( new ContactResolver() ) )->add_reply(
			$ticket,
			array(
				'content'           => wpautop( esc_html( $text ) ),
				'source'            => 'chat',
				// The customer wrote it, even if someone is signed in.
				'author_user_id'    => null,
				'attachment_hashes' => array_values( $files ),
				'keep_status'       => $closing,
			)
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		// The team's ticket screen reads it at once (real-time).
		ChatPush::to_team( (int) $conversation['id'], 'reply' );

		$attachments = self::files_of( (int) $reply->id );
		// What is in their cart now, for the team's side panel (once a minute).
		VisitorCapture::on_message( $conversation );

		return array(
			'id'          => ConversationStore::add_message( (int) $conversation['id'], 'user', $text, array( 'attachments' => $attachments ) ),
			'attachments' => $attachments,
			// It opened a solved conversation again (even if the team solves it
			// again before the chat reads next).
			'reopened'    => $solved && ! $closing,
		);
	}

	/**
	 * A file the visitor uploads in a live conversation, for their next
	 * message (free's AttachmentService: types, size and count limits).
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @param array<string, mixed> $file         PHP upload.
	 * @param int                  $pending      Files already waiting to be sent.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload( array $conversation, array $file, int $pending ) {
		$service = new AttachmentService();
		// The chat says how many wait for its next message; the server counts
		// for itself (files uploaded for this ticket in the last half hour and
		// not sent yet), so a page that lies cannot pile them up. A file taken
		// off before sending stops counting after that half hour (the daily
		// cleanup deletes it). Times are UTC, like the model's.
		$waiting  = (int) AttachmentModel::query()
			->forType( AttachmentModel::ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $conversation['ticket_id'] )
			->where( 'status', 'temp' )
			->where( 'created_at', '>=', gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) )
			->count();
		$too_many = $service->guard_file_count( max( $pending, $waiting ) );
		if ( $too_many ) {
			return $too_many;
		}

		$stored = $service->store_upload(
			$file,
			(int) $conversation['ticket_id'],
			array( 'contact_id' => (int) ( $conversation['contact_id'] ?? 0 ) )
		);
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'file_hash' => (string) $stored->file_hash,
			'file_name' => (string) $stored->file_name,
			'file_size' => (int) $stored->file_size,
			'file_type' => (string) $stored->file_type,
		);
	}

	/**
	 * A reply's files as the chat shows them: name, size, type, signed link.
	 *
	 * @param int $activity_id Reply activity.
	 * @return array<int, array<string, mixed>>
	 */
	private static function files_of( int $activity_id ): array {
		if ( ! class_exists( AttachmentService::class ) ) {
			return array();
		}
		$map = ( new AttachmentService() )->map_for_activities( array( $activity_id ) );

		return array_values(
			array_map(
				static function ( $file ) {
					return array(
						'file_name' => (string) ( $file['file_name'] ?? '' ),
						'file_size' => (int) ( $file['file_size'] ?? 0 ),
						'file_type' => (string) ( $file['file_type'] ?? '' ),
						'url'       => (string) ( $file['url'] ?? '' ),
					);
				},
				$map[ $activity_id ] ?? array()
			)
		);
	}

	/**
	 * A reply was added to a ticket: show it in the conversation it came from.
	 *
	 * @param mixed $activity Reply activity.
	 * @param mixed $ticket   Ticket.
	 * @return void
	 */
	public static function on_reply( $activity, $ticket ): void {
		if ( ! is_object( $activity ) || ! is_object( $ticket ) || ! self::enabled() ) {
			return;
		}
		$conversation = ConversationStore::find_by_ticket( (int) $ticket->id );
		if ( null === $conversation ) {
			return;
		}

		$data = is_array( $activity->data ) ? $activity->data : (array) json_decode( (string) $activity->data, true );
		// The chat's own message, already in the conversation; an automatic
		// system reply is no one talking.
		if ( 'chat' === ( $data['source'] ?? '' ) || ! empty( $data['system'] ) ) {
			return;
		}

		$text = self::plain( (string) ( $data['content'] ?? '' ) );
		if ( '' === $text ) {
			return;
		}

		$author = (int) ( $activity->user_id ?? 0 );

		// A ticket whose address was typed and never proven: only the team's
		// words go to the chat. The customer's own replies (email, portal) come
		// from whoever owns that address, and their files with them.
		if ( $author <= 0 && UnverifiedContact::for_conversation( $conversation ) ) {
			return;
		}

		$files = self::files_of( (int) $activity->id );
		if ( $author > 0 ) {
			ConversationStore::add_message(
				(int) $conversation['id'],
				'agent',
				$text,
				array(
					'author'      => self::first_name( $author ),
					'author_id'   => $author,
					'activity_id' => (int) $activity->id,
					'attachments' => $files,
				)
			);
			// The ticket screen: this reply went to the customer's chat.
			self::mark( (int) $activity->id, 'chat_sent_at' );
			return;
		}

		ConversationStore::add_message( (int) $conversation['id'], 'customer', $text, array( 'attachments' => $files ) );
		ChatPush::to_team( (int) $conversation['id'], 'reply' );
	}

	/**
	 * A reply's HTML as the chat's plain text: paragraphs and line breaks kept.
	 *
	 * @param string $html Reply body.
	 * @return string
	 */
	private static function plain( string $html ): string {
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$text = (string) preg_replace( '#</(p|div|li|h[1-6])>#i', "\n\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );

		return mb_substr( $text, 0, self::MAX_CHARS, 'UTF-8' );
	}

	/**
	 * @param int $user_id Team member.
	 * @return string Their first name, else the first word of their display name.
	 */
	private static function first_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		$name = trim( (string) $user->first_name );
		if ( '' === $name ) {
			$parts = preg_split( '/\s+/u', trim( (string) $user->display_name ) );
			$name  = (string) ( $parts[0] ?? '' );
		}

		return $name;
	}
}
