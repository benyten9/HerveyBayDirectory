<?php
/**
 * The pause guard free uses when no engine owns WhatsApp: nothing is paused,
 * a reason already stored still shows (it is data), and resuming says which
 * Pro to install. Same static API as WhatsappAccountGuard, so
 * WhatsappAccountPause can hand it out in its place.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;

/**
 * NoWhatsappAccountGuard.
 */
final class NoWhatsappAccountGuard {

	/** Same option every guard copy stores a pause reason under. */
	const REASON_OPTION = 'doublescale_campaign_pause_reason_';

	/**
	 * @param int        $campaign_id Campaign id.
	 * @param string|int $code        Error code.
	 * @param string     $message     Error message.
	 * @return bool
	 */
	public static function handle_error( $campaign_id, $code, $message = '' ) {
		return false;
	}

	/**
	 * @param int        $campaign_id Campaign id.
	 * @param string|int $code        Reason code.
	 * @param string     $message     Reason.
	 * @param array      $extra       Extra reason data.
	 * @return bool
	 */
	public static function pause( $campaign_id, $code, $message = '', array $extra = array() ) {
		return false;
	}

	/**
	 * @param int $campaign_id Campaign id.
	 * @return bool
	 */
	public static function was_paused( $campaign_id ) {
		return false;
	}

	/**
	 * @param int $campaign_id Campaign id.
	 * @return array|null
	 */
	public static function get_pause_reason( $campaign_id ) {
		$reason = get_option( self::REASON_OPTION . (int) $campaign_id, null );

		return is_array( $reason ) ? $reason : null;
	}

	/**
	 * @param int $campaign_id Campaign id.
	 * @return \WP_Error
	 */
	public static function resume( $campaign_id ) {
		return ChannelAvailability::engine_unavailable_error( CampaignChannel::STR_WHATSAPP );
	}
}
