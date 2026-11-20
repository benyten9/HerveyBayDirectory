<?php
/**
 * How a client wants to be emailed about what happens in their portal.
 *
 * Only "team updates" (team comments written to them on a project task,
 * files the team shares on a task or project) are theirs to choose: every
 * update, one daily summary, or no email. What the team sends on purpose —
 * invoices, proposals, contracts, credit notes, bookings, ticket replies —
 * always goes: it carries money or a commitment.
 *
 * Company members may also choose to be copied on every ticket their company
 * shares, not only the ones they opened or replied to (every, or none).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * PortalEmailPreferences.
 */
final class PortalEmailPreferences {

	/** Contact meta holding the choices. */
	public const META_KEY = 'portal_email_preferences';

	public const EVERY = 'every';
	public const DAILY = 'daily';
	public const NONE  = 'none';

	/** Copied on every ticket shared with the client's company. */
	public const COMPANY_TICKETS = 'company_tickets';

	/** Category => default mode. */
	private const DEFAULTS = array(
		PortalNotifications::TEAM_UPDATES => self::EVERY,
		self::COMPANY_TICKETS             => self::NONE,
	);

	/**
	 * The client's choices, defaults filled in.
	 *
	 * @param int $contact_id Contact.
	 * @return array<string, string>
	 */
	public static function for_contact( int $contact_id ): array {
		$stored = function_exists( 'doublescale_get_contact_meta' )
			? doublescale_get_contact_meta( $contact_id, self::META_KEY, true )
			: array();
		$stored = is_array( $stored ) ? $stored : array();

		$prefs = array();
		foreach ( self::DEFAULTS as $category => $default ) {
			$mode               = isset( $stored[ $category ] ) ? (string) $stored[ $category ] : $default;
			$prefs[ $category ] = in_array( $mode, self::modes( $category ), true ) ? $mode : $default;
		}
		return $prefs;
	}

	/**
	 * How one category is emailed to the client.
	 *
	 * @param int    $contact_id Contact.
	 * @param string $category   Category.
	 * @return string
	 */
	public static function mode( int $contact_id, string $category ): string {
		return self::for_contact( $contact_id )[ $category ] ?? self::EVERY;
	}

	/**
	 * Save the client's choices; unknown categories or modes are refused.
	 *
	 * @param int                  $contact_id Contact.
	 * @param array<string, mixed> $input      Category => mode.
	 * @return array<string, string>|WP_Error
	 */
	public static function save( int $contact_id, array $input ) {
		$prefs = self::for_contact( $contact_id );
		foreach ( $input as $category => $mode ) {
			if ( ! array_key_exists( (string) $category, self::DEFAULTS ) || ! in_array( (string) $mode, self::modes( (string) $category ), true ) ) {
				return new WP_Error(
					'rest_invalid_param',
					__( 'Choose every update, a daily summary or no email.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
			$prefs[ (string) $category ] = (string) $mode;
		}
		doublescale_update_contact_meta( $contact_id, self::META_KEY, $prefs );
		return $prefs;
	}

	/**
	 * Modes a category offers: ticket emails go as they happen or not at all.
	 *
	 * @param string $category Category ('' = every mode).
	 * @return string[]
	 */
	public static function modes( string $category = '' ): array {
		if ( self::COMPANY_TICKETS === $category ) {
			return array( self::EVERY, self::NONE );
		}
		return array( self::EVERY, self::DAILY, self::NONE );
	}
}
