<?php
/**
 * When the team hears about a live chat message (Intercom style).
 *
 * A visitor in the help chat sends many short messages. Telling the team
 * about each one floods their inbox and makes each message wait for the
 * emails. Instead:
 * - None while a team member has the ticket on screen (the ticket screen
 *   reads its conversation every few seconds) or had it in the last two
 *   minutes: they see it there.
 * - Otherwise at most once per ticket every ten minutes.
 * The notice itself is sent by a background task (SupportNotifications).
 *
 * Two short-lived transients per ticket; nothing else is stored.
 *
 * @package DoubleScale\Modules\Notifications
 */

namespace DoubleScale\Modules\Notifications\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ChatReplyNotice class.
 */
final class ChatReplyNotice {

	/** Seen this recently by a team member: no notice. */
	private const VIEWING = 2 * MINUTE_IN_SECONDS;

	/** At most one notice per ticket this often. */
	private const QUIET = 10 * MINUTE_IN_SECONDS;

	/**
	 * A team member has the ticket's conversation on screen. Written at most
	 * once a minute, though the screen reads every few seconds.
	 *
	 * @param int $ticket_id Ticket.
	 * @return void
	 */
	public static function viewed( int $ticket_id ): void {
		$key  = 'ds_ticket_viewed_' . $ticket_id;
		$last = (int) get_transient( $key );
		if ( $last > 0 && time() - $last < MINUTE_IN_SECONDS ) {
			return;
		}
		set_transient( $key, time(), self::VIEWING );
	}

	/**
	 * @param int $ticket_id Ticket.
	 * @return bool A team member had it on screen in the last two minutes.
	 */
	public static function viewed_lately( int $ticket_id ): bool {
		$last = (int) get_transient( 'ds_ticket_viewed_' . $ticket_id );
		return $last > 0 && time() - $last < self::VIEWING;
	}

	/**
	 * @param int $ticket_id Ticket.
	 * @return bool The team was told about this ticket's chat in the last ten minutes.
	 */
	public static function noticed_lately( int $ticket_id ): bool {
		return false !== get_transient( 'ds_chat_noticed_' . $ticket_id );
	}

	/**
	 * @param int $ticket_id Ticket.
	 * @return void
	 */
	public static function noticed( int $ticket_id ): void {
		set_transient( 'ds_chat_noticed_' . $ticket_id, time(), self::QUIET );
	}
}
