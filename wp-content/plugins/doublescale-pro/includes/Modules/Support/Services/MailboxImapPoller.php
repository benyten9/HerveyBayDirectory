<?php
/**
 * MailboxImapPoller — the PRIMARY inbound path for support email channels.
 *
 * Runs on the recurring `doublescale_support_email_inbound` action (group
 * `doublescale_support`, scheduled by {@see \DoubleScale\Pro\Modules\Support\Module}).
 * For each `box_type='email'` mailbox it resolves the mailbox's IMAP config and
 * polls that address's OWN inbox directly, routing each message to a ticket via
 * the shared {@see InboundTicketFactory}. Two credential sources, tried in order:
 *   1. Gmail/Outlook OAuth, keyed off the from_email
 *      ({@see \DoubleScale\Modules\Smtp\Settings::get_support_imap_config_for_email()}); else
 *   2. custom IMAP credentials stored on the mailbox `data.imap` block
 *      ({@see \DoubleScale\Modules\Smtp\Settings::build_custom_imap_config()}, auth `login`)
 *      — for inboxes on generic providers with no OAuth.
 * "IMAP uses the credentials of this email directly" — no shared global inbox,
 * no forwarding required.
 *
 * Dedup: we fetch only UNSEEN messages and {@see ImapClient::mark_as_seen()}
 * each one after it is routed, so a message is normally processed once per
 * inbox. Seen-tracking is the first line of defence; the factory's Message-ID
 * guard ({@see InboundTicketFactory::route()}, covering BOTH opening messages and
 * replies via free's {@see \DoubleScale\Modules\Support\Services\TicketService::reply_exists_by_message_id()})
 * is the backstop that keeps ingestion exactly-once when the same physical
 * message also reaches the CRM-inbox router ({@see InboundTicketRouter}).
 *
 * Guarded end-to-end: a per-mailbox IMAP/routing error is logged
 * (`source='support-pro-imap'`) and never breaks the scheduler or the other
 * mailboxes' polls.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Tracking\ImapClient;
use DoubleScale\Pro\Modules\Inbox\Incoming\GraphMailClient;

/**
 * MailboxImapPoller class.
 */
final class MailboxImapPoller {

	/**
	 * Per-mailbox messages fetched per run (cap to avoid PHP timeouts).
	 */
	private const FETCH_LIMIT = 20;

	/**
	 * Shared ticket-minting core.
	 *
	 * @var InboundTicketFactory
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param InboundTicketFactory|null $factory Injectable for tests; defaults to a fresh instance.
	 */
	public function __construct( ?InboundTicketFactory $factory = null ) {
		$this->factory = $factory instanceof InboundTicketFactory ? $factory : new InboundTicketFactory();
	}

	/**
	 * Scheduler entry point: poll every email channel.
	 *
	 * @return void
	 */
	public function run(): void {
		try {
			$mailboxes = MailboxModel::where( 'box_type', 'email' )->get();
		} catch ( \Throwable $e ) {
			// Table not ready / DB hiccup — never fatal the scheduled run.
			doublescale_get_logger()->error(
				'Support IMAP poll could not list mailboxes',
				array(
					'source'    => 'support-pro-imap',
					'exception' => $e->getMessage(),
				)
			);
			return;
		}

		foreach ( $mailboxes as $mailbox ) {
			$this->poll_mailbox( $mailbox );
		}
	}

	/**
	 * Poll a single mailbox's bound inbox and route new messages to tickets.
	 *
	 * @param MailboxModel $mailbox Email channel mailbox.
	 * @return void
	 */
	private function poll_mailbox( MailboxModel $mailbox ): void {
		$client = null;
		try {
			$config = $this->resolve_imap_config( $mailbox );
			if ( ! is_array( $config ) ) {
				// Genuinely send-only: no OAuth account AND no custom-IMAP block.
				// Nothing to poll. Info-level: a routine, expected skip.
				doublescale_get_logger()->info(
					'Support mailbox is not receive-capable; IMAP poll skipped',
					array(
						'source'     => 'support-pro-imap',
						'mailbox_id' => (int) $mailbox->id,
					)
				);
				return;
			}

			// Outlook resolves to a graph-tagged config — receive over Microsoft
			// Graph instead of IMAP (same drop-in surface, so the loop below is
			// transport-agnostic). Everything else stays on ImapClient.
			if ( 'graph' === ( $config['transport'] ?? '' ) ) {
				$client = GraphMailClient::from_config( $config );
			} else {
				$client = new ImapClient(
					(string) ( $config['host'] ?? '' ),
					(int) ( $config['port'] ?? 993 ),
					(string) ( $config['username'] ?? '' ),
					(string) ( $config['password'] ?? '' ),
					(string) ( $config['encryption'] ?? 'ssl' ),
					(string) ( $config['authentication'] ?? 'oauth' )
				);
			}
			$client->connect();

			// Only ingest mail that arrived after this mailbox was connected, so
			// the inbox's pre-existing unread backlog (newsletters, bounces, …)
			// never becomes tickets. `created_at` is the connection moment; we
			// pass its DATE as a server-side SINCE floor (coarse, day-granular)
			// and enforce the exact timestamp per message below.
			$cutoff_ts = $this->mailbox_cutoff_timestamp( $mailbox );

			$seen_in_batch = array();
			foreach ( $client->fetch_unseen( self::FETCH_LIMIT, $cutoff_ts ? gmdate( 'Y-m-d', $cutoff_ts ) : null ) as $message ) {
				// Skip our own outbound (threaded replies carry the X-Plugin-Sent
				// stamp the ImapClient flags as crm_sent).
				if ( ! empty( $message['crm_sent'] ) ) {
					$this->mark_seen( $client, $message );
					continue;
				}

				// Precise cutoff: drop messages that arrived before the mailbox was
				// connected (SINCE only filters by day). Do NOT mark them seen —
				// these are the user's pre-existing unread mail and must stay
				// untouched in their inbox; we simply never ticket them.
				if ( null !== $cutoff_ts && $this->is_before_cutoff( $message, $cutoff_ts ) ) {
					continue;
				}

				$message_id = isset( $message['message_id'] ) ? (string) $message['message_id'] : '';
				if ( '' !== $message_id && isset( $seen_in_batch[ $message_id ] ) ) {
					continue;
				}
				$seen_in_batch[ $message_id ] = true;

				$this->factory->route( $mailbox, $message );

				// Mark seen regardless of route() outcome: a dup/no-op must not be
				// re-fetched next cycle (which, for a reply, would double-append).
				$this->mark_seen( $client, $message );
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support mailbox IMAP poll failed',
				array(
					'source'     => 'support-pro-imap',
					'mailbox_id' => (int) $mailbox->id,
					'exception'  => $e->getMessage(),
					'file'       => $e->getFile(),
					'line'       => $e->getLine(),
				)
			);
		} finally {
			// $client is ImapClient or GraphMailClient — both expose disconnect().
			if ( $client instanceof ImapClient || $client instanceof GraphMailClient ) {
				$client->disconnect();
			}
		}
	}

	/**
	 * Resolve a mailbox's IMAP config, trying OAuth first then custom credentials.
	 *
	 * The two-source resolution, isolated from the socket I/O in {@see poll_mailbox()}
	 * so it is unit-testable:
	 *   1. Gmail/Outlook OAuth keyed off `data.identity.from_email`
	 *      ({@see \DoubleScale\Modules\Smtp\Settings::get_support_imap_config_for_email()}); else
	 *   2. the mailbox's own custom-IMAP block under `data.imap`
	 *      ({@see \DoubleScale\Modules\Smtp\Settings::build_custom_imap_config()}, auth `login`) —
	 *      the third option for inboxes on generic providers with no OAuth.
	 *
	 * Returns null when the mailbox has no from_email, free's Smtp\Settings is
	 * unavailable, or neither source yields a config (a genuinely send-only box).
	 * `protected` so a test double can drive it without a live IMAP socket.
	 *
	 * @param MailboxModel $mailbox Email channel mailbox.
	 * @return array<string, mixed>|null Ready-to-poll IMAP config, or null.
	 */
	protected function resolve_imap_config( MailboxModel $mailbox ): ?array {
		$data       = is_array( $mailbox->data ) ? $mailbox->data : array();
		$from_email = isset( $data['identity']['from_email'] ) ? (string) $data['identity']['from_email'] : '';
		if ( '' === $from_email || ! class_exists( '\DoubleScale\Modules\Smtp\Settings' ) ) {
			return null;
		}

		$config = \DoubleScale\Modules\Smtp\Settings::get_support_imap_config_for_email( $from_email );
		if ( is_array( $config ) ) {
			return $config;
		}

		// No Gmail/Outlook OAuth account for this address — fall back to the
		// mailbox's own custom-IMAP credentials (password decrypted at poll time).
		// Returns null when no custom block is configured.
		$config = \DoubleScale\Modules\Smtp\Settings::build_custom_imap_config( $data );
		return is_array( $config ) ? $config : null;
	}

	/**
	 * Mark a fetched message as seen so it isn't re-processed next cycle.
	 *
	 * No typehint on $client: it is ImapClient OR GraphMailClient, both of which
	 * expose mark_as_seen(). A union typehint can't be used (project floor is
	 * PHP 7.4), and both clients are constructed locally in poll_mailbox() so the
	 * looser type is safe.
	 *
	 * @param ImapClient|GraphMailClient $client  Connected client.
	 * @param array                      $message Normalized message (carries the `uid`).
	 * @return void
	 */
	private function mark_seen( $client, array $message ): void {
		if ( ! empty( $message['uid'] ) ) {
			$client->mark_as_seen( $message['uid'] );
		}
	}

	/**
	 * Resolve the mailbox's "ingest only after" cutoff as a Unix timestamp.
	 *
	 * Uses the mailbox `created_at` (the moment the email channel was connected):
	 * mail older than this is the inbox's pre-existing backlog and must not open
	 * tickets. Returns null when no usable created_at is present (then no date
	 * filter is applied — preserves the previous "fetch all unseen" behaviour).
	 *
	 * @param MailboxModel $mailbox Email channel mailbox.
	 * @return int|null Unix timestamp, or null when unavailable.
	 */
	private function mailbox_cutoff_timestamp( MailboxModel $mailbox ): ?int {
		$created = $mailbox->created_at ?? null;
		if ( null === $created ) {
			return null;
		}

		// Eloquent date cast exposes a Carbon instance; fall back to string parse.
		if ( is_object( $created ) && method_exists( $created, 'getTimestamp' ) ) {
			return (int) $created->getTimestamp();
		}

		$ts = strtotime( (string) $created );
		return false !== $ts ? $ts : null;
	}

	/**
	 * Whether a fetched message arrived strictly before the cutoff timestamp.
	 *
	 * Parses the message's `date` (raw RFC 2822 `Date:` header). A message with
	 * no parseable date is treated as NOT-before-cutoff (i.e. kept) — we never
	 * drop a message just because its date header is missing/odd.
	 *
	 * @param array $message  Normalized message.
	 * @param int   $cutoff_ts Unix timestamp floor.
	 * @return bool True when the message predates the cutoff.
	 */
	private function is_before_cutoff( array $message, int $cutoff_ts ): bool {
		$raw = isset( $message['date'] ) ? (string) $message['date'] : '';
		if ( '' === $raw ) {
			return false;
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return false;
		}
		return $ts < $cutoff_ts;
	}
}
