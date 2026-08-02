<?php
/**
 * InboundTicketFactory — mints or threads a support ticket from one inbound email.
 *
 * The routing + thread-matching core shared by BOTH inbound paths:
 *   - {@see InboundTicketRouter} — mail forwarded into the global CRM inbox,
 *     matched to a mailbox by recipient; and
 *   - {@see MailboxImapPoller} — a mailbox's own inbox polled directly over IMAP.
 *
 * The caller supplies the owning mailbox and a normalized message array
 * (`from_email`, `from_name`, `to_email`, `all_recipients`, `subject`, `body`,
 * `message_id`, `in_reply_to`). All ticket work delegates to free's TicketService
 * (resolved from the shared container); this class only decides open-vs-append,
 * dedups by Message-ID (opening message AND reply), threads inbound replies back
 * to their ticket, and parses the sender name.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\EmailReplyParser;
use DoubleScale\Modules\Support\Services\ReplyAddressing;
use DoubleScale\Modules\Support\Services\TicketService;

/**
 * InboundTicketFactory class.
 */
final class InboundTicketFactory {

	/**
	 * Route one inbound message to its mailbox: thread it onto an existing ticket
	 * or open a new one.
	 *
	 * Exactly-once across BOTH inbound engines (the per-mailbox IMAP poller and
	 * the CRM-inbox router, which can both see the same physical message when one
	 * account is wired as CRM inbox + support mailbox). Dedup is by Message-ID:
	 * a message that already OPENED a ticket is caught on the indexed ticket row,
	 * and one already APPENDED as a reply is caught via free's reply-level guard
	 * ({@see TicketService::reply_exists_by_message_id()}). Callers may therefore
	 * deliver the same message more than once without producing duplicates.
	 *
	 * @param MailboxModel $mailbox Owning mailbox.
	 * @param array        $data    Normalized email data.
	 * @return bool True when a ticket was opened or a reply appended; false when skipped.
	 */
	public function route( MailboxModel $mailbox, array $data ): bool {
		// Never ticket our OWN outbound mail. When a mailbox sends FROM and
		// receives AT the same address (e.g. a Gmail box mailing itself its own
		// "We received your request" notification), the CRM's notification lands
		// back in the polled inbox and would loop into an endless ticket/reply
		// chain. CRM-originated mail carries the `X-Plugin-Sent` stamp that
		// ImapClient surfaces as `crm_sent`. The IMAP poller already skips these,
		// but we re-check here as the single chokepoint that ALSO covers the
		// forwarding router ({@see InboundTicketRouter}).
		if ( ! empty( $data['crm_sent'] ) ) {
			return false;
		}

		$service = $this->ticket_service();
		if ( ! $service ) {
			return false;
		}

		// Idempotency guard for both inbound engines. Opening messages store the
		// Message-ID on the ticket row; replies store it on the activity. Check
		// both so the same physical email — seen by the per-mailbox poll AND the
		// CRM-inbox router — is ingested exactly once.
		$message_id = isset( $data['message_id'] ) ? trim( (string) $data['message_id'] ) : '';
		if ( '' !== $message_id ) {
			if ( TicketModel::where( 'message_id', $message_id )->exists() ) {
				return false;
			}
			if ( $service->reply_exists_by_message_id( $message_id ) ) {
				return false;
			}
		}

		$ticket = $this->find_thread_ticket( $data );

		if ( $ticket ) {
			$this->append_reply( $service, $ticket, $data );
		} else {
			// Don't OPEN a new ticket for automated/no-reply senders (bounces,
			// mailer-daemon, newsletters from a no-reply address). These are not
			// real customer requests. A message that threads onto an EXISTING
			// ticket above is still appended — only brand-new opens are filtered,
			// so a legitimate conversation is never dropped.
			$from_email = isset( $data['from_email'] ) ? (string) $data['from_email'] : '';
			if ( $this->is_automated_sender( $from_email ) ) {
				return false;
			}

			$this->open_ticket( $service, $mailbox, $data );
		}

		return true;
	}

	/**
	 * Whether the sender address is an automated/no-reply mailbox that must not
	 * open a support ticket (delivery-failure bounces, mailer-daemon, postmaster,
	 * and the common no-reply local parts). Mirrors the Inbox module's
	 * {@see \DoubleScale\Pro\Modules\Inbox\Incoming\EmailIncoming::is_excluded_sender()}
	 * local-part blocklist so ticket and CRM ingestion behave consistently.
	 *
	 * @param string $email_address Sender email address.
	 * @return bool True when the sender is automated/no-reply.
	 */
	private function is_automated_sender( string $email_address ): bool {
		$at         = strpos( $email_address, '@' );
		$local_part = false !== $at ? strtolower( substr( $email_address, 0, $at ) ) : strtolower( $email_address );

		$blocked_local_parts = array(
			'noreply',
			'no-reply',
			'no_reply',
			'donotreply',
			'do-not-reply',
			'mailer-daemon',
			'postmaster',
		);

		return in_array( $local_part, $blocked_local_parts, true );
	}

	/**
	 * Resolve the ticket this email belongs to, or null to open a new one.
	 *
	 * Threading order (most → least reliable):
	 *   1. Reply-To plus-address marker on a recipient (`…+ticket-{id}-{token}@…`).
	 *      PRIMARY: it survives providers that rewrite the Message-ID on relay
	 *      (Gmail/Outlook), and the token is verified against the ticket hash.
	 *   2. Our structured Message-ID in In-Reply-To (`doublescale-support-{id}-…`).
	 *   3. `support_tickets.message_id` matching the In-Reply-To value.
	 *   4. `[Ticket #N]` in the subject (defence in depth).
	 *   5. Miss → null (caller opens a new ticket).
	 *
	 * @param array $data Normalized email data.
	 * @return TicketModel|null
	 */
	private function find_thread_ticket( array $data ): ?TicketModel {
		$in_reply_to = isset( $data['in_reply_to'] ) ? (string) $data['in_reply_to'] : '';
		$subject     = isset( $data['subject'] ) ? (string) $data['subject'] : '';

		// 1. Reply-To plus-address marker — the signal that survives Message-ID
		// rewriting. The customer replies to the plus-tagged Reply-To, so the
		// ticket id rides in on the inbound recipient. Verify the token against
		// the ticket hash so a tampered/guessed id can't thread into another
		// ticket. The format is owned by free's ReplyAddressing (single source of
		// truth for both the outbound stamp and this parse).
		if ( class_exists( ReplyAddressing::class ) ) {
			foreach ( $this->candidate_recipients( $data ) as $recipient ) {
				$parsed = ReplyAddressing::parse_recipient( $recipient );
				if ( null === $parsed ) {
					continue;
				}
				$ticket = TicketModel::find( (int) $parsed['ticket_id'] );
				if ( $ticket instanceof TicketModel
					&& ReplyAddressing::token_matches( (string) $ticket->hash, (string) $parsed['token'] ) ) {
					return $ticket;
				}
			}
		}

		// 2. Structured outbound Message-ID pattern.
		if ( '' !== $in_reply_to && preg_match( '/doublescale-support-(\d+)-/', $in_reply_to, $m ) ) {
			$ticket = TicketModel::find( (int) $m[1] );
			if ( $ticket instanceof TicketModel ) {
				return $ticket;
			}
		}

		// 3. Original-thread root stored on the ticket.
		if ( '' !== $in_reply_to ) {
			$ticket = TicketModel::where( 'message_id', $in_reply_to )->first();
			if ( $ticket instanceof TicketModel ) {
				return $ticket;
			}
		}

		// 4. Subject tag fallback.
		if ( '' !== $subject && preg_match( '/\[\s*Ticket\s*#(\d+)\s*\]/i', $subject, $m ) ) {
			$ticket = TicketModel::find( (int) $m[1] );
			if ( $ticket instanceof TicketModel ) {
				return $ticket;
			}
		}

		return null;
	}

	/**
	 * Recipient addresses to scan for a Reply-To plus-address ticket marker: the
	 * primary `To` plus every `To`/`CC` address the fetcher surfaced (so the tag
	 * is found whether the customer's client put it on To or CC). Lower-cased and
	 * de-duplicated.
	 *
	 * @param array $data Normalized email data.
	 * @return string[]
	 */
	private function candidate_recipients( array $data ): array {
		$recipients = array();

		if ( ! empty( $data['to_email'] ) ) {
			$recipients[] = (string) $data['to_email'];
		}
		if ( ! empty( $data['all_recipients'] ) && is_array( $data['all_recipients'] ) ) {
			foreach ( $data['all_recipients'] as $recipient ) {
				$recipients[] = (string) $recipient;
			}
		}

		return array_values( array_unique( array_map( 'strtolower', $recipients ) ) );
	}

	/**
	 * Append the email as a customer reply on an existing ticket.
	 *
	 * `author_user_id` is null → customer-authored. Free's TicketService /
	 * EmailNotifications already handle "customer reply re-opens a closed ticket"
	 * and "don't email the customer their own message back". The body is run
	 * through free's {@see EmailReplyParser} first so the thread shows only what
	 * the customer typed, not the quoted copy of the agent's previous message the
	 * mail client appended underneath it.
	 *
	 * @param TicketService $service Free ticket service.
	 * @param TicketModel   $ticket  Target ticket.
	 * @param array         $data    Normalized email data.
	 * @return void
	 */
	private function append_reply( TicketService $service, TicketModel $ticket, array $data ): void {
		$body = isset( $data['body'] ) ? (string) $data['body'] : '';
		if ( '' !== $body && class_exists( EmailReplyParser::class ) ) {
			$body = EmailReplyParser::strip_quoted_reply( $body );
		}

		$service->add_reply(
			$ticket,
			array(
				'content'          => $body,
				'source'           => 'email',
				'message_id'       => isset( $data['message_id'] ) ? (string) $data['message_id'] : '',
				'in_reply_to'      => isset( $data['in_reply_to'] ) ? (string) $data['in_reply_to'] : '',
				'author_user_id'   => null,
				'attachment_files' => isset( $data['attachments'] ) && is_array( $data['attachments'] ) ? $data['attachments'] : array(),
			)
		);
	}

	/**
	 * Open a new ticket from an inbound email.
	 *
	 * TicketService resolves/creates the contact from `email` via ContactResolver,
	 * so we just pass the sender address + names parsed from `from_name`.
	 *
	 * @param TicketService $service Free ticket service.
	 * @param MailboxModel  $mailbox Owning mailbox.
	 * @param array         $data    Normalized email data.
	 * @return void
	 */
	private function open_ticket( TicketService $service, MailboxModel $mailbox, array $data ): void {
		$from_email = isset( $data['from_email'] ) ? (string) $data['from_email'] : '';
		if ( '' === $from_email ) {
			return;
		}

		$name = $this->split_name( isset( $data['from_name'] ) ? (string) $data['from_name'] : '' );

		$subject = isset( $data['subject'] ) ? trim( (string) $data['subject'] ) : '';
		if ( '' === $subject ) {
			$subject = __( '(no subject)', 'doublescale' );
		}

		$service->create_ticket(
			array(
				'title'            => $subject,
				'content'          => isset( $data['body'] ) ? (string) $data['body'] : '',
				'email'            => $from_email,
				'first_name'       => $name['first'],
				'last_name'        => $name['last'],
				'mailbox_id'       => (int) $mailbox->id,
				'source'           => 'email',
				'message_id'       => isset( $data['message_id'] ) ? (string) $data['message_id'] : '',
				'attachment_files' => isset( $data['attachments'] ) && is_array( $data['attachments'] ) ? $data['attachments'] : array(),
			)
		);
	}

	/**
	 * Split a display name into first / last parts. Single-token names become the
	 * first name with an empty last name.
	 *
	 * @param string $name Display name.
	 * @return array{first:string,last:string}
	 */
	private function split_name( string $name ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return array(
				'first' => '',
				'last'  => '',
			);
		}
		$parts = preg_split( '/\s+/', $name );
		$first = array_shift( $parts );
		$last  = $parts ? implode( ' ', $parts ) : '';
		return array(
			'first' => (string) $first,
			'last'  => (string) $last,
		);
	}

	/**
	 * Resolve free's TicketService from the shared container.
	 *
	 * @return TicketService|null
	 */
	private function ticket_service(): ?TicketService {
		if ( ! function_exists( 'doublescale_resolve' ) ) {
			return null;
		}
		$service = doublescale_resolve( TicketService::class );
		return $service instanceof TicketService ? $service : null;
	}
}
