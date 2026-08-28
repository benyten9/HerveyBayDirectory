<?php
/**
 * Outbound email notifications for the support module (Free).
 *
 * Subscribes to the ticket-lifecycle hooks emitted by {@see TicketService} and
 * sends the matching customer-facing email through {@see Emails::send()} (which
 * the SMTP module intercepts). This is the *outbound* half of the email
 * pipeline — the inbound IMAP engine that creates tickets FROM email is a Pro
 * feature and lives elsewhere.
 *
 * Customer-facing ONLY: admin / agent alerts are owned by Pro's
 * SupportNotifications. This service never emails staff.
 *
 * Sender identity + transport: support mail sends from the TICKET'S MAILBOX
 * sending identity (`data.identity.from_email`), and ONLY that — there is no
 * fallback to the replying agent, the shared CRM email, or the admin address.
 * Delivery is additionally PINNED to the SMTP connection that sends from that
 * address (see {@see self::dispatch()}) so a multi-connection install can't route
 * the mail through the wrong SMTP connection. A mailbox with no sending identity
 * — or a from_email no SMTP connection sends from — causes the outbound email to
 * be skipped + logged (the reply still saves). Operators set the sending identity
 * per mailbox in the Support → Mailboxes editor.
 *
 * Per-mailbox templates: each event's enabled-state + subject + body resolve
 * per-mailbox override → built-in default ({@see self::default_templates()}).
 * Operator subject/body templates support {token} placeholders (see
 * {@see self::tokens()}).
 *
 * Reliability: every listener is wrapped so a broken SMTP transport can never
 * abort the REST request that triggered it (an agent's reply must still be
 * saved even if the notification email fails). Failures are logged through
 * `doublescale_get_logger()` with `source='support-email-notifications'`.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\AttachmentService;
use DoubleScale\Modules\Support\Services\PortalUrl;

/**
 * EmailNotifications class.
 */
final class EmailNotifications {

	/**
	 * Built-in fallback enabled-state per event — used when a mailbox doesn't set
	 * `enabled` for the event. Anything not present defaults to enabled so a fresh
	 * install emails out of the box.
	 *
	 * @var array<string, bool>
	 */
	private const NOTIFICATION_DEFAULTS = array(
		'ticket_created_to_customer' => true,
		'reply_to_customer'          => true,
		'status_change_to_customer'  => true,
	);

	/**
	 * Wire the hook listeners.
	 */
	public function __construct() {
		add_action( 'doublescale_support_ticket_created', array( $this, 'on_ticket_created' ), 10, 1 );
		add_action( 'doublescale_support_reply_created', array( $this, 'on_reply_created' ), 10, 2 );
		add_action( 'doublescale_support_ticket_updated', array( $this, 'on_ticket_updated' ), 10, 3 );
	}

	// ---------------------------------------------------------------------
	// Hook listeners
	// ---------------------------------------------------------------------

	/**
	 * Ticket opened → confirmation email to the customer.
	 *
	 * @param TicketModel $ticket Newly created ticket.
	 * @return void
	 */
	public function on_ticket_created( $ticket ): void {
		$this->safely(
			$ticket,
			function ( TicketModel $t ) {
				$tpl = $this->resolve_template( $t, 'ticket_created_to_customer' );
				if ( ! $tpl['enabled'] ) {
					return;
				}
				$email = $this->customer_email( $t );
				if ( '' === $email ) {
					return;
				}

				$tokens = $this->tokens( $t );
				if ( '' === $tokens['ticket_public_url'] && str_contains( $tpl['body'], '{ticket_public_url}' ) ) {
					doublescale_get_logger()->warning(
						'{ticket_public_url} is empty — publish a page with the [doublescale_support_portal] shortcode.',
						array(
							'source'    => 'support-email-notifications',
							'ticket_id' => (int) $t->id,
						)
					);
				}
				$subject = $this->render( $tpl['subject'], $tokens );
				$inner   = $this->render( $tpl['body'], $tokens );

				$this->dispatch( $t, $email, $subject, $this->wrap_body( $t, $inner ) );
			}
		);
	}

	/**
	 * Agent reply → email the customer.
	 *
	 * Only fires for AGENT replies: a reply activity carries a `user_id` when an
	 * agent authored it and NULL when the customer did (the portal forces
	 * `author_user_id = null`). Emailing on a NULL-author reply would send the
	 * customer their own message back.
	 *
	 * @param mixed       $activity The reply activity row (ActivityModel).
	 * @param TicketModel $ticket   The parent ticket.
	 * @return void
	 */
	public function on_reply_created( $activity, $ticket ): void {
		// Customer-authored reply (no agent user_id) — nothing to send outward.
		if ( ! is_object( $activity ) || empty( $activity->user_id ) ) {
			return;
		}

		$this->safely(
			$ticket,
			function ( TicketModel $t ) use ( $activity ) {
				$tpl = $this->resolve_template( $t, 'reply_to_customer' );
				if ( ! $tpl['enabled'] ) {
					return;
				}
				$email = $this->customer_email( $t );
				if ( '' === $email ) {
					return;
				}

				$data    = is_array( $activity->data ) ? $activity->data : array();
				$content = isset( $data['content'] ) ? (string) $data['content'] : '';

				$tokens  = $this->tokens( $t, array( 'reply_content' => $content ) );
				$subject = $this->render( $tpl['subject'], $tokens );
				// Default body links to the portal; operators can add {reply_content}
				// to include the agent message inline.
				$inner = $this->render( $tpl['body'], $tokens );

				$attachments = array();
				if ( is_object( $activity ) && ! empty( $activity->id ) ) {
					$attachments = ( new AttachmentService() )->absolute_paths_for_activity( (int) $activity->id );
				}

				// CC the recipients the agent added on THIS reply. The list was
				// validated and stored on the activity by TicketService::add_reply;
				// dispatch() puts it on the outbound Cc: header.
				$cc = isset( $data['cc'] ) && is_array( $data['cc'] ) ? $data['cc'] : array();

				// From is the mailbox's sending identity (not the agent's) — see
				// dispatch() / sender_identity().
				$this->dispatch( $t, $email, $subject, $this->wrap_body( $t, $inner ), $attachments, $cc );
			}
		);
	}

	/**
	 * Status moved to resolved/closed → notify the customer.
	 *
	 * Only the status transition is emailed; other field changes (priority,
	 * agent reassignment) are agent-internal and produce no customer mail here.
	 *
	 * @param TicketModel $ticket    Ticket after the update.
	 * @param array       $effective Keys that changed, with new values.
	 * @param array       $before    Same keys, with pre-save values.
	 * @return void
	 */
	public function on_ticket_updated( $ticket, $effective, $before ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $before is required by the 3-arg hook signature; only the new value ($effective) is needed here.
		if ( ! is_array( $effective ) || ! array_key_exists( 'status', $effective ) ) {
			return;
		}
		$new_status = (string) $effective['status'];
		if ( ! in_array( $new_status, array( TicketStatus::RESOLVED, TicketStatus::CLOSED ), true ) ) {
			return;
		}

		$this->safely(
			$ticket,
			function ( TicketModel $t ) use ( $new_status ) {
				$tpl = $this->resolve_template( $t, 'status_change_to_customer' );
				if ( ! $tpl['enabled'] ) {
					return;
				}
				$email = $this->customer_email( $t );
				if ( '' === $email ) {
					return;
				}

				$label   = TicketStatus::get_label( $new_status );
				$tokens  = $this->tokens( $t, array( 'ticket_status' => $label ) );
				$subject = $this->render( $tpl['subject'], $tokens );
				$inner   = $this->render( $tpl['body'], $tokens );

				$this->dispatch( $t, $email, $subject, $this->wrap_body( $t, $inner ) );
			}
		);
	}

	// ---------------------------------------------------------------------
	// Send
	// ---------------------------------------------------------------------

	/**
	 * Build an {@see Emails} instance with the mailbox sender identity and send,
	 * pinning delivery to that mailbox's own SMTP connection.
	 *
	 * @param TicketModel $ticket      Ticket — supplies the mailbox sending identity and logging context.
	 * @param string      $to          Recipient email.
	 * @param string      $subject     Subject line.
	 * @param string      $body        HTML body.
	 * @param array       $attachments Absolute file paths to attach.
	 * @param string[]    $cc          CC recipients for this message (already validated upstream).
	 * @return void
	 */
	private function dispatch( TicketModel $ticket, string $to, string $subject, string $body, array $attachments = array(), array $cc = array() ): void {
		$identity = $this->sender_identity( $ticket );

		// No resolvable sending identity on the mailbox → do NOT send. A support
		// mailbox must carry its own sending identity so the channel's From is
		// deterministic; there is no fallback to the agent / shared / admin
		// identity. The triggering reply/ticket has already been persisted, so
		// skipping the email leaves the agent's action intact and surfaces the
		// misconfiguration in the log + the mailbox UI.
		if ( null === $identity ) {
			// Error level: a customer notification the operator enabled silently
			// not going out is an operator-facing failure they need to see (the
			// mailbox is misconfigured). error/critical/alert/emergency are the
			// levels the shared logger persists at the default threshold, so this
			// diagnostic actually lands without depending on a raised log_level.
			doublescale_get_logger()->error(
				'Support mailbox has no sending identity; outbound email skipped',
				array(
					'source'     => 'support-email-notifications',
					'ticket_id'  => (int) $ticket->id,
					'mailbox_id' => $ticket->mailbox_id ? (int) $ticket->mailbox_id : null,
					'recipient'  => $to,
				)
			);
			return;
		}

		if ( '' === $identity['connection_id'] ) {
			// Identity set, but no SMTP connection sends from this address. We do
			// NOT fall through to the global default route (it would deliver under a
			// right-looking From with misaligned SPF/DKIM) — skip + log instead.
			doublescale_get_logger()->error(
				'No SMTP connection sends from the support mailbox address; outbound email skipped',
				array(
					'source'       => 'support-email-notifications',
					'ticket_id'    => (int) $ticket->id,
					'mailbox_id'   => $ticket->mailbox_id ? (int) $ticket->mailbox_id : null,
					'from_address' => $identity['from_address'],
					'recipient'    => $to,
				)
			);
			return;
		}

		$emails               = new Emails();
		$emails->from_address = $identity['from_address'];
		$emails->from_name    = $identity['from_name'];

		// Thread inbound replies back to THIS ticket. The Reply-To carries a
		// plus-addressed ticket token the inbound parser reads off the recipient —
		// this survives providers that rewrite the Message-ID on relay (Gmail/
		// Outlook). The structured Message-ID is the secondary signal for providers
		// that preserve it. {@see ReplyAddressing} owns the format; Pro's inbound
		// factory parses it with the same class.
		$emails->reply_to   = ReplyAddressing::build_reply_to( $identity['reply_to'], (int) $ticket->id, (string) $ticket->hash );
		$host               = wp_parse_url( home_url(), PHP_URL_HOST );
		$emails->message_id = ReplyAddressing::build_message_id( (int) $ticket->id, is_string( $host ) ? $host : '' );

		// In-Reply-To / References make THIS message nest inside the existing
		// conversation in the CUSTOMER'S inbox. We point at the customer's last
		// inbound message (or their opening email) — a Message-ID that already
		// exists in their thread. This is purely cosmetic on the customer side;
		// our own inbound threading relies on the Reply-To plus-address above, not
		// on this header. Empty for web/portal-opened tickets with no inbound id.
		$parent_message_id = $this->thread_parent_message_id( $ticket );
		if ( '' !== $parent_message_id ) {
			$emails->in_reply_to = $parent_message_id;
		}

		// Stamp [Ticket #N] on the subject so the inbound subject-tag matcher
		// (the last-ditch fallback) has something to read when every header and
		// the plus-address have been stripped by an intermediary.
		$subject = $this->ensure_ticket_tag( $subject, (int) $ticket->id );

		// CC recipients for this message. Emails::get_cc() validates each address
		// again and builds the Cc: header; an empty list leaves $emails->cc at its
		// `false` default so no header is emitted.
		if ( ! empty( $cc ) ) {
			$emails->cc = implode( ',', $cc );
		}

		// Pin delivery to the mailbox's OWN connection. Emails::send() routes
		// through the SMTP module's PHPMailerOverride, which reads this filter via
		// get_smart_route() inside wp_mail(); the one-shot add/remove scopes the
		// override to exactly this send so a later mail isn't mis-routed.
		$connection_id = $identity['connection_id'];
		$pin           = static function () use ( $connection_id ) {
			return $connection_id;
		};
		add_filter( 'doublescale_smtp_explicit_connection', $pin );
		try {
			$result = $emails->send( $to, $subject, $body, $attachments );
		} finally {
			remove_filter( 'doublescale_smtp_explicit_connection', $pin );
		}

		if ( $result ) {
			doublescale_get_logger()->info(
				'Support notification email sent',
				array(
					'source'    => 'support-email-notifications',
					'recipient' => $to,
					'subject'   => $subject,
					'ticket_id' => (int) $ticket->id,
				)
			);
		} else {
			doublescale_get_logger()->warning(
				'Failed to send support notification email',
				array(
					'source'    => 'support-email-notifications',
					'recipient' => $to,
					'subject'   => $subject,
					'ticket_id' => (int) $ticket->id,
				)
			);
		}
	}

	/**
	 * Resolve the From / Reply-To (and the SMTP connection to pin) from the
	 * TICKET'S MAILBOX sending identity.
	 *
	 * The mailbox stores ONLY its From address (`data.identity.from_email`), set in
	 * the Support → Mailboxes editor. That address is the sole From source — there
	 * is no fallback to the replying agent, the shared CRM email, or the admin
	 * address — so every reply in a mailbox sends from the channel's address
	 * regardless of which agent replied. From-name and Reply-To are derived, not
	 * stored: the From name comes from the SMTP connection that sends from this
	 * address (falling back to the site name), and Reply-To is the From address.
	 * `connection_id` is that same connection ({@see \DoubleScale\Modules\Smtp\Settings::get_connection_id_for_from_email()})
	 * so {@see dispatch()} can pin transport; it is '' when no connection sends from
	 * the address, which dispatch() treats as "skip + log".
	 *
	 * Returns NULL only when the mailbox has no from_email at all.
	 *
	 * @param TicketModel $ticket Ticket whose mailbox identity to resolve.
	 * @return array{connection_id: string, from_address: string, from_name: string, reply_to: string}|null
	 */
	private function sender_identity( TicketModel $ticket ): ?array {
		$mailbox = $ticket->mailbox;
		if ( ! $mailbox ) {
			return null;
		}

		$data       = is_array( $mailbox->data ) ? $mailbox->data : array();
		$identity   = isset( $data['identity'] ) && is_array( $data['identity'] ) ? $data['identity'] : array();
		$from_email = isset( $identity['from_email'] ) ? sanitize_email( (string) $identity['from_email'] ) : '';
		if ( '' === $from_email ) {
			return null;
		}

		// The mailbox stores only the From address; the SMTP connection that sends
		// from it supplies the From name (and is what we pin transport to). No
		// matching connection → '' → dispatch() skips + logs.
		$connection_id = '';
		$from_name     = '';
		if ( class_exists( '\DoubleScale\Modules\Smtp\Settings' ) ) {
			$connection_id = \DoubleScale\Modules\Smtp\Settings::get_connection_id_for_from_email( $from_email );
			if ( '' !== $connection_id ) {
				$resolved = \DoubleScale\Modules\Smtp\Settings::get_identity_for_connection( $connection_id );
				if ( is_array( $resolved ) && ! empty( $resolved['from_name'] ) ) {
					$from_name = (string) $resolved['from_name'];
				}
			}
		}
		if ( '' === $from_name ) {
			$from_name = (string) get_bloginfo( 'name' );
		}

		return array(
			'connection_id' => $connection_id,
			'from_address'  => $from_email,
			'from_name'     => $from_name,
			'reply_to'      => $from_email,
		);
	}

	/**
	 * The Message-ID our outbound mail should thread under in the customer's
	 * inbox: the most recent INBOUND (customer-authored) reply we recorded a
	 * Message-ID for, falling back to the opening email's Message-ID stored on
	 * the ticket. Agent replies never carry a Message-ID in their activity data,
	 * so the JSON filter already isolates customer mail; the `user_id IS NULL`
	 * clause is belt-and-braces. Best-effort: any failure yields '' (no header).
	 *
	 * @param TicketModel $ticket Ticket whose latest inbound id to resolve.
	 * @return string Parent Message-ID (with angle brackets), or '' when none.
	 */
	private function thread_parent_message_id( TicketModel $ticket ): string {
		try {
			$activity = ActivityModel::forTicket( (int) $ticket->id )
				->where( 'activity_type', ActivityTypes::SUPPORT_REPLY )
				->whereNull( 'user_id' )
				->whereRaw( "JSON_UNQUOTE(JSON_EXTRACT(data, '\$.message_id')) <> ''" )
				->orderBy( 'id', 'desc' )
				->first();

			if ( $activity ) {
				$data = is_array( $activity->data ) ? $activity->data : array();
				if ( ! empty( $data['message_id'] ) ) {
					return (string) $data['message_id'];
				}
			}
		} catch ( \Throwable $e ) {
			// Threading is best-effort and must never block the send.
			unset( $e );
		}

		return isset( $ticket->message_id ) ? (string) $ticket->message_id : '';
	}

	/**
	 * Ensure the subject carries a `[Ticket #N]` tag (idempotent). Skips when a
	 * tag is already present so an operator template or a customer's quoted
	 * subject isn't double-stamped.
	 *
	 * @param string $subject   Rendered subject.
	 * @param int    $ticket_id Ticket id.
	 * @return string Subject guaranteed to contain a ticket tag.
	 */
	private function ensure_ticket_tag( string $subject, int $ticket_id ): string {
		if ( $ticket_id <= 0 ) {
			return $subject;
		}
		if ( preg_match( '/\[\s*Ticket\s*#\d+\s*\]/i', $subject ) ) {
			return $subject;
		}
		return trim( $subject ) . ' [Ticket #' . $ticket_id . ']';
	}

	// ---------------------------------------------------------------------
	// Template resolution
	// ---------------------------------------------------------------------

	/**
	 * Resolve a notification event to its effective `{enabled, subject, body}`: the
	 * built-in default ({@see self::default_templates()}) overlaid by the mailbox's
	 * own override. A non-empty override subject/body wins; an empty one leaves the
	 * built-in copy in place, so the returned subject/body are always render-ready.
	 * `enabled` defaults from {@see self::NOTIFICATION_DEFAULTS} and is overridden
	 * by the mailbox. The override tolerates the legacy flat-bool toggle shape (a
	 * bare bool = enabled-state only).
	 *
	 * @param TicketModel $ticket Ticket (supplies the mailbox).
	 * @param string      $event  Event key.
	 * @return array{enabled:bool, subject:string, body:string}
	 */
	private function resolve_template( TicketModel $ticket, string $event ): array {
		$defaults = self::default_templates();
		$default  = isset( $defaults[ $event ] ) ? $defaults[ $event ] : array(
			'subject' => '',
			'body'    => '',
		);

		$enabled = self::NOTIFICATION_DEFAULTS[ $event ] ?? false;
		$subject = (string) $default['subject'];
		$body    = (string) $default['body'];

		$overrides = $this->mailbox_templates( $ticket );
		if ( array_key_exists( $event, $overrides ) ) {
			$tpl = $overrides[ $event ];

			// Legacy flat toggle: a bare bool only carries the enabled state.
			if ( is_bool( $tpl ) ) {
				$enabled = $tpl;
			} elseif ( is_array( $tpl ) ) {
				if ( array_key_exists( 'enabled', $tpl ) ) {
					$enabled = (bool) $tpl['enabled'];
				}
				if ( isset( $tpl['subject'] ) && '' !== trim( (string) $tpl['subject'] ) ) {
					$subject = (string) $tpl['subject'];
				}
				if ( isset( $tpl['body'] ) && '' !== trim( (string) $tpl['body'] ) ) {
					$body = (string) $tpl['body'];
				}
			}
		}

		return array(
			'enabled' => $enabled,
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * The built-in default customer-email templates — the canonical copy that
	 * supplies the default subject/body for every mailbox and is the final fallback
	 * when a mailbox leaves a subject/body blank. Subjects/bodies use {token}
	 * placeholders rendered by
	 * {@see self::render()} ({@see self::tokens()} for the token set). This is also
	 * the source the mailbox editor reads (via the REST list `meta`) so the default
	 * copy is visible and editable in the UI.
	 *
	 * @return array<string, array{subject:string, body:string}>
	 */
	public static function default_templates(): array {
		return array(
			'ticket_created_to_customer' => array(
				'subject' => __( 'We received your request: {ticket_title}', 'doublescale' ),
				'body'    => '<p>Hi <strong>{customer_full_name}</strong>,</p>'
					. '<p>Your request (<a href="{ticket_public_url}">#{ticket_id}</a>) has been received, and is being reviewed by our support staff.</p>'
					. '<p>To add additional comments, follow the link below:</p>'
					. '<p><a href="{ticket_public_url}">View Ticket</a></p>'
					. '<p>&nbsp;</p>'
					. '<p>or follow this link: {ticket_public_url}</p>'
					. '<hr />'
					. '<p>{site_name}</p>',
			),
			'reply_to_customer'          => array(
				'subject' => __( 'Re: {ticket_title}', 'doublescale' ),
				'body'    => '<p>Hi <strong>{customer_full_name}</strong>,</p>'
					. '<p>An agent just replied to your ticket "<strong>{ticket_title}</strong>" (<a href="{ticket_public_url}">#{ticket_id}</a>). To view his reply or add additional comments, click the button below:</p>'
					. '<p><a href="{ticket_public_url}">View Ticket</a></p>'
					. '<p>or follow this link: {ticket_public_url}</p>'
					. '<hr />'
					. '<p>Regards,<br />{site_name}</p>',
			),
			'status_change_to_customer'  => array(
				'subject' => __( 'Your ticket "{ticket_title}" was marked {ticket_status}', 'doublescale' ),
				'body'    => '<p>Hi <strong>{customer_full_name}</strong>,</p>'
					. '<p>Your ticket - {ticket_title}</p>'
					. '<p>We hope that the ticket was resolved to your satisfaction. If you feel that the ticket should not be closed or if the ticket has not been resolved, please reopen the ticket (<a href="{ticket_public_url}">#{ticket_id}</a>)</p>'
					. '<p>Regards,<br />{site_name}</p>',
			),
		);
	}

	/**
	 * Per-mailbox notification template overrides for a ticket's mailbox.
	 *
	 * @param TicketModel $ticket Ticket.
	 * @return array<string, mixed>
	 */
	private function mailbox_templates( TicketModel $ticket ): array {
		$mailbox = $ticket->mailbox;
		if ( ! $mailbox ) {
			return array();
		}
		$data = is_array( $mailbox->data ) ? $mailbox->data : array();
		return isset( $data['notifications'] ) && is_array( $data['notifications'] )
			? $data['notifications']
			: array();
	}

	/**
	 * The {token} → value map an operator subject/body template may reference.
	 * `$extra` lets a listener add event-specific tokens (e.g. `reply_content`,
	 * a resolved `ticket_status` label).
	 *
	 * @param TicketModel $ticket Ticket.
	 * @param array       $extra  Event-specific overrides merged over the base set.
	 * @return array<string, string>
	 */
	private function tokens( TicketModel $ticket, array $extra = array() ): array {
		$base = array(
			'customer_first_name' => $this->customer_first_name( $ticket ),
			'customer_full_name'  => $this->customer_full_name( $ticket ),
			'customer_email'      => $this->customer_email( $ticket ),
			'ticket_title'        => (string) $ticket->title,
			'ticket_id'           => (string) $ticket->id,
			'ticket_status'       => TicketStatus::get_label( (string) $ticket->status ),
			'ticket_public_url'   => PortalUrl::get_public_ticket_url( $ticket ),
			'site_name'           => (string) get_bloginfo( 'name' ),
			'reply_content'       => '',
		);
		return array_merge( $base, $extra );
	}

	/**
	 * Substitute `{token}` placeholders in an operator template.
	 *
	 * @param string                $template Subject or body template.
	 * @param array<string, string> $tokens   Token map from {@see tokens()}.
	 * @return string
	 */
	private function render( string $template, array $tokens ): string {
		$search  = array();
		$replace = array();
		foreach ( $tokens as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}
		return str_replace( $search, $replace, $template );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * The ticket customer's email, or '' when unresolvable.
	 *
	 * @param TicketModel $ticket Ticket.
	 * @return string
	 */
	private function customer_email( TicketModel $ticket ): string {
		$contact = $ticket->contact;
		if ( $contact && is_email( (string) $contact->email ) ) {
			return (string) $contact->email;
		}
		return '';
	}

	/**
	 * The customer's first name, or a neutral greeting fallback.
	 *
	 * @param TicketModel $ticket Ticket.
	 * @return string
	 */
	private function customer_first_name( TicketModel $ticket ): string {
		$contact = $ticket->contact;
		$first   = $contact ? trim( (string) $contact->first_name ) : '';
		return '' !== $first ? $first : __( 'there', 'doublescale' );
	}

	/**
	 * The customer's full name (first + last), with sensible fallbacks.
	 *
	 * @param TicketModel $ticket Ticket.
	 * @return string
	 */
	private function customer_full_name( TicketModel $ticket ): string {
		$contact = $ticket->contact;
		if ( ! $contact ) {
			return __( 'there', 'doublescale' );
		}

		$name = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}

		if ( ! empty( $contact->email ) ) {
			return (string) $contact->email;
		}

		return __( 'there', 'doublescale' );
	}

	/**
	 * Wrap message content in a minimal HTML shell.
	 *
	 * Kept intentionally simple — the booking module renders richer templated
	 * emails; support's outbound is plain so it reads well in any client. The
	 * content is already `wp_kses_post`-sanitised at write time by
	 * {@see TicketService::sanitize_content()} (or, for operator bodies, by the
	 * notification-template sanitiser).
	 *
	 * Always appends a "View Ticket" call-to-action linking to the customer's
	 * guest portal URL so a recipient can always reach the thread — even when an
	 * operator's custom mailbox template omits the `{ticket_public_url}` token.
	 * The button is skipped when (a) no portal page is published (URL is empty,
	 * so there's nothing to link) or (b) the body already references the URL
	 * (operator placed the link themselves — don't duplicate it).
	 *
	 * @param TicketModel $ticket Ticket whose guest portal URL to append.
	 * @param string      $inner  Inner HTML/body content.
	 * @return string
	 */
	private function wrap_body( TicketModel $ticket, string $inner ): string {
		return '<div style="font-family:sans-serif;font-size:14px;line-height:1.6;color:#1f2937;">'
			. $inner
			. $this->portal_link_block( $ticket, $inner )
			. '</div>';
	}

	/**
	 * Build the appended "View Ticket" button, or '' when it should be omitted.
	 *
	 * @param TicketModel $ticket Ticket whose guest portal URL to link.
	 * @param string      $inner  Already-rendered body — checked so we don't
	 *                            duplicate a link the operator template included.
	 * @return string Button HTML, or '' when no portal URL or already present.
	 */
	private function portal_link_block( TicketModel $ticket, string $inner ): string {
		$url = PortalUrl::get_public_ticket_url( $ticket );
		if ( '' === $url ) {
			return '';
		}
		if ( false !== strpos( $inner, $url ) ) {
			return '';
		}

		return '<div style="margin-top:24px;">'
			. '<a href="' . esc_url( $url ) . '" '
			. 'style="display:inline-block;padding:10px 18px;background:#6d78d8;color:#ffffff;'
			. 'text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;">'
			. esc_html__( 'View Ticket', 'doublescale' )
			. '</a></div>';
	}

	/**
	 * Run a send closure guarded against a broken mailer.
	 *
	 * A thrown exception is logged and swallowed so it cannot bubble up and
	 * abort the ticket REST request that fired the lifecycle hook.
	 *
	 * @param mixed    $ticket Expected TicketModel; ignored if not.
	 * @param callable $send   Closure receiving the TicketModel.
	 * @return void
	 */
	private function safely( $ticket, callable $send ): void {
		if ( ! ( $ticket instanceof TicketModel ) ) {
			return;
		}
		try {
			$send( $ticket );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support email notification failed',
				array(
					'source'    => 'support-email-notifications',
					'ticket_id' => (int) $ticket->id,
					'exception' => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
		}
	}
}
