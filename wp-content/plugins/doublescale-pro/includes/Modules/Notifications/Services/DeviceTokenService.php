<?php
/**
 * Device Token Service
 *
 * Manages FCM device tokens stored in wp_usermeta.
 * Each user can have multiple device tokens (one per device).
 *
 * @since 2.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications\Services;

/**
 * DeviceTokenService class
 */
class DeviceTokenService {

	/**
	 * User meta key for storing FCM tokens.
	 *
	 * @var string
	 */
	const META_KEY = '_doublescale_fcm_tokens';

	/**
	 * Maximum tokens per user to prevent unbounded growth.
	 *
	 * @var int
	 */
	const MAX_TOKENS_PER_USER = 10;

	/**
	 * Get all device tokens for a user.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Array of token entries [ ['token' => ..., 'platform' => ..., 'registered_at' => ...], ... ]
	 */
	public static function get_tokens( $user_id ) {
		$tokens = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		return $tokens;
	}

	/**
	 * Register a device token for a user.
	 *
	 * Upserts: if the token already exists for this user, updates the timestamp.
	 * Also removes the same token from any other user (device switched accounts).
	 * Enforces a per-user token cap.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $token    FCM device token.
	 * @param string $platform Device platform ('android' or 'ios').
	 * @return bool True on success.
	 */
	public static function register( $user_id, $token, $platform = 'android' ) {
		if ( empty( $token ) || ! $user_id ) {
			return false;
		}

		$platform = in_array( $platform, array( 'android', 'ios' ), true ) ? $platform : 'android';

		// Remove this token from any other user first (device switched accounts).
		self::remove_token_from_other_users( $user_id, $token );

		$tokens = self::get_tokens( $user_id );
		$now    = current_time( 'mysql', true );
		$found  = false;

		foreach ( $tokens as &$entry ) {
			if ( $entry['token'] === $token ) {
				$entry['registered_at'] = $now;
				$entry['platform']      = $platform;
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			$tokens[] = array(
				'token'         => $token,
				'platform'      => $platform,
				'registered_at' => $now,
			);
		}

		// Enforce token cap — remove oldest entries if over limit.
		if ( count( $tokens ) > self::MAX_TOKENS_PER_USER ) {
			usort( $tokens, function ( $a, $b ) {
				return strcmp( $a['registered_at'] ?? '', $b['registered_at'] ?? '' );
			} );
			$tokens = array_slice( $tokens, -self::MAX_TOKENS_PER_USER );
		}

		update_user_meta( $user_id, self::META_KEY, $tokens );

		return true;
	}

	/**
	 * Unregister a specific token for a user (called on logout).
	 *
	 * @since 2.0.0
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $token   FCM device token.
	 * @return bool True if token was found and removed.
	 */
	public static function unregister( $user_id, $token ) {
		if ( empty( $token ) || ! $user_id ) {
			return false;
		}

		$tokens   = self::get_tokens( $user_id );
		$original = count( $tokens );

		$tokens = array_values( array_filter( $tokens, function ( $entry ) use ( $token ) {
			return $entry['token'] !== $token;
		} ) );

		if ( count( $tokens ) < $original ) {
			if ( empty( $tokens ) ) {
				delete_user_meta( $user_id, self::META_KEY );
			} else {
				update_user_meta( $user_id, self::META_KEY, $tokens );
			}
			return true;
		}

		return false;
	}

	/**
	 * Unregister all tokens for a user (account deletion).
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function unregister_all( $user_id ) {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Remove a specific token from ALL users.
	 *
	 * Called when FCM returns UNREGISTERED (app uninstalled, token expired).
	 *
	 * @since 2.0.0
	 *
	 * @param string $token FCM device token.
	 */
	public static function remove_stale_token( $token ) {
		if ( empty( $token ) ) {
			return;
		}

		$users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => self::META_KEY,
					'value'   => $token,
					'compare' => 'LIKE',
				),
			),
			'fields' => 'ID',
		) );

		foreach ( $users as $uid ) {
			self::unregister( $uid, $token );
		}
	}

	/**
	 * Remove a token from all users except the specified one.
	 *
	 * Handles the case where a device switches accounts.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $exclude_user_id User ID to keep the token for.
	 * @param string $token           FCM device token.
	 */
	private static function remove_token_from_other_users( $exclude_user_id, $token ) {
		$users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => self::META_KEY,
					'value'   => $token,
					'compare' => 'LIKE',
				),
			),
			'fields'  => 'ID',
			'exclude' => array( $exclude_user_id ),
		) );

		foreach ( $users as $uid ) {
			self::unregister( $uid, $token );
		}
	}
}
