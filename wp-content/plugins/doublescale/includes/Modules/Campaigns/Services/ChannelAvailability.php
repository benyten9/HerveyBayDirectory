<?php
/**
 * Can this channel actually deliver a message right now?
 *
 * Campaign creation used to be gated on whether a class existed. That is the
 * wrong question: `WhatsappProcessing` lived in free and always existed, while
 * the thing that actually sends — a configured provider — lives in Pro and may
 * never have been connected. A gate built on the wrong question either blocks
 * a working site or, worse, lets a user build a campaign that is guaranteed to
 * fail at send with no explanation.
 *
 * So this asks the same question `send_via_provider()` asks: does a provider
 * resolve for the channel, and does it report itself configured? Gate and send
 * path agree by construction.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;

/**
 * ChannelAvailability helper.
 */
class ChannelAvailability {

	/**
	 * Whether a channel can deliver messages on this site.
	 *
	 * Email is always true: it sends through wp_mail() and needs no messaging
	 * provider. Every other channel needs Pro's registry, which may be absent
	 * entirely on a free-only site — hence the class guard rather than a bare
	 * reference.
	 *
	 * The result passes through the `doublescale_channel_sendable` filter so
	 * integration tests can decide the answer without real provider
	 * credentials, and so a site can override the check for a custom provider.
	 *
	 * @param string $channel Channel slug ('email', 'sms', 'whatsapp').
	 * @return bool
	 */
	public static function is_channel_sendable( string $channel ): bool {
		// Checked before the filter on purpose: no provider, and no plugin
		// filtering this answer, can make a channel send when nothing runs it.
		if ( ! self::has_engine( $channel ) ) {
			return false;
		}

		$sendable = self::resolve( $channel );

		/**
		 * Filters whether a campaign channel can currently send.
		 *
		 * @param bool   $sendable Whether the channel can send.
		 * @param string $channel  Channel slug.
		 */
		return (bool) apply_filters( 'doublescale_channel_sendable', $sendable, $channel );
	}

	/**
	 * Whether a campaign engine runs this channel on this site.
	 *
	 * SMS and WhatsApp need one from ChannelEngineRegistry: a provider can be
	 * connected while no engine runs the channel — a free that no longer ships
	 * the WhatsApp engine next to an older Pro. Email always runs on free's own
	 * engine; any other channel brings its own.
	 *
	 * @param string $channel Channel slug.
	 * @return bool
	 */
	public static function has_engine( string $channel ): bool {
		if ( ! in_array( $channel, array( CampaignChannel::STR_SMS, CampaignChannel::STR_WHATSAPP ), true ) ) {
			return true;
		}

		return ChannelEngineRegistry::has( $channel );
	}

	/**
	 * The error for a channel no engine runs, telling the user what to install.
	 *
	 * @param string $channel Channel slug ('sms' or 'whatsapp').
	 * @return \WP_Error
	 */
	public static function engine_unavailable_error( string $channel ): \WP_Error {
		if ( CampaignChannel::STR_SMS === $channel ) {
			return new \WP_Error(
				'pro_feature_required',
				__( 'Sms campaigns require Plugin Pro', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return new \WP_Error(
			'channel_engine_unavailable',
			sprintf(
				/* translators: %s: DoubleScale Pro version number, e.g. "1.3.0". */
				__( 'Sending WhatsApp campaigns needs DoubleScale Pro %s or newer. Update DoubleScale Pro and try again.', 'doublescale' ),
				ChannelEngineRegistry::PRO_ENGINE_VERSION
			),
			// 409, not 403: an update fixes it; it is not a licensing wall.
			array( 'status' => 409 )
		);
	}

	/**
	 * The unfiltered answer.
	 *
	 * @param string $channel Channel slug.
	 * @return bool
	 */
	private static function resolve( string $channel ): bool {
		if ( CampaignChannel::STR_EMAIL === $channel ) {
			return true;
		}

		$registry_class = '\DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry';

		// Free-only site: no messaging providers exist at all.
		if ( ! class_exists( $registry_class ) ) {
			return false;
		}

		try {
			$provider = $registry_class::instance()->get_provider( $channel );
		} catch ( \Throwable $e ) {
			// A registry that throws is a misconfigured site, not a sendable
			// one. Never let that surface as a fatal on the campaigns screen.
			doublescale_get_logger()->error(
				'Failed to resolve message provider while checking channel availability',
				array(
					'code'    => 'channel_availability_error',
					'channel' => $channel,
					'error'   => $e->getMessage(),
				)
			);

			return false;
		}

		if ( ! $provider ) {
			return false;
		}

		return method_exists( $provider, 'is_configured' ) && (bool) $provider->is_configured();
	}

	/**
	 * The campaign channels this site can actually send on.
	 *
	 * @return array<int, string> Channel slugs, email always first.
	 */
	public static function sendable_campaign_channels(): array {
		$channels = array( CampaignChannel::STR_EMAIL );

		foreach ( array( CampaignChannel::STR_SMS, CampaignChannel::STR_WHATSAPP ) as $channel ) {
			if ( self::is_channel_sendable( $channel ) ) {
				$channels[] = $channel;
			}
		}

		return $channels;
	}
}
