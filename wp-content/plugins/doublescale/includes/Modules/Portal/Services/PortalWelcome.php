<?php
/**
 * The client's first visit to the portal: a short welcome, once, offering to
 * fill in the details missing from their profile when they may edit them.
 *
 * Accounts made before the welcome existed are not new: the date it first
 * ran is kept, and only accounts registered since then are welcomed.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_User;

/**
 * PortalWelcome.
 */
final class PortalWelcome {

	/** When the welcome started (Unix time). */
	public const SINCE_OPTION = 'doublescale_portal_welcome_since';

	/** User meta: when the client closed their welcome. */
	public const SEEN_META = 'doublescale_portal_welcomed';

	/** Profile details the welcome offers to fill in. */
	private const DETAILS = array( 'first_name', 'last_name', 'phone' );

	/**
	 * Keep the day the welcome started (once).
	 *
	 * @return int
	 */
	public static function since(): int {
		$since = (int) get_option( self::SINCE_OPTION, 0 );
		if ( $since <= 0 ) {
			$since = time();
			add_option( self::SINCE_OPTION, $since );
		}
		return $since;
	}

	/**
	 * What the portal shows this client on arrival.
	 *
	 * @param WP_User           $user    Signed-in user.
	 * @param ContactModel|null $contact Their contact.
	 * @return array{show:bool, first_name:string, missing:string[], can_edit:bool}
	 */
	public static function for_user( WP_User $user, ?ContactModel $contact ): array {
		$registered = strtotime( (string) $user->user_registered . ' UTC' );
		$show       = $contact
			&& ! get_user_meta( $user->ID, self::SEEN_META, true )
			&& false !== $registered
			&& $registered >= self::since();

		$missing = array();
		if ( $contact ) {
			foreach ( self::DETAILS as $field ) {
				if ( '' === trim( (string) $contact->{$field} ) ) {
					$missing[] = $field;
				}
			}
		}

		return array(
			'show'       => (bool) $show,
			// Their own name only: an account name is no greeting.
			'first_name' => $contact ? trim( (string) $contact->first_name ) : '',
			'missing'    => $missing,
			'can_edit'   => $contact && PortalAccess::allows( 'profile', $contact ) && PortalAccess::allows( 'profile_edit', $contact ),
		);
	}

	/**
	 * The client closed their welcome.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function mark_seen( int $user_id ): void {
		update_user_meta( $user_id, self::SEEN_META, time() );
	}
}
