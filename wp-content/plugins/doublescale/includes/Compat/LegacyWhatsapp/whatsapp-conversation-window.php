<?php
/**
 * Stand-in for free's former WhatsApp conversation window, served only next to
 * a Pro that predates its own engine. See {@see \DoubleScale\Compat\LegacyWhatsapp}.
 *
 * The window is always closed, so nothing is sent as a session message.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsappConversationWindow.
 */
final class WhatsappConversationWindow {

	/** Kept so callers reading the constants still find them. */
	const WINDOW_HOURS = 24;

	/** Contact meta key for Meta's conversation window. */
	const META_KEY = 'whatsapp_conversation_window';

	/**
	 * Always closed.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array
	 */
	public static function check( $contact_id = 0 ) {
		return array(
			'active'       => false,
			'expires_at'   => null,
			'minutes_left' => 0,
			'last_inbound' => null,
			'source'       => 'calculated',
			'origin_type'  => null,
		);
	}

	/**
	 * Any other window method an older Pro may call: nothing to do.
	 *
	 * @param string $name Method.
	 * @param array  $args Arguments.
	 * @return null
	 */
	public static function __callStatic( $name, $args ) {
		return null;
	}
}
