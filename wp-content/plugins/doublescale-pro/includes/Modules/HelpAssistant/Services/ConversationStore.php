<?php
/**
 * Read and write help assistant conversations and their messages.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Support\Services\TeamPresence;

/**
 * ConversationStore class.
 */
final class ConversationStore {

	/**
	 * Start a conversation.
	 *
	 * @param string               $channel  admin, portal, widget, help_page.
	 * @param string               $audience Visibility tier the answers draw from.
	 * @param int                  $user_id  Signed-in user, 0 for a visitor.
	 * @param array<string, mixed> $extra    contact_id, ip_hash (a salted hash, never the address).
	 * @return array<string, mixed> The row.
	 */
	public static function create( string $channel, string $audience, int $user_id, array $extra = array() ): array {
		global $wpdb;
		// Lower case: routes read it back through sanitize_key().
		$hash = strtolower( wp_generate_password( 32, false, false ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::conversations(),
			array(
				'hash'       => $hash,
				'channel'    => $channel,
				'audience'   => $audience,
				'user_id'    => $user_id > 0 ? $user_id : null,
				'contact_id' => ! empty( $extra['contact_id'] ) ? (int) $extra['contact_id'] : null,
				'ip_hash'    => isset( $extra['ip_hash'] ) ? (string) $extra['ip_hash'] : null,
			)
		);

		return (array) self::find( $hash );
	}

	/**
	 * @param string $hash Conversation hash.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $hash ): ?array {
		global $wpdb;
		$table = self::conversations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE hash = %s", $hash ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int $id Conversation id.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_id( int $id ): ?array {
		global $wpdb;
		$table = self::conversations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The last $limit messages, oldest first, as role/content pairs.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $limit           Messages.
	 * @return array<int, array{role: string, content: string}>
	 */
	public static function history( int $conversation_id, int $limit ): array {
		global $wpdb;
		$table = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT role, content FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d", $conversation_id, $limit ), ARRAY_A );

		return array_reverse( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every message of a conversation, oldest first, with its citations (JSON).
	 *
	 * @param int $conversation_id Conversation id.
	 * @return array<int, array{role: string, content: string, citations: string|null}>
	 */
	public static function transcript( int $conversation_id ): array {
		global $wpdb;
		$table = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT role, content, citations, author FROM {$table} WHERE conversation_id = %d ORDER BY id ASC", $conversation_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Change a conversation's status, ticket, contact or visitor email.
	 *
	 * @param int                  $conversation_id Conversation id.
	 * @param array<string, mixed> $fields          status, ticket_id, contact_id, visitor_email.
	 * @return void
	 */
	public static function update( int $conversation_id, array $fields ): void {
		$fields = array_intersect_key( $fields, array_flip( array( 'status', 'ticket_id', 'contact_id', 'visitor_email' ) ) );
		if ( array() === $fields ) {
			return;
		}

		global $wpdb;
		$updated = $wpdb->update( self::conversations(), $fields, array( 'id' => $conversation_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $updated && function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->error(
				'Help assistant conversation could not be updated',
				array(
					// Never the database's own text: it can quote the query.
					'conversation' => $conversation_id,
					'code'         => 'help_conversation_update_failed',
				)
			);
		}
	}

	/**
	 * What the team sees about the visitor (VisitorCapture): the pages their
	 * browser kept and their cart. Names, paths and totals only.
	 *
	 * @param int                  $conversation_id Conversation id.
	 * @param array<string, mixed> $context         pages, cart.
	 * @return void
	 */
	public static function set_context( int $conversation_id, array $context ): void {
		global $wpdb;
		$wpdb->update( self::conversations(), array( 'visitor_context' => wp_json_encode( $context ) ), array( 'id' => $conversation_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return array<string, mixed>
	 */
	public static function context( array $conversation ): array {
		$context = json_decode( (string) ( $conversation['visitor_context'] ?? '' ), true );

		return is_array( $context ) ? $context : array();
	}

	/**
	 * Record a customer's vote on one answer of their conversation; the
	 * conversation keeps the latest vote as its rating.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $message_id      Assistant message id.
	 * @param int $helpful         1 or 0.
	 * @return bool False when the message is not an answer in that conversation.
	 */
	public static function rate( int $conversation_id, int $message_id, int $helpful ): bool {
		global $wpdb;
		$table = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND conversation_id = %d AND role = 'assistant'", $message_id, $conversation_id ) );
		if ( 1 !== $found ) {
			return false;
		}

		$wpdb->update( $table, array( 'feedback' => $helpful ), array( 'id' => $message_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( self::conversations(), array( 'rating' => $helpful ), array( 'id' => $conversation_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return true;
	}

	/**
	 * Delete a conversation and its messages.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return void
	 */
	public static function delete( int $conversation_id ): void {
		global $wpdb;
		$wpdb->delete( self::messages(), array( 'conversation_id' => $conversation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( self::conversations(), array( 'id' => $conversation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Claim a conversation for handing over: one request at a time. A claim
	 * left by a request that died frees itself after two minutes. updated_at is
	 * set explicitly: re-writing the same status alone changes no row, and
	 * MySQL would report 0 affected rows for a stale claim.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return bool True when this request holds the claim.
	 */
	public static function claim_handoff( int $conversation_id ): bool {
		global $wpdb;
		$table = self::conversations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'handing_off', updated_at = NOW() WHERE id = %d AND ticket_id IS NULL AND ( status <> 'handing_off' OR updated_at < NOW() - INTERVAL 2 MINUTE )", $conversation_id ) );

		return 1 === (int) $affected;
	}

	/**
	 * Questions asked in a conversation so far.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return int
	 */
	public static function count_questions( int $conversation_id ): int {
		global $wpdb;
		$table = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d AND role = 'user'", $conversation_id ) );
	}

	/**
	 * Store one message and keep the conversation's totals.
	 *
	 * @param int                  $conversation_id Conversation id.
	 * @param string               $role            user or assistant.
	 * @param string               $content         Text.
	 * @param array<string, mixed> $meta            citations, answered, tokens_in, tokens_out, latency_ms, author, author_id, activity_id, attachments.
	 * @return int Message id.
	 */
	public static function add_message( int $conversation_id, string $role, string $content, array $meta = array() ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::messages(),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => $content,
				'author'          => isset( $meta['author'] ) ? mb_substr( (string) $meta['author'], 0, 100, 'UTF-8' ) : null,
				'author_id'       => ! empty( $meta['author_id'] ) ? (int) $meta['author_id'] : null,
				'activity_id'     => ! empty( $meta['activity_id'] ) ? (int) $meta['activity_id'] : null,
				'citations'       => isset( $meta['citations'] ) ? wp_json_encode( $meta['citations'] ) : null,
				'attachments'     => ! empty( $meta['attachments'] ) ? wp_json_encode( array_values( (array) $meta['attachments'] ) ) : null,
				'answered'        => array_key_exists( 'answered', $meta ) ? (int) (bool) $meta['answered'] : null,
				'tokens_in'       => (int) ( $meta['tokens_in'] ?? 0 ),
				'tokens_out'      => (int) ( $meta['tokens_out'] ?? 0 ),
				'latency_ms'      => (int) ( $meta['latency_ms'] ?? 0 ),
			)
		);
		$id = (int) $wpdb->insert_id;

		$table = self::conversations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET message_count = message_count + 1, tokens_in = tokens_in + %d, tokens_out = tokens_out + %d WHERE id = %d", (int) ( $meta['tokens_in'] ?? 0 ), (int) ( $meta['tokens_out'] ?? 0 ), $conversation_id ) );

		// A team or email reply: the visitor's chat reads it at once (real-time).
		if ( $id > 0 && in_array( $role, array( 'agent', 'customer' ), true ) ) {
			ChatPush::to_visitor( $conversation_id, 'message' );
		}
		return $id;
	}

	/**
	 * The conversation handed over as $ticket_id, if any.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_ticket( int $ticket_id ): ?array {
		global $wpdb;
		$table = self::conversations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id DESC LIMIT 1", $ticket_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Messages the chat did not write itself (team replies, the customer's own
	 * replies by email or portal) after message $after, oldest first.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $after           Last message id the chat has.
	 * @return array<int, array{id: int, role: string, text: string, author: string, attachments: array<int, array<string, mixed>>, at: string}>
	 */
	public static function arrived( int $conversation_id, int $after ): array {
		global $wpdb;
		$table = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, role, content, author, author_id, attachments, created_at FROM {$table} WHERE conversation_id = %d AND id > %d AND role IN ('agent','customer') ORDER BY id ASC LIMIT 50", $conversation_id, $after ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				return array(
					'id'     => (int) $row['id'],
					'role'   => (string) $row['role'],
					'text'   => (string) $row['content'],
					'author'      => (string) ( $row['author'] ?? '' ),
					'avatar'      => ! empty( $row['author_id'] ) ? TeamPresence::photo( (int) $row['author_id'] ) : '',
					'attachments' => array_values( (array) json_decode( (string) ( $row['attachments'] ?? '' ), true ) ),
					'at'          => mysql_to_rfc3339( (string) $row['created_at'] ),
				);
			},
			$rows
		);
	}

	/**
	 * The team member who answered last: name, photo and when they were last
	 * in wp-admin; null before anyone answered.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return array{name: string, avatar: string, seen_at: string|null}|null
	 */
	public static function last_agent( int $conversation_id ): ?array {
		global $wpdb;
		$table = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT author, author_id FROM {$table} WHERE conversation_id = %d AND role = 'agent' AND author_id IS NOT NULL ORDER BY id DESC LIMIT 1", $conversation_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$seen = TeamPresence::seen( (int) $row['author_id'] );

		return array(
			'name'    => (string) $row['author'],
			'avatar'  => TeamPresence::photo( (int) $row['author_id'] ),
			'seen_at' => null !== $seen ? gmdate( 'c', $seen ) : null,
		);
	}

	/**
	 * The team had the conversation on screen: everything in it so far is
	 * seen by them. Only ever moves forward.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return void
	 */
	/**
	 * Seconds a typing mark lasts: the team's screen reads every 10 s, so a
	 * mark must outlive one read or it would blink.
	 */
	public const TYPING_FOR = 12;

	/**
	 * The visitor (visitor_typing_at) or a team member (team_typing_at, by
	 * $user_id) is typing: a time only, never what they type. Written at most
	 * every 4 s per conversation (the UPDATE matches an older mark), and
	 * updated_at is kept: typing is not a message.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $who             visitor or team.
	 * @param int    $user_id         The team member (team only).
	 * @return void
	 */
	public static function mark_typing( int $conversation_id, string $who, int $user_id = 0 ): void {
		global $wpdb;
		$table  = self::conversations();
		$column = 'team' === $who ? 'team_typing_at' : 'visitor_typing_at';
		$by     = 'team' === $who ? $wpdb->prepare( ', team_typing_by = %d', $user_id ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = NOW(){$by}, updated_at = updated_at WHERE id = %d AND ( {$column} IS NULL OR {$column} < NOW() - INTERVAL 4 SECOND )", $conversation_id ) );
		// Newly typing (at most every 4 s): the other side shows it at once.
		if ( $wpdb->rows_affected > 0 ) {
			if ( 'team' === $who ) {
				ChatPush::to_visitor( $conversation_id, 'typing' );
			} else {
				ChatPush::to_team( $conversation_id, 'typing' );
			}
		}
	}

	/**
	 * Whether the visitor or the team typed in the last {@see TYPING_FOR} seconds.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $who             visitor or team.
	 * @return bool
	 */
	public static function is_typing( int $conversation_id, string $who ): bool {
		global $wpdb;
		$table  = self::conversations();
		$column = 'team' === $who ? 'team_typing_at' : 'visitor_typing_at';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND {$column} >= NOW() - INTERVAL %d SECOND", $conversation_id, self::TYPING_FOR ) );
	}

	/**
	 * Whether the visitor had the team reply from ticket reply $activity_id on
	 * screen (its message is at or before the last one they saw).
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $activity_id     Ticket reply the message came from.
	 * @return bool
	 */
	public static function reply_seen( int $conversation_id, int $activity_id ): bool {
		global $wpdb;
		$messages = self::messages();
		$table    = self::conversations();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} m INNER JOIN {$table} c ON c.id = m.conversation_id WHERE m.conversation_id = %d AND m.activity_id = %d AND c.visitor_seen_id >= m.id", $conversation_id, $activity_id ) );
	}

	public static function team_saw( int $conversation_id ): void {
		global $wpdb;
		$table    = self::conversations();
		$messages = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$messages} WHERE conversation_id = %d", $conversation_id ) );
		if ( $last <= 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(team_seen_id, 0) FROM {$table} WHERE id = %d", $conversation_id ) );
		// updated_at is kept: a look is not activity (claim_handoff reads it).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET team_seen_id = %d, updated_at = updated_at WHERE id = %d AND ( team_seen_id IS NULL OR team_seen_id < %d )", $last, $conversation_id, $last ) );
		if ( $wpdb->rows_affected > 0 ) {
			// Only news when it covers something the customer wrote: their
			// chat shows "Seen", the team's chat icons drop it from the count.
			// (The team's own reply moving the mark is not news to anyone.)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$theirs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages} WHERE conversation_id = %d AND role IN ('user', 'customer') AND id > %d AND id <= %d", $conversation_id, $before, $last ) );
			if ( $theirs > 0 ) {
				ChatPush::to_visitor( $conversation_id, 'seen' );
				ChatPush::to_team( $conversation_id, 'seen' );
			}
		}
	}

	/**
	 * The ticket replies these chat messages came from.
	 *
	 * @param int   $conversation_id Conversation id.
	 * @param int[] $message_ids     Chat message ids.
	 * @return int[] Activity ids.
	 */
	public static function reply_ids( int $conversation_id, array $message_ids ): array {
		$message_ids = array_values( array_filter( array_map( 'intval', $message_ids ) ) );
		if ( array() === $message_ids ) {
			return array();
		}
		global $wpdb;
		$messages = self::messages();
		$in       = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT activity_id FROM {$messages} WHERE conversation_id = %d AND role = 'agent' AND activity_id IS NOT NULL AND id IN ({$in})", array_merge( array( $conversation_id ), $message_ids ) ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The visitor had the team's replies up to message $upto on screen: the
	 * ticket replies they came from that were not seen before. Only ever
	 * moves forward.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $upto            Last message the chat shows.
	 * @return int[] Ticket reply activity ids now seen.
	 */
	public static function visitor_saw( int $conversation_id, int $upto ): array {
		global $wpdb;
		$table    = self::conversations();
		$messages = self::messages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$from = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(visitor_seen_id, 0) FROM {$table} WHERE id = %d", $conversation_id ) );
		// Never past the last message there is: the chat says what it shows,
		// and a number from the future would mark later replies seen early.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$upto = min( $upto, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id), 0) FROM {$messages} WHERE conversation_id = %d", $conversation_id ) ) );
		if ( $upto <= $from ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET visitor_seen_id = %d, updated_at = updated_at WHERE id = %d", $upto, $conversation_id ) );
		// The customer read the team's replies: the ticket screen marks them read.
		ChatPush::to_team( $conversation_id, 'read' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT activity_id FROM {$messages} WHERE conversation_id = %d AND role = 'agent' AND activity_id IS NOT NULL AND id > %d AND id <= %d", $conversation_id, $from, $upto ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The visitor's rating of the team (1-5) and their words, latest wins.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param int    $score           1 to 5.
	 * @param string $comment         '' for none.
	 * @return void
	 */
	public static function satisfaction( int $conversation_id, int $score, string $comment ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::conversations(),
			array(
				'satisfaction'         => $score,
				'satisfaction_comment' => '' !== $comment ? $comment : null,
				'satisfaction_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $conversation_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The ticket note that holds the conversation's rating.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $note_id         Activity id of the note.
	 * @return void
	 */
	public static function satisfaction_note( int $conversation_id, int $note_id ): void {
		global $wpdb;
		$wpdb->update( self::conversations(), array( 'satisfaction_note_id' => $note_id ), array( 'id' => $conversation_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/** @return string */
	public static function conversations(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_help_conversations';
	}

	/** @return string */
	public static function messages(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_help_messages';
	}
}
