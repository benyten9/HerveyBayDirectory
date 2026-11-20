<?php
/**
 * Keep an older DoubleScale Pro working after WhatsApp sending moved into Pro.
 *
 * DoubleScale no longer ships its own WhatsApp engine: DoubleScale Pro 1.3.0+
 * runs WhatsApp campaigns, automations and Meta's webhook itself. A Pro released
 * before that (1.2.34 and older) still calls free's engine classes by name, with
 * no class_exists() check, from its automation step, automated campaigns, Meta
 * tier guard, inbox and template routes — so a missing class would be a fatal
 * error on a webhook, a cron run or a click.
 *
 * Next to such a Pro, this answers those names with stand-ins that send
 * nothing: WhatsApp is off on the site, every call returns quietly, and
 * {@see ProMessagingEngineCheck} asks the admin to update Pro. With a current
 * Pro, or no Pro, none of it loads.
 *
 * - Campaign\WhatsappProcessing: sends fail with "update Pro", recorded on the
 *   message, so an automation step or campaign reports the reason.
 * - Services\WhatsappConversationWindow: the window is always closed.
 * - Services\WhatsappAccountGuard: the do-nothing guard free already ships.
 * - Services\MetaTemplateFetcher / MetaTemplateSaver: throw the "update Pro"
 *   message; every older-Pro caller catches it and shows it.
 * - Tracking\Whatsapp: links in WhatsApp messages already sent keep redirecting;
 *   Meta's webhook is acknowledged and ignored, so Meta keeps the URL healthy.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Compat;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Services\ChannelEngineRegistry;

/**
 * LegacyWhatsapp.
 */
final class LegacyWhatsapp {

	/** Old engine class names an older Pro calls, and the stand-in that answers each. */
	const STANDINS = array(
		'DoubleScale\\Modules\\Campaigns\\Campaign\\WhatsappProcessing'         => 'whatsapp-processing.php',
		'DoubleScale\\Modules\\Campaigns\\Services\\WhatsappConversationWindow' => 'whatsapp-conversation-window.php',
		'DoubleScale\\Modules\\Tracking\\Whatsapp'                              => 'whatsapp-tracking.php',
		'DoubleScale\\Modules\\Campaigns\\Services\\MetaTemplateFetcher'        => 'meta-templates.php',
		'DoubleScale\\Modules\\Campaigns\\Services\\MetaTemplateSaver'          => 'meta-templates.php',
	);

	/** Answered by an alias of free's do-nothing guard. */
	const GUARD = 'DoubleScale\\Modules\\Campaigns\\Services\\WhatsappAccountGuard';

	/**
	 * Register the autoloader and the link receiver once.
	 *
	 * @return void
	 */
	public static function register() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		spl_autoload_register( array( self::class, 'load' ) );

		// Free's tracking receivers are registered at 30 on this hook; the
		// stand-in takes the same slot free's own receiver used to.
		add_action( 'doublescale_modules_booted', array( self::class, 'register_receiver' ), 30 );
	}

	/**
	 * Serve a stand-in for an old engine class, only next to an older Pro.
	 *
	 * @param string $class Requested class.
	 * @return void
	 */
	public static function load( $class ) {
		if ( ! isset( self::STANDINS[ $class ] ) && self::GUARD !== $class ) {
			return;
		}

		if ( ! self::active() ) {
			return;
		}

		if ( self::GUARD === $class ) {
			class_alias( \DoubleScale\Modules\Campaigns\Services\NoWhatsappAccountGuard::class, self::GUARD );
			return;
		}

		require_once __DIR__ . '/LegacyWhatsapp/' . self::STANDINS[ $class ];
	}

	/**
	 * Whether the active Pro predates its own WhatsApp engine.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( ProMessagingEngineCheck::class ) && ProMessagingEngineCheck::pro_needs_update();
	}

	/**
	 * Take the WhatsApp receiver slot with the stand-in, so links in messages
	 * already sent keep working and Meta's webhook gets an answer.
	 *
	 * @return void
	 */
	public static function register_receiver() {
		if ( ! self::active() || ! class_exists( ChannelEngineRegistry::class ) ) {
			return;
		}

		$key = ChannelEngineRegistry::tracking_key( CampaignChannel::STR_WHATSAPP );
		if ( ChannelEngineRegistry::has( $key ) ) {
			return;
		}

		ChannelEngineRegistry::register( $key, \DoubleScale\Modules\Tracking\Whatsapp::instance(), ChannelEngineRegistry::OWNER_FREE );
	}

	/**
	 * Why a send did not happen, for logs and the message's error.
	 *
	 * @return string
	 */
	public static function message() {
		return sprintf(
			/* translators: %s: DoubleScale Pro version, e.g. "1.3.0". */
			__( 'WhatsApp is turned off: update DoubleScale Pro to %s or newer to send WhatsApp messages.', 'doublescale' ),
			ChannelEngineRegistry::PRO_ENGINE_VERSION
		);
	}

	/**
	 * The same reason as an error.
	 *
	 * @return \WP_Error
	 */
	public static function error() {
		return new \WP_Error( 'whatsapp_needs_pro_update', self::message(), array( 'status' => 409 ) );
	}
}
