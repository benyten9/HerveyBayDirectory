<?php

/**
 * cURL Multi Email Dispatch Strategy
 *
 * Sends a contact batch using parallel cURL requests (SMTP2GO and similar
 * mailers that lack a native bulk API).  Replaces the former
 * EmailProcessing::do_process_campaign_curl_multi() method.
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
 * CurlMultiEmailDispatchStrategy class
 */
class CurlMultiEmailDispatchStrategy implements BatchDispatchStrategyInterface {

	/** @var string */
	private $channel;

	/**
	 * Callable bound to EmailProcessing::send_email_batch_curl_multi().
	 * Signature: fn(CampaignModel, TemplateModel, Collection): array
	 *
	 * @var callable
	 */
	private $fn_send_batch;

	/** @var TemplateModel|null Loaded in on_loop_start() */
	private $template = null;

	/**
	 * @param string   $channel       Channel string (email).
	 * @param callable $fn_send_batch Bound to EmailProcessing::send_email_batch_curl_multi().
	 */
	public function __construct( $channel, callable $fn_send_batch ) {
		$this->channel       = $channel;
		$this->fn_send_batch = $fn_send_batch;
	}

	/**
	 * @inheritDoc
	 */
	public function on_loop_start( CampaignContext $ctx ) {
		$start_time_key = "doublescale_{$this->channel}_campaign_start_time_{$ctx->campaign->id}";
		if ( ! get_option( $start_time_key ) ) {
			$start_time = microtime( true );
			update_option( $start_time_key, $start_time );

			doublescale_get_logger()->info(
				__( 'Curl Multi email sending started for campaign', 'doublescale' ),
				array(
					'code'        => 'curl_multi_email_campaign_started',
					'campaign_id' => $ctx->campaign->id,
					'start_time'  => gmdate( 'Y-m-d H:i:s', (int) $start_time ),
					'mailer'      => \DoubleScale\Modules\Emails\CurlMultiEmailSender::get_active_mailer_slug(),
				)
			);
		}

		$template_ids   = $ctx->campaign->get_template_ids();
		$template_id    = reset( $template_ids );
		$this->template = TemplateModel::find( $template_id );

		if ( ! $this->template ) {
			doublescale_get_logger()->error(
				__( 'Template not found for curl multi email campaign', 'doublescale' ),
				array(
					'code'        => 'curl_multi_email_no_template',
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
	 */
	public function process_batch( CampaignContext $ctx, $contacts ) {
		$result = call_user_func( $this->fn_send_batch, $ctx->campaign, $this->template, $contacts );

		$ctx->offset += $contacts->count();

		if ( isset( $result['fatal'] ) && $result['fatal'] ) {
			return BatchResult::fatal();
		}

		return BatchResult::ok();
	}

	/**
	 * @inheritDoc
	 */
	public function get_batch_size() {
		return min(
			\DoubleScale\Modules\Emails\CurlMultiEmailSender::get_max_batch_size(),
			apply_filters( 'doublescale_campaign_curl_multi_batch_size', 100, $this->channel )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_loop_delay_microseconds() {
		return 100000; // 0.1 s
	}
}
