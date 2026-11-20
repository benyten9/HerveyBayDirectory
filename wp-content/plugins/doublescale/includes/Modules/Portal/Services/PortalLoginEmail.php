<?php
/**
 * A client's portal login follows the email the team gives their contact.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * The portal finds a client by their login's email ({@see PortalIdentity}),
 * so a contact whose email the team changed lost its client: they logged in
 * to an empty portal. Their own login now takes the new address (WordPress
 * tells the old one), keeping their password and their data.
 *
 * Left alone: a team member's account, an address another account already
 * has, and a login another contact still uses.
 */
final class PortalLoginEmail {

	/**
	 * @return void
	 */
	public static function register(): void {
		ContactModel::updated(
			static function ( $contact ) {
				self::follow( $contact );
			}
		);
	}

	/**
	 * Move the client's login to the contact's new email.
	 *
	 * @param ContactModel $contact Contact just saved.
	 * @return void
	 */
	public static function follow( $contact ): void {
		if ( ! $contact instanceof ContactModel || ! array_key_exists( 'email', $contact->getChanges() ) ) {
			return;
		}
		$old = strtolower( trim( (string) $contact->getOriginal( 'email' ) ) );
		$new = strtolower( trim( (string) $contact->email ) );
		if ( '' === $old || $old === $new || ! is_email( $new ) ) {
			return;
		}

		$user = get_user_by( 'email', $old );
		if ( ! $user instanceof \WP_User || ! PortalInvite::is_client_login( $user ) || get_user_by( 'email', $new ) ) {
			return;
		}
		// Linked to another contact, or the old address is still another
		// contact's: the login is theirs.
		$linked = (int) get_user_meta( $user->ID, PortalUserProvisioner::META_CONTACT_ID, true );
		if ( ( $linked > 0 && $linked !== (int) $contact->id ) || ContactModel::where( 'email', $old )->where( 'id', '!=', (int) $contact->id )->exists() ) {
			return;
		}

		$updated = wp_update_user(
			array(
				'ID'         => (int) $user->ID,
				'user_email' => $new,
			)
		);
		if ( ! is_wp_error( $updated ) ) {
			update_user_meta( $user->ID, PortalUserProvisioner::META_CONTACT_ID, (int) $contact->id );
			// The team gave the client this address: the login stays theirs.
			PortalEmailConfirmation::confirm( (int) $user->ID );
		}
	}
}
