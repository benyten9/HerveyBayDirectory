<?php
/**
 * Monotonic delivery status rules for provider webhooks (SMS / WhatsApp).
 *
 * Meta and other providers can emit status events out of order. A late "failed"
 * webhook must not overwrite a message that is already delivered or read.
 *
 * @package DoubleScale\Modules\Tracking
 */

namespace DoubleScale\Modules\Tracking;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\TrackingStatus;

defined( 'ABSPATH' ) || exit;

/**
 * DeliveryStatusProgression helper.
 */
class DeliveryStatusProgression {

	/**
	 * Channels that use messaging-style delivery progression.
	 *
	 * @return string[]
	 */
	public static function messaging_channels(): array {
		return array(
			CampaignChannel::STR_SMS,
			CampaignChannel::STR_WHATSAPP,
		);
	}

	/**
	 * Map a provider webhook status slug to a tracking status constant.
	 *
	 * @param string $slug    Provider status (sent, delivered, read, failed, undelivered).
	 * @param string $channel Communication channel slug.
	 * @return int|null Tracking status constant, or null when the slug does not apply.
	 */
	public static function status_from_webhook_slug( string $slug, string $channel ): ?int {
		switch ( strtolower( $slug ) ) {
			case 'sent':
				return TrackingStatus::SENT;
			case 'delivered':
				return TrackingStatus::DELIVERED;
			case 'read':
				return CampaignChannel::STR_WHATSAPP === $channel ? TrackingStatus::READ : null;
			case 'failed':
			case 'undelivered':
				return TrackingStatus::FAILED;
			default:
				return null;
		}
	}

	/**
	 * Whether a webhook should change the stored tracking status.
	 *
	 * @param int    $current_status Stored status constant.
	 * @param int    $proposed_status Status constant from the webhook.
	 * @param string $channel Communication channel slug.
	 * @return bool
	 */
	public static function should_apply_status_update( int $current_status, int $proposed_status, string $channel ): bool {
		if ( ! in_array( $channel, self::messaging_channels(), true ) ) {
			return true;
		}

		if ( TrackingStatus::FAILED === $proposed_status ) {
			return ! self::is_proven_delivery( $current_status );
		}

		$current_rank  = self::success_rank( $current_status );
		$proposed_rank = self::success_rank( $proposed_status );

		if ( null === $proposed_rank ) {
			return true;
		}

		if ( TrackingStatus::FAILED === $current_status ) {
			return $proposed_rank >= self::success_rank( TrackingStatus::SENT );
		}

		if ( null === $current_rank ) {
			return true;
		}

		return $proposed_rank >= $current_rank;
	}

	/**
	 * Delivered or read — provider failure webhooks after this point are stale.
	 *
	 * @param int $status Tracking status constant.
	 * @return bool
	 */
	private static function is_proven_delivery( int $status ): bool {
		return TrackingStatus::DELIVERED === $status || TrackingStatus::READ === $status;
	}

	/**
	 * Rank on the successful delivery path (higher = further along).
	 *
	 * @param int $status Tracking status constant.
	 * @return int|null Null when not on the success path (e.g. failed).
	 */
	private static function success_rank( int $status ): ?int {
		switch ( $status ) {
			case TrackingStatus::PENDING:
				return 0;
			case TrackingStatus::SENT:
				return 1;
			case TrackingStatus::DELIVERED:
				return 2;
			case TrackingStatus::READ:
				return 3;
			default:
				return null;
		}
	}
}
