<?php

/**
 * Campaign Continuation Scheduler
 *
 * Handles AJAX and Action Scheduler continuation for long-running campaigns.
 * Strategy: try a non-blocking AJAX request first; fall back to Action Scheduler.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Core\PluginKernel;

/**
 * CampaignContinuationScheduler class
 */
class CampaignContinuationScheduler {

	/** @var string */
	private $channel;

	/**
	 * Callable bound to the processor's continue_campaign_processing().
	 * Signature: fn(int $campaign_id): void
	 *
	 * @var callable
	 */
	private $fn_continue;

	/**
	 * @param string   $channel     Channel string (email, sms, whatsapp).
	 * @param callable $fn_continue Bound to the processor's continue_campaign_processing().
	 */
	public function __construct( $channel, callable $fn_continue ) {
		$this->channel     = $channel;
		$this->fn_continue = $fn_continue;
	}

	/**
	 * Register wp_ajax hooks so this scheduler handles AJAX continuation requests.
	 *
	 * @return void
	 */
	public function register_ajax_hooks() {
		$ajax_action = "doublescale_continue_campaign_{$this->channel}";
		add_action( "wp_ajax_nopriv_{$ajax_action}", array( $this, 'handle_ajax_continuation' ) );
		add_action( "wp_ajax_{$ajax_action}", array( $this, 'handle_ajax_continuation' ) );
	}

	/**
	 * Queue a continuation for $campaign_id.
	 *
	 * Tries an immediate non-blocking AJAX request first; falls back to Action Scheduler.
	 *
	 * @param int $campaign_id
	 * @return void
	 */
	public function queue( $campaign_id ) {
		$campaign = CampaignModel::find( $campaign_id );
		if ( ! $campaign || $campaign->status !== 'processing' ) {
			return;
		}

		try {
			if ( $this->trigger_ajax( $campaign_id ) ) {
				doublescale_get_logger()->info(
					/* translators: %s: channel name */
					sprintf( __( 'Campaign %s continuation triggered via AJAX (non-blocking)', 'doublescale' ), $this->channel ),
					array(
						'code'        => "{$this->channel}_continuation_ajax",
						'campaign_id' => $campaign_id,
					)
				);
				return;
			}
		} catch ( \Exception $e ) {
			doublescale_get_logger()->info(
				/* translators: %s: channel name */
				sprintf( __( 'AJAX continuation attempt failed for campaign %s, using Action Scheduler fallback', 'doublescale' ), $this->channel ),
				array(
					'code'        => "{$this->channel}_ajax_continuation_exception",
					'campaign_id' => $campaign_id,
					'error'       => $e->getMessage(),
				)
			);
		}

		$action_id = PluginKernel::instance()->campaigns_tasks->enqueue_async(
			"continue_{$this->channel}_campaign",
			$campaign_id
		);

		if ( ! $action_id ) {
			doublescale_get_logger()->error(
				/* translators: %s: channel name */
				sprintf( __( 'Failed to queue campaign %s continuation (both AJAX and Action Scheduler failed)', 'doublescale' ), $this->channel ),
				array(
					'code'        => "{$this->channel}_continuation_queue_failed",
					'campaign_id' => $campaign_id,
				)
			);
			return;
		}

		doublescale_get_logger()->info(
			/* translators: %s: channel name */
			sprintf( __( 'Campaign %s continuation queued via Action Scheduler (fallback, non-blocking)', 'doublescale' ), $this->channel ),
			array(
				'code'        => "{$this->channel}_continuation_queued",
				'campaign_id' => $campaign_id,
				'action_id'   => $action_id,
			)
		);
	}

	/**
	 * Handle an AJAX continuation request (runs in a separate PHP process).
	 *
	 * @return void
	 */
	public function handle_ajax_continuation() {
		nocache_headers();

		$campaign_id = isset( $_REQUEST['campaign_id'] ) ? (int) $_REQUEST['campaign_id'] : 0;
		$channel     = isset( $_REQUEST['channel'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['channel'] ) ) : '';
		$nonce       = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';

		if ( ! $campaign_id ) {
			wp_send_json_error( array( 'message' => 'Invalid campaign ID' ) );
			return;
		}

		if ( $channel && $channel !== $this->channel ) {
			wp_send_json_error( array( 'message' => 'Channel mismatch' ) );
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'doublescale_continue_campaign_' . $campaign_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		// If Action Scheduler is about to fire, let it handle this instead.
		$full_hook   = "doublescale_campaigns_continue_{$this->channel}_campaign";
		$next_action = as_next_scheduled_action( $full_hook );
		if ( $next_action ) {
			$time_until_action = $next_action - time();
			if ( $time_until_action > 0 && $time_until_action <= 3 ) {
				wp_send_json_success( array( 'message' => 'Action Scheduler will handle it soon' ) );
				return;
			}
		}

		try {
			call_user_func( $this->fn_continue, $campaign_id );
			wp_send_json_success(
				array(
					'message'     => 'Processed',
					'campaign_id' => $campaign_id,
					'channel'     => $this->channel,
				)
			);
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				/* translators: %s: channel name */
				sprintf( __( 'AJAX continuation error for campaign %s', 'doublescale' ), $this->channel ),
				array(
					'code'        => "{$this->channel}_ajax_continuation_error",
					'campaign_id' => $campaign_id,
					'error'       => $e->getMessage(),
					'trace'       => $e->getTraceAsString(),
				)
			);
			wp_send_json_error(
				array(
					'message'     => $e->getMessage(),
					'campaign_id' => $campaign_id,
				)
			);
		}
	}

	/**
	 * Send a non-blocking AJAX continuation request.
	 *
	 * @param int $campaign_id
	 * @return bool True if the HTTP request was dispatched without WP_Error.
	 */
	private function trigger_ajax( $campaign_id ) {
		if ( ! apply_filters( 'doublescale_ajax_continuation_enable', true, $this->channel, $campaign_id ) ) {
			return false;
		}

		try {
			if ( ! $this->should_use_ajax() ) {
				return false;
			}
		} catch ( \Exception $e ) {
			doublescale_get_logger()->info(
				/* translators: %s: channel name */
				sprintf( __( 'Error checking AJAX continuation eligibility for campaign %s', 'doublescale' ), $this->channel ),
				array(
					'code'        => "{$this->channel}_ajax_check_error",
					'campaign_id' => $campaign_id,
					'error'       => $e->getMessage(),
				)
			);
			return false;
		}

		$ajax_action = "doublescale_continue_campaign_{$this->channel}";
		$url         = add_query_arg(
			array(
				'action'      => $ajax_action,
				'campaign_id' => $campaign_id,
				'channel'     => $this->channel,
				'nonce'       => wp_create_nonce( 'doublescale_continue_campaign_' . $campaign_id ),
				'time'        => time(),
			),
			admin_url( 'admin-ajax.php' )
		);

		$response = wp_remote_post(
			$url,
			array(
				'sslverify' => false,
				'blocking'  => false,
				'timeout'   => 1,
				'body'      => array(
					'campaign_id' => $campaign_id,
					'channel'     => $this->channel,
				),
			)
		);

		return ! is_wp_error( $response );
	}

	/**
	 * Return true if AJAX should be preferred over waiting for Action Scheduler.
	 *
	 * @return bool
	 */
	private function should_use_ajax() {
		$full_hook   = "doublescale_campaigns_continue_{$this->channel}_campaign";
		$next_action = as_next_scheduled_action( $full_hook );

		if ( ! $next_action ) {
			return true;
		}

		$ajax_threshold = apply_filters(
			'doublescale_ajax_continuation_threshold',
			5,
			$this->channel
		);

		return ( $next_action - time() ) > $ajax_threshold;
	}
}
