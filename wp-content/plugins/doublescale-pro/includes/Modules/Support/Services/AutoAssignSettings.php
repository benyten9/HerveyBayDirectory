<?php
/**
 * Auto assign settings (Support → Auto assign): new tickets go to the team
 * in turn. Stored in the support settings under `auto_assign`.
 *
 *   enabled      Off until the owner turns it on.
 *   members      Who takes part (user ids, in turn by id). Only people with
 *                helpdesk access are kept.
 *   skip_offline Skip who has no DoubleScale screen open (5 minutes).
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * AutoAssignSettings class.
 */
final class AutoAssignSettings {

	private const SETTINGS_KEY = 'auto_assign';

	/**
	 * Most people in the turn.
	 */
	public const MAX_MEMBERS = 100;

	/**
	 * @return array{enabled: bool, members: int[], skip_offline: bool}
	 */
	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'members'      => array(),
			'skip_offline' => false,
		);
	}

	/**
	 * @return array{enabled: bool, members: int[], skip_offline: bool}
	 */
	public static function get(): array {
		$support = Settings::get( 'support', array() );
		$raw     = is_array( $support ) && isset( $support[ self::SETTINGS_KEY ] ) && is_array( $support[ self::SETTINGS_KEY ] )
			? $support[ self::SETTINGS_KEY ]
			: array();
		$defaults = self::defaults();

		return array(
			'enabled'      => ! empty( $raw['enabled'] ),
			'members'      => self::ids( $raw['members'] ?? $defaults['members'] ),
			'skip_offline' => ! empty( $raw['skip_offline'] ),
		);
	}

	/**
	 * @param array<string, mixed> $input Changes.
	 * @return array{enabled: bool, members: int[], skip_offline: bool}
	 */
	public static function save( array $input ): array {
		$current = self::get();
		$clean   = array(
			'enabled'      => array_key_exists( 'enabled', $input ) ? rest_sanitize_boolean( $input['enabled'] ) : $current['enabled'],
			'members'      => array_key_exists( 'members', $input ) ? self::team( self::ids( $input['members'] ) ) : $current['members'],
			'skip_offline' => array_key_exists( 'skip_offline', $input ) ? rest_sanitize_boolean( $input['skip_offline'] ) : $current['skip_offline'],
		);

		$support = Settings::get( 'support', array() );
		$support = is_array( $support ) ? $support : array();
		$support[ self::SETTINGS_KEY ] = $clean;
		Settings::update( 'support', $support );

		return $clean;
	}

	/**
	 * @param mixed $raw Ids.
	 * @return int[] Positive, once each, sorted.
	 */
	private static function ids( $raw ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : array() ) ) ) );
		sort( $ids );

		return array_slice( $ids, 0, self::MAX_MEMBERS );
	}

	/**
	 * Only people who answer tickets.
	 *
	 * @param int[] $ids Ids.
	 * @return int[]
	 */
	private static function team( array $ids ): array {
		return array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return false !== get_userdata( $id ) && Permissions::has_support_access( $id );
				}
			)
		);
	}
}
