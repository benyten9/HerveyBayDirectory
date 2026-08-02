<?php

/**
 * Batch Dispatch Strategy Interface
 *
 * Encapsulates the channel- and mailer-specific logic for dispatching a
 * batch of contacts during campaign processing.
 *
 * Three built-in strategies:
 *   - IndividualDispatchStrategy  – one wp_mail / provider call per contact
 *   - BulkEmailDispatchStrategy   – single bulk-API call for the whole batch
 *   - CurlMultiEmailDispatchStrategy – parallel cURL requests for the batch
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Pipeline;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BatchDispatchStrategyInterface
 */
interface BatchDispatchStrategyInterface {

	/**
	 * Called once before the processing while-loop begins.
	 * Use for one-time setup: loading templates, logging start time, etc.
	 * Call $ctx->abort() to halt processing before the loop starts.
	 *
	 * @param CampaignContext $ctx
	 */
	public function on_loop_start( CampaignContext $ctx );

	/**
	 * Process one batch of contacts.
	 *
	 * The strategy is responsible for:
	 * - Dispatching the contacts (send / enqueue)
	 * - Advancing $ctx->offset for every contact processed
	 *
	 * @param CampaignContext                          $ctx
	 * @param \Illuminate\Database\Eloquent\Collection $contacts
	 * @return BatchResult
	 */
	public function process_batch( CampaignContext $ctx, $contacts );

	/**
	 * Batch size to request from the contact filter.
	 * Return 0 to use the default from $ctx->batch_size.
	 *
	 * @return int
	 */
	public function get_batch_size();

	/**
	 * Microseconds to sleep at the top of each while-loop iteration.
	 * Individual / curl-multi: 100 000 µs (0.1 s)
	 * Bulk API: 1 000 000 µs (1 s) to respect provider rate limits.
	 *
	 * @return int
	 */
	public function get_loop_delay_microseconds();
}
