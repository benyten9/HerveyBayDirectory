<?php
/**
 * What the owner reads about the assistant: the customer conversations log
 * and the questions the articles held no answer to.
 *
 * Staff previews ("Try the assistant", channel admin) are left out: they are
 * not customers. Conversation hashes never leave this class — a hash is a
 * customer's only key to their chat.
 *
 * Outcome of a conversation:
 *   handed_off  it became a support ticket
 *   unanswered  at least one question got the "no answer" reply
 *   answered    every question was answered
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reporting reads over our own tables.

/**
 * Insights class.
 */
final class Insights {

	public const OUTCOMES = array( 'answered', 'unanswered', 'handed_off' );

	/**
	 * Unanswered questions scanned for the report (most recent first).
	 */
	private const UNANSWERED_SCAN = 2000;

	/**
	 * A page of customer conversations, newest first.
	 *
	 * @param array<string, mixed> $filters channel, outcome, search, page, per_page.
	 * @return array{data: array<int, array<string, mixed>>, total: int}
	 */
	public static function conversations( array $filters ): array {
		global $wpdb;
		$conv = ConversationStore::conversations();
		$msgs = ConversationStore::messages();

		$where  = array( "c.channel <> 'admin'" );
		$params = array();
		self::scope( $where, $params );
		if ( ! empty( $filters['channel'] ) && in_array( $filters['channel'], HelpSettings::CHANNELS, true ) ) {
			$where[]  = 'c.channel = %s';
			$params[] = (string) $filters['channel'];
		}
		$missed = "EXISTS ( SELECT 1 FROM {$msgs} a WHERE a.conversation_id = c.id AND a.role = 'assistant' AND a.answered = 0 )";
		switch ( $filters['outcome'] ?? '' ) {
			case 'handed_off':
				$where[] = 'c.ticket_id IS NOT NULL';
				break;
			case 'unanswered':
				$where[] = "c.ticket_id IS NULL AND {$missed}";
				break;
			case 'answered':
				$where[] = "c.ticket_id IS NULL AND NOT {$missed}";
				break;
		}
		if ( '' !== (string) ( $filters['search'] ?? '' ) ) {
			$where[]  = "EXISTS ( SELECT 1 FROM {$msgs} s WHERE s.conversation_id = c.id AND s.content LIKE %s )";
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 100, (int) ( $filters['per_page'] ?? 20 ) ) );
		$offset    = ( max( 1, (int) ( $filters['page'] ?? 1 ) ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$conv} c WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.channel, c.audience, c.status, c.ticket_id, c.rating, c.satisfaction, c.satisfaction_comment, c.message_count, c.created_at,
					( SELECT q.content FROM {$msgs} q WHERE q.conversation_id = c.id AND q.role = 'user' ORDER BY q.id ASC LIMIT 1 ) AS first_question,
					{$missed} AS missed
				FROM {$conv} c WHERE {$where_sql} ORDER BY c.id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$data = array();
		foreach ( (array) $rows as $row ) {
			$data[] = self::shape( $row );
		}

		return array(
			'data'  => $data,
			'total' => $total,
		);
	}

	/**
	 * Who reads which chats: a chat handed to the team is part of its ticket,
	 * so it follows the ticket's rule (free's RestTicketController): whoever
	 * sees every ticket reads every chat; an agent reads the chats nobody
	 * handed over and those of the tickets assigned to them.
	 *
	 * @param string[]          $where  SQL conditions on `c`, added to.
	 * @param array<int, mixed> $params Their values, added to.
	 * @return void
	 */
	private static function scope( array &$where, array &$params ): void {
		if ( ! class_exists( Permissions::class ) || Permissions::can_manage_all_tickets() ) {
			return;
		}
		global $wpdb;
		$tickets  = $wpdb->prefix . 'doublescale_support_tickets';
		$where[]  = "( c.ticket_id IS NULL OR EXISTS ( SELECT 1 FROM {$tickets} t WHERE t.id = c.ticket_id AND t.agent_user_id = %d ) )";
		$params[] = get_current_user_id();
	}

	/**
	 * One customer conversation with every message.
	 *
	 * @param int $id Conversation id.
	 * @return array<string, mixed>|null
	 */
	public static function conversation( int $id ): ?array {
		global $wpdb;
		$conv = ConversationStore::conversations();
		$msgs = ConversationStore::messages();

		$where  = array( 'c.id = %d', "c.channel <> 'admin'" );
		$params = array( $id );
		self::scope( $where, $params );
		$where_sql = implode( ' AND ', $where );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.id, c.channel, c.audience, c.status, c.ticket_id, c.rating, c.satisfaction, c.satisfaction_comment, c.message_count, c.created_at, c.visitor_email, c.user_id,
					( SELECT q.content FROM {$msgs} q WHERE q.conversation_id = c.id AND q.role = 'user' ORDER BY q.id ASC LIMIT 1 ) AS first_question,
					EXISTS ( SELECT 1 FROM {$msgs} a WHERE a.conversation_id = c.id AND a.role = 'assistant' AND a.answered = 0 ) AS missed
				FROM {$conv} c WHERE {$where_sql}",
				$params
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$messages = $wpdb->get_results( $wpdb->prepare( "SELECT id, role, content, citations, answered, feedback, created_at FROM {$msgs} WHERE conversation_id = %d ORDER BY id ASC", $id ), ARRAY_A );
		$out      = array();
		foreach ( (array) $messages as $message ) {
			$citations = json_decode( (string) $message['citations'], true );
			$out[]     = array(
				'id'         => (int) $message['id'],
				'role'       => (string) $message['role'],
				'content'    => (string) $message['content'],
				'citations'  => is_array( $citations ) ? $citations : array(),
				'answered'   => null === $message['answered'] ? null : (bool) $message['answered'],
				'feedback'   => null === $message['feedback'] ? null : (int) $message['feedback'],
				'created_at' => (string) $message['created_at'],
			);
		}

		$conversation                  = self::shape( $row );
		$conversation['visitor_email'] = (string) ( $row['visitor_email'] ?? '' );
		$user                          = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : false;
		$conversation['user']          = $user ? $user->display_name : '';

		return array(
			'conversation' => $conversation,
			'messages'     => $out,
		);
	}

	/**
	 * How visitors rated the team's help in the last $days: the average (one
	 * decimal), how many ratings, and how many of each score.
	 *
	 * @param int $days Look back this many days.
	 * @return array{average: float|null, count: int, scores: array<int, int>}
	 */
	public static function satisfaction( int $days ): array {
		global $wpdb;
		$conv  = ConversationStore::conversations();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT satisfaction AS score, COUNT(*) AS n FROM {$conv} WHERE satisfaction IS NOT NULL AND satisfaction_at >= %s AND channel <> 'admin' GROUP BY satisfaction", $since ), ARRAY_A );

		$scores = array_fill( 1, 5, 0 );
		$count  = 0;
		$sum    = 0;
		foreach ( $rows as $row ) {
			$score = (int) $row['score'];
			if ( $score < 1 || $score > 5 ) {
				continue;
			}
			$scores[ $score ] = (int) $row['n'];
			$count           += (int) $row['n'];
			$sum             += $score * (int) $row['n'];
		}

		return array(
			'average' => $count > 0 ? round( $sum / $count, 1 ) : null,
			'count'   => $count,
			'scores'  => $scores,
		);
	}

	/**
	 * The questions answered "not found", grouped, most asked first.
	 *
	 * @param int $days Look back this many days.
	 * @return array<int, array{question: string, count: int, last_asked: string, channels: string[]}>
	 */
	public static function unanswered( int $days ): array {
		global $wpdb;
		$conv = ConversationStore::conversations();
		$msgs = ConversationStore::messages();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.created_at, c.channel,
					( SELECT u.content FROM {$msgs} u WHERE u.conversation_id = a.conversation_id AND u.role = 'user' AND u.id < a.id ORDER BY u.id DESC LIMIT 1 ) AS question
				FROM {$msgs} a JOIN {$conv} c ON c.id = a.conversation_id
				WHERE a.role = 'assistant' AND a.answered = 0 AND c.channel <> 'admin' AND a.created_at >= NOW() - INTERVAL %d DAY
				ORDER BY a.id DESC LIMIT %d",
				max( 1, $days ),
				self::UNANSWERED_SCAN
			),
			ARRAY_A
		);

		// One row per topic: "Can you design a logo for me?" and "…a logo
		// for my shop?" are the same gap in the articles. Questions are
		// grouped by their topic words (Retriever::terms()), merged when
		// they share most of them (same_topic()).
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$question = trim( (string) $row['question'] );
			if ( '' === AnswerCache::normalize( $question ) ) {
				continue;
			}
			$terms = array_values( array_unique( Retriever::terms( $question ) ) );
			$found = null;
			foreach ( $groups as $index => $group ) {
				if ( self::same_topic( $group['terms'], $terms ) ) {
					$found = $index;
					break;
				}
			}
			if ( null === $found ) {
				// Newest first: the first one seen is the latest wording.
				$groups[] = array(
					'question'   => $question,
					'count'      => 0,
					'last_asked' => (string) $row['created_at'],
					'channels'   => array(),
					'terms'      => $terms,
				);
				$found    = count( $groups ) - 1;
			}
			++$groups[ $found ]['count'];
			$groups[ $found ]['channels'][ (string) $row['channel'] ] = true;
		}

		$out = array();
		foreach ( $groups as $group ) {
			$group['channels'] = array_keys( $group['channels'] );
			unset( $group['terms'] );
			$out[] = $group;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'] ?: strcmp( $b['last_asked'], $a['last_asked'] );
			}
		);

		return array_slice( $out, 0, 100 );
	}

	/**
	 * Whether two questions ask about the same thing: the same topic words,
	 * or at least two shared and together two thirds of all of them
	 * ("design logo" and "design logo shop"; not "logo" and "logo price").
	 *
	 * @param string[] $a Topic words of one question.
	 * @param string[] $b Topic words of the other.
	 * @return bool
	 */
	public static function same_topic( array $a, array $b ): bool {
		$shared = count( array_intersect( $a, $b ) );
		$all    = count( array_unique( array_merge( $a, $b ) ) );
		if ( 0 === $all ) {
			return false;
		}
		if ( $shared === $all ) {
			return true;
		}

		return $shared >= 2 && $shared / $all >= 2 / 3;
	}

	/**
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		if ( ! empty( $row['ticket_id'] ) ) {
			$outcome = 'handed_off';
		} else {
			$outcome = ! empty( $row['missed'] ) ? 'unanswered' : 'answered';
		}

		return array(
			'id'             => (int) $row['id'],
			'channel'        => (string) $row['channel'],
			'audience'       => (string) $row['audience'],
			'first_question' => (string) ( $row['first_question'] ?? '' ),
			'messages'       => (int) $row['message_count'],
			'outcome'        => $outcome,
			'ticket_id'      => empty( $row['ticket_id'] ) ? null : (int) $row['ticket_id'],
			'rating'         => null === $row['rating'] ? null : (int) $row['rating'],
			// The visitor's rating of the team's help (1-5) and their words.
			'satisfaction'         => isset( $row['satisfaction'] ) && null !== $row['satisfaction'] ? (int) $row['satisfaction'] : null,
			'satisfaction_comment' => (string) ( $row['satisfaction_comment'] ?? '' ),
			'created_at'     => (string) $row['created_at'],
		);
	}
}
