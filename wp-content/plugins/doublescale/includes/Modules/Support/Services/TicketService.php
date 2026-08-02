<?php
/**
 * TicketService — single orchestration point for every ticket state change.
 *
 * Every path that creates or mutates a ticket (REST controllers, IMAP poller
 * in Phase 3, WP-CLI commands) goes through this service so the canonical
 * domain events fire exactly once per change regardless of caller:
 *
 *   - doublescale_support_ticket_created    (Ticket $ticket)
 *   - doublescale_support_ticket_updated    (Ticket $ticket, array $updates, array $before)
 *   - doublescale_support_ticket_deleted    (int $ticket_id)
 *   - doublescale_support_reply_created     (Activity $activity, Ticket $ticket)
 *   - doublescale_support_note_created      (Activity $activity, Ticket $ticket)
 *
 * Conversations (replies + notes + system events) are stored as
 * `doublescale_activities` rows with `activity_type='support_reply'`,
 * `'support_note'`, or `'support_event'`, linked back to the ticket through
 * `doublescale_activity_associations` with `entity_type=3` (TICKET).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use WP_Error;

/**
 * TicketService class.
 */
class TicketService {

	/**
	 * Columns on `support_tickets` that are user-controllable through
	 * {@see update_ticket()}. Any other key in the $updates array is
	 * silently dropped to prevent mass-assignment surprises (e.g. someone
	 * trying to overwrite `hash` or `response_count` via the REST patch
	 * endpoint).
	 */
	private const UPDATABLE_COLUMNS = array(
		'title',
		'status',
		'priority',
		'mailbox_id',
		'agent_user_id',
		'product',
		'tag_ids',
		'custom_data',
	);

	/**
	 * Maximum number of CC recipients accepted on a single reply. Caps the
	 * outbound `Cc:` header length and bounds the accumulated ticket list.
	 */
	private const MAX_CC = 10;

	/**
	 * @var ContactResolver
	 */
	private $contact_resolver;

	/**
	 * @param ContactResolver $contact_resolver Resolves email → ContactModel.
	 */
	public function __construct( ContactResolver $contact_resolver ) {
		$this->contact_resolver = $contact_resolver;
	}

	/**
	 * Create a ticket and its opening message in a single transaction.
	 *
	 * Required keys in $data:
	 *   - `title`            string  Ticket subject line.
	 *   - `content`          string  HTML body of the opening message.
	 *   - One of: `contact_id` int OR `email` string (+ optional first_name/last_name)
	 *
	 * Optional keys:
	 *   - `status`           string  Defaults to TicketStatus::OPEN.
	 *   - `priority`         string  Defaults to TicketPriority::NORMAL.
	 *   - `mailbox_id`       int     Routing channel.
	 *   - `agent_user_id`    int     Initial agent assignment.
	 *   - `product`          string  Free-text product label.
	 *   - `message_id`       string  Email Message-ID (set by IMAP path; web path leaves NULL).
	 *   - `source`           string  Where the opening message came from: 'web'|'email'. Default 'web'.
	 *   - `tag_ids`          int[]   Tag IDs.
	 *   - `custom_data`      array   Per-ticket custom field values.
	 *   - `author_user_id`   int     WP user creating on behalf of the customer (logged-in agent).
	 *
	 * @param array<string, mixed> $data Ticket fields + opening message body.
	 * @return TicketModel|WP_Error
	 */
	public function create_ticket( array $data ) {
		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			return new WP_Error( 'missing_title', __( 'Ticket title is required.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$content = $this->sanitize_content( $data['content'] ?? '' );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'missing_content', __( 'Opening message content is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$contact = $this->resolve_contact( $data );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		// Mailbox is mandatory (NOT NULL). resolve_mailbox_id() falls back to the
		// default mailbox, so 0 only happens on a fresh install that has no mailbox
		// yet — there is no auto-created mailbox, the operator must create the first
		// one. Surface that as a clear error rather than writing an invalid 0 FK.
		$mailbox_id = $this->resolve_mailbox_id( $data );
		if ( 0 === $mailbox_id ) {
			return new WP_Error(
				'no_mailbox_available',
				__( 'No support mailbox is configured. Create a mailbox in Settings → Support before opening tickets.', 'doublescale' ),
				array( 'status' => 409 )
			);
		}

		$custom_data = null;
		if ( isset( $data['custom_data'] ) && is_array( $data['custom_data'] ) ) {
			$service = self::custom_fields_service();
			if ( $service ) {
				$scope     = isset( $data['custom_fields_scope'] ) && 'portal' === $data['custom_fields_scope'] ? 'portal' : 'admin';
				$context   = array(
					'ticket_title'    => (string) ( $data['title'] ?? '' ),
					'ticket_content'  => $content,
					'ticket_priority' => (string) ( $data['priority'] ?? TicketPriority::NORMAL ),
					'product'         => (string) ( $data['product'] ?? '' ),
				);
				$validated = $service->validate( $data['custom_data'], $scope, $context );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				$custom_data = $validated;
			}
		}

		$ticket_attrs = array(
			'title'         => $this->sanitize_title( $data['title'] ),
			'status'        => $this->normalize_status( $data['status'] ?? TicketStatus::OPEN ),
			'priority'      => $this->normalize_priority( $data['priority'] ?? TicketPriority::NORMAL ),
			'mailbox_id'    => $mailbox_id,
			'contact_id'    => $contact->id,
			'agent_user_id' => isset( $data['agent_user_id'] ) ? (int) $data['agent_user_id'] : null,
			'product'       => isset( $data['product'] ) ? $this->sanitize_short_string( $data['product'] ) : null,
			'message_id'    => isset( $data['message_id'] ) ? (string) $data['message_id'] : null,
			'tag_ids'       => $this->normalize_tag_ids( $data['tag_ids'] ?? null ),
			'custom_data'   => $custom_data,
		);

		$source         = $this->normalize_source( $data['source'] ?? 'web' );
		$author_user_id = $this->resolve_author_user_id( $data, $source );

		try {
			$ticket = TicketModel::create( $ticket_attrs );

			// Opening message — always attributed to whoever wrote it. For email
			// source with no agent SENT match, author is NULL (the customer wrote
			// it; we don't fall back to the assigned agent because the agent
			// didn't author this message). For web source, the logged-in user
			// (typically an agent filing on behalf of a customer) is credited.
			//
			// No wrapping transaction — the rest of the CRM avoids Eloquent
			// transactions for connector compatibility. The orphan-row failure
			// mode (ticket without opening message) is identical in shape to
			// Booking's multi-table flows and is logged via the catch block.
			$activity = $this->record_conversation_activity(
				$ticket,
				ActivityTypes::SUPPORT_REPLY,
				array(
					'content' => $content,
					'source'  => $source,
				),
				$author_user_id
			);

			if ( ! empty( $data['attachment_hashes'] ) && is_array( $data['attachment_hashes'] ) ) {
				$this->attachments()->link_to_activity( (int) $activity->id, (int) $ticket->id, $data['attachment_hashes'] );
			}

			// Raw-bytes attachments (inbound email): persisted + linked directly as
			// active. Kept here so every linking path runs in this one try-block.
			if ( ! empty( $data['attachment_files'] ) && is_array( $data['attachment_files'] ) ) {
				$this->store_email_attachments( $activity, $data['attachment_files'] );
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support ticket creation failed',
				array(
					'source'    => 'support-ticket-service',
					'exception' => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
			return new WP_Error( 'ticket_create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		// Eager-load the relations listeners typically want so hooks can read
		// $ticket->contact / ->agent / ->mailbox without re-querying. We use
		// load() (not with()->find()) because we already hold the model — no
		// reason to re-SELECT the ticket row we just inserted.
		$ticket->load( array( 'contact', 'agent', 'mailbox' ) );

		/**
		 * Fires after a support ticket is created (including its opening message).
		 *
		 * @param TicketModel $ticket Newly created ticket with relations loaded.
		 */
		do_action( 'doublescale_support_ticket_created', $ticket );

		return $ticket;
	}

	/**
	 * Add a customer-visible reply to an existing ticket.
	 *
	 * Required keys in $data:
	 *   - `content` string HTML reply body.
	 *
	 * Optional keys:
	 *   - `source`         string  'web' (default) | 'email'.
	 *   - `message_id`     string  Outbound Message-ID for email pipeline.
	 *   - `in_reply_to`    string  Parent Message-ID (for email threading).
	 *   - `author_user_id` int     Agent posting. Defaults to current user.
	 *
	 * @param TicketModel|int      $ticket Ticket model or id.
	 * @param array<string, mixed> $data   Reply fields.
	 * @return ActivityModel|WP_Error
	 */
	public function add_reply( $ticket, array $data ) {
		$ticket = $this->resolve_ticket( $ticket );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$content = $this->sanitize_content( $data['content'] ?? '' );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'missing_content', __( 'Reply content is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$source = $this->normalize_source( $data['source'] ?? 'web' );
		$author = $this->resolve_author_user_id( $data, $source );

		// Per-reply CC: validated email list stored on the reply activity itself.
		// Set BEFORE record_conversation_activity() so it is persisted in the
		// activity's data before the doublescale_support_reply_created hook fires —
		// EmailNotifications reads it off the activity to build the Cc: header.
		$cc = $this->sanitize_cc_list( $data['cc'] ?? array() );

		$activity_data = array(
			'content' => $content,
			'source'  => $source,
		);
		if ( ! empty( $cc ) ) {
			$activity_data['cc'] = $cc;
		}
		foreach ( array( 'message_id', 'in_reply_to' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$activity_data[ $key ] = (string) $data[ $key ];
			}
		}

		try {
			$activity = $this->record_conversation_activity(
				$ticket,
				ActivityTypes::SUPPORT_REPLY,
				$activity_data,
				$author
			);

			if ( ! empty( $data['attachment_hashes'] ) && is_array( $data['attachment_hashes'] ) ) {
				$this->attachments()->link_to_activity( (int) $activity->id, (int) $ticket->id, $data['attachment_hashes'] );
			}

			// Raw-bytes attachments (inbound email reply): persisted + linked
			// directly as active, alongside the hash-based path above.
			if ( ! empty( $data['attachment_files'] ) && is_array( $data['attachment_files'] ) ) {
				$this->store_email_attachments( $activity, $data['attachment_files'] );
			}

			// Replies AFTER the opening message bump the counter; the opening
			// message goes through `record_conversation_activity` from
			// `create_ticket()` without this call.
			$ticket->increment( 'response_count' );

			// Accumulate the union of every CC ever used on this ticket so the UI
			// can show the full participant list. Only writes when this reply adds
			// a new address — a CC-less reply (the common case) is a no-op.
			if ( ! empty( $cc ) ) {
				$this->accumulate_ticket_cc( $ticket, $cc );
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support reply creation failed',
				array(
					'source'    => 'support-ticket-service',
					'exception' => $e->getMessage(),
					'ticket_id' => $ticket->id,
				)
			);
			return new WP_Error( 'reply_create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		/**
		 * Fires when a customer-visible reply is added to a ticket.
		 *
		 * @param ActivityModel $activity The reply activity row.
		 * @param TicketModel   $ticket   The parent ticket.
		 */
		do_action( 'doublescale_support_reply_created', $activity, $ticket );

		return $activity;
	}

	/**
	 * Whether a customer reply carrying this email Message-ID has already been
	 * recorded on a ticket.
	 *
	 * Inbound email ingestion (Pro) calls this so a reply is appended exactly
	 * once even when BOTH inbound engines — the per-mailbox IMAP poller and the
	 * CRM-inbox router — see the same physical message (which happens when one
	 * Gmail/Outlook account is wired as both the CRM inbox and a support
	 * mailbox). Scoped to SUPPORT_REPLY activities on purpose: the CRM logs the
	 * same email as an `email_received` activity that ALSO stores this Message-ID,
	 * and that contact-timeline row must not be mistaken for an already-ingested
	 * support reply.
	 *
	 * Opening messages are deduped separately on the indexed
	 * `support_tickets.message_id` column (the opening activity is stored without
	 * a `message_id` in its data), so this method intentionally covers the reply
	 * case only.
	 *
	 * @param string $message_id Raw Message-ID header value (with angle brackets).
	 * @return bool
	 */
	public function reply_exists_by_message_id( string $message_id ): bool {
		$message_id = trim( $message_id );
		if ( '' === $message_id ) {
			return false;
		}

		return ActivityModel::where( 'activity_type', ActivityTypes::SUPPORT_REPLY )
			->whereRaw( "JSON_UNQUOTE(JSON_EXTRACT(data, '\$.message_id')) = ?", array( $message_id ) )
			->exists();
	}

	/**
	 * Add an internal-only note to a ticket. Not visible to the customer.
	 *
	 * Required keys in $data:
	 *   - `content` string HTML note body.
	 *
	 * Optional keys:
	 *   - `author_user_id` int Agent posting. Defaults to current user.
	 *
	 * @param TicketModel|int      $ticket Ticket model or id.
	 * @param array<string, mixed> $data   Note fields.
	 * @return ActivityModel|WP_Error
	 */
	public function add_note( $ticket, array $data ) {
		$ticket = $this->resolve_ticket( $ticket );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$content = $this->sanitize_content( $data['content'] ?? '' );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'missing_content', __( 'Note content is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$author = $this->resolve_author_user_id( $data, 'web' );

		try {
			$activity = $this->record_conversation_activity(
				$ticket,
				ActivityTypes::SUPPORT_NOTE,
				array(
					'content' => $content,
					'source'  => 'web',
				),
				$author
			);

			if ( ! empty( $data['attachment_hashes'] ) && is_array( $data['attachment_hashes'] ) ) {
				$this->attachments()->link_to_activity( (int) $activity->id, (int) $ticket->id, $data['attachment_hashes'] );
			}
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support note creation failed',
				array(
					'source'    => 'support-ticket-service',
					'exception' => $e->getMessage(),
					'ticket_id' => $ticket->id,
				)
			);
			return new WP_Error( 'note_create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		/**
		 * Fires when an internal note is added to a ticket.
		 *
		 * @param ActivityModel $activity The note activity row.
		 * @param TicketModel   $ticket   The parent ticket.
		 */
		do_action( 'doublescale_support_note_created', $activity, $ticket );

		return $activity;
	}

	/**
	 * Update mutable ticket fields. Only keys in {@see self::UPDATABLE_COLUMNS}
	 * are applied; everything else is dropped. Empty deltas are no-ops (no
	 * event fires, no DB write).
	 *
	 * @param TicketModel|int      $ticket  Ticket model or id.
	 * @param array<string, mixed> $updates Field → new value map.
	 * @return TicketModel|WP_Error
	 */
	public function update_ticket( $ticket, array $updates ) {
		$ticket = $this->resolve_ticket( $ticket );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$effective = array();
		$before    = array();
		foreach ( $updates as $key => $value ) {
			if ( ! in_array( $key, self::UPDATABLE_COLUMNS, true ) ) {
				continue;
			}
			if ( 'custom_data' === $key && is_array( $value ) ) {
				$stored_custom = is_array( $ticket->custom_data ) ? $ticket->custom_data : array();
				$context       = array(
					'ticket_title'    => (string) $ticket->title,
					'ticket_content'  => '',
					'ticket_priority' => (string) $ticket->priority,
					'product'         => (string) ( $ticket->product ?? '' ),
					'custom_data'     => array_merge( $stored_custom, $value ),
				);
				$service       = self::custom_fields_service();
				if ( $service ) {
					$prepared = $service->prepare_for_save( $value, 'admin', $context, $stored_custom );
					if ( is_wp_error( $prepared ) ) {
						return $prepared;
					}
					$value = $prepared;
				}
			}
			$normalized = $this->normalize_update_value( $key, $value );
			$current    = $ticket->{$key};
			if ( $current === $normalized ) {
				continue; // No-op — value unchanged.
			}
			$effective[ $key ] = $normalized;
			// Snapshot the pre-save value while it's still readable from the
			// hydrated model. `getOriginal()` would also work, but reading
			// the attribute directly preserves the cast (e.g. `tag_ids` stays
			// an array on both sides of the comparison) so the listener sees
			// the same canonical shape on `from` and `to`.
			$before[ $key ] = $current;
		}

		if ( empty( $effective ) ) {
			return $ticket;
		}

		try {
			$ticket->fill( $effective )->save();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support ticket update failed',
				array(
					'source'    => 'support-ticket-service',
					'exception' => $e->getMessage(),
					'ticket_id' => $ticket->id,
					'updates'   => array_keys( $effective ),
				)
			);
			return new WP_Error( 'ticket_update_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		/**
		 * Fires when a ticket's workflow state changes.
		 *
		 * @param TicketModel $ticket    The ticket after the update.
		 * @param array       $effective Only the keys that actually changed, with their new values.
		 * @param array       $before    Same keys, with their pre-save values, so listeners can log {from, to}.
		 */
		do_action( 'doublescale_support_ticket_updated', $ticket, $effective, $before );

		return $ticket;
	}

	/**
	 * Delete a ticket. The model's `deleting` boot event cascades to
	 * attachments and activity associations.
	 *
	 * @param TicketModel|int $ticket Ticket model or id.
	 * @return bool|WP_Error
	 */
	public function delete_ticket( $ticket ) {
		$ticket = $this->resolve_ticket( $ticket );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		$id = (int) $ticket->id;

		try {
			$ticket->delete();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support ticket delete failed',
				array(
					'source'    => 'support-ticket-service',
					'exception' => $e->getMessage(),
					'ticket_id' => $id,
				)
			);
			return new WP_Error( 'ticket_delete_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a ticket has been removed (post-cascade).
		 *
		 * @param int $ticket_id The id of the deleted ticket.
		 */
		do_action( 'doublescale_support_ticket_deleted', $id );

		return true;
	}

	/**
	 * Record a system-generated activity on the ticket timeline (status
	 * changes, assignments, etc.). Used by {@see ActivityLogger}.
	 *
	 * @param TicketModel|int $ticket    Ticket model or id.
	 * @param string          $event_key Short identifier — e.g. 'status_changed', 'assigned'. Stored in data.event_key.
	 * @param array           $data      Extra payload merged into activity.data.
	 * @param int|null        $user_id   Acting WP user; defaults to current user (NULL for system actions).
	 * @return ActivityModel|WP_Error
	 */
	public function log_event( $ticket, $event_key, array $data = array(), $user_id = null ) {
		$ticket = $this->resolve_ticket( $ticket );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$payload = array_merge( $data, array( 'event_key' => (string) $event_key ) );

		return $this->record_conversation_activity(
			$ticket,
			ActivityTypes::SUPPORT_EVENT,
			$payload,
			null === $user_id ? $this->current_user_id_or_null() : (int) $user_id
		);
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Sanitize conversation body HTML for storage. This is the single choke point
	 * for every path that writes a reply / note / opening message (admin REST,
	 * customer portal REST, inbound email, CLI), so content is consistently run
	 * through the WordPress post-content allow-list ({@see wp_kses_post()}) before
	 * it is persisted and later rendered.
	 *
	 * `wp_unslash()` runs first: REST JSON bodies arrive already-unslashed (a
	 * no-op here), but the form-encoded / CLI fallback paths are slashed, and
	 * `wp_kses_post()` would otherwise double-encode pre-slashed entities. The
	 * combination is idempotent, so a controller that already sanitized (the
	 * portal) is unaffected.
	 *
	 * @param mixed $raw Raw content from the caller.
	 * @return string Sanitized HTML.
	 */
	private function sanitize_content( $raw ): string {
		return wp_kses_post( wp_unslash( (string) $raw ) );
	}

	/**
	 * Validate + normalize a CC recipient list: keep only deliverable email
	 * addresses, de-duplicate case-insensitively, and cap at {@see self::MAX_CC}.
	 *
	 * `sanitize_email()` strips characters not permitted in an address — including
	 * CR/LF — so this is the primary guard against header injection through the
	 * outbound `Cc:` line; {@see \DoubleScale\Modules\Emails\Emails::get_cc()}
	 * re-validates each address as belt-and-braces.
	 *
	 * @param mixed $raw Raw value from the caller (expected array of strings).
	 * @return string[] Clean, unique, capped list of email addresses.
	 */
	private function sanitize_cc_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $addr ) {
			$clean = sanitize_email( (string) $addr );
			if ( '' !== $clean && is_email( $clean ) ) {
				// Key by lowercase form to de-dupe case-insensitively while
				// preserving the first-seen casing as the stored value.
				$key = strtolower( $clean );
				if ( ! isset( $out[ $key ] ) ) {
					$out[ $key ] = $clean;
				}
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX_CC );
	}

	/**
	 * Merge a reply's CC addresses into the ticket-level accumulated list
	 * (`custom_data.cc_recipients`) — the union of everyone ever CC'd on the
	 * ticket. Saves only when the union actually grows, so a repeated CC set is a
	 * no-op write. Case-insensitive de-dupe; the result is re-capped at
	 * {@see self::MAX_CC}.
	 *
	 * @param TicketModel $ticket Ticket to update.
	 * @param string[]    $cc     Already-sanitized CC list from the reply.
	 * @return void
	 */
	private function accumulate_ticket_cc( TicketModel $ticket, array $cc ): void {
		$custom  = is_array( $ticket->custom_data ) ? $ticket->custom_data : array();
		$current = isset( $custom['cc_recipients'] ) && is_array( $custom['cc_recipients'] )
			? $custom['cc_recipients']
			: array();

		$merged = array();
		foreach ( array_merge( $current, $cc ) as $addr ) {
			$clean = sanitize_email( (string) $addr );
			if ( '' === $clean ) {
				continue;
			}
			$key = strtolower( $clean );
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $clean;
			}
		}
		$merged = array_slice( array_values( $merged ), 0, self::MAX_CC );

		// No new address → nothing to persist.
		if ( count( $merged ) === count( $current )
			&& array_map( 'strtolower', $merged ) === array_map( 'strtolower', $current )
		) {
			return;
		}

		$custom['cc_recipients'] = $merged;
		$ticket->custom_data     = $custom;
		$ticket->save();
	}

	/**
	 * Persist the activity row + the ticket↔activity association in one place
	 * so every conversation entry (reply / note / event) has the same shape.
	 *
	 * @param TicketModel $ticket        Parent ticket.
	 * @param string      $activity_type ActivityTypes::SUPPORT_REPLY|SUPPORT_NOTE|SUPPORT_EVENT.
	 * @param array       $payload       Goes into activity.data. `ticket_id` is added automatically.
	 * @param int|null    $user_id       WP user id of the actor; null for system / customer-via-email.
	 * @return ActivityModel
	 */
	private function record_conversation_activity( TicketModel $ticket, $activity_type, array $payload, $user_id ) {
		$payload['ticket_id'] = $ticket->id;

		$activity = ActivityModel::create(
			array(
				'contact_id'    => $ticket->contact_id,
				'activity_type' => $activity_type,
				'data'          => $payload,
				'user_id'       => $user_id,
			)
		);

		ActivityAssociationModel::create(
			array(
				'activity_id' => $activity->id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_TICKET,
				'entity_id'   => $ticket->id,
			)
		);

		return $activity;
	}

	/**
	 * Resolve $data into a ContactModel. Either `contact_id` is provided
	 * directly, or `email` (+ optional name) is passed and the contact is
	 * looked up / created via {@see ContactResolver}.
	 *
	 * @param array $data Input array.
	 * @return \DoubleScale\Modules\Contacts\Models\ContactModel|WP_Error
	 */
	private function resolve_contact( array $data ) {
		if ( ! empty( $data['contact_id'] ) ) {
			$contact = \DoubleScale\Modules\Contacts\Models\ContactModel::find( (int) $data['contact_id'] );
			if ( ! $contact ) {
				return new WP_Error( 'contact_not_found', __( 'Contact not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			return $contact;
		}

		if ( empty( $data['email'] ) ) {
			return new WP_Error( 'missing_customer', __( 'Either contact_id or email is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		try {
			return $this->contact_resolver->find_or_create(
				$data['email'],
				$data['first_name'] ?? null,
				$data['last_name'] ?? null
			);
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_email', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Decide the ticket's mailbox. Every ticket belongs to a mailbox (the column
	 * is NOT NULL), so this never returns a "no channel" value.
	 *
	 * Precedence:
	 *   1. An explicit `mailbox_id` from the caller always wins (agent picked a
	 *      channel, or the IMAP/portal path resolved one).
	 *   2. Otherwise fall back to the default mailbox ({@see MailboxModel::get_default()}),
	 *      then to the first mailbox if no default flag is set. As long as at least
	 *      one mailbox exists, a create that omits `mailbox_id` still lands in the
	 *      default channel rather than being channel-less.
	 *   3. `0` when the install has zero mailboxes — the normal state of a fresh
	 *      install, since no mailbox is auto-created. The caller turns that into a
	 *      clear error instead of writing a 0 FK.
	 *
	 * @param array<string, mixed> $data Create payload.
	 * @return int Mailbox id, or 0 when no mailbox exists at all.
	 */
	private function resolve_mailbox_id( array $data ): int {
		if ( isset( $data['mailbox_id'] ) ) {
			return (int) $data['mailbox_id'];
		}

		$default = MailboxModel::get_default();
		if ( $default ) {
			return (int) $default->id;
		}

		// No default flagged — fall back to any mailbox so the ticket still has one.
		$first = MailboxModel::first();
		if ( $first ) {
			return (int) $first->id;
		}

		// Zero mailboxes on the install (fresh install, none created yet). Signal
		// "unresolvable" — the caller rejects rather than violating the NOT NULL / FK
		// constraint.
		return 0;
	}

	private function resolve_ticket( $ticket ) {
		if ( $ticket instanceof TicketModel ) {
			return $ticket;
		}
		$found = TicketModel::find( (int) $ticket );
		if ( ! $found ) {
			return new WP_Error( 'ticket_not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		return $found;
	}

	private function normalize_status( $status ) {
		return TicketStatus::is_valid( (string) $status ) ? (string) $status : TicketStatus::OPEN;
	}

	private function normalize_priority( $priority ) {
		return TicketPriority::is_valid( (string) $priority ) ? (string) $priority : TicketPriority::NORMAL;
	}

	/**
	 * @param mixed $source Raw source string.
	 */
	private function normalize_source( $source ): string {
		$allowed = array( 'web', 'email', 'system', 'email_sent' );
		$source  = is_string( $source ) ? strtolower( $source ) : 'web';
		return in_array( $source, $allowed, true ) ? $source : 'web';
	}

	private function sanitize_title( $title ): string {
		return mb_substr( sanitize_text_field( (string) $title ), 0, 255 );
	}

	private function sanitize_short_string( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, 100 );
	}

	/**
	 * @param mixed $tag_ids Raw value from the caller.
	 * @return int[]|null
	 */
	private function normalize_tag_ids( $tag_ids ): ?array {
		if ( null === $tag_ids ) {
			return null;
		}
		if ( ! is_array( $tag_ids ) ) {
			return array();
		}
		$normalized = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );
		return $normalized;
	}

	/**
	 * Apply per-column normalization for {@see update_ticket()} so the
	 * comparison against the existing model value works against canonical
	 * forms (e.g. 'OPEN' → 'open', '5' → 5).
	 *
	 * @param string $key   Column name.
	 * @param mixed  $value Raw value.
	 * @return mixed Normalized value or null when the caller cleared it.
	 */
	private function normalize_update_value( $key, $value ) {
		switch ( $key ) {
			case 'status':
				return $this->normalize_status( $value );
			case 'priority':
				return $this->normalize_priority( $value );
			case 'mailbox_id':
			case 'agent_user_id':
				return null === $value || '' === $value ? null : (int) $value;
			case 'title':
				return $this->sanitize_title( $value );
			case 'product':
				return null === $value || '' === $value ? null : $this->sanitize_short_string( $value );
			case 'tag_ids':
				return $this->normalize_tag_ids( $value );
			case 'custom_data':
				return is_array( $value ) ? $value : null;
			default:
				return $value;
		}
	}

	/**
	 * Pick a sensible WP user id to credit on an activity:
	 *  - Web source: explicit `author_user_id` (including null) → current user → NULL.
	 *  - Email source: caller (IMAP handler) sets `author_user_id` only when the
	 *    inbound is from an agent's SENT folder; otherwise NULL (customer reply).
	 *
	 * @param array  $data   Input.
	 * @param string $source Normalized source.
	 * @return int|null
	 */
	private function resolve_author_user_id( array $data, $source ) {
		// Explicit null (portal / guest hash replies) must stay customer-authored
		// even when a support agent happens to be logged into WordPress.
		if ( array_key_exists( 'author_user_id', $data ) ) {
			$explicit = $data['author_user_id'];
			if ( null === $explicit || '' === $explicit || 0 === (int) $explicit ) {
				return null;
			}
			return (int) $explicit;
		}
		if ( 'web' === $source ) {
			return $this->current_user_id_or_null();
		}
		return null;
	}

	private function current_user_id_or_null(): ?int {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return null;
		}
		$id = (int) get_current_user_id();
		return $id > 0 ? $id : null;
	}

	/**
	 * @return AttachmentService
	 */
	private function attachments(): AttachmentService {
		return new AttachmentService();
	}

	/**
	 * Persist raw-bytes attachments (from inbound email) and link them to an
	 * activity. A single bad attachment is logged and skipped — it must never
	 * abort the surrounding ticket/reply write.
	 *
	 * After storing, rewrite any inline-image references in the activity body
	 * (`<img src="cid:…">`, or the bare Content-ID that `wp_kses_post()` leaves
	 * once it strips the `cid:` scheme) to the served attachment URL, so inline
	 * images render in the thread instead of breaking. Re-saves the activity only
	 * when the body actually changed.
	 *
	 * @param ActivityModel                                                                          $activity Conversation activity.
	 * @param array<int, array{filename?:string, mime?:string, content?:string, content_id?:string}> $files Decoded email attachments.
	 * @return void
	 */
	private function store_email_attachments( ActivityModel $activity, array $files ): void {
		$ticket_id   = isset( $activity->data['ticket_id'] ) ? (int) $activity->data['ticket_id'] : 0;
		$activity_id = (int) $activity->id;

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$stored = $this->attachments()->store_email_attachment( $file, $ticket_id, $activity_id );
			if ( is_wp_error( $stored ) ) {
				doublescale_get_logger()->warning(
					'Skipped an inbound email attachment',
					array(
						'source'    => 'support-attachment',
						'ticket_id' => $ticket_id,
						'reason'    => $stored->get_error_code(),
						'filename'  => isset( $file['filename'] ) ? (string) $file['filename'] : '',
					)
				);
			}
		}

		// Swap inline cid: image references for served URLs now that the
		// attachments (with their Content-IDs) exist for this activity.
		$data           = is_array( $activity->data ) ? $activity->data : array();
		$original_body  = isset( $data['content'] ) ? (string) $data['content'] : '';
		$rewritten_body = $this->attachments()->rewrite_inline_image_srcs( $original_body, $activity_id );
		if ( $rewritten_body !== $original_body ) {
			$data['content'] = $rewritten_body;
			$activity->data  = $data;
			$activity->save();
		}
	}

	/**
	 * Pro support custom fields service, when the Pro add-on is active.
	 *
	 * @return object|null
	 */
	private static function custom_fields_service() {
		$class = '\\DoubleScale\\Pro\\Modules\\Support\\Services\\CustomFieldsService';
		if ( ! class_exists( $class ) ) {
			return null;
		}
		return new $class();
	}
}
