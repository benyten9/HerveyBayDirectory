<?php

/**
 * Individual Dispatch Strategy
 *
 * The default dispatch strategy: one message per contact, with per-second
 * rate limiting enforced between contacts.  Used by Email (wp_mail fallback),
 * SMS, and WhatsApp channels.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Pipeline\Strategies;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Pipeline\BatchDispatchStrategyInterface;
use DoubleScale\Modules\Campaigns\Pipeline\BatchResult;
use DoubleScale\Modules\Campaigns\Pipeline\CampaignContext;
use DoubleScale\Core\Utils\Utils;

/**
 * IndividualDispatchStrategy class
 */
class IndividualDispatchStrategy implements BatchDispatchStrategyInterface {

	/**
	 * @inheritDoc
	 */
	public function on_loop_start( CampaignContext $ctx ) {
		// No-op: individual dispatch requires no per-campaign setup.
	}

	/**
	 * @inheritDoc
	 */
	public function get_batch_size() {
		return 0; // Use the filterable default from $ctx->batch_size.
	}

	/**
	 * @inheritDoc
	 */
	public function get_loop_delay_microseconds() {
		return 100000; // 0.1 s
	}

	/**
	 * @inheritDoc
	 *
	 * Iterates over each contact, applying rate limiting and periodic lock
	 * refresh.  Advances $ctx->offset for every contact dispatched.
	 */
	public function process_batch( CampaignContext $ctx, $contacts ) {
		$last_lock_refresh = time();

		foreach ( $contacts as $contact ) {
			// Blocking rate-limit check (may sleep up to 1 s).
			$ctx->rate_limiter->check_and_wait_per_second( $ctx->channel, $ctx->max_per_second );

			// Re-check constraints after the potential sleep.
			if (
				$ctx->get_execution_time() >= $ctx->max_execution_time
				|| Utils::is_memory_limit_reached()
			) {
				// Save partial progress before yielding.
				update_option( $ctx->offset_key, $ctx->offset );
				return BatchResult::stop();
			}

			// Keep the distributed lock alive during long batches.
			if ( time() - $last_lock_refresh >= 30 ) {
				$ctx->refresh_lock();
				$last_lock_refresh = time();
			}

			// Dispatch: create tracking record + enqueue via Action Scheduler.
			$result = call_user_func( $ctx->fn_add_message, $ctx->campaign, $contact );

			if ( ! $result['success'] ) {
				// Save partial progress before stopping.
				update_option( $ctx->offset_key, $ctx->offset );

				if ( ! empty( $result['fatal'] ) || 'failed' === $ctx->campaign->status ) {
					return BatchResult::fatal();
				}
				return BatchResult::stop();
			}

			// Track throughput for per-second rate limiting.
			if ( empty( $result['skipped'] ) ) {
				$ctx->rate_limiter->record_sent( $ctx->channel );
			}

			++$ctx->offset;
		}

		return BatchResult::ok();
	}
}
