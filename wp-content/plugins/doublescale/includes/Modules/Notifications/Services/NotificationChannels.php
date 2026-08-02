<?php
/**
 * Notification Channels registry.
 *
 * Single source of truth for which delivery channels are AVAILABLE on this
 * install. Email is the free channel and is always available. Bell, browser,
 * and push are Pro channels — Pro opts them in via the
 * {@see 'doublescale_notification_allowed_channels'} filter.
 *
 * Every consumer (preferences storage, the notification service, and the REST
 * payloads) intersects its channel set with {@see self::allowed()} so a channel
 * that is not available is never stored, never fired, and never surfaced in the
 * settings UI — even if a stale stored preference still has it enabled.
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Services;

defined( 'ABSPATH' ) || exit;

/**
 * NotificationChannels class.
 */
class NotificationChannels {

	/**
	 * The free channel, available on every install.
	 *
	 * @var string
	 */
	const EMAIL = 'email';

	/**
	 * Pro-only channels.
	 *
	 * @var string[]
	 */
	const PRO_CHANNELS = array( 'bell', 'browser', 'push' );

	/**
	 * Canonical full channel order, used to keep payload keys deterministic.
	 *
	 * @var string[]
	 */
	const ALL = array( 'bell', 'email', 'browser', 'push' );

	/**
	 * Channels available on this install.
	 *
	 * Free ships only email. Pro adds bell/browser/push by hooking the filter
	 * (see Pro's Notifications module). The result is normalized against the
	 * canonical list so the order is stable and unknown values are dropped.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Ordered list of available channel keys.
	 */
	public static function allowed() {
		/**
		 * Filter the notification channels available on this install.
		 *
		 * Free defaults to email only. Pro returns the full set.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $channels Available channel keys.
		 */
		$allowed = (array) apply_filters( 'doublescale_notification_allowed_channels', array( self::EMAIL ) );

		// Normalize: keep canonical order, drop anything unknown/duplicated.
		$normalized = array();
		foreach ( self::ALL as $channel ) {
			if ( in_array( $channel, $allowed, true ) ) {
				$normalized[] = $channel;
			}
		}

		// Email is always available, even if a filter tried to remove it.
		if ( ! in_array( self::EMAIL, $normalized, true ) ) {
			array_unshift( $normalized, self::EMAIL );
		}

		return $normalized;
	}

	/**
	 * Whether a given channel is available on this install.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel key.
	 * @return bool
	 */
	public static function is_allowed( $channel ) {
		return in_array( $channel, self::allowed(), true );
	}
}
