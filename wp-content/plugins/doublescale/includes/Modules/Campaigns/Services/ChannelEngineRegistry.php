<?php
/**
 * Which engine sends each campaign channel on this site.
 *
 * WhatsApp's campaign engine is moving from free to Pro, and for a while both
 * plugins ship a copy. WordPress updates the two one at a time, so any mix of
 * versions can be live. Two engines on one channel would both listen on the
 * same task hooks — `Tasks::register_callback()` only de-duplicates the same
 * object, and `process_campaign_message()` never checks whether a message
 * already went out — so every message would be sent twice.
 *
 * So exactly one engine owns a channel, and ownership is decided by
 * registration rather than by a flag: the first engine registered for a
 * channel owns it and every later registration is refused. Pro registers on
 * `doublescale_modules_booted` at priority 20; free's own copy registers at 30,
 * and only into a slot that is still empty. An engine can therefore never own
 * a channel it does not actually run, and the answer depends only on the code
 * that is loaded — the same in admin, REST, cron and CLI requests.
 *
 * Engines check `is_owner()` before hooking into the send pipeline, which also
 * keeps a stray `::instance()` that was never registered silent.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;

/**
 * ChannelEngineRegistry.
 */
final class ChannelEngineRegistry {

	/** The engine free ships. */
	const OWNER_FREE = 'free';

	/** The engine Pro ships. */
	const OWNER_PRO = 'pro';

	/** First Pro release that ships its own WhatsApp engine. */
	const PRO_ENGINE_VERSION = '1.3.0';

	/**
	 * Channels free still ships its own engine for. SMS left free in May 2026
	 * and WhatsApp in DoubleScale 1.8.0, so Pro owns every non-email channel.
	 */
	const FALLBACK_CHANNELS = array();

	/**
	 * Registered engines by channel.
	 *
	 * @var array<string, array{engine: object, owner: string}>
	 */
	private static $engines = array();

	/**
	 * Register an engine for a channel. The first registration wins.
	 *
	 * Registering the owner again is a harmless repeat and still answers true,
	 * so a registrar that runs twice in one request does not report a conflict.
	 *
	 * @param string $channel Channel slug ('email', 'sms', 'whatsapp').
	 * @param object $engine  The engine instance.
	 * @param string $owner   self::OWNER_FREE or self::OWNER_PRO.
	 * @return bool Whether $engine owns the channel after the call.
	 */
	public static function register( string $channel, $engine, string $owner ): bool {
		if ( ! is_object( $engine ) ) {
			return false;
		}

		if ( isset( self::$engines[ $channel ] ) ) {
			if ( self::$engines[ $channel ]['engine'] === $engine ) {
				return true;
			}

			doublescale_get_logger()->notice(
				/* translators: %s: channel name */
				sprintf( __( 'A second %s campaign engine was not registered; the first one keeps the channel.', 'doublescale' ), $channel ),
				array(
					'code'     => "{$channel}_engine_already_registered",
					'owner'    => self::$engines[ $channel ]['owner'],
					'kept'     => get_class( self::$engines[ $channel ]['engine'] ),
					'refused'  => get_class( $engine ),
					'refusing' => $owner,
				)
			);

			return false;
		}

		self::$engines[ $channel ] = array(
			'engine' => $engine,
			'owner'  => $owner,
		);

		return true;
	}

	/**
	 * The engine that owns a channel.
	 *
	 * @param string $channel Channel slug.
	 * @return object|null
	 */
	public static function get( string $channel ) {
		return isset( self::$engines[ $channel ] ) ? self::$engines[ $channel ]['engine'] : null;
	}

	/**
	 * Whether any engine owns a channel.
	 *
	 * @param string $channel Channel slug.
	 * @return bool
	 */
	public static function has( string $channel ): bool {
		return isset( self::$engines[ $channel ] );
	}

	/**
	 * Who shipped the engine that owns a channel.
	 *
	 * @param string $channel Channel slug.
	 * @return string|null self::OWNER_FREE, self::OWNER_PRO, or null when unowned.
	 */
	public static function owner( string $channel ) {
		return isset( self::$engines[ $channel ] ) ? self::$engines[ $channel ]['owner'] : null;
	}

	/**
	 * Whether $engine is the one that owns the channel.
	 *
	 * @param string $channel Channel slug.
	 * @param object $engine  Engine instance.
	 * @return bool
	 */
	public static function is_owner( string $channel, $engine ): bool {
		return null !== $engine && self::get( $channel ) === $engine;
	}

	/**
	 * Channels that currently have an engine.
	 *
	 * @return array<int, string>
	 */
	public static function channels(): array {
		return array_keys( self::$engines );
	}

	/**
	 * The registry key for a channel's receiver (webhook + click links).
	 *
	 * Kept apart from the channel's campaign engine: the receiver also serves
	 * messages sent from the contact dialog, and keeps working with Campaigns
	 * turned off.
	 *
	 * @param string $channel Channel slug.
	 * @return string
	 */
	public static function tracking_key( string $channel ): string {
		return 'tracking:' . $channel;
	}

	/**
	 * Whether free ships its own engine for a channel, so it can take the
	 * channel back on a request where Pro's engine is gone (Pro deactivated,
	 * or the emergency switch turned on).
	 *
	 * @param string $channel Channel slug.
	 * @return bool
	 */
	public static function has_fallback( string $channel ): bool {
		return in_array( $channel, self::FALLBACK_CHANNELS, true );
	}
}
