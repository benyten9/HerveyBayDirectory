<?php
/**
 * Whether the support team is here now, for the chat's "Online" sign.
 *
 * Someone who may answer tickets (free's Permissions::has_support_access())
 * opening wp-admin marks the team as seen; WordPress's heartbeat keeps
 * marking it while an admin tab stays open. Seen in the last five minutes =
 * online. Written at most once a minute, so busy admins cost no writes.
 *
 * @since 1.0.0
 * @package DoubleScale\HelpCenter\Assistant
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;

/**
 * TeamPresence class.
 */
final class TeamPresence {

	/**
	 * When the team was last seen (unix time).
	 */
	public const OPTION = 'doublescale_help_team_seen';

	/**
	 * When this team member was last seen (user meta, unix time).
	 */
	public const USER_META = 'doublescale_help_seen';

	/**
	 * Seen this recently = online.
	 */
	private const ONLINE_FOR = 5 * MINUTE_IN_SECONDS;

	/**
	 * Marks closer together than this are not written.
	 */
	private const EVERY = MINUTE_IN_SECONDS;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'mark' ) );
		add_filter( 'heartbeat_received', array( self::class, 'on_heartbeat' ), 10, 1 );
		// A new or removed photo shows in the chat now, not 30 seconds later.
		add_action( 'doublescale_profile_photo_changed', array( self::class, 'flush' ) );
	}

	/**
	 * @param mixed $response Heartbeat response.
	 * @return mixed
	 */
	public static function on_heartbeat( $response ) {
		self::mark();

		return $response;
	}

	/**
	 * The current user is here: mark the team as seen when they answer tickets.
	 *
	 * @return void
	 */
	public static function mark(): void {
		if ( ! is_user_logged_in() || ! class_exists( Permissions::class ) || ! Permissions::has_support_access() ) {
			return;
		}
		$user = get_current_user_id();
		if ( time() - (int) get_user_meta( $user, self::USER_META, true ) >= self::EVERY ) {
			update_user_meta( $user, self::USER_META, time() );
		}
		if ( time() - (int) get_option( self::OPTION, 0 ) < self::EVERY ) {
			return;
		}
		update_option( self::OPTION, time(), false );
	}

	/**
	 * @param int $user_id Team member.
	 * @return int|null When they were last in wp-admin (unix time), null if never.
	 */
	public static function seen( int $user_id ): ?int {
		$at = (int) get_user_meta( $user_id, self::USER_META, true );

		return $at > 0 ? $at : null;
	}

	/**
	 * How long the chat's faces and count are kept: they are on every page
	 * with the chat, and a cached page is older than this anyway.
	 */
	private const CACHE_FOR = 30;

	/**
	 * The transient holding faces() and online_count().
	 */
	private const CACHE_KEY = 'doublescale_help_team_faces';

	/**
	 * The faces on the chat's home page: the team members who answer tickets,
	 * those here now first (online: true), then whoever was here most
	 * recently (online: false). First name and photo only.
	 *
	 * @param int $limit Faces.
	 * @return array<int, array{name: string, avatar: string, online: bool}>
	 */
	public static function faces( int $limit = 3 ): array {
		return self::snapshot( $limit )['faces'];
	}

	/**
	 * How many people who answer tickets are here now (seen in the last five
	 * minutes), for the chat's "3 online now".
	 *
	 * @return int
	 */
	public static function online_count(): int {
		return self::snapshot( 3 )['online'];
	}

	/**
	 * Forget the kept faces and count (a photo changed, or a test moved the clock).
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Faces and count together, from at most two user queries, kept for 30
	 * seconds (a transient: WordPress keeps it in memory for the rest of the
	 * request too).
	 *
	 * @param int $limit Faces.
	 * @return array{faces: array<int, array{name: string, avatar: string, online: bool}>, online: int}
	 */
	private static function snapshot( int $limit ): array {
		$limit = max( 1, $limit );
		$kept  = get_transient( self::CACHE_KEY );
		if ( is_array( $kept ) && isset( $kept[ $limit ]['faces'], $kept[ $limit ]['online'] ) ) {
			return $kept[ $limit ];
		}

		$cutoff = time() - self::ONLINE_FOR;
		// Here now: everyone seen since the cutoff, most recent first. Full
		// user objects, so their roles and names come from one primed cache.
		$here = self::support_people(
			get_users(
				array(
					'meta_key'     => self::USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'   => $cutoff, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_compare' => '>=',
					'meta_type'    => 'NUMERIC',
					'orderby'      => 'meta_value_num',
					'order'        => 'DESC',
					'number'       => 200,
				)
			)
		);
		$faces = array();
		foreach ( array_slice( $here, 0, $limit ) as $user ) {
			$faces[] = self::face( $user, true );
		}
		// Room left: whoever was here last, so the chat still shows the team.
		if ( count( $faces ) < $limit ) {
			$before = self::support_people(
				get_users(
					array(
						'meta_key' => self::USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'orderby'  => 'meta_value_num',
						'order'    => 'DESC',
						'number'   => $limit * 3,
						'exclude'  => array_map(
							static function ( $user ) {
								return (int) $user->ID;
							},
							$here
						),
					)
				)
			);
			foreach ( array_slice( $before, 0, $limit - count( $faces ) ) as $user ) {
				$faces[] = self::face( $user, false );
			}
		}

		$snapshot       = array(
			'faces'  => $faces,
			'online' => count( $here ),
		);
		$kept           = is_array( $kept ) ? $kept : array();
		$kept[ $limit ] = $snapshot;
		set_transient( self::CACHE_KEY, $kept, self::CACHE_FOR );

		return $snapshot;
	}

	/**
	 * Only people whose role still answers tickets.
	 *
	 * @param array<int, \WP_User> $users Users.
	 * @return array<int, \WP_User>
	 */
	private static function support_people( array $users ): array {
		if ( ! class_exists( Permissions::class ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$users,
				static function ( $user ) {
					return $user instanceof \WP_User && Permissions::has_support_access( (int) $user->ID );
				}
			)
		);
	}

	/**
	 * @param \WP_User $user   Team member.
	 * @param bool     $online Here now.
	 * @return array{name: string, avatar: string, online: bool}
	 */
	private static function face( \WP_User $user, bool $online ): array {
		$first = trim( (string) $user->first_name );
		$parts = preg_split( '/\s+/u', trim( (string) $user->display_name ) );

		return array(
			'name'   => '' !== $first ? $first : (string) ( $parts[0] ?? '' ),
			'avatar' => self::photo( (int) $user->ID ),
			'online' => $online,
		);
	}

	/**
	 * A team member's photo for the chat: the one they uploaded to
	 * DoubleScale, else '' (the chat shows their first letter). Never a
	 * Gravatar: its address carries a hash of the person's email, so every
	 * public page would tell the photo service who answers tickets and which
	 * visitor's browser was looking.
	 *
	 * @param int $user_id Team member.
	 * @return string
	 */
	public static function photo( int $user_id ): string {
		$class = '\\DoubleScale\\Modules\\Support\\Services\\ProfilePhoto';
		if ( $user_id < 1 || ! class_exists( $class ) || ! method_exists( $class, 'url' ) ) {
			return '';
		}

		return (string) $class::url( $user_id );
	}

	/**
	 * This person had a DoubleScale screen open in the last 5 minutes.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_online( int $user_id ): bool {
		$at = self::seen( $user_id );

		return null !== $at && time() - $at <= self::ONLINE_FOR;
	}

	/**
	 * @return bool Someone who answers tickets was here in the last five minutes.
	 */
	public static function online(): bool {
		return time() - (int) get_option( self::OPTION, 0 ) <= self::ONLINE_FOR;
	}
}
