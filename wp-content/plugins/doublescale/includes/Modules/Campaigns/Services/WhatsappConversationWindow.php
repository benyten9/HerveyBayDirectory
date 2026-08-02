<?php
/**
 * WhatsApp Conversation Window Helper
 * Checks if 24-hour conversation window is active for a contact
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\MessageDirection;

/**
 * WhatsappConversationWindow class
 *
 * WhatsApp Business Api allows free-text "session messages" within 24 hours
 * of the last inbound message from a contact. Outside this window,
 * only pre-approved templates can be sent.
 *
 * DATA SOURCES (in priority order):
 * 1. Meta's expiration_timestamp from webhook (most accurate)
 * 2. Local calculation from last inbound message (+24 hours)
 *
 * TIMEZONE HANDLING:
 * - Meta provides expiration_timestamp as Unix timestamp (UTC)
 * - Local calculation uses UTC timestamps
 * - Display times are converted to WordPress timezone
 */
class WhatsappConversationWindow {

	/**
	 * Conversation window duration in hours (fallback calculation)
	 */
	const WINDOW_HOURS = 24;

	/**
	 * Contact meta key for storing Meta's conversation window data
	 */
	const META_KEY = 'whatsapp_conversation_window';

	/**
	 * Check if conversation window is active for a contact
	 *
	 * Uses Meta's expiration_timestamp if available (from webhook),
	 * otherwise falls back to local calculation from last inbound message.
	 *
	 * @param int $contact_id Contact ID
	 * @return array {
	 *     @type bool   $active        Whether window is active
	 *     @type string $expires_at    Window expiry time in WordPress timezone
	 *     @type int    $minutes_left  Minutes remaining (if active)
	 *     @type string $last_inbound  Last inbound message time in WordPress timezone
	 *     @type string $source        Data source: 'meta' or 'calculated'
	 *     @type string $origin_type   Conversation origin: 'user_initiated' or 'business_initiated'
	 * }
	 */
	public static function check( $contact_id ) {
		// First, try to use Meta's expiration data from contact meta
		$meta_result = self::check_from_meta( $contact_id );
		if ( null !== $meta_result ) {
			return $meta_result;
		}

		// Fall back to local calculation from last inbound message
		return self::check_from_inbound_messages( $contact_id );
	}

	/**
	 * Check conversation window using Meta's stored expiration data
	 *
	 * @param int $contact_id Contact ID
	 * @return array|null Result array or null if no Meta data available
	 */
	private static function check_from_meta( $contact_id ) {
		$meta = doublescale_get_contact_meta( $contact_id, self::META_KEY, true );

		if ( empty( $meta ) || empty( $meta['expiration_timestamp'] ) ) {
			return null;
		}

		$expiration_timestamp = (int) $meta['expiration_timestamp'];
		$now                  = time();
		$wp_timezone          = wp_timezone();

		// Check if the stored data is too old (older than 48 hours)
		// This prevents using stale data if webhooks stopped working
		$meta_updated_at = $meta['updated_at'] ?? 0;
		if ( $meta_updated_at && ( $now - $meta_updated_at ) > ( 48 * HOUR_IN_SECONDS ) ) {
			// Data is stale, fall back to local calculation
			return null;
		}

		// Get last inbound message for display
		$last_inbound         = self::get_last_inbound_message( $contact_id );
		$last_inbound_display = null;

		if ( $last_inbound ) {
			$utc_tz     = new \DateTimeZone( 'UTC' );
			$inbound_dt = new \DateTime( $last_inbound->created_at, $utc_tz );
			$inbound_dt->setTimezone( $wp_timezone );
			$last_inbound_display = $inbound_dt->format( 'Y-m-d H:i:s' );
		}

		// Convert expiry to WordPress timezone for display
		$expires_dt = new \DateTime( '@' . $expiration_timestamp );
		$expires_dt->setTimezone( $wp_timezone );
		$expires_display = $expires_dt->format( 'Y-m-d H:i:s' );

		// Check if window has expired
		if ( $now > $expiration_timestamp ) {
			return array(
				'active'       => false,
				'expires_at'   => $expires_display,
				'minutes_left' => 0,
				'last_inbound' => $last_inbound_display,
				'reason'       => 'window_expired',
				'source'       => 'meta',
				'origin_type'  => $meta['origin_type'] ?? 'unknown',
			);
		}

		// Calculate remaining time
		$minutes_left = (int) ceil( ( $expiration_timestamp - $now ) / 60 );

		return array(
			'active'       => true,
			'expires_at'   => $expires_display,
			'minutes_left' => $minutes_left,
			'last_inbound' => $last_inbound_display,
			'reason'       => null,
			'source'       => 'meta',
			'origin_type'  => $meta['origin_type'] ?? 'unknown',
		);
	}

	/**
	 * Check conversation window by calculating from last inbound message
	 *
	 * @param int $contact_id Contact ID
	 * @return array Result array
	 */
	private static function check_from_inbound_messages( $contact_id ) {
		$last_inbound = self::get_last_inbound_message( $contact_id );

		if ( ! $last_inbound ) {
			return array(
				'active'       => false,
				'expires_at'   => null,
				'minutes_left' => 0,
				'last_inbound' => null,
				'reason'       => 'no_inbound_messages',
				'source'       => 'calculated',
				'origin_type'  => null,
			);
		}

		// Get the created_at value
		// Eloquent returns this as a string in 'Y-m-d H:i:s' format
		// The value is stored in the database in UTC (MySQL TIMESTAMP behavior)
		$created_at_str = $last_inbound->created_at;

		// Parse as UTC since that's how Eloquent/MySQL stores it
		$utc_tz       = new \DateTimeZone( 'UTC' );
		$inbound_dt   = new \DateTime( $created_at_str, $utc_tz );
		$inbound_time = $inbound_dt->getTimestamp();

		// Calculate expiry: 24 hours after inbound message
		$expires_at = $inbound_time + ( self::WINDOW_HOURS * HOUR_IN_SECONDS );
		$now        = time();

		// For display: convert times to WordPress timezone
		$wp_timezone = wp_timezone();

		// Convert expiry to WordPress timezone for display
		$expires_dt = new \DateTime( '@' . $expires_at );
		$expires_dt->setTimezone( $wp_timezone );
		$expires_display = $expires_dt->format( 'Y-m-d H:i:s' );

		// Convert inbound time to WordPress timezone for display
		$inbound_dt->setTimezone( $wp_timezone );
		$inbound_display = $inbound_dt->format( 'Y-m-d H:i:s' );

		// Check if window has expired
		if ( $now > $expires_at ) {
			return array(
				'active'       => false,
				'expires_at'   => $expires_display,
				'minutes_left' => 0,
				'last_inbound' => $inbound_display,
				'reason'       => 'window_expired',
				'source'       => 'calculated',
				'origin_type'  => 'user_initiated', // Calculated from inbound = user initiated
			);
		}

		// Calculate remaining time
		$minutes_left = (int) ceil( ( $expires_at - $now ) / 60 );

		return array(
			'active'       => true,
			'expires_at'   => $expires_display,
			'minutes_left' => $minutes_left,
			'last_inbound' => $inbound_display,
			'reason'       => null,
			'source'       => 'calculated',
			'origin_type'  => 'user_initiated',
		);
	}

	/**
	 * Get the most recent inbound WhatsApp message for a contact
	 *
	 * @param int $contact_id Contact ID
	 * @return CommunicationTrackingModel|null
	 */
	private static function get_last_inbound_message( $contact_id ) {
		return CommunicationTrackingModel::where( 'contact_id', $contact_id )
			->where( 'mode', CommunicationTrackingModel::MODE_WHATSAPP )
			->where( 'direction', MessageDirection::INBOUND )
			->orderBy( 'created_at', 'desc' )
			->first();
	}

	/**
	 * Check if free-text messages are allowed
	 *
	 * @param int $contact_id Contact ID
	 * @return bool True if session messages allowed
	 */
	public static function is_active( $contact_id ) {
		$result = self::check( $contact_id );
		return $result['active'];
	}

	/**
	 * Update conversation window from Meta webhook data
	 *
	 * Called by the Meta WhatsApp provider when receiving webhook data
	 * with conversation information.
	 *
	 * @param int   $contact_id  Contact ID
	 * @param array $conversation Conversation data from Meta webhook
	 */
	public static function update_from_meta( $contact_id, array $conversation ) {
		if ( empty( $conversation['expiration_timestamp'] ) ) {
			return;
		}

		$expiration_data = array(
			'expiration_timestamp' => $conversation['expiration_timestamp'],
			'origin_type'          => $conversation['origin_type'] ?? 'unknown',
			'conversation_id'      => $conversation['id'] ?? null,
			'updated_at'           => time(),
		);

		doublescale_update_contact_meta( $contact_id, self::META_KEY, $expiration_data );
	}

	/**
	 * Clear stored conversation window data for a contact
	 *
	 * Useful when you want to force recalculation from inbound messages.
	 *
	 * @param int $contact_id Contact ID
	 */
	public static function clear_meta( $contact_id ) {
		doublescale_delete_contact_meta( $contact_id, self::META_KEY );
	}
}
