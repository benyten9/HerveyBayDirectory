<?php
/**
 * Sweep due recurrence rules and generate their invoices.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\RecurringInvoices\Models\InvoiceRecurrenceModel;

/**
 * RecurringInvoicesRunner cron runner.
 */
class RecurringInvoicesRunner {

	/**
	 * Rules processed per query.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Ceiling on batches per run, so one tick can't monopolise the queue.
	 *
	 * Caps a sweep at BATCH_SIZE * MAX_BATCHES (500) rules. A backlog larger
	 * than that is drained by subsequent hourly runs rather than in one pass —
	 * deliberate, so a huge due set cannot stall the Action Scheduler queue.
	 */
	private const MAX_BATCHES = 10;

	/**
	 * Process every due rule (at most one invoice per rule per run).
	 *
	 * @return void
	 */
	public function run(): void {
		$service     = new GenerateRecurringInvoice();
		$batch_count = 0;
		$after_id    = 0;

		do {
			// Keyset pagination on `id`, not OFFSET.
			//
			// The due set mutates as we work through it, and the two ways it
			// mutates pull in opposite directions:
			//   - a processed rule advances `next_run_at` and leaves the set,
			//     so a fixed OFFSET would skip past the rules that shifted down;
			//   - a rule held back by `require_paid` stays due by design, so
			//     re-reading from the head would hand back the same rows forever.
			// Remembering the last id seen satisfies both: we never revisit a
			// row and never step over one.
			$recurrences = InvoiceRecurrenceModel::query()
				->due()
				->with( 'templateInvoice' )
				->where( 'id', '>', $after_id )
				->orderBy( 'id' )
				->limit( self::BATCH_SIZE )
				->get();

			if ( $recurrences->isEmpty() ) {
				return;
			}

			$after_id = (int) $recurrences->last()->id;

			foreach ( $recurrences as $recurrence ) {
				try {
					$service->run( $recurrence );
				} catch ( \Throwable $e ) {
					// One bad rule must not stop the sweep.
					if ( function_exists( 'doublescale_get_logger' ) ) {
						doublescale_get_logger()->error(
							'Recurring invoice generation failed',
							array(
								'source'        => 'recurring-invoices',
								'recurrence_id' => (int) $recurrence->id,
								'error'         => $e->getMessage(),
							)
						);
					}
				}
			}

			++$batch_count;
		} while ( $recurrences->count() === self::BATCH_SIZE && $batch_count < self::MAX_BATCHES );
	}
}
