<?php
/**
 * Live chat comes with every plan (Basic, Plus, Agency, Enterprise and their
 * lifetime versions): any plan in free's plan list. Without a licence plan
 * the live chat switch stays off whatever is saved, and the settings screen
 * says a licence is needed. The AI assistant has no plan check.
 *
 * The relay (relay.doublescale.io) checks the same thing on its side, since
 * it carries the cost of the real-time connection.
 *
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Core\Realtime\Realtime;
use DoubleScale\Website\License;

/**
 * LiveChatPlan class.
 */
final class LiveChatPlan {

	/**
	 * Lowest plan level with live chat (Basic: every plan).
	 */
	public const MIN_LEVEL = 1;

	/**
	 * Whether this site's licence plan includes live chat.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		$license = get_option( 'doublescale_license' );
		$plan    = is_array( $license ) && isset( $license['plan'] ) ? (string) $license['plan'] : '';

		/**
		 * Whether live chat is available on this site's plan.
		 *
		 * @param bool   $allowed Any known plan (level 1, Basic, or higher).
		 * @param string $plan    Licence plan key.
		 */
		// The demo (try.doublescale.io) lets visitors try live chat without a licence.
		$allowed = self::plan_allows( $plan )
			|| ( class_exists( Realtime::class ) && Realtime::demo_site() );

		return (bool) apply_filters( 'doublescale_live_chat_plan_allowed', $allowed, $plan );
	}

	/**
	 * Whether a plan key includes live chat.
	 *
	 * @param string $plan Plan key (basic, plus, agency, enterprise, *-ltd).
	 * @return bool
	 */
	public static function plan_allows( string $plan ): bool {
		if ( '' === $plan || ! class_exists( License::class ) ) {
			return false;
		}
		$plans = License::instance()->get_plans();
		$level = isset( $plans[ $plan ]['level'] ) ? (int) $plans[ $plan ]['level'] : 0;

		return $level >= self::MIN_LEVEL;
	}
}
