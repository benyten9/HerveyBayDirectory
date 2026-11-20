<?php

/**
 * Process Batches Step
 *
 * The main processing loop: fetches contact batches and dispatches them
 * via the injected BatchDispatchStrategyInterface.  All three sending
 * modes (individual, bulk API, cURL multi) run through this same loop
 * skeleton – only the strategy differs.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Pipeline\Steps;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Pipeline\PipelineStepInterface;
use DoubleScale\Modules\Campaigns\Pipeline\CampaignContext;
use DoubleScale\Modules\Campaigns\Pipeline\BatchDispatchStrategyInterface;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Core\Utils\Utils;

/**
 * ProcessBatchesStep class
 */
class ProcessBatchesStep implements PipelineStepInterface {

	/** @var BatchDispatchStrategyInterface */
	private $strategy;

	/**
	 * @param BatchDispatchStrategyInterface $strategy
	 */
	public function __construct( BatchDispatchStrategyInterface $strategy ) {
		$this->strategy = $strategy;
	}

	/**
	 * @inheritDoc
	 */
	public function handle( CampaignContext $ctx, callable $next ) {
		// Allow the strategy to perform one-time setup (template loading, logging, etc.).
		$this->strategy->on_loop_start( $ctx );

		if ( $ctx->aborted ) {
			return;
		}

		$batch_size = $this->strategy->get_batch_size() ?: $ctx->batch_size;
		$loop_delay = $this->strategy->get_loop_delay_microseconds();

		while (
			$ctx->get_execution_time() < $ctx->max_execution_time
			&& ! Utils::is_memory_limit_reached()
		) {
			// Throttle to avoid server overload.
			usleep( $loop_delay );

			// Refresh distributed lock at the start of each batch iteration.
			$ctx->refresh_lock();

			// Re-read campaign status: abort if admin paused or cancelled.
			$fresh = CampaignModel::find( $ctx->campaign->id );
			if ( ! $fresh || 'processing' !== $fresh->status ) {
				doublescale_get_logger()->info(
					sprintf(
						/* translators: %s: channel name */
						__( 'Campaign %s stopped – status changed during processing', 'doublescale' ),
						$ctx->channel
					),
					array(
						'code'           => "{$ctx->channel}_campaign_status_changed",
						'campaign_id'    => $ctx->campaign->id,
						'current_status' => $fresh ? $fresh->status : 'deleted',
						'offset'         => $ctx->offset,
					)
				);
				update_option( $ctx->offset_key, $ctx->offset );
				$ctx->abort();
				return;
			}

			if ( $ctx->is_complete() ) {
				break;
			}

			// Shared daily cap used up, or workflow messages go first: pause
			// here and resume later instead of failing anyone.
			$wait = self::wait_reason( $ctx );
			if ( $wait ) {
				$ctx->wait_until( $wait['at'], $wait['reason'] );
				return;
			}

			// Never send past today's cap: a page holds at most what is left of it.
			$page_size = $batch_size;
			if ( $ctx->rate_limiter && method_exists( $ctx->rate_limiter, 'get_daily_limit' ) ) {
				$daily_limit = $ctx->rate_limiter->get_daily_limit( $ctx->channel );
				if ( null !== $daily_limit ) {
					$page_size = max( 1, min( $batch_size, $daily_limit - $ctx->rate_limiter->get_daily_count( $ctx->channel ) ) );
				}
			}

			// Fetch the next batch of contacts.
			// A Campaign Agent's campaign reaches only the contacts they own.
			$contacts = $ctx->contact_filter->get_contacts_for_processing(
				$ctx->channel,
				$ctx->filters,
				$ctx->offset,
				$page_size,
				\DoubleScale\Core\UserRoles\Permissions::campaign_audience_owner( (int) ( $ctx->campaign->created_by ?? 0 ) )
			);

			if ( $contacts->isEmpty() ) {
				break;
			}

			// Delegate dispatch to the strategy.
			// The strategy advances $ctx->offset for each contact it processes.
			$result = $this->strategy->process_batch( $ctx, $contacts );

			// Persist progress after every batch.
			update_option( $ctx->offset_key, $ctx->offset );

			if ( $result->fatal ) {
				$ctx->abort();
				return;
			}

			if ( $result->stop ) {
				break;
			}
		}

		$next();
	}

	/**
	 * Whether the campaign should pause before its next page, and until when.
	 *
	 * @param CampaignContext $ctx Campaign context.
	 * @return array{at:int, reason:string}|null
	 */
	public static function wait_reason( CampaignContext $ctx ) {
		$limiter = $ctx->rate_limiter;
		if ( $limiter && method_exists( $limiter, 'is_daily_cap_reached' ) && $limiter->is_daily_cap_reached( $ctx->channel ) ) {
			return array(
				'at'     => $limiter->next_reset_at()->getTimestamp() + MINUTE_IN_SECONDS,
				'reason' => 'daily_limit_reached',
			);
		}

		/**
		 * Whether a campaign should give way this run (Pro: workflow messages
		 * waiting in the send queue go first on the shared limits).
		 *
		 * @param bool   $yield   Default false.
		 * @param string $channel email|sms|whatsapp.
		 */
		if ( apply_filters( 'doublescale_campaign_should_yield', false, (string) $ctx->channel ) ) {
			return array(
				'at'     => time() + MINUTE_IN_SECONDS,
				'reason' => 'workflow_messages_first',
			);
		}

		return null;
	}
}
