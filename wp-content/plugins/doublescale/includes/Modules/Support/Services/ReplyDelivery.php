<?php
/**
 * Where a team reply got to, for the ticket screen (like WhatsApp's ticks).
 *
 * Each step is a time on the reply's activity data, written the first time
 * it happens and never again:
 *
 *   chat_sent_at        it went to the customer's live chat (DoubleScale Pro)
 *   chat_delivered_at   the customer's chat received it (Pro)
 *   client_seen_at      the customer had it on screen ({@see ClientSeen}),
 *   client_seen_via     … in the chat or the portal
 *   email_held_until    in a live chat: emailed at this time if not read (Pro)
 *   emailed_at          the reply email was sent
 *   email_failed_at     it could not be sent (email_failed: why)
 *   email_opened_at     the reply email was opened (an image in it loaded)
 *
 * An open is learned from a small image in the email. Mail apps that load
 * images ahead (Apple Mail Privacy Protection) can report one the customer
 * never made, and apps that block images report none: "opened" is a hint.
 *
 * Every write is one UPDATE that sets the key only while it is missing, so
 * two steps landing at the same moment (delivered and seen) never undo each
 * other.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Portal\Services\ClientSeen;

/**
 * ReplyDelivery.
 */
final class ReplyDelivery {

	public const CHAT_SENT      = 'chat_sent_at';
	public const CHAT_DELIVERED = 'chat_delivered_at';
	public const SEEN_VIA       = 'client_seen_via';
	public const EMAIL_HELD     = 'email_held_until';
	public const EMAILED        = 'emailed_at';
	public const EMAIL_FAILED   = 'email_failed_at';
	public const FAILED_WHY     = 'email_failed';
	public const EMAIL_OPENED   = 'email_opened_at';

	/** Query flag of the open image. */
	private const PIXEL_FLAG = 'reply_open';

	/** A 1x1 transparent GIF. */
	private const PIXEL = 'R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs=';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'serve_pixel' ), 0 );
	}

	/**
	 * Write $key on the reply once (only while it is missing).
	 *
	 * @param int    $activity_id The reply.
	 * @param string $key         One of the constants.
	 * @param string $value       Default: now, UTC like the activity's own times.
	 * @return bool Whether this call wrote it.
	 */
	public static function stamp( int $activity_id, string $key, string $value = '' ): bool {
		if ( $activity_id <= 0 || ! preg_match( '/^[a-z_]+$/', $key ) ) {
			return false;
		}
		global $wpdb;
		$value = '' !== $value ? $value : current_time( 'mysql', true );
		$path  = '$.' . $key;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}doublescale_activities
				SET data = JSON_SET( IF( data IS NOT NULL AND JSON_VALID( data ), data, '{}' ), %s, %s )
				WHERE id = %d AND ( data IS NULL OR NOT JSON_VALID( data ) OR JSON_EXTRACT( data, %s ) IS NULL )",
				$path,
				$value,
				$activity_id,
				$path
			)
		);

		return (int) $changed > 0;
	}

	/**
	 * Write several keys at once, each only while missing.
	 *
	 * @param int                  $activity_id The reply.
	 * @param array<string,string> $values      Key => value ('' = now).
	 * @return void
	 */
	public static function stamp_all( int $activity_id, array $values ): void {
		foreach ( $values as $key => $value ) {
			self::stamp( $activity_id, (string) $key, (string) $value );
		}
	}

	/**
	 * Remove a key (a held email that went out, a failure that was retried).
	 *
	 * @param int    $activity_id The reply.
	 * @param string $key         Key.
	 * @return void
	 */
	public static function clear( int $activity_id, string $key ): void {
		if ( $activity_id <= 0 || ! preg_match( '/^[a-z_]+$/', $key ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}doublescale_activities SET data = JSON_REMOVE( data, %s ) WHERE id = %d AND data IS NOT NULL AND JSON_VALID( data ) AND JSON_EXTRACT( data, %s ) IS NOT NULL",
				'$.' . $key,
				$activity_id,
				'$.' . $key
			)
		);
	}

	/**
	 * The image that tells an open, for the reply email.
	 *
	 * @param int $activity_id The reply.
	 * @return string An <img> tag.
	 */
	public static function pixel_tag( int $activity_id ): string {
		$url = add_query_arg(
			array(
				'doublescale' => self::PIXEL_FLAG,
				'r'           => $activity_id,
				'k'           => self::key( $activity_id ),
			),
			home_url( '/' )
		);

		return '<img src="' . esc_url( $url ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';
	}

	/**
	 * The open image: mark the reply opened (the first time, not by the team)
	 * and answer with the image.
	 *
	 * @return void
	 */
	public static function serve_pixel(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public image in an email; the reply id is signed (key()).
		if ( ! isset( $_GET['doublescale'], $_GET['r'], $_GET['k'] ) || self::PIXEL_FLAG !== $_GET['doublescale'] ) {
			return;
		}
		$id  = absint( wp_unslash( $_GET['r'] ) );
		$key = sanitize_text_field( wp_unslash( $_GET['k'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $id > 0 && hash_equals( self::key( $id ), $key ) && ! ClientSeen::is_team_viewer() ) {
			self::stamp( $id, self::EMAIL_OPENED );
		}

		nocache_headers();
		header( 'Content-Type: image/gif' );
		echo base64_decode( self::PIXEL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixed image.
		exit;
	}

	/**
	 * The signature that makes a reply's open image unguessable.
	 *
	 * @param int $activity_id The reply.
	 * @return string
	 */
	public static function key( int $activity_id ): string {
		return substr( wp_hash( 'reply_open|' . $activity_id ), 0, 20 );
	}
}
