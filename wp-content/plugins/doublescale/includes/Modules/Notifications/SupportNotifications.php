<?php
/**
 * Support Notifications Handler
 *
 * Subscribes to the support ticket lifecycle events emitted by Free's
 * TicketService and creates in-app notifications (bell / browser / email /
 * push) for the CRM agents who work the queue.
 *
 * This is the AGENT-facing pipeline. It is deliberately separate from the Free
 * Support module's `Support\Services\EmailNotifications`, which emails the
 * *customer* ("we got your ticket", "an agent replied", "resolved"). These two
 * never collide: different audience (agent vs. customer), different store, and
 * the whole Notifications module is Pro, so a Support section in the
 * preferences matrix only exists when Pro runs.
 *
 * Audience: when a ticket has an assignee (`agent_user_id`) the notification
 * targets that one agent; otherwise it fans out to every CRM user via
 * NotificationService::broadcast() (capability-filtered, preference-aware).
 * "Ticket Assigned to Me" always targets exactly the new assignee.
 *
 * One hook does the heavy lifting: `doublescale_support_ticket_updated` carries
 * only the columns that actually changed ($effective) plus their prior values
 * ($before), so a single handler fans out to three subcategories — assigned,
 * resolved/closed, reopened — by diffing those two arrays.
 *
 * @listens doublescale_support_ticket_created
 * @listens doublescale_support_reply_created
 * @listens doublescale_support_ticket_updated
 *
 * @since 2.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications;

use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationService;

defined( 'ABSPATH' ) || exit;

/**
 * SupportNotifications class.
 */
class SupportNotifications {

	/**
	 * Statuses considered a "closed" terminal state, for resolved/reopened
	 * fan-out. Mirrors TicketStatus::RESOLVED / CLOSED without taking a hard
	 * dependency on the Free constants class (string compare is enough).
	 *
	 * @var string[]
	 */
	private const CLOSED_STATUSES = array( 'resolved', 'closed' );

	/**
	 * Subscribe to the support lifecycle hooks fired by Free's TicketService.
	 *
	 * Skips registration entirely when the support module is disabled — Free's
	 * Support module won't boot in that case so the hooks never fire anyway,
	 * but skipping add_action() keeps the hook table clean and mirrors how
	 * BookingNotifications guards on its category.
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::SUPPORT ) ) {
			return;
		}
		add_action( 'doublescale_support_ticket_created', array( $this, 'on_ticket_created' ), 10, 1 );
		add_action( 'doublescale_support_reply_created', array( $this, 'on_reply_created' ), 10, 2 );
		add_action( 'doublescale_support_ticket_updated', array( $this, 'on_ticket_updated' ), 10, 3 );
	}

	/**
	 * Handle ticket.created — a new ticket landed in the queue.
	 *
	 * @param mixed $ticket TicketModel instance (validated in safely()).
	 */
	public function on_ticket_created( $ticket ): void {
		$this->safely(
			$ticket,
			function ( TicketModel $t ) {
				$customer = $this->customer_label( $t );
				$this->notify(
					$t,
					/* translators: %s: customer name */
					sprintf( __( 'New ticket from %s', 'doublescale' ), $customer ),
					$this->ticket_subject_excerpt( $t ),
					NotificationCategories::SUPPORT_TICKET_CREATED
				);
			}
		);
	}

	/**
	 * Handle reply.created — fire only for CUSTOMER replies, the inverse of the
	 * gate Free's EmailNotifications uses (it emails the customer only on AGENT
	 * replies). A reply whose activity has a `user_id` was written by an agent;
	 * the customer doesn't need an agent notified for the agent's own message.
	 *
	 * @param mixed $activity ActivityModel instance for the reply (validated below).
	 * @param mixed $ticket   TicketModel instance (validated in safely()).
	 */
	public function on_reply_created( $activity, $ticket ): void {
		// Agent reply (has an authoring WP user) → not an agent-facing event.
		if ( is_object( $activity ) && ! empty( $activity->user_id ) ) {
			return;
		}
		$this->safely(
			$ticket,
			function ( TicketModel $t ) {
				$customer = $this->customer_label( $t );
				$this->notify(
					$t,
					/* translators: %s: customer name */
					sprintf( __( '%s replied', 'doublescale' ), $customer ),
					$this->ticket_subject_excerpt( $t ),
					NotificationCategories::SUPPORT_CUSTOMER_REPLY
				);
			}
		);
	}

	/**
	 * Handle ticket.updated — fan out to up to three subcategories from one
	 * hook by diffing $effective (changed columns → new value) against $before
	 * (same keys → prior value). Both are already filtered to the ticket's
	 * updatable columns by TicketService, and only contain keys that genuinely
	 * changed on this call, so `isset($effective[$key])` is a true "changed
	 * this call" signal.
	 *
	 * @param mixed $ticket    TicketModel instance (validated in safely()).
	 * @param mixed $effective Changed columns → new values.
	 * @param mixed $before    Same keys → prior values.
	 */
	public function on_ticket_updated( $ticket, $effective, $before ): void {
		$effective = is_array( $effective ) ? $effective : array();
		$before    = is_array( $before ) ? $before : array();

		$this->safely(
			$ticket,
			function ( TicketModel $t ) use ( $effective, $before ) {
				// 1) Assignment changed → notify ONLY the new assignee.
				if ( array_key_exists( 'agent_user_id', $effective ) ) {
					$assignee = (int) $effective['agent_user_id'];
					if ( $assignee > 0 ) {
						NotificationService::create(
							$assignee,
							__( 'Ticket assigned to you', 'doublescale' ),
							$this->ticket_subject_excerpt( $t ),
							$this->links_for( $t ),
							NotificationCategories::SUPPORT_TICKET_ASSIGNED,
							array( 'ticket_id' => (int) $t->id )
						);
					}
				}

				// 2) Status change → resolved/closed, or reopened.
				if ( array_key_exists( 'status', $effective ) ) {
					$new_status = (string) $effective['status'];
					$old_status = isset( $before['status'] ) ? (string) $before['status'] : '';

					if ( in_array( $new_status, self::CLOSED_STATUSES, true ) ) {
						$this->notify(
							$t,
							/* translators: %s: ticket status (resolved or closed) */
							sprintf( __( 'Ticket %s', 'doublescale' ), $new_status ),
							$this->ticket_subject_excerpt( $t ),
							NotificationCategories::SUPPORT_TICKET_RESOLVED
						);
					} elseif ( 'open' === $new_status && in_array( $old_status, self::CLOSED_STATUSES, true ) ) {
						$this->notify(
							$t,
							__( 'Ticket reopened', 'doublescale' ),
							$this->ticket_subject_excerpt( $t ),
							NotificationCategories::SUPPORT_TICKET_REOPENED
						);
					}
				}
			}
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Send a notification to the ticket's audience: the assigned agent when the
	 * ticket has one, otherwise every CRM user (capability-filtered) via
	 * broadcast(). broadcast() applies per-user preference + page-capability
	 * checks, so we don't re-implement "who is an agent" here.
	 *
	 * @param TicketModel $ticket      The ticket.
	 * @param string      $title       Notification title.
	 * @param string      $body        Notification body.
	 * @param string      $subcategory NotificationCategories::SUPPORT_* subcategory.
	 */
	private function notify( TicketModel $ticket, string $title, string $body, string $subcategory ): void {
		$links    = $this->links_for( $ticket );
		$metadata = array( 'ticket_id' => (int) $ticket->id );
		$assignee = (int) ( $ticket->agent_user_id ?? 0 );

		if ( $assignee > 0 ) {
			NotificationService::create( $assignee, $title, $body, $links, $subcategory, $metadata );
			return;
		}

		// Unassigned ticket → fan out to the whole CRM team.
		NotificationService::broadcast( $title, $body, $links, $subcategory, $metadata );
	}

	/**
	 * Run a callback against the ticket, swallowing any Throwable. The
	 * `doublescale_support_*` hooks are fired by TicketService with no exception
	 * guard, so an unhandled fatal here would abort the REST request the agent
	 * (or customer, on a portal reply) is sitting on.
	 *
	 * @param mixed    $ticket TicketModel instance (or anything — validated here).
	 * @param callable $fn     Callback receiving the validated TicketModel.
	 */
	private function safely( $ticket, callable $fn ): void {
		if ( ! ( $ticket instanceof TicketModel ) ) {
			return;
		}
		try {
			$fn( $ticket );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Support in-app notification failed',
				array(
					'source'    => 'support-pro-notifications',
					'ticket_id' => (int) $ticket->id,
					'exception' => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
		}
	}

	/**
	 * Human label for the ticket's customer — preferring contact name, falling
	 * back to email, then a generic placeholder. Mirrors
	 * BookingNotifications::invitee_label().
	 *
	 * @param TicketModel $ticket The ticket.
	 * @return string Non-empty label.
	 */
	private function customer_label( TicketModel $ticket ): string {
		$contact = $ticket->contact ?? null;
		if ( $contact ) {
			$name = trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) );
			if ( '' !== $name ) {
				return $name;
			}
			if ( ! empty( $contact->email ) ) {
				return (string) $contact->email;
			}
		}
		return __( 'A customer', 'doublescale' );
	}

	/**
	 * The ticket title, used as the notification body. Trimmed defensively in
	 * case a long subject slipped past the 255-char column cap.
	 *
	 * @param TicketModel $ticket The ticket.
	 * @return string Non-empty body.
	 */
	private function ticket_subject_excerpt( TicketModel $ticket ): string {
		$title = trim( (string) ( $ticket->title ?? '' ) );
		if ( '' === $title ) {
			return __( '(no subject)', 'doublescale' );
		}
		return mb_substr( $title, 0, 140 );
	}

	/**
	 * Build the link payload for the notification — admin URL for web, relative
	 * path for mobile, matching BookingNotifications' convention.
	 *
	 * @param TicketModel $ticket The ticket.
	 * @return array{web:string,mobile:string}
	 */
	private function links_for( TicketModel $ticket ): array {
		return array(
			'web'    => admin_url( 'admin.php?page=doublescale&path=support/ticket/' . (int) $ticket->id ),
			'mobile' => '/support/tickets/' . (int) $ticket->id,
		);
	}
}
