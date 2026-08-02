<?php
/**
 * InboundTicketRouter — turns FORWARDED inbound email into support tickets.
 *
 * The secondary (fallback) inbound path. The CRM Inbox module polls one inbox
 * and fires
 *
 *     do_action( 'doublescale_email_received', $contact, $activity, $tracking, $data )
 *
 * from {@see \DoubleScale\Pro\Modules\Inbox\Incoming\EmailIncoming}. We subscribe
 * to it and, when the message's recipient (`$data['to_email']`) matches a
 * `box_type='email'` support mailbox, mint/thread a ticket via the shared
 * {@see InboundTicketFactory}. This covers operators who FORWARD support mail
 * into the globally-connected CRM inbox.
 *
 * Runs alongside the primary path, NOT mutually exclusive with it: this router
 * handles EVERY `box_type='email'` mailbox whose address matches the recipient,
 * including receive-capable ones that {@see MailboxImapPoller} also polls. That
 * overlap is deliberate — when one Gmail/Outlook account is wired as BOTH the
 * CRM inbox and a support mailbox, a customer reply reaches the CRM inbox first
 * (it polls recent mail and marks it \Seen), so the per-mailbox poll (unseen
 * only) never sees it. Routing off this event guarantees the reply still lands
 * on its ticket. {@see InboundTicketFactory::route()} dedups by the message's
 * Message-ID (opening message → ticket row; reply → activity), so processing the
 * same physical message from both engines appends it exactly once.
 *
 * Reliability: the whole handler is guarded — a routing bug must never bubble
 * out of the CRM poll loop and break inbound email for every other consumer.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\MailboxModel;

/**
 * InboundTicketRouter class.
 */
final class InboundTicketRouter {

	/**
	 * Shared ticket-minting core.
	 *
	 * @var InboundTicketFactory
	 */
	private $factory;

	/**
	 * Subscribe to the CRM's inbound-email broadcast.
	 *
	 * @param InboundTicketFactory|null $factory Injectable for tests; defaults to a fresh instance.
	 */
	public function __construct( ?InboundTicketFactory $factory = null ) {
		$this->factory = $factory instanceof InboundTicketFactory ? $factory : new InboundTicketFactory();
		add_action( 'doublescale_email_received', array( $this, 'on_email_received' ), 10, 4 );
	}

	/**
	 * Route one inbound email to a support ticket when its recipient matches a
	 * `box_type='email'` mailbox. No-op for every other inbound email (those
	 * remain pure contact-timeline activities).
	 *
	 * @param mixed $contact  ContactModel (free) — unused; TicketService re-resolves from email.
	 * @param mixed $activity ActivityModel (free) — unused.
	 * @param mixed $tracking CommunicationTrackingModel (free) — unused.
	 * @param array $data     Normalized email: from_email, to_email, subject, body, message_id, in_reply_to.
	 * @return void
	 */
	public function on_email_received( $contact, $activity, $tracking, $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $contact/$activity/$tracking are part of the 4-arg hook contract; routing only needs $data.
		try {
			if ( ! is_array( $data ) ) {
				return;
			}

			$to_email = isset( $data['to_email'] ) ? strtolower( trim( (string) $data['to_email'] ) ) : '';
			if ( '' === $to_email ) {
				return;
			}

			$mailbox = $this->match_mailbox( $to_email );
			if ( ! $mailbox ) {
				// Not a support channel — leave it as a contact-timeline email.
				return;
			}

			$this->factory->route( $mailbox, $data );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Inbound support email routing failed',
				array(
					'source'    => 'support-pro-inbound',
					'exception' => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
		}
	}

	/**
	 * Find the `box_type='email'` mailbox whose address matches the recipient,
	 * or null when the recipient is not a support channel.
	 *
	 * Handles plus-addressing: `sales+anything@acme.com` matches a mailbox
	 * registered as `sales@acme.com`. Receive-capable mailboxes are matched here
	 * too (no longer skipped) — see the class docblock; the factory dedups so the
	 * overlap with {@see MailboxImapPoller} appends a message exactly once.
	 *
	 * @param string $to_email Lower-cased recipient address.
	 * @return MailboxModel|null
	 */
	private function match_mailbox( string $to_email ): ?MailboxModel {
		$candidates = array( $to_email );

		$normalized = $this->strip_plus_alias( $to_email );
		if ( $normalized !== $to_email ) {
			$candidates[] = $normalized;
		}

		$mailbox = MailboxModel::where( 'box_type', 'email' )
			->whereIn( 'email', array_unique( $candidates ) )
			->first();

		return $mailbox instanceof MailboxModel ? $mailbox : null;
	}

	/**
	 * Strip a `+alias` sub-address from the local part of an email.
	 * `sales+vip@acme.com` → `sales@acme.com`. Returns the input unchanged when
	 * there is no `+` in the local part.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function strip_plus_alias( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return $email;
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		$plus   = strpos( $local, '+' );
		if ( false === $plus ) {
			return $email;
		}
		return substr( $local, 0, $plus ) . $domain;
	}
}
