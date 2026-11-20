<?php
/**
 * The ticket flag "the email on this ticket was typed and never proven".
 *
 * A chat visitor may type anyone's address; the ticket then joins that
 * person's contact. Free keeps the flag in the ticket's `custom_data`
 * (`unverified_contact`) and refuses to merge a flagged ticket into a real
 * customer's. The chat uses it for the same reason: a conversation whose
 * ticket is flagged never receives the customer's own replies (they come from
 * the person whose address was typed) and is never followed to another
 * ticket after a merge.
 *
 * Free's own helpers are used when this Free has them; the flag is read and
 * written directly in `custom_data` with an older Free, so the two always
 * agree on where it lives.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\TicketService;

/**
 * UnverifiedContact class.
 */
final class UnverifiedContact {

	/**
	 * Where the flag lives in the ticket's custom_data.
	 */
	public const KEY = 'unverified_contact';

	/**
	 * Whether a ticket's address is unproven.
	 *
	 * @param TicketModel|int $ticket Ticket or id.
	 * @return bool
	 */
	public static function is( $ticket ): bool {
		if ( ! class_exists( TicketModel::class ) ) {
			return false;
		}
		if ( method_exists( TicketService::class, 'is_unverified' ) ) {
			return (bool) TicketService::is_unverified( $ticket );
		}
		if ( ! $ticket instanceof TicketModel ) {
			$ticket = TicketModel::find( (int) $ticket );
		}
		if ( ! $ticket ) {
			return false;
		}
		$data = is_array( $ticket->custom_data ) ? $ticket->custom_data : array();

		return ! empty( $data[ self::KEY ] );
	}

	/**
	 * Mark a ticket's address as unproven.
	 *
	 * @param TicketModel|int $ticket Ticket or id.
	 * @return bool Saved.
	 */
	public static function set( $ticket ): bool {
		if ( ! class_exists( TicketModel::class ) ) {
			return false;
		}
		if ( method_exists( TicketService::class, 'set_unverified' ) ) {
			return (bool) TicketService::set_unverified( $ticket );
		}
		if ( ! $ticket instanceof TicketModel ) {
			$ticket = TicketModel::find( (int) $ticket );
		}
		if ( ! $ticket ) {
			return false;
		}
		$data              = is_array( $ticket->custom_data ) ? $ticket->custom_data : array();
		$data[ self::KEY ] = true;
		$ticket->custom_data = $data;

		return (bool) $ticket->save();
	}

	/**
	 * Whether the conversation's ticket is flagged.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return bool
	 */
	public static function for_conversation( array $conversation ): bool {
		return ! empty( $conversation['ticket_id'] ) && self::is( (int) $conversation['ticket_id'] );
	}
}
