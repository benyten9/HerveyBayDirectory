<?php

/**
 * Campaign Processing Context
 *
 * Shared mutable state passed through the processing pipeline.
 * Holds all data and callbacks needed by pipeline steps and dispatch strategies.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Pipeline;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Campaigns\Services\CampaignContactFilter;
use DoubleScale\Modules\Campaigns\Services\CampaignRateLimiter;

/**
 * CampaignContext class
 */
class CampaignContext {

	// ── Campaign data ────────────────────────────────────────────────────────

	/** @var CampaignModel */
	public $campaign;

	/** @var string Channel string (email, sms, whatsapp) */
	public $channel;

	/** @var array Contact filter settings from campaign */
	public $filters = array();

	/** @var array Channel settings (max_in_second, email_footer, etc.) */
	public $settings = array();

	// ── Mutable processing state ─────────────────────────────────────────────

	/** @var int Current contact offset */
	public $offset = 0;

	/** @var int Total contact count for this campaign run */
	public $total = 0;

	/** @var bool True when a step or strategy signals the pipeline to halt */
	public $aborted = false;

	// ── Configuration ────────────────────────────────────────────────────────

	/** @var int Default batch size (overridden by strategy) */
	public $batch_size = 100;

	/** @var int Messages per second cap */
	public $max_per_second = 10;

	/** @var int PHP max_execution_time ceiling for this run */
	public $max_execution_time = 25;

	/** @var int Lock TTL in seconds */
	public $lock_duration = 300;

	/** @var string wp_options key for the distributed lock */
	public $lock_key = '';

	/** @var string wp_options key tracking the contact offset */
	public $offset_key = '';

	// ── Services ─────────────────────────────────────────────────────────────

	/** @var CampaignContactFilter */
	public $contact_filter;

	/** @var CampaignRateLimiter */
	public $rate_limiter;

	// ── Processor callbacks (bound to AbstractCampaignProcessing instance) ──

	/** @var callable fn(CampaignModel, int): void */
	public $fn_complete;

	/** @var callable fn(int $campaign_id): void */
	public $fn_continue;

	/** @var callable fn(string $lock_key, int $duration): bool */
	public $fn_refresh_lock;

	/** @var callable fn(): float Returns elapsed seconds since processing started */
	public $fn_execution_time;

	/** @var callable fn(CampaignModel, ContactModel): array Used by IndividualDispatchStrategy */
	public $fn_add_message;

	// ── Helper methods ───────────────────────────────────────────────────────

	/**
	 * Seconds elapsed since the processing run started.
	 */
	public function get_execution_time() {
		return call_user_func( $this->fn_execution_time );
	}

	/**
	 * Refresh the distributed lock, keeping it alive.
	 */
	public function refresh_lock() {
		call_user_func( $this->fn_refresh_lock, $this->lock_key, $this->lock_duration );
	}

	/**
	 * Mark the campaign as completed and halt the pipeline.
	 */
	public function complete() {
		call_user_func( $this->fn_complete, $this->campaign, $this->total );
		$this->abort();
	}

	/**
	 * Schedule a continuation run and let the pipeline finish normally.
	 */
	public function queue_continuation() {
		call_user_func( $this->fn_continue, $this->campaign->id );
	}

	/**
	 * Signal all subsequent pipeline steps to skip.
	 */
	public function abort() {
		$this->aborted = true;
	}

	/**
	 * True when every contact has been dispatched.
	 */
	public function is_complete() {
		return $this->total > 0 && $this->offset >= $this->total;
	}
}
