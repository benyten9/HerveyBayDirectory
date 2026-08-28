<?php
/**
 * Spawn one invoice from a recurrence rule and advance the schedule.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\DocumentCurrency;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Services\DuplicateInvoice;
use DoubleScale\Modules\Documents\Services\InvoiceNotifications;
use DoubleScale\Pro\Modules\RecurringInvoices\Models\InvoiceRecurrenceModel;

/**
 * GenerateRecurringInvoice service.
 *
 * Concurrency guarantee: a cycle is billed at most once. Overlapping sweeps
 * (Action Scheduler runs parallel queue workers) race on an atomic slot claim,
 * and only the winner spawns. Under heavy contention a loser may fail to create
 * its copy — the shared invoice-number retry budget is finite — in which case
 * the slot is handed back untouched and the next sweep bills it. The failure
 * mode is therefore a delayed invoice, never a duplicate one.
 */
class GenerateRecurringInvoice {

	/**
	 * Attempts at creating the copy before giving the slot back.
	 *
	 * Guards against losing a billing cycle when concurrent queue workers
	 * exhaust the shared invoice-number retry budget.
	 */
	private const SPAWN_ATTEMPTS = 3;

	/**
	 * Run one rule: generate the due occurrence, then move the schedule on.
	 *
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @return InvoiceModel|null Generated invoice, or null when nothing was produced.
	 */
	public function run( InvoiceRecurrenceModel $recurrence ): ?InvoiceModel {
		$template = $recurrence->templateInvoice;

		// Template deleted out from under the rule — retire it rather than
		// leaving a row that can never fire.
		if ( ! $template ) {
			$this->deactivate( $recurrence );

			return null;
		}

		// Gate: don't pile new invoices on a customer who hasn't settled the
		// current one. `next_run_at` is deliberately NOT advanced, so the rule
		// retries on the next sweep and fires as soon as payment lands.
		if ( $recurrence->require_paid && InvoiceStatus::PAID !== (string) $template->status ) {
			return null;
		}

		$run_at = (string) $recurrence->next_run_at;

		// Claim the slot before doing any work.
		//
		// Two sweeps can overlap — Action Scheduler runs concurrent queue
		// workers, and an admin can trigger a run while cron is mid-flight.
		// Without a claim both would read the same `next_run_at`, both spawn,
		// and the customer would be billed twice for one cycle.
		//
		// The UPDATE is the claim: it only matches while `next_run_at` still
		// holds the value we read, so exactly one process can affect a row.
		// A loser sees 0 affected rows and backs out.
		if ( ! $this->claim_slot( $recurrence, $run_at ) ) {
			return null;
		}

		// Once claimed, the slot is parked on NULL and is invisible to every
		// other sweep. Any exit path that does not bill MUST hand it back, or
		// the rule is stranded: active, never due, silently never invoiced.
		try {
			$invoice = $this->spawn( $template, $recurrence, $run_at );
		} catch ( \Throwable $e ) {
			$this->release_slot( $recurrence, $run_at );

			throw $e;
		}

		if ( ! $invoice ) {
			$this->release_slot( $recurrence, $run_at );

			return null;
		}

		$this->advance( $recurrence, $template, $run_at );

		return $invoice;
	}

	/**
	 * Atomically take ownership of the due slot.
	 *
	 * Parks `next_run_at` on NULL for the duration of the spawn, which both
	 * removes the row from the due set and acts as the claim flag.
	 *
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @param string                 $run_at     Slot being claimed.
	 * @return bool True when this process won the slot.
	 */
	private function claim_slot( InvoiceRecurrenceModel $recurrence, string $run_at ): bool {
		if ( '' === $run_at ) {
			return false;
		}

		$claimed = InvoiceRecurrenceModel::query()
			->where( 'id', (int) $recurrence->id )
			->where( 'next_run_at', $run_at )
			->update( array( 'next_run_at' => null ) );

		return (int) $claimed > 0;
	}

	/**
	 * Return an unused slot to the schedule after a failed spawn.
	 *
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @param string                 $run_at     Slot to restore.
	 * @return void
	 */
	private function release_slot( InvoiceRecurrenceModel $recurrence, string $run_at ): void {
		InvoiceRecurrenceModel::query()
			->where( 'id', (int) $recurrence->id )
			->whereNull( 'next_run_at' )
			->update( array( 'next_run_at' => $run_at ) );
	}

	/**
	 * Copy the template into a new invoice for this occurrence.
	 *
	 * @param InvoiceModel           $template   Template invoice.
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @param string                 $run_at     Slot being filled (MySQL datetime).
	 * @return InvoiceModel|null
	 */
	private function spawn( InvoiceModel $template, InvoiceRecurrenceModel $recurrence, string $run_at ): ?InvoiceModel {
		// DuplicateInvoice deliberately drops payment state, proposal and
		// subscription links, and lifecycle timestamps — carrying those over
		// would attribute an existing payment to a brand-new document. It also
		// routes through SalesNumbering::save_with_retry(), which keeps invoice
		// numbers unique when several rules fire in the same tick.
		//
		// That retry budget is finite (5 attempts) and is shared with every
		// other writer racing the same number sequence. When several queue
		// workers sweep at once it can be exhausted, and DuplicateInvoice then
		// raises a TypeError from its own return type rather than returning
		// null. Retrying here turns a lost race into a short wait instead of a
		// skipped billing cycle.
		$invoice   = null;
		$last_error = null;

		for ( $attempt = 0; $attempt < self::SPAWN_ATTEMPTS; $attempt++ ) {
			try {
				$invoice = ( new DuplicateInvoice() )->duplicate( $template );
				break;
			} catch ( \Throwable $e ) {
				$last_error = $e;
				// Stagger retries so simultaneous workers stop colliding.
				usleep( random_int( 20000, 120000 ) );
			}
		}

		if ( ! $invoice || ! $invoice->id ) {
			if ( $last_error && function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Recurring invoice could not be created; slot will be retried',
					array(
						'source'        => 'recurring-invoices',
						'recurrence_id' => (int) $recurrence->id,
						'error'         => $last_error->getMessage(),
					)
				);
			}

			return null;
		}

		$issue_date = $this->slot_date( $run_at );
		$due_date   = $this->shift_due_date( $template, $issue_date );

		$invoice->recurrence_id = (int) $recurrence->id;
		$invoice->invoice_date  = $issue_date;
		$invoice->due_date      = $due_date;

		// InvoiceModel::saving always recomputes subtotal/total from line_items,
		// so totals stay correct without being set here.
		//
		// Status must be settled before any payment reconciliation runs:
		// InvoicePayments::derive_status keeps DRAFT sticky, so an auto-sent
		// invoice has to leave DRAFT here or it can never progress to paid.
		$invoice->status = $recurrence->auto_send ? InvoiceStatus::UNPAID : InvoiceStatus::DRAFT;

		$invoice->save();

		if ( $recurrence->auto_send ) {
			$this->send( $invoice );
		}

		/**
		 * Fires after a recurring invoice occurrence is generated.
		 *
		 * @param InvoiceModel           $invoice    Generated invoice.
		 * @param InvoiceModel           $template   Template invoice.
		 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
		 */
		do_action( 'doublescale_recurring_invoice_generated', $invoice, $template, $recurrence );

		return $invoice;
	}

	/**
	 * Email the generated invoice, marking it sent on success.
	 *
	 * A delivery failure must not abort the cycle — the invoice exists and can
	 * be resent by hand, so the schedule still moves on.
	 *
	 * @param InvoiceModel $invoice Generated invoice.
	 * @return void
	 */
	private function send( InvoiceModel $invoice ): void {
		try {
			$sent = ( new InvoiceNotifications() )->send_invoice( $invoice );
			if ( $sent ) {
				DocumentCurrency::freeze_on_send( $invoice );
				$invoice->sent_at = current_time( 'mysql' );
				$invoice->save();
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Recurring invoice email failed',
					array(
						'source'     => 'recurring-invoices',
						'invoice_id' => (int) $invoice->id,
						'error'      => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Record the cycle and compute the following slot, retiring the rule when
	 * either limit is met.
	 *
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @param InvoiceModel           $template   Template invoice.
	 * @param string                 $run_at     Slot just filled.
	 * @return void
	 */
	private function advance( InvoiceRecurrenceModel $recurrence, InvoiceModel $template, string $run_at ): void {
		$now         = current_time( 'mysql' );
		$cycles_done = (int) $recurrence->cycles_done + 1;

		$recurrence->cycles_done = $cycles_done;
		$recurrence->last_run_at = $now;

		// Cycle cap is evaluated on the updated count.
		if ( ! $recurrence->is_infinite && (int) $recurrence->total_cycles > 0
			&& $cycles_done >= (int) $recurrence->total_cycles ) {
			$recurrence->is_active   = false;
			$recurrence->next_run_at = null;
			$this->persist( $recurrence );

			return;
		}

		$anchor = $this->anchor( $template, $run_at );
		$next   = $recurrence->compute_next_run_at( $run_at, $anchor );

		// A rule with no cycle cap that has been dormant (or a site that slept)
		// can have many slots in the past; skipping that backlog avoids a burst
		// of invoices the customer never expected.
		//
		// A capped rule is the opposite case: "12 monthly invoices" is a
		// commitment to twelve documents, so missed slots are worked through one
		// sweep at a time until the count is met, rather than silently dropped.
		if ( $recurrence->is_infinite || (int) $recurrence->total_cycles <= 0 ) {
			$now_ts = strtotime( $now );
			$guard  = 0;
			while ( false !== $now_ts && strtotime( $next ) <= $now_ts && $guard < 1200 ) {
				$next = $recurrence->compute_next_run_at( $next, $anchor );
				++$guard;
			}
		}

		// End date is checked against the slot we would actually fire next.
		if ( $recurrence->has_reached_limit( $next ) ) {
			$recurrence->is_active   = false;
			$recurrence->next_run_at = null;
			$this->persist( $recurrence );

			return;
		}

		$recurrence->next_run_at = $next;
		$this->persist( $recurrence );
	}

	/**
	 * Write the schedule fields back by targeted UPDATE.
	 *
	 * `save()` would push the whole model, including the stale `next_run_at`
	 * the claim already cleared — writing only the fields this cycle owns keeps
	 * the claim protocol intact.
	 *
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @return void
	 */
	private function persist( InvoiceRecurrenceModel $recurrence ): void {
		InvoiceRecurrenceModel::query()
			->where( 'id', (int) $recurrence->id )
			->update(
				array(
					'cycles_done' => (int) $recurrence->cycles_done,
					'last_run_at' => $recurrence->last_run_at,
					'next_run_at' => $recurrence->next_run_at,
					'is_active'   => $recurrence->is_active ? 1 : 0,
				)
			);
	}

	/**
	 * Series anchor — the template's invoice date, which pins the day-of-month
	 * for every occurrence.
	 *
	 * @param InvoiceModel $template Template invoice.
	 * @param string       $run_at   Current slot, used when the template has no date.
	 * @return \DateTime
	 */
	private function anchor( InvoiceModel $template, string $run_at ): \DateTime {
		$tz  = wp_timezone();
		$raw = ! empty( $template->invoice_date ) ? (string) $template->invoice_date : $run_at;

		try {
			return new \DateTime( $raw, $tz );
		} catch ( \Throwable $e ) {
			return new \DateTime( $run_at, $tz );
		}
	}

	/**
	 * Date portion of a slot.
	 *
	 * @param string $run_at MySQL datetime.
	 * @return string Y-m-d
	 */
	private function slot_date( string $run_at ): string {
		$ts = strtotime( $run_at );

		return false === $ts ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Preserve the template's payment window relative to the new issue date.
	 *
	 * @param InvoiceModel $template   Template invoice.
	 * @param string       $issue_date New issue date (Y-m-d).
	 * @return string Y-m-d
	 */
	private function shift_due_date( InvoiceModel $template, string $issue_date ): string {
		$days = 7;

		if ( ! empty( $template->invoice_date ) && ! empty( $template->due_date ) ) {
			$from = strtotime( (string) $template->invoice_date );
			$to   = strtotime( (string) $template->due_date );
			if ( $from && $to && $to >= $from ) {
				$days = (int) floor( ( $to - $from ) / DAY_IN_SECONDS );
			}
		}

		return gmdate( 'Y-m-d', strtotime( $issue_date . ' +' . $days . ' days' ) );
	}

	/**
	 * Retire a rule that can no longer fire.
	 *
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @return void
	 */
	private function deactivate( InvoiceRecurrenceModel $recurrence ): void {
		$recurrence->is_active   = false;
		$recurrence->next_run_at = null;
		$this->persist( $recurrence );
	}

}
