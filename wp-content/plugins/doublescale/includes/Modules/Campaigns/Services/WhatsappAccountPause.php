<?php
/**
 * Pausing and resuming WhatsApp campaigns, through the guard of the engine
 * that owns WhatsApp.
 *
 * The guard lives with the WhatsApp engine: free's WhatsappAccountGuard, and
 * Pro's own copy. Free's copy is deleted once every site runs a Pro that has
 * its own, so free's shared code — the dispatch strategy shared with email,
 * campaign enrichment, the send path, the receiver and the /resume route —
 * never names it. pause() and was_paused() always reach the same class, since
 * was_paused() reads a flag only the pausing class sees. With nothing owning
 * WhatsApp the answers are safe: nothing is paused, a stored reason still
 * shows, and resuming says which Pro to install.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;

/**
 * WhatsappAccountPause.
 */
final class WhatsappAccountPause {

	/**
	 * The guard class of the engine that owns WhatsApp.
	 *
	 * @return string Class name with WhatsappAccountGuard's static API.
	 */
	public static function source(): string {
		$engine = ChannelEngineRegistry::get( CampaignChannel::STR_WHATSAPP );

		if ( $engine && method_exists( $engine, 'account_guard' ) ) {
			$class = $engine->account_guard();
			if ( is_string( $class ) && class_exists( $class ) ) {
				return $class;
			}
		}

		return NoWhatsappAccountGuard::class;
	}

	/**
	 * Pause the campaign when the code is a Meta account restriction.
	 *
	 * @param int        $campaign_id Campaign id.
	 * @param string|int $code        Error code.
	 * @param string     $message     Error message.
	 * @return bool
	 */
	public static function handle_error( $campaign_id, $code, $message = '' ) {
		$class = self::source();

		return (bool) $class::handle_error( $campaign_id, $code, $message );
	}

	/**
	 * Pause a campaign.
	 *
	 * @param int        $campaign_id Campaign id.
	 * @param string|int $code        Reason code.
	 * @param string     $message     Reason.
	 * @param array      $extra       Extra reason data.
	 * @return bool
	 */
	public static function pause( $campaign_id, $code, $message = '', array $extra = array() ) {
		$class = self::source();

		return (bool) $class::pause( $campaign_id, $code, $message, $extra );
	}

	/**
	 * Whether the campaign was paused during this request.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return bool
	 */
	public static function was_paused( $campaign_id ) {
		$class = self::source();

		return (bool) $class::was_paused( $campaign_id );
	}

	/**
	 * Why a campaign is paused.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array|null
	 */
	public static function get_pause_reason( $campaign_id ) {
		$class = self::source();

		return $class::get_pause_reason( $campaign_id );
	}

	/**
	 * Resume a paused campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return \DoubleScale\Modules\Campaigns\Models\CampaignModel|\WP_Error
	 */
	public static function resume( $campaign_id ) {
		$class = self::source();

		return $class::resume( $campaign_id );
	}
}
