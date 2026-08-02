<?php

/**
 * Campaign Resender
 *
 * Handles resending failed campaign messages.
 * Extracted from AbstractCampaignProcessing to keep resend concerns in one place.
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
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Modules\Campaigns\Services\CampaignRateLimiter;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Core\Utils\Utils;

/**
 * CampaignResender class
 */
class CampaignResender {

	/** @var string */
	private $channel;

	/**
	 * Callable bound to the processor's get_message_mode().
	 * Signature: fn(): int
	 *
	 * @var callable
	 */
	private $fn_get_message_mode;

	/**
	 * Callable bound to the processor's get_current_execution_time().
	 * Signature: fn(): float
	 *
	 * @var callable
	 */
	private $fn_execution_time;

	/** @var int */
	private $max_execution_time;

	/** @var array */
	private $settings;

	/** @var CampaignRateLimiter */
	private $rate_limiter;

	/**
	 * @param string              $channel             Channel string (email, sms, whatsapp).
	 * @param callable            $fn_get_message_mode Bound to processor's get_message_mode().
	 * @param callable            $fn_execution_time   Bound to processor's get_current_execution_time().
	 * @param int                 $max_execution_time  PHP max execution ceiling in seconds.
	 * @param array               $settings            Channel settings array.
	 * @param CampaignRateLimiter $rate_limiter
	 */
	public function __construct(
		$channel,
		callable $fn_get_message_mode,
		callable $fn_execution_time,
		$max_execution_time,
		$settings,
		CampaignRateLimiter $rate_limiter
	) {
		$this->channel             = $channel;
		$this->fn_get_message_mode = $fn_get_message_mode;
		$this->fn_execution_time   = $fn_execution_time;
		$this->max_execution_time  = $max_execution_time;
		$this->settings            = $settings;
		$this->rate_limiter        = $rate_limiter;
	}

	/**
	 * Check for a campaign in "resending" status and process it.
	 *
	 * @return bool True if a resending campaign was found and handled.
	 */
	public function handle_resending() {
		$type_int = CampaignChannel::to_integer( $this->channel );

		$resending_campaign = CampaignModel::where( 'status', 'resending' )
			->where( 'type', $type_int )
			->orderBy( 'updated_at', 'asc' )
			->first();

		if ( $resending_campaign ) {
			$this->resend_failed( $resending_campaign );
			return true;
		}

		return false;
	}

	/**
	 * Resend failed messages for a campaign.
	 *
	 * @param CampaignModel $campaign
	 * @return void
	 */
	private function resend_failed( CampaignModel $campaign ) {
		try {
			$offset_key  = "doublescale_campaigns_last_resent_{$this->channel}_offset_{$campaign->id}";
			$last_offset = get_option( $offset_key, 0 );
			$count       = $this->get_failed_messages_count( $campaign );

			if ( $last_offset >= $count ) {
				$this->complete_resending( $campaign, $offset_key );
				return;
			}

			while ( call_user_func( $this->fn_execution_time ) < $this->max_execution_time && ! Utils::is_memory_limit_reached() ) {
				usleep( 100000 ); // 0.1 s

				if ( $last_offset >= $count ) {
					$this->complete_resending( $campaign, $offset_key );
					break;
				}

				$max_per_second  = $this->settings['max_in_second'] ?? $this->rate_limiter->get_default_per_second_limit( $this->channel );
				$failed_messages = $this->get_failed_messages( $campaign, $last_offset, $max_per_second );

				if ( $failed_messages->isEmpty() ) {
					break;
				}

				foreach ( $failed_messages as $message ) {
					$this->resend_single_message( $campaign, $message->contact, $message );
					++$last_offset;
					update_option( $offset_key, $last_offset );
				}
			}
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				/* translators: %s: channel name */
				sprintf( __( 'Resent failed %s messages error.', 'doublescale' ), $this->channel ),
				array(
					'code'  => "resent_failed_{$this->channel}",
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}
	}

	/**
	 * Reschedule a single failed message for re-processing.
	 *
	 * @param CampaignModel              $campaign
	 * @param ContactModel               $contact
	 * @param CommunicationTrackingModel $message
	 * @return void
	 */
	public function resend_single_message( CampaignModel $campaign, ContactModel $contact, CommunicationTrackingModel $message ) {
		$message->status = TrackingStatus::SCHEDULED;
		$message->save();

		PluginKernel::instance()->campaigns_tasks->enqueue_sync(
			"process_campaign_{$this->channel}",
			$campaign,
			$contact,
			$message
		);
	}

	/**
	 * Mark a campaign as completed after all failed messages have been re-queued.
	 *
	 * @param CampaignModel $campaign
	 * @param string        $offset_key
	 * @return void
	 */
	private function complete_resending( CampaignModel $campaign, $offset_key ) {
		$campaign->status = 'completed';
		$campaign->save();
		update_option( $offset_key, 0 );

		doublescale_get_logger()->info(
			/* translators: %s: channel name */
			sprintf( __( 'Resent failed %s messages completed.', 'doublescale' ), $this->channel ),
			array(
				'code'     => "resent_failed_{$this->channel}",
				'campaign' => $campaign->id,
			)
		);
	}

	/**
	 * Count failed messages for a campaign.
	 *
	 * @param CampaignModel $campaign
	 * @return int
	 */
	private function get_failed_messages_count( CampaignModel $campaign ) {
		$mode = call_user_func( $this->fn_get_message_mode );
		return $campaign->messages()
			->where( 'mode', $mode )
			->where( 'status', TrackingStatus::FAILED )
			->count();
	}

	/**
	 * Fetch a batch of failed messages.
	 *
	 * @param CampaignModel $campaign
	 * @param int           $offset
	 * @param int           $limit
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	private function get_failed_messages( CampaignModel $campaign, $offset, $limit ) {
		$mode = call_user_func( $this->fn_get_message_mode );
		return $campaign->messages()
			->where( 'mode', $mode )
			->where( 'status', TrackingStatus::FAILED )
			->offset( $offset )
			->limit( $limit )
			->get();
	}
}
