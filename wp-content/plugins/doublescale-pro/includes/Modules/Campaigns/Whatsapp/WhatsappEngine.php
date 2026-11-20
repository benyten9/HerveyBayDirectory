<?php
/**
 * Which WhatsApp campaign engine Pro's code talks to.
 *
 * WhatsApp's campaign engine is moving from DoubleScale (free) into Pro, and
 * WordPress updates the two plugins one at a time:
 *
 * - Free 1.7.0+ ships ChannelEngineRegistry. Pro registers its own engine
 *   there before free's fallback, so Pro's engine runs WhatsApp — unless the
 *   switch below is off, and then free's copy runs it.
 * - An older free has no registry and runs its own engine; Pro's stays unbuilt
 *   and everything here hands Pro's code free's engine instead.
 *
 * This is the only place in Pro that names free's engine class: every caller
 * (automations, automated campaigns, the Meta tier guard) asks processor().
 *
 * Emergency switch: define( 'DOUBLESCALE_PRO_WHATSAPP_ENGINE', false ) in
 * wp-config.php — or answer false on `doublescale_pro_whatsapp_engine_enabled` —
 * and free's own engine takes WhatsApp back on the next request, for as long as
 * free still ships one.
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Whatsapp
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsappEngine.
 */
final class WhatsappEngine {

	/** Free's registry (free 1.7.0+). Named as a string: older free lacks it. */
	const REGISTRY = '\\DoubleScale\\Modules\\Campaigns\\Services\\ChannelEngineRegistry';

	/** Free's own engine, the one an older free runs. */
	const FREE_ENGINE = '\\DoubleScale\\Modules\\Campaigns\\Campaign\\WhatsappProcessing';

	/** Free's pause guard, the one an older free pauses with. */
	const FREE_GUARD = '\\DoubleScale\\Modules\\Campaigns\\Services\\WhatsappAccountGuard';

	/** Free 1.7.0+'s way to the owning engine's guard. */
	const PAUSE = '\\DoubleScale\\Modules\\Campaigns\\Services\\WhatsappAccountPause';

	/**
	 * Register Pro's engines with free's registry. Called on
	 * `doublescale_modules_booted` (priority 20) while Campaigns is on —
	 * before free's fallback (30), so Pro's engine owns the channel.
	 *
	 * @return void
	 */
	public static function register() {
		$registry = self::REGISTRY;
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::register( 'sms', \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::instance(), $registry::OWNER_PRO );

		if ( self::enabled() ) {
			$registry::register( 'whatsapp', WhatsappProcessing::instance(), $registry::OWNER_PRO );
		}
	}

	/**
	 * Register Pro's WhatsApp receiver with free's registry, under its own key.
	 * Called on `doublescale_modules_booted` (priority 20) once free's
	 * AbstractTracking is loaded — before free's fallback receiver (30).
	 *
	 * @return void
	 */
	public static function register_tracking() {
		$registry = self::REGISTRY;
		if ( ! class_exists( $registry ) || ! method_exists( $registry, 'tracking_key' ) || ! self::enabled() ) {
			return;
		}

		$registry::register( $registry::tracking_key( 'whatsapp' ), WhatsappTracking::instance(), $registry::OWNER_PRO );
	}

	/**
	 * Whether Pro's WhatsApp engine may run WhatsApp on this site.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = ! ( defined( 'DOUBLESCALE_PRO_WHATSAPP_ENGINE' ) && false === DOUBLESCALE_PRO_WHATSAPP_ENGINE );

		/**
		 * Whether Pro's WhatsApp campaign engine may run WhatsApp.
		 *
		 * @param bool $enabled False hands WhatsApp back to free's engine.
		 */
		return (bool) apply_filters( 'doublescale_pro_whatsapp_engine_enabled', $enabled );
	}

	/**
	 * The pause guard Pro's code should pause and resume with: the owning
	 * engine's (free 1.7.0+, through WhatsappAccountPause), or an older free's.
	 * One guard per site, since was_paused() only sees its own pauses.
	 *
	 * @return string|null Class name with WhatsappAccountGuard's static API.
	 */
	public static function account_guard() {
		if ( class_exists( self::PAUSE ) ) {
			return self::PAUSE;
		}

		return class_exists( self::FREE_GUARD ) ? self::FREE_GUARD : null;
	}

	/**
	 * Whether free fires `doublescale_whatsapp_campaign_hold_recipient` and
	 * owns pausing — every free with the guard, and every free with the registry.
	 *
	 * @return bool
	 */
	public static function free_fires_hold_hook() {
		return class_exists( self::REGISTRY ) || class_exists( self::FREE_GUARD );
	}

	/**
	 * The engine Pro's code should send WhatsApp messages through.
	 *
	 * - Free 1.7.0+: the engine that owns WhatsApp. With nothing owning it
	 *   (Campaigns off) automations still send one message at a time, so they
	 *   get Pro's engine unregistered — it never joins the campaign pipeline.
	 * - An older free: free's own engine, which runs WhatsApp there.
	 *
	 * @return object|null Null only when no engine exists at all.
	 */
	public static function processor() {
		$registry = self::REGISTRY;

		if ( class_exists( $registry ) ) {
			$owner = $registry::get( 'whatsapp' );
			if ( $owner ) {
				return $owner;
			}

			return self::enabled() ? WhatsappProcessing::instance() : null;
		}

		$free = self::FREE_ENGINE;

		return class_exists( $free ) ? $free::instance() : null;
	}
}
