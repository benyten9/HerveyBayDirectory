<?php
/**
 * Reclaims messages left SCHEDULED with no job behind them.
 *
 * SCHEDULED is a handover state: the resender sets it, queues a job, and the
 * job moves the row on within seconds. If that job never runs — a fatal in the
 * handler, a queue wiped by hand, Action Scheduler giving up on a repeatedly
 * failing action — the row is stranded. It is no longer FAILED, so no retry can
 * find it, and no job exists to run it. Nothing else in the system would ever
 * touch it again.
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * StrandedMessageSweeper class
 */
class StrandedMessageSweeper {

	/**
	 * How long a message may sit in SCHEDULED before it counts as stranded.
	 *
	 * Generous on purpose: a queue under load, or a campaign throttled to a few
	 * sends a second, can legitimately leave a message waiting for minutes.
	 * Reclaiming one that is still on its way would send it twice.
	 *
	 * @var int Seconds.
	 */
	const STALE_AFTER = 1800;

	/**
	 * Class instance.
	 *
	 * @var StrandedMessageSweeper
	 */
	private static $instance;

	/**
	 * Instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Return stranded messages to FAILED so a retry can pick them up.
	 *
	 * @return int How many messages were reclaimed.
	 */
	public function sweep() {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER );

		$stranded = CommunicationTrackingModel::where( 'status', TrackingStatus::SCHEDULED )
			->where( 'updated_at', '<', $cutoff )
			->get();

		$reclaimed = 0;

		foreach ( $stranded as $message ) {
			$message->status = TrackingStatus::FAILED;
			$message->save();

			// Without a reason the operator sees a failure appear from nowhere.
			CommunicationTrackingMetaModel::store_error_info(
				$message->id,
				'stranded_scheduled',
				__( 'The send was queued but never ran, so it was returned to failed. Use Retry Sending to try again.', 'doublescale' )
			);

			++$reclaimed;
		}

		if ( $reclaimed > 0 ) {
			doublescale_get_logger()->info(
				'Reclaimed stranded scheduled messages',
				array(
					'code'      => 'stranded_scheduled_reclaimed',
					'reclaimed' => $reclaimed,
				)
			);
		}

		return $reclaimed;
	}
}
