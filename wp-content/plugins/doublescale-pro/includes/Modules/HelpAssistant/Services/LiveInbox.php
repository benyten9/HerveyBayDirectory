<?php
/**
 * The team's live chats with something new from the customer: what the chat
 * icon in the admin header (and later the mobile app) shows.
 *
 * A chat counts while its ticket is not closed and the customer wrote after
 * the last message the team had on screen (`team_seen_id`, moved by opening
 * the ticket). Who sees which chat follows the ticket rule: whoever sees
 * every ticket sees every chat; an agent sees the chats of tickets assigned
 * to them.
 *
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;

/**
 * LiveInbox class.
 */
final class LiveInbox {

	/**
	 * Longest preview, in characters.
	 */
	private const PREVIEW = 120;

	/**
	 * Live chat is on: the team answers in the chat (and the module is on).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return LiveChat::enabled()
			&& ( ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( 'support' ) );
	}

	/**
	 * @param int  $user_id Team member.
	 * @param int  $limit   Chats listed.
	 * @param bool $all     Every open live chat (the ticket screen's list), not only those with something new.
	 * @return array{unread: int, chats: array<int, array<string, mixed>>}
	 */
	public static function for_user( int $user_id, int $limit = 20, bool $all = false ): array {
		global $wpdb;
		$conversations = ConversationStore::conversations();
		$messages      = ConversationStore::messages();
		$tickets       = $wpdb->prefix . 'doublescale_support_tickets';
		$contacts      = $wpdb->prefix . 'doublescale_contacts';

		$scope  = "t.status <> 'closed'";
		$params = array();
		if ( class_exists( Permissions::class ) && ! Permissions::can_manage_all_tickets( $user_id ) ) {
			$scope   .= ' AND t.agent_user_id = %d';
			$params[] = $user_id;
		}
		$unread_sql = "(SELECT COUNT(*) FROM {$messages} mu WHERE mu.conversation_id = c.id AND mu.role IN ('user', 'customer') AND mu.id > COALESCE(c.team_seen_id, 0))";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total_sql = "SELECT COUNT(*) FROM {$conversations} c INNER JOIN {$tickets} t ON t.id = c.ticket_id WHERE {$scope} AND {$unread_sql} > 0";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql );

		$list_sql = "SELECT c.id AS conversation_id, c.ticket_id, t.status, {$unread_sql} AS unread,
				(SELECT MAX(ml.id) FROM {$messages} ml WHERE ml.conversation_id = c.id) AS last_id
			FROM {$conversations} c INNER JOIN {$tickets} t ON t.id = c.ticket_id
			WHERE {$scope}" . ( $all ? '' : " AND {$unread_sql} > 0" ) . '
			ORDER BY last_id DESC
			LIMIT %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( max( 1, $limit ) ) ) ), ARRAY_A );

		$chats = array();
		foreach ( (array) $rows as $row ) {
			// Something new: the customer's latest words; else the last message from anyone.
			$last = (int) $row['unread'] > 0
				? $wpdb->get_row( $wpdb->prepare( "SELECT role, content, UNIX_TIMESTAMP(created_at) AS ts FROM {$messages} WHERE conversation_id = %d AND role IN ('user', 'customer') ORDER BY id DESC LIMIT 1", (int) $row['conversation_id'] ), ARRAY_A )
				: $wpdb->get_row( $wpdb->prepare( "SELECT role, content, UNIX_TIMESTAMP(created_at) AS ts FROM {$messages} WHERE id = %d", (int) $row['last_id'] ), ARRAY_A );
			$who  = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ct.first_name, ct.last_name, ct.email, c.visitor_email FROM {$conversations} c INNER JOIN {$tickets} t ON t.id = c.ticket_id LEFT JOIN {$contacts} ct ON ct.id = t.contact_id WHERE c.id = %d",
					(int) $row['conversation_id']
				),
				ARRAY_A
			);
			$name = trim( (string) ( $who['first_name'] ?? '' ) . ' ' . (string) ( $who['last_name'] ?? '' ) );
			if ( '' === $name ) {
				$name = (string) ( $who['email'] ?? $who['visitor_email'] ?? '' );
			}
			$preview = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $last['content'] ?? '' ) ) ) );

			$chats[] = array(
				'conversation_id' => (int) $row['conversation_id'],
				'ticket_id'       => (int) $row['ticket_id'],
				'name'            => $name,
				'preview'         => mb_strlen( $preview, 'UTF-8' ) > self::PREVIEW ? mb_substr( $preview, 0, self::PREVIEW - 1, 'UTF-8' ) . '…' : $preview,
				// Who wrote the last message: customer (user, customer), team (agent) or assistant.
				'last_from'       => in_array( (string) ( $last['role'] ?? '' ), array( 'user', 'customer' ), true ) ? 'customer' : ( 'agent' === ( $last['role'] ?? '' ) ? 'team' : 'assistant' ),
				'status'          => (string) $row['status'],
				'unread'          => (int) $row['unread'],
				'last_id'         => (int) $row['last_id'],
				'at'              => gmdate( 'c', (int) ( $last['ts'] ?? time() ) ),
			);
		}
		// phpcs:enable

		return array(
			'unread' => $total,
			'chats'  => $chats,
		);
	}
}
