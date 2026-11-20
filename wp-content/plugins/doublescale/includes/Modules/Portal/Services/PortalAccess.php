<?php
/**
 * What one customer can see in the Client Portal.
 *
 * An area shows when its module is on (and Pro is there when it needs Pro)
 * AND the customer's own choice allows it — their own access when a team
 * member customised it, the defaults otherwise. A module that is off wins over
 * every saved choice; the choice itself is kept, so turning the module back on
 * restores it.
 *
 * A customer's own access is stored on the contact (`portal_access` contact
 * meta) and only for a contact who can log in — one with a WordPress account
 * matching their email, the same rule {@see PortalIdentity} logs them in by.
 * Customising pins every area, so changing the defaults later leaves that
 * customer alone; an area added afterwards follows the defaults.
 *
 * Add-ons may narrow the result through `doublescale_portal_access`, never
 * widen it: whatever the filter returns is combined with AND.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_Error;

/**
 * PortalAccess.
 */
final class PortalAccess {

	/** Contact meta key holding the customer's own access. */
	public const META_KEY = 'portal_access';

	/**
	 * What each merge in this request did, for its note.
	 *
	 * @var array<int, array{outcome:string, source_id:int}>
	 */
	private static $merges = array();

	/**
	 * The effective visibility of every area for a contact.
	 *
	 * @param ContactModel|null $contact Contact, or null for "no own access".
	 * @return array<string, bool> Feature key => visible.
	 */
	public static function for_contact( ?ContactModel $contact ): array {
		$features = PortalFeatures::all();
		$choices  = self::choices( $contact ? (int) $contact->id : 0 );

		$access = array();
		foreach ( $features as $feature ) {
			$key            = $feature['key'];
			$access[ $key ] = ! empty( $choices[ $key ] ) && PortalFeatures::is_available( $feature );
		}

		/**
		 * Narrow what a customer can see in the Client Portal.
		 *
		 * Combined with AND: returning true never shows an area the settings
		 * hide, and a key left out keeps its value.
		 *
		 * @param array<string, bool> $access  Feature key => visible.
		 * @param ContactModel|null   $contact Contact.
		 */
		$narrowed = apply_filters( 'doublescale_portal_access', $access, $contact );
		if ( is_array( $narrowed ) ) {
			foreach ( $access as $key => $visible ) {
				if ( array_key_exists( $key, $narrowed ) ) {
					$access[ $key ] = $visible && (bool) $narrowed[ $key ];
				}
			}
		}

		// Financial summaries are invoice money: no invoices, no summaries.
		// After the filter, so an area an add-on hides takes its dependants along.
		foreach ( $features as $feature ) {
			foreach ( $feature['depends_on'] as $dependency ) {
				if ( empty( $access[ $dependency ] ) ) {
					$access[ $feature['key'] ] = false;
				}
			}
		}

		return $access;
	}

	/**
	 * Whether a customer can see one area.
	 *
	 * @param string            $feature Feature key.
	 * @param ContactModel|null $contact Contact (null = defaults).
	 * @return bool
	 */
	public static function allows( string $feature, ?ContactModel $contact ): bool {
		$access = self::for_contact( $contact );

		return ! empty( $access[ $feature ] );
	}

	/**
	 * The saved choice of every area for a contact (module state aside):
	 * their own access where they have it, the defaults for the rest.
	 *
	 * @param int $contact_id Contact ID (0 = defaults only).
	 * @return array<string, bool>
	 */
	public static function choices( int $contact_id ): array {
		$choices = PortalFeatures::defaults();
		$own     = $contact_id > 0 ? self::override_for( $contact_id ) : null;

		if ( null !== $own ) {
			foreach ( $choices as $key => $value ) {
				if ( array_key_exists( $key, $own ) ) {
					$choices[ $key ] = (bool) $own[ $key ];
				}
			}
		}

		return $choices;
	}

	/**
	 * A contact's own access, or null when they follow the defaults.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<string, bool>|null
	 */
	public static function override_for( int $contact_id ): ?array {
		if ( $contact_id <= 0 ) {
			return null;
		}

		$stored = doublescale_get_contact_meta( $contact_id, self::META_KEY, true );
		if ( ! is_array( $stored ) || ! isset( $stored['features'] ) || ! is_array( $stored['features'] ) ) {
			return null;
		}

		return array_map( 'boolval', $stored['features'] );
	}

	/**
	 * Give a contact their own access. Only for a contact who can log in.
	 *
	 * Pins every area: the submitted keys over the current choices.
	 *
	 * @param ContactModel         $contact  Contact.
	 * @param array<string, mixed> $features Feature key => visible.
	 * @return array<string, bool>|WP_Error The stored access.
	 */
	public static function save_override( ContactModel $contact, array $features ) {
		if ( ! PortalIdentity::user_for_contact( $contact ) ) {
			return new WP_Error(
				'portal_no_account',
				__( 'This contact has no WordPress account, so they cannot log in to the client portal.', 'doublescale' ),
				array( 'status' => 409 )
			);
		}

		$invalid = PortalFeatures::invalid_values( $features );
		if ( $invalid ) {
			return $invalid;
		}

		$before = self::override_for( (int) $contact->id );
		$pinned = self::choices( (int) $contact->id );
		foreach ( $features as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( array_key_exists( $key, $pinned ) ) {
				$pinned[ $key ] = rest_sanitize_boolean( $value );
			}
		}

		doublescale_update_contact_meta(
			(int) $contact->id,
			self::META_KEY,
			array(
				'features'   => $pinned,
				'updated_at' => current_time( 'mysql', true ),
				'updated_by' => get_current_user_id(),
			)
		);

		self::announce( (int) $contact->id, $before, $pinned );

		return $pinned;
	}

	/**
	 * Put a contact back on the defaults.
	 *
	 * @param int $contact_id Contact ID.
	 * @return void
	 */
	public static function clear_override( int $contact_id ): void {
		$before = self::override_for( $contact_id );
		doublescale_delete_contact_meta( $contact_id, self::META_KEY );

		if ( null !== $before ) {
			self::announce( $contact_id, $before, null );
		}
	}

	/**
	 * Settle a contact merge before the generic "primary wins" meta union runs.
	 *
	 * Contact emails are unique and a merge refuses two different ones, so at
	 * most one side can have had a portal login. The access that survives is
	 * the access of the side whose email the merged contact ends up with — the
	 * access that login already had. The other side had no login, so whatever
	 * it stored is dropped rather than becoming live. With no email anywhere
	 * the generic rule applies (the primary's first): nothing is live yet.
	 *
	 * @param int                   $primary_id Primary contact ID.
	 * @param int                   $source_id  Source contact ID.
	 * @param array<string, mixed>  $primary    Primary row as loaded.
	 * @param array<string, mixed>  $source     Source row as loaded.
	 * @param array<string, string> $overrides  Identifiers the primary is being saved with.
	 * @return void
	 */
	public static function settle_merge( int $primary_id, int $source_id, array $primary, array $source, array $overrides = array() ): void {
		$primary_own = doublescale_get_contact_meta( $primary_id, self::META_KEY, true );
		$source_own  = doublescale_get_contact_meta( $source_id, self::META_KEY, true );
		$primary_has = is_array( $primary_own ) && isset( $primary_own['features'] );
		$source_has  = is_array( $source_own ) && isset( $source_own['features'] );

		if ( ! $primary_has && ! $source_has ) {
			return;
		}

		$email = static function ( $value ): string {
			return strtolower( trim( (string) $value ) );
		};
		$final = $email( $overrides['email'] ?? '' );
		if ( '' === $final ) {
			$final = $email( $primary['email'] ?? '' );
		}
		if ( '' === $final ) {
			$final = $email( $source['email'] ?? '' );
		}

		if ( '' !== $final && $email( $source['email'] ?? '' ) === $final ) {
			$outcome = $source_has ? 'source' : 'defaults';
			if ( $source_has ) {
				doublescale_update_contact_meta( $primary_id, self::META_KEY, $source_own );
			} else {
				doublescale_delete_contact_meta( $primary_id, self::META_KEY );
			}
		} elseif ( '' !== $final && $email( $primary['email'] ?? '' ) === $final ) {
			$outcome = $primary_has ? 'primary' : 'defaults';
		} else {
			$outcome = 'waiting';
			if ( ! $primary_has ) {
				doublescale_update_contact_meta( $primary_id, self::META_KEY, $source_own );
			}
		}

		doublescale_delete_contact_meta( $source_id, self::META_KEY );

		self::$merges[ $primary_id ] = array(
			'outcome'   => $outcome,
			'source_id' => $source_id,
		);
	}

	/**
	 * Add what a merge did to portal access to the merge note.
	 *
	 * @param array<int, string>   $lines      Note lines.
	 * @param int                  $primary_id Primary contact ID.
	 * @return array<int, string>
	 */
	public static function merge_note_lines( array $lines, int $primary_id ): array {
		if ( ! isset( self::$merges[ $primary_id ] ) ) {
			return $lines;
		}

		$merge = self::$merges[ $primary_id ];
		unset( self::$merges[ $primary_id ] );

		switch ( $merge['outcome'] ) {
			case 'source':
				/* translators: %d: merged contact id */
				$lines[] = sprintf( __( 'Client portal access: kept the access of contact #%d, whose email this contact now uses.', 'doublescale' ), $merge['source_id'] );
				break;
			case 'primary':
				/* translators: %d: merged contact id */
				$lines[] = sprintf( __( 'Client portal access: kept this contact\'s own access; the access saved on contact #%d was removed.', 'doublescale' ), $merge['source_id'] );
				break;
			case 'defaults':
				$lines[] = __( 'Client portal access: this contact keeps following the default access, as its login did before the merge; saved access no login used was removed.', 'doublescale' );
				break;
			default:
				$lines[] = __( 'Client portal access: the saved access is kept for when this contact gets a login.', 'doublescale' );
		}

		return $lines;
	}

	/**
	 * A deleted contact takes their own access with them.
	 *
	 * @param mixed $contact Contact being deleted.
	 * @return void
	 */
	public static function forget_contact( $contact ): void {
		if ( $contact instanceof ContactModel ) {
			doublescale_delete_contact_meta( (int) $contact->id, self::META_KEY );
		}
	}

	/**
	 * How many contacts have their own access (shown next to the defaults).
	 *
	 * @return int
	 */
	public static function count_customized(): int {
		global $wpdb;

		// Joined to contacts: a row left behind by a bulk delete is no client.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT m.contact_id) FROM {$wpdb->prefix}doublescale_contact_meta m
				INNER JOIN {$wpdb->prefix}doublescale_contacts c ON c.id = m.contact_id
				WHERE m.meta_key = %s",
				self::META_KEY
			)
		);
	}

	/**
	 * Fires after a contact's own access changed.
	 *
	 * @param int                      $contact_id Contact ID.
	 * @param array<string, bool>|null $before     Previous own access (null = defaults).
	 * @param array<string, bool>|null $after      New own access (null = defaults).
	 * @return void
	 */
	private static function announce( int $contact_id, ?array $before, ?array $after ): void {
		/**
		 * A contact's own Client Portal access changed.
		 *
		 * @param int                      $contact_id Contact ID.
		 * @param array<string, bool>|null $before     Previous own access (null = defaults).
		 * @param array<string, bool>|null $after      New own access (null = defaults).
		 */
		do_action( 'doublescale_portal_access_updated', $contact_id, $before, $after );
	}
}
