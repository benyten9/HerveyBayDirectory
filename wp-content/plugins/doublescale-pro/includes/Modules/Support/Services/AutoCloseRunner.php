<?php
/**
 * Auto-close inactive tickets — the daily background job.
 *
 * Closes support tickets that have been idle longer than the configured
 * threshold, honouring the include/exclude rules from {@see AutoCloseSettings}.
 * Scheduled on the `doublescale_support` Action Scheduler group by
 * {@see \DoubleScale\Pro\Modules\Support\Module}; the callback is this class's
 * {@see run()}.
 *
 * Closing always goes through free's
 * {@see \DoubleScale\Modules\Support\Services\TicketService::update_ticket()} so
 * the canonical `doublescale_support_ticket_updated` event fires exactly once
 * per ticket (status-change audit row, automations, customer email). In silent
 * mode the customer-facing status-change email listener is detached for the
 * duration of the run so no mail goes out — everything else (audit row,
 * automations) still happens.
 *
 * Inactivity semantic: time since the ticket's **last customer reply** (the
 * newest `support_reply` activity authored by the customer — `user_id IS NULL`).
 * Tickets with no customer reply yet fall back to `tickets.updated_at`.
 *
 * No-ops gracefully when free Support is absent (Pro running standalone).
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\EmailNotifications;
use DoubleScale\Modules\Support\Services\TicketService;

/**
 * AutoCloseRunner class.
 */
final class AutoCloseRunner {

	/**
	 * How many candidate tickets to load per chunk, so a single cron tick stays
	 * bounded on large installs.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Run the auto-close pass. Safe to call repeatedly; no-ops when disabled or
	 * when free Support is unavailable.
	 *
	 * @return int Number of tickets closed this run.
	 */
	public function run(): int {
		if ( ! class_exists( TicketService::class ) || ! class_exists( TicketModel::class ) ) {
			return 0;
		}

		$settings = AutoCloseSettings::get();
		if ( empty( $settings['enabled'] ) ) {
			return 0;
		}

		$inactive_days = (int) $settings['inactive_days'];
		if ( $inactive_days < 1 ) {
			return 0;
		}

		$service = $this->ticket_service();
		if ( null === $service ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $inactive_days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- matching the codebase's cutoff idiom (CleanupHandler / AttachmentService).

		$silent_guard = $this->maybe_suppress_emails( ! empty( $settings['silent'] ) );

		$closed = 0;
		try {
			$closed = $this->close_candidates( $service, $settings, $cutoff );
		} finally {
			// Always restore the email listener, even if a ticket close threw.
			$silent_guard();
		}

		doublescale_get_logger()->info(
			'Support auto-close pass complete',
			array(
				'source'        => 'support-auto-close',
				'closed'        => $closed,
				'inactive_days' => $inactive_days,
				'silent'        => ! empty( $settings['silent'] ),
			)
		);

		return $closed;
	}

	/**
	 * Walk the candidate tickets in batches and close the ones that qualify.
	 *
	 * @param TicketService        $service  Free ticket service.
	 * @param array<string, mixed> $settings Normalized auto-close settings.
	 * @param string               $cutoff   MySQL datetime; tickets idle before this close.
	 * @return int Number closed.
	 */
	private function close_candidates( TicketService $service, array $settings, string $cutoff ): int {
		$closed    = 0;
		$last_id   = 0;
		$include   = $settings['include_tag_ids'];
		$exclude   = $settings['exclude_tag_ids'];
		$skip_wait = ! empty( $settings['skip_waiting_on_agent'] );

		do {
			$query = TicketModel::query()
				->whereIn( 'status', TicketStatus::get_active_statuses() )
				->where( 'updated_at', '<', $cutoff )
				->where( 'id', '>', $last_id )
				->orderBy( 'id', 'asc' )
				->limit( self::BATCH_SIZE );

			foreach ( $include as $tag_id ) {
				$query->whereRaw( 'JSON_CONTAINS(tag_ids, ?)', array( (string) (int) $tag_id ) );
			}
			foreach ( $exclude as $tag_id ) {
				$query->whereRaw( 'NOT JSON_CONTAINS(COALESCE(tag_ids, JSON_ARRAY()), ?)', array( (string) (int) $tag_id ) );
			}

			$tickets = $query->get();
			if ( $tickets->isEmpty() ) {
				break;
			}

			foreach ( $tickets as $ticket ) {
				$last_id = (int) $ticket->id;

				if ( ! $this->is_inactive_enough( $ticket, $cutoff, $skip_wait ) ) {
					continue;
				}

				$result = $service->update_ticket( $ticket, array( 'status' => TicketStatus::CLOSED ) );
				if ( is_wp_error( $result ) ) {
					continue;
				}

				$this->maybe_add_close_note( $service, $ticket, $settings );
				++$closed;
			}
		} while ( $tickets->count() === self::BATCH_SIZE );

		return $closed;
	}

	/**
	 * Decide whether a candidate ticket is genuinely inactive enough to close,
	 * using the last-customer-reply semantic and the waiting-on-agent rule.
	 *
	 * @param TicketModel $ticket    Candidate ticket (already past the updated_at cutoff).
	 * @param string      $cutoff    MySQL datetime cutoff.
	 * @param bool        $skip_wait When true, never close a ticket awaiting an agent reply.
	 * @return bool
	 */
	private function is_inactive_enough( TicketModel $ticket, string $cutoff, bool $skip_wait ): bool {
		$last_reply = $this->latest_reply( (int) $ticket->id );

		// No reply activity at all yet → fall back to the ticket's own updated_at,
		// which the candidate query already constrained to < cutoff.
		if ( null === $last_reply ) {
			return true;
		}

		$customer_authored = empty( $last_reply->user_id );

		// "Waiting on agent" means the most recent reply came from the customer.
		// When the operator opted to skip those, leave the ticket open.
		if ( $skip_wait && $customer_authored ) {
			return false;
		}

		// Inactivity is measured from the last CUSTOMER reply. If the latest reply
		// is from the customer, use its timestamp; otherwise the customer has gone
		// quiet since at least the agent's reply, so the agent reply time is the
		// floor for "how long the customer has been silent".
		$reference = (string) $last_reply->created_at;
		if ( '' === $reference ) {
			return true;
		}

		return strtotime( $reference ) < strtotime( $cutoff );
	}

	/**
	 * The newest customer-visible reply on a ticket (any author), or null.
	 *
	 * @param int $ticket_id Ticket id.
	 * @return ActivityModel|null
	 */
	private function latest_reply( int $ticket_id ): ?ActivityModel {
		$reply = ActivityModel::forTicket( $ticket_id )
			->where( 'activity_type', ActivityTypes::SUPPORT_REPLY )
			->orderBy( 'created_at', 'desc' )
			->orderBy( 'id', 'desc' )
			->first();

		return $reply instanceof ActivityModel ? $reply : null;
	}

	/**
	 * Append the operator's configured close message after a successful close,
	 * either as an internal note or a customer-visible reply.
	 *
	 * @param TicketService        $service  Free ticket service.
	 * @param TicketModel          $ticket   The just-closed ticket.
	 * @param array<string, mixed> $settings Normalized auto-close settings.
	 * @return void
	 */
	private function maybe_add_close_note( TicketService $service, TicketModel $ticket, array $settings ): void {
		if ( empty( $settings['add_close_note'] ) ) {
			return;
		}
		$content = (string) $settings['close_note'];
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return;
		}

		// author_user_id is forced null: the cron context has no logged-in agent,
		// and the note is system-generated.
		$payload = array(
			'content'        => $content,
			'author_user_id' => null,
		);

		if ( AutoCloseSettings::NOTE_VISIBILITY_REPLY === ( $settings['close_note_visibility'] ?? '' ) ) {
			$service->add_reply( $ticket, $payload );
			return;
		}

		$service->add_note( $ticket, $payload );
	}

	/**
	 * Detach the customer-facing status-change email listener for the run when
	 * silent mode is on, returning a restore callback. When silent mode is off
	 * the returned callback is a no-op.
	 *
	 * The status-change email is the only customer-facing side effect of a
	 * close, so detaching just this one listener is sufficient to "close
	 * silently" while keeping the audit row + automations intact.
	 *
	 * @param bool $silent Whether to suppress.
	 * @return callable Restore callback (call to re-attach).
	 */
	private function maybe_suppress_emails( bool $silent ): callable {
		if ( ! $silent ) {
			return static function () {};
		}

		$notifier = $this->email_notifications();
		if ( null === $notifier ) {
			return static function () {};
		}

		$callback = array( $notifier, 'on_ticket_updated' );
		remove_action( 'doublescale_support_ticket_updated', $callback, 10 );

		return static function () use ( $callback ) {
			add_action( 'doublescale_support_ticket_updated', $callback, 10, 3 );
		};
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

	/**
	 * Resolve free's EmailNotifications singleton (the instance whose listeners
	 * are actually registered) from the shared container.
	 *
	 * @return EmailNotifications|null
	 */
	private function email_notifications(): ?EmailNotifications {
		if ( ! function_exists( 'doublescale_resolve' ) ) {
			return null;
		}
		$notifier = doublescale_resolve( EmailNotifications::class );
		return $notifier instanceof EmailNotifications ? $notifier : null;
	}
}
