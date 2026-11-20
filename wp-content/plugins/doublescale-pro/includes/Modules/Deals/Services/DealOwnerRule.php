<?php
/**
 * Who may own a deal.
 *
 * @package DoubleScale\Pro\Modules\Deals
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use WP_Error;

/**
 * Only someone who works deals owns one: the sales roles (Sales Rep and
 * Manager), the CRM Agent and Manager, and site administrators. Project roles
 * read deals only to link them to projects; module roles never open them.
 */
final class DealOwnerRule {

	/**
	 * Why this user cannot own a deal, or null when they can.
	 *
	 * @param int $user_id Wanted owner.
	 * @return WP_Error|null
	 */
	public static function error( int $user_id ): ?WP_Error {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', 'The specified owner does not exist.', array( 'status' => 400 ) );
		}

		// No name: the account may be a customer's.
		if ( ! user_can( $user, 'doublescale_access' ) && ! user_can( $user, 'manage_options' ) ) {
			return new WP_Error(
				'doublescale_deal_owner_not_on_team',
				__( 'This person is not on your team. Deals can only go to team members.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::works_deals( $user_id ) ) {
			return new WP_Error(
				'doublescale_deal_owner_no_deal_access',
				sprintf(
					/* translators: %s: person's name */
					__( '%s does not work on deals. Pick someone from the sales team, or give them a Sales role in Settings → Team.', 'doublescale' ),
					$user->display_name
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function works_deals( int $user_id ): bool {
		return PermissionsCompat::has_agent_access( $user_id ) || Permissions::has_sales_manager_access( $user_id );
	}

	/**
	 * People who may own a deal, by name.
	 *
	 * @param string $search  Name, login or email (2+ characters), or ''.
	 * @param int[]  $include Only these ids (resolving a picked value).
	 * @return \WP_User[]
	 */
	public static function candidates( string $search = '', array $include = array() ): array {
		$args = array(
			'role__in' => array_values(
				array_filter(
					array(
						UserRoles::CRM_MANAGER,
						PermissionsCompat::crm_agent_role(),
						UserRoles::SALES_MANAGER,
						UserRoles::SALES_REP,
						UserRoles::ADMINISTRATOR,
					)
				)
			),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'number'   => 500,
		);

		$include = array_values( array_filter( array_map( 'intval', $include ), static fn( $id ) => $id > 0 ) );
		if ( ! empty( $include ) ) {
			$args['include'] = $include;
		}

		$search = trim( $search );
		if ( strlen( $search ) >= 2 ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		return array_values( array_filter( get_users( $args ), static fn( $user ) => null === self::error( (int) $user->ID ) ) );
	}
}
