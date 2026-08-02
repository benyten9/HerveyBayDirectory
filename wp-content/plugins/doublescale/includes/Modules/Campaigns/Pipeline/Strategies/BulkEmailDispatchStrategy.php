<?php

/**
 * Bulk Email Dispatch Strategy
 *
 * Sends an entire contact batch in a single bulk-API call
 * (Mailgun, SendGrid, Postmark, etc.).  Replaces the former
 * EmailProcessing::do_process_campaign_bulk() method.
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
use DoubleScale\Modules\Campaigns\Models\TemplateModel;

/**
 * BulkEmailDispatchStrategy class
 */
class BulkEmailDispatchStrategy implements BatchDispatchStrategyInterface {

	/** @var string */
	private $channel;

	/**
	 * Callable bound to EmailProcessing::send_email_batch().
	 * Signature: fn(CampaignModel, TemplateModel, Collection): array
	 *
	 * @var callable
	 */
	private $fn_send_batch;

	/** @var TemplateModel|null Loaded in on_loop_start() */
	private $template = null;

	/**
	 * @param string   $channel       Channel string (email).
	 * @param callable $fn_send_batch Bound to EmailProcessing::send_email_batch().
	 */
	public function __construct( $channel, callable $fn_send_batch ) {
		$this->channel       = $channel;
		$this->fn_send_batch = $fn_send_batch;
	}

	/**
	 * @inheritDoc
	 *
	 * Logs the campaign start time (once per campaign) and loads the template.
	 */
	public function on_loop_start( CampaignContext $ctx ) {
		// Track wall-clock start time for the campaign (first batch only).
		$start_time_key = "doublescale_{$this->channel}_campaign_start_time_{$ctx->campaign->id}";
		if ( ! get_option( $start_time_key ) ) {
			$start_time = microtime( true );
			update_option( $start_time_key, $start_time );

			doublescale_get_logger()->info(
				__( 'Bulk email sending started for campaign', 'doublescale' ),
				array(
					'code'        => 'bulk_email_campaign_started',
					'campaign_id' => $ctx->campaign->id,
					'start_time'  => gmdate( 'Y-m-d H:i:s', (int) $start_time ),
					'mailer'      => \DoubleScale\Modules\Emails\BulkEmailSender::get_active_mailer_slug(),
				)
			);
		}

		// Load the campaign template (required for bulk sending).
		$template_ids   = $ctx->campaign->get_template_ids();
		$template_id    = reset( $template_ids );
		$this->template = TemplateModel::find( $template_id );

		if ( ! $this->template ) {
			doublescale_get_logger()->error(
				__( 'Template not found for bulk email campaign', 'doublescale' ),
				array(
					'code'        => 'bulk_email_no_template',
					'campaign_id' => $ctx->campaign->id,
				)
			);

			$ctx->campaign->status = 'failed';
			$ctx->campaign->save();
			$ctx->abort();
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Delegates to EmailProcessing::send_email_batch() and advances the
	 * offset by the full batch size.
	 */
	public function process_batch( CampaignContext $ctx, $contacts ) {
		$result = call_user_func( $this->fn_send_batch, $ctx->campaign, $this->template, $contacts );

		// Advance offset by the entire batch regardless of per-contact results
		// (bulk API is all-or-nothing per batch).
		$ctx->offset += $contacts->count();

		if ( isset( $result['fatal'] ) && $result['fatal'] ) {
			return BatchResult::fatal();
		}

		return BatchResult::ok();
	}

	/**
	 * @inheritDoc
	 *
	 * Uses the mailer's own max batch size capped by the filterable default.
	 */
	public function get_batch_size() {
		return min(
			\DoubleScale\Modules\Emails\BulkEmailSender::get_max_batch_size(),
			apply_filters( 'doublescale_campaign_bulk_batch_size', 500, $this->channel )
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Bulk API calls are slower; use a 1-second delay between batches to
	 * respect provider rate limits.
	 */
	public function get_loop_delay_microseconds() {
		return 1000000; // 1 s
	}
}
