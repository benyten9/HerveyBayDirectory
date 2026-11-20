<?php
/**
 * Keep a customer's 1-5 rating on the ticket: score, their words (no markup),
 * when, and where it came from (email or chat). The latest rating replaces
 * the earlier one. A note tells the team, unless the caller writes its own
 * (the live chat keeps one updated note).
 *
 * Fires `doublescale_support_ticket_rated( $ticket, $score, $comment, $source )`.
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;

/**
 * SatisfactionRecorder class.
 */
final class SatisfactionRecorder {

	/**
	 * @param TicketModel $ticket  Ticket.
	 * @param int         $score   1 to 5.
	 * @param string      $comment Their words ('' for none).
	 * @param string      $source  email or chat.
	 * @param bool        $note    Write a note for the team.
	 * @return void
	 */
	public static function record( TicketModel $ticket, int $score, string $comment, string $source, bool $note = true ): void {
		global $wpdb;
		$score   = max( 1, min( 5, $score ) );
		$comment = mb_substr( trim( sanitize_textarea_field( wp_strip_all_tags( $comment ) ) ), 0, 1000, 'UTF-8' );

		// updated_at kept: a rating is not work on the ticket.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}doublescale_support_tickets SET satisfaction = %d, satisfaction_comment = %s, satisfaction_at = NOW(), satisfaction_source = %s, updated_at = updated_at WHERE id = %d",
				$score,
				$comment,
				'chat' === $source ? 'chat' : 'email',
				(int) $ticket->id
			)
		);

		if ( $note ) {
			$face = SatisfactionLinks::faces()[ $score ];
			/* translators: 1: a face and a word (e.g. "🙂 Good"), 2: the score out of five */
			$text = sprintf( __( 'Customer rating: %1$s (%2$d/5)', 'doublescale' ), $face[0] . ' ' . $face[1], $score );
			if ( '' !== $comment ) {
				$text .= "\n\n" . $comment;
			}
			( new TicketService( new ContactResolver() ) )->add_note( $ticket, array( 'content' => wpautop( esc_html( $text ) ), 'author_user_id' => null ) );
		}

		do_action( 'doublescale_support_ticket_rated', $ticket, $score, $comment, $source );
	}
}
