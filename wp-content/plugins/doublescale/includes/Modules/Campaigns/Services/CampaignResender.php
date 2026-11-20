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
use DoubleScale\Core\Constants\MessageSourceTypes;
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

			while ( true ) {
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
					$contact = $message->contact;
					if ( $contact ) {
						$this->resend_single_message( $campaign, $contact, $message );
					} else {
						$message->status = TrackingStatus::SCHEDULED;
						$message->save();
					}
					++$last_offset;
					update_option( $offset_key, $last_offset );
				}

				if ( call_user_func( $this->fn_execution_time ) >= $this->max_execution_time || Utils::is_memory_limit_reached() ) {
					break;
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

		$hook = "process_campaign_{$this->channel}";

		if ( CampaignChannel::STR_WHATSAPP === $this->channel ) {
			PluginKernel::instance()->campaigns_tasks->schedule_single(
				time() + 1,
				$hook,
				$campaign,
				$contact,
				$message
			);
			return;
		}

		PluginKernel::instance()->campaigns_tasks->enqueue_sync(
			$hook,
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
		$settings         = is_array( $campaign->settings ) ? $campaign->settings : array();
		$campaign->status = ! empty( $settings['automated'] ) ? 'active' : 'completed';
		$campaign->save();
		update_option( $offset_key, 0 );
		delete_option( 'doublescale_resend_error_code_' . $campaign->id );
		delete_transient( 'doublescale_failure_causes_' . $campaign->id );

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
		$mode  = call_user_func( $this->fn_get_message_mode );
		$query = $campaign->messages()
			->where( 'mode', $mode )
			->where( 'status', TrackingStatus::FAILED );

		$this->apply_error_code_scope( $query, $campaign );
		$this->skip_recipients_in_cooldown( $query );

		return $query->count();
	}

	/**
	 * Fetch a batch of failed messages.
	 *
	 * @param CampaignModel $campaign
	 * @param int           $offset
	 * @param int           $limit
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Collection
	 */
	private function get_failed_messages( CampaignModel $campaign, $offset, $limit ) {
		$mode  = call_user_func( $this->fn_get_message_mode );
		$query = $campaign->messages()
			->where( 'mode', $mode )
			->where( 'status', TrackingStatus::FAILED );

		$this->apply_error_code_scope( $query, $campaign );
		$this->skip_recipients_in_cooldown( $query );

		return $query
			->offset( $offset )
			->limit( $limit )
			->get();
	}

	/**
	 * Leave out recipients Meta asked us to leave alone for now.
	 *
	 * Meta's per-user marketing cap (131049) is about the person, not the
	 * account: it drops marketing messages to someone who has had too many
	 * lately, from any business. Resending straight away fails the same way,
	 * so admins end up in a resend loop that reaches nobody and only feeds
	 * the number's quality rating. Those messages stay failed and become
	 * resendable once the recipient's own window has moved on; every other
	 * failure resends as before.
	 *
	 * Applied to the count and to the batch alike, so the resend offsets stay
	 * in step.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query Query.
	 * @return void
	 */
	private function skip_recipients_in_cooldown( $query ) {
		self::exclude_recipients_in_cooldown( $query, (string) $this->channel );
	}

	/**
	 * The filter itself, shared with the Retry Sending endpoint so the button
	 * and the resend it starts agree on what can be retried now.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query   Query over the tracking table.
	 * @param string                                $channel Campaign channel slug.
	 * @return void
	 */
	public static function exclude_recipients_in_cooldown( $query, $channel ) {
		if ( CampaignChannel::STR_WHATSAPP !== $channel ) {
			return;
		}

		$cooldown = WhatsappErrorCodes::recipient_cooldown_seconds();
		if ( $cooldown <= 0 ) {
			return;
		}

		global $wpdb;

		$meta   = $wpdb->prefix . 'doublescale_communication_tracking_meta';
		$table  = ( new CommunicationTrackingModel() )->getTable();
		$codes  = array_map( 'strval', WhatsappErrorCodes::recipient_cooldown_codes() );
		$since  = gmdate( 'Y-m-d H:i:s', time() - $cooldown );
		$holes  = implode( ',', array_fill( 0, count( $codes ), '?' ) );
		$params = array_merge( $codes, array( $since ) );

		$query->whereRaw(
			"NOT EXISTS (
				SELECT 1 FROM {$meta} m
				WHERE m.communication_tracking_id = {$table}.id
				  AND m.meta_key = 'error_info'
				  AND JSON_VALID(m.meta_value)
				  AND JSON_UNQUOTE(JSON_EXTRACT(m.meta_value, '$.code')) IN ({$holes})
				  AND {$table}.updated_at >= ?
			)",
			$params
		);
	}

	/**
	 * When the first capped recipient of a campaign may be retried, or null.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int|null Unix time.
	 */
	public static function first_cooldown_release( $campaign_id ) {
		$cooldown = WhatsappErrorCodes::recipient_cooldown_seconds();
		if ( $cooldown <= 0 ) {
			return null;
		}

		global $wpdb;

		$meta  = $wpdb->prefix . 'doublescale_communication_tracking_meta';
		$table = ( new CommunicationTrackingModel() )->getTable();
		$codes = implode( ',', array_map( 'intval', WhatsappErrorCodes::recipient_cooldown_codes() ) );

		$first = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(t.updated_at) FROM {$table} t
				 INNER JOIN {$meta} m ON m.communication_tracking_id = t.id AND m.meta_key = 'error_info'
				 WHERE t.source_id = %d AND t.source_type = %d AND t.mode = %d AND t.status = %d
				   AND JSON_VALID(m.meta_value)
				   AND JSON_UNQUOTE(JSON_EXTRACT(m.meta_value, '$.code')) IN ({$codes})
				   AND t.updated_at >= %s",
				(int) $campaign_id,
				MessageSourceTypes::CAMPAIGN,
				CommunicationTrackingModel::MODE_WHATSAPP,
				TrackingStatus::FAILED,
				gmdate( 'Y-m-d H:i:s', time() - $cooldown )
			)
		);

		return $first ? strtotime( $first . ' UTC' ) + $cooldown : null;
	}

	/**
	 * Restrict a failed-message query to a scoped error_info code, if any.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query    Query.
	 * @param CampaignModel                         $campaign Campaign.
	 * @return void
	 */
	private function apply_error_code_scope( $query, CampaignModel $campaign ) {
		$code = sanitize_text_field( (string) get_option( 'doublescale_resend_error_code_' . $campaign->id, '' ) );
		if ( '' === $code ) {
			return;
		}

		global $wpdb;

		$meta  = $wpdb->prefix . 'doublescale_communication_tracking_meta';
		$table = ( new CommunicationTrackingModel() )->getTable();

		$query->whereRaw(
			"EXISTS (
				SELECT 1 FROM {$meta} m
				WHERE m.communication_tracking_id = {$table}.id
				  AND m.meta_key = 'error_info'
				  AND JSON_VALID(m.meta_value)
				  AND JSON_UNQUOTE(JSON_EXTRACT(m.meta_value, '$.code')) = ?
			)",
			array( $code )
		);
	}
}
