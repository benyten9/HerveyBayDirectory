<?php
/**
 * Shared identity + ownership gate for every Client Portal endpoint.
 *
 * Centralises the rule the Support portal controller implements inline so that
 * Portal, Booking, and (later) the gated Sales documents controller all enforce
 * the same contract:
 *
 *   1. Must be logged in           → 401 (handled by {@see permission_check()}).
 *   2. Resolve contact by lowercased email; missing contact is NOT an error —
 *      callers render an empty state (return [] / 200).
 *   3. Ownership is `row.contact_id === contact.id`; a mismatch must surface as
 *      404 (not 403) so an attacker can't tell "exists but not yours" from
 *      "doesn't exist".
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_Error;

/**
 * PortalIdentity helper.
 */
final class PortalIdentity {

	/**
	 * Permission callback shared by every portal REST route.
	 *
	 * WP REST validates the `X-WP-Nonce` (cookie auth) before this runs, so we
	 * assert two things here:
	 *   1. A logged-in session            → 401 when logged out.
	 *   2. Not portal-blocked support staff → 403. Mirrors the frontend gate in
	 *      {@see \DoubleScale\Modules\Portal\Renderer\PortalFrontendHandler}: an
	 *      agent whose email is NOT also a contact is turned away (a staff member
	 *      whose email DOES match a contact is treated as a customer and allowed).
	 *
	 * @return bool|WP_Error
	 */
	public static function permission_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to access the client portal.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		if ( self::is_staff_blocked() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'The client portal is only accessible by customers.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Resolve the CRM contact for the current WP user by lowercased email.
	 *
	 * @return ContactModel|null Null when logged out, no email, or no match.
	 */
	public static function current_contact(): ?ContactModel {
		$user = wp_get_current_user();
		if ( ! $user || 0 === (int) $user->ID || empty( $user->user_email ) ) {
			return null;
		}

		$contact = ContactModel::where( 'email', strtolower( trim( (string) $user->user_email ) ) )->first();

		return $contact instanceof ContactModel ? $contact : null;
	}

	/**
	 * Whether the current user is support staff who should be redirected away
	 * from the customer portal (an agent whose email is not also a contact).
	 *
	 * @return bool
	 */
	public static function is_staff_blocked(): bool {
		return Permissions::should_block_customer_portal();
	}

	/**
	 * Build the standard "row not found / not yours" 404 error.
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	public static function not_found( string $message ): WP_Error {
		return new WP_Error( 'not_found', $message, array( 'status' => 404 ) );
	}
}
