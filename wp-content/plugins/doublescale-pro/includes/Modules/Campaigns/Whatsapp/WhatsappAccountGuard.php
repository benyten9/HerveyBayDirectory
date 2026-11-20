<?php
/**
 * Stops WhatsApp campaigns when Meta restricts the sending number.
 *
 * Meta answers #131048 when it limits what the business number may send —
 * because too many earlier messages were blocked or reported as spam. Every
 * further send deepens the problem and is how a number ends up blocked, so the
 * campaign pauses at the first one instead of working through its list.
 *
 * The pause is reported where Meta reports it: synchronously in the send
 * response, or later on the delivery webhook.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

defined( 'ABSPATH' ) || exit;

/*
 * Pro's copy of free's campaign pause guard. It moved with the WhatsApp engine;
 * free keeps its own, frozen, for sites whose Pro predates this. Both read and
 * write the same pause-reason option and campaign status.
 */

use DoubleScale\Modules\Campaigns\Services\CampaignStatusManager;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;

/**
 * WhatsappAccountGuard class
 */
final class WhatsappAccountGuard {

	/**
	 * Option holding why a campaign was paused, keyed by campaign id.
	 */
	const REASON_OPTION = 'doublescale_campaign_pause_reason_';

	/**
	 * Campaign states a restriction interrupts. A draft or finished campaign is
	 * left alone — pausing it would make a completed run look unfinished.
	 *
	 * @var string[]
	 */
	const PAUSABLE = array(
		CampaignStatusManager::PROCESSING,
		CampaignStatusManager::RESENDING,
		CampaignStatusManager::ACTIVE,
	);

	/**
	 * Campaigns paused during this request, so a running send loop can stop at
	 * once instead of waiting for its next batch to re-read the status.
	 *
	 * @var array<int, true>
	 */
	private static $paused_now = array();

	/**
	 * Pause a campaign if a send failed because Meta restricted the number.
	 *
	 * @param int        $campaign_id Campaign the failed message belongs to.
	 * @param int|string $code        Meta error code.
	 * @param string     $message     Meta's error message.
	 * @return bool True when the code was an account restriction.
	 */
	public static function handle_error( $campaign_id, $code, $message = '' ) {
		if ( ! MetaWhatsappErrorCodes::is_account_restriction( $code ) ) {
			return false;
		}

		self::pause( (int) $campaign_id, $code, $message );

		return true;
	}

	/**
	 * Pause a campaign and record why.
	 *
	 * @param int        $campaign_id Campaign id.
	 * @param int|string $code        Meta error code.
	 * @param string     $message     Meta's error message.
	 * @param array      $extra       More detail for the reason (e.g. resumes_at).
	 * @return bool True when this call paused the campaign.
	 */
	public static function pause( $campaign_id, $code, $message = '', array $extra = array() ) {
		$campaign_id = (int) $campaign_id;
		if ( $campaign_id < 1 ) {
			return false;
		}

		self::$paused_now[ $campaign_id ] = true;

		$campaign = CampaignModel::find( $campaign_id );
		if ( ! $campaign || ! in_array( (string) $campaign->status, self::PAUSABLE, true ) ) {
			return false;
		}

		$previous = (string) $campaign->status;

		// Never throw from here: this runs inside a send or a webhook, and an
		// exception would leave the campaign running — the one outcome this
		// guard exists to prevent. The loop still stops via $paused_now.
		try {
			$campaign->status = CampaignStatusManager::PAUSED;
			$campaign->save();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				__( 'Could not pause the WhatsApp campaign after Meta restricted the number.', 'doublescale' ),
				array(
					'code'        => 'whatsapp_campaign_pause_failed',
					'campaign_id' => $campaign_id,
					'from_status' => $previous,
					'error'       => $e->getMessage(),
				)
			);
			return false;
		}

		update_option(
			self::REASON_OPTION . $campaign_id,
			array_merge(
				$extra,
				array(
					'code'            => (string) $code,
					'message'         => (string) $message,
					'paused_at'       => gmdate( 'Y-m-d H:i:s' ),
					// Resume returns here: a campaign paused mid-retry goes
					// back to retrying, not to a fresh send of its audience.
					'previous_status' => $previous,
				)
			),
			false
		);

		doublescale_get_logger()->error(
			__( 'WhatsApp campaign paused: Meta restricted the sending number.', 'doublescale' ),
			array(
				'code'        => 'whatsapp_campaign_paused_account_restricted',
				'campaign_id' => $campaign_id,
				'meta_code'   => (string) $code,
				'meta_error'  => (string) $message,
			)
		);

		/**
		 * Fires when a WhatsApp campaign is paused because Meta restricted the
		 * sending number.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $campaign_id Campaign id.
		 * @param string $code        Meta error code.
		 * @param string $message     Meta's error message.
		 */
		do_action( 'doublescale_whatsapp_campaign_paused_by_meta', $campaign_id, (string) $code, (string) $message );

		return true;
	}

	/**
	 * Whether this request already paused the campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return bool
	 */
	public static function was_paused( $campaign_id ) {
		return isset( self::$paused_now[ (int) $campaign_id ] );
	}

	/**
	 * Recorded pause reason for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array{code:string,message:string,paused_at:string}|null
	 */
	public static function get_pause_reason( $campaign_id ) {
		$reason = get_option( self::REASON_OPTION . (int) $campaign_id, null );

		return is_array( $reason ) ? $reason : null;
	}

	/**
	 * Put a paused campaign back to the state it was paused from.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return CampaignModel|\WP_Error The resumed campaign.
	 */
	public static function resume( $campaign_id ) {
		$campaign = CampaignModel::find( (int) $campaign_id );
		if ( ! $campaign ) {
			return new \WP_Error( 'not_found', __( 'Campaign not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( CampaignStatusManager::PAUSED !== (string) $campaign->status ) {
			return new \WP_Error(
				'doublescale_campaign_not_paused',
				__( 'Only a paused campaign can be resumed.', 'doublescale' ),
				array( 'status' => 409 )
			);
		}

		$reason   = self::get_pause_reason( $campaign->id );
		$previous = is_array( $reason ) ? (string) ( $reason['previous_status'] ?? '' ) : '';

		$campaign->status = in_array( $previous, self::PAUSABLE, true ) ? $previous : CampaignStatusManager::PROCESSING;
		$campaign->save();

		self::clear( $campaign->id );

		return $campaign;
	}

	/**
	 * Forget the recorded reason, e.g. when the admin resumes the campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return void
	 */
	public static function clear( $campaign_id ) {
		delete_option( self::REASON_OPTION . (int) $campaign_id );
		unset( self::$paused_now[ (int) $campaign_id ] );
	}

	/**
	 * Forget per-request state (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$paused_now = array();
	}
}
