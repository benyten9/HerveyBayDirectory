<?php
/**
 * API keys for the DoubleScale MCP endpoint.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and verifies MCP API keys.
 *
 * Keys exist alongside WordPress Application Passwords rather than replacing
 * them: application passwords are unavailable unless the site is served over
 * HTTPS (`wp_is_application_passwords_available()`), which rules them out on
 * local and staging installs. A key is always bound to a WordPress user, so
 * every capability, module, and ownership check downstream behaves exactly as
 * it would for that user logging in.
 *
 * Only a hash is stored. The plaintext key is shown once, at creation.
 */
final class ApiKeyStore {

	public const OPTION = 'doublescale_mcp_api_keys';

	/**
	 * Prefix makes a leaked key greppable in logs and recognisable to the user.
	 */
	private const KEY_PREFIX = 'dsmcp_';

	/**
	 * Generate a key, persist its hash, and return the plaintext ONCE.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label   Human-readable name.
	 * @param int    $user_id WordPress user the key acts as.
	 * @return array{id: string, label: string, key: string, created_at: string, user_id: int}
	 */
	public static function create( string $label, int $user_id ): array {
		$secret = self::KEY_PREFIX . bin2hex( random_bytes( 24 ) );
		$id     = bin2hex( random_bytes( 8 ) );

		$keys = self::all();

		$keys[ $id ] = array(
			'id'         => $id,
			'label'      => '' !== trim( $label ) ? sanitize_text_field( $label ) : __( 'API key', 'doublescale' ),
			'hash'       => self::hash( $secret ),
			'user_id'    => $user_id,
			'created_at' => gmdate( 'c' ),
			'last_used'  => '',
		);

		update_option( self::OPTION, $keys, false );

		return array(
			'id'         => $id,
			'label'      => $keys[ $id ]['label'],
			'key'        => $secret,
			'created_at' => $keys[ $id ]['created_at'],
			'user_id'    => $user_id,
		);
	}

	/**
	 * Delete a key by id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Key id.
	 * @return bool
	 */
	/**
	 * The user a stored key acts as.
	 *
	 * Looked up by key id, never by the secret: callers that already hold the
	 * id (the settings screen) should not have to handle the plaintext to find
	 * out who a key belongs to.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Key id.
	 * @return int User id, or 0 when the key is unknown.
	 */
	public static function user_for( string $id ): int {
		$keys = self::all();

		return isset( $keys[ $id ] ) ? (int) ( $keys[ $id ]['user_id'] ?? 0 ) : 0;
	}

	/**
	 * Delete a key by id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Key id.
	 * @return bool
	 */
	public static function delete( string $id ): bool {
		$keys = self::all();
		if ( ! isset( $keys[ $id ] ) ) {
			return false;
		}

		unset( $keys[ $id ] );
		update_option( self::OPTION, $keys, false );

		return true;
	}

	/**
	 * Delete a key only if it belongs to the given user.
	 *
	 * Returns false both when the key is missing and when it belongs to someone
	 * else, deliberately: distinguishing the two would confirm to a caller that
	 * a key id exists on the site, which is the one fact they should not learn
	 * from a failed revoke.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id      Key id.
	 * @param int    $user_id Required owner.
	 * @return bool
	 */
	public static function delete_own( string $id, int $user_id ): bool {
		if ( self::user_for( $id ) !== $user_id || $user_id <= 0 ) {
			return false;
		}

		return self::delete( $id );
	}

	/**
	 * Resolve a presented key to its user id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $presented Plaintext key from the request.
	 * @return int WordPress user id, or 0 when no key matches.
	 */
	public static function resolve_user( string $presented ): int {
		$presented = trim( $presented );
		if ( '' === $presented ) {
			return 0;
		}

		$candidate = self::hash( $presented );

		foreach ( self::all() as $id => $record ) {
			$stored = (string) ( $record['hash'] ?? '' );
			if ( '' === $stored ) {
				continue;
			}

			// Timing-safe: a plain === leaks how much of the hash matched.
			if ( hash_equals( $stored, $candidate ) ) {
				self::touch( $id );
				return (int) ( $record['user_id'] ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Stored keys, hashes stripped, for display.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_display(): array {
		return self::build_display_list( null );
	}

	/**
	 * Only the keys belonging to one user.
	 *
	 * A non-administrator managing their own key must never be handed the whole
	 * site's key inventory — the labels and usernames alone map out who has
	 * agent access, and the list is what the revoke button acts on.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id Owner to filter by.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_user( int $user_id ): array {
		return self::build_display_list( $user_id );
	}

	/**
	 * Shared display shaper.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $only_user Owner filter, or null for every key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_display_list( ?int $only_user ): array {
		$out = array();

		foreach ( self::all() as $record ) {
			if ( null !== $only_user && (int) ( $record['user_id'] ?? 0 ) !== $only_user ) {
				continue;
			}

			$user = get_userdata( (int) ( $record['user_id'] ?? 0 ) );

			// A key can outlive the user it was issued for; the settings screen
			// must still render so the orphaned key can be revoked.
			$login = ( $user && ! empty( $user->user_login ) )
				? (string) $user->user_login
				: __( '(deleted user)', 'doublescale' );

			$out[] = array(
				'id'         => (string) ( $record['id'] ?? '' ),
				'label'      => (string) ( $record['label'] ?? '' ),
				'user_id'    => (int) ( $record['user_id'] ?? 0 ),
				'user_login' => $login,
				'created_at' => (string) ( $record['created_at'] ?? '' ),
				'last_used'  => (string) ( $record['last_used'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * All stored key records.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all(): array {
		$keys = get_option( self::OPTION, array() );
		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Record that a key was used, so stale keys are identifiable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Key id.
	 * @return void
	 */
	private static function touch( string $id ): void {
		$keys = self::all();
		if ( ! isset( $keys[ $id ] ) ) {
			return;
		}

		$keys[ $id ]['last_used'] = gmdate( 'c' );
		update_option( self::OPTION, $keys, false );
	}

	/**
	 * Hash a key for storage and comparison.
	 *
	 * SHA-256 rather than password_hash(): these are 192-bit random secrets,
	 * not user-chosen passwords, so there is nothing to brute-force and the
	 * lookup must stay cheap enough to run on every request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $secret Plaintext key.
	 * @return string
	 */
	private static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}
}
