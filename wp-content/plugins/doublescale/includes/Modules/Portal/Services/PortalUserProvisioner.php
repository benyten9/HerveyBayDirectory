<?php
/**
 * Create WordPress accounts for contacts so they can use the Client Portal.
 *
 * The portal identifies a customer by matching the logged-in user's email to
 * a contact ({@see PortalIdentity}). A contact without a WordPress user can
 * therefore never log in. This service closes that gap two ways:
 *
 *  - automatically, for contacts created after the option is switched on
 *    (optionally limited to contacts in chosen lists);
 *  - on demand, for the backlog of existing contacts, in chunks the settings
 *    screen drives with a progress readout.
 *
 * Rules that keep it safe: only contacts with an email are eligible; an
 * existing user with the same email is reused, never modified; accounts are
 * created as the configured role (subscriber) so staff roles are never
 * granted; deleting a contact never deletes the WordPress user.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Portal account provisioning.
 */
final class PortalUserProvisioner {

	/** Settings key holding the portal options. */
	const SETTINGS_KEY = 'client_portal';

	/** User meta written on every account this service creates. */
	const META_CONTACT_ID  = 'doublescale_contact_id';
	const META_PROVISIONED = 'doublescale_portal_provisioned';

	/**
	 * Portal settings with defaults applied.
	 *
	 * @return array{auto_create_users:bool,auto_create_list_ids:int[],send_welcome_email:bool,role:string}
	 */
	public static function settings(): array {
		$raw = Settings::get( self::SETTINGS_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$list_ids = isset( $raw['auto_create_list_ids'] ) && is_array( $raw['auto_create_list_ids'] )
			? array_values( array_filter( array_map( 'intval', $raw['auto_create_list_ids'] ) ) )
			: array();

		$role = isset( $raw['role'] ) && is_string( $raw['role'] ) && get_role( $raw['role'] ) ? $raw['role'] : 'subscriber';

		$send_welcome = ! array_key_exists( 'send_welcome_email', $raw ) || ! empty( $raw['send_welcome_email'] );
		$role         = (string) apply_filters( 'doublescale_portal_user_role', $role );

		return array(
			'auto_create_users'    => ! empty( $raw['auto_create_users'] ),
			'auto_create_list_ids' => $list_ids,
			'send_welcome_email'   => $send_welcome,
			'role'                 => $role,
		);
	}

	/**
	 * Persist portal settings (only the keys this service owns).
	 *
	 * @param array $input Raw input.
	 * @return array Normalised settings after save.
	 */
	public static function update_settings( array $input ): array {
		$current = Settings::get( self::SETTINGS_KEY, array() );
		$current = is_array( $current ) ? $current : array();

		if ( array_key_exists( 'auto_create_users', $input ) ) {
			$current['auto_create_users'] = (bool) $input['auto_create_users'];
		}
		if ( array_key_exists( 'auto_create_list_ids', $input ) ) {
			$ids                             = is_array( $input['auto_create_list_ids'] ) ? $input['auto_create_list_ids'] : array();
			$current['auto_create_list_ids'] = array_values( array_filter( array_map( 'intval', $ids ) ) );
		}
		if ( array_key_exists( 'send_welcome_email', $input ) ) {
			$current['send_welcome_email'] = (bool) $input['send_welcome_email'];
		}

		Settings::update( self::SETTINGS_KEY, $current );
		return self::settings();
	}

	// -- eligibility -------------------------------------------------------

	/**
	 * Whether a contact falls inside the auto-create scope.
	 *
	 * @param ContactModel $contact Contact.
	 * @param array|null   $settings Pre-loaded settings.
	 * @return bool
	 */
	public static function in_scope( ContactModel $contact, ?array $settings = null ): bool {
		$settings = $settings ?? self::settings();
		if ( '' === trim( (string) $contact->email ) || ! is_email( (string) $contact->email ) ) {
			return false;
		}
		if ( empty( $settings['auto_create_list_ids'] ) ) {
			return true;
		}
		global $wpdb;
		$ids = implode( ',', array_map( 'intval', $settings['auto_create_list_ids'] ) );
		$sql = "SELECT 1 FROM {$wpdb->prefix}doublescale_contact_taxonomy_relationship WHERE contact_id = %d AND taxonomy_type = 'list' AND taxonomy_id IN ({$ids}) LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $ids are ints cast above.
		$hit = $wpdb->get_var( $wpdb->prepare( $sql, (int) $contact->id ) );
		return (bool) $hit;
	}

	// -- provisioning ------------------------------------------------------

	/**
	 * Ensure the contact has a WordPress account. Idempotent.
	 *
	 * @param ContactModel $contact Contact.
	 * @param bool|null    $send_welcome Override the welcome-email setting.
	 * @return array{status:'created'|'linked'|'skipped'|'error', user_id:int, message:string}
	 */
	public static function provision( ContactModel $contact, ?bool $send_welcome = null ): array {
		$email = strtolower( trim( (string) $contact->email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'status'  => 'skipped',
				'user_id' => 0,
				'message' => 'no_email',
			);
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			// Reuse, never alter: the user may be staff or have their own password.
			if ( ! get_user_meta( $existing->ID, self::META_CONTACT_ID, true ) ) {
				update_user_meta( $existing->ID, self::META_CONTACT_ID, (int) $contact->id );
			}
			return array(
				'status'  => 'linked',
				'user_id' => (int) $existing->ID,
				'message' => 'existing_user',
			);
		}

		$settings = self::settings();
		$login    = self::unique_login( $email );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true ),
				'first_name'   => (string) $contact->first_name,
				'last_name'    => (string) $contact->last_name,
				'display_name' => '' !== trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ) ? trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ) : $email,
				'role'         => $settings['role'],
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return array(
				'status'  => 'error',
				'user_id' => 0,
				'message' => $user_id->get_error_message(),
			);
		}

		update_user_meta( $user_id, self::META_CONTACT_ID, (int) $contact->id );
		update_user_meta( $user_id, self::META_PROVISIONED, time() );

		$send = null === $send_welcome ? $settings['send_welcome_email'] : $send_welcome;
		if ( $send ) {
			// Core's set-password email; the customer never sees the generated
			// password and lands on the standard reset flow.
			wp_new_user_notification( $user_id, null, 'user' );
		}

		/**
		 * Fires after a portal account was created for a contact.
		 *
		 * @param int          $user_id  New WordPress user id.
		 * @param ContactModel $contact  Contact the account belongs to.
		 */
		do_action( 'doublescale_portal_user_created', (int) $user_id, $contact );

		return array(
			'status'  => 'created',
			'user_id' => (int) $user_id,
			'message' => '',
		);
	}

	/**
	 * Login derived from the email, made unique.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function unique_login( string $email ): string {
		$base = sanitize_user( $email, true );
		if ( '' === $base ) {
			$base = 'customer';
		}
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . '-' . ( ++$i );
		}
		return $login;
	}

	// -- automatic path ----------------------------------------------------

	/**
	 * Hook target for new contacts.
	 *
	 * @param ContactModel $contact Contact that was just created.
	 */
	public static function on_contact_created( $contact ): void {
		if ( ! $contact instanceof ContactModel ) {
			return;
		}
		$settings = self::settings();
		if ( ! $settings['auto_create_users'] || ! self::in_scope( $contact, $settings ) ) {
			return;
		}
		self::provision( $contact );
	}

	/**
	 * Hook target for lists being added to a contact.
	 *
	 * With a list scope, most contacts are created first and put on the list
	 * afterwards (forms, imports, automations), so creation alone would miss
	 * them. Provision the moment a scoped list is applied.
	 *
	 * @param ContactModel $contact  Contact.
	 * @param array        $list_ids Lists that were just applied.
	 */
	public static function on_lists_applied( $contact, $list_ids ): void {
		if ( ! $contact instanceof ContactModel ) {
			return;
		}
		$settings = self::settings();
		if ( ! $settings['auto_create_users'] || empty( $settings['auto_create_list_ids'] ) ) {
			return; // Unscoped: creation already handled it.
		}
		$applied = array_map( 'intval', (array) $list_ids );
		if ( ! array_intersect( $applied, $settings['auto_create_list_ids'] ) ) {
			return;
		}
		self::provision( $contact );
	}

	// -- backlog -----------------------------------------------------------

	/**
	 * SQL fragment selecting contacts with an email but no WordPress user.
	 *
	 * @param bool $scoped Restrict to the configured lists.
	 * @return array{sql:string,args:array}
	 */
	private static function missing_users_query( bool $scoped ): array {
		global $wpdb;
		$settings = self::settings();
		$where    = "c.email IS NOT NULL AND c.email <> '' AND u.ID IS NULL";
		$join     = "LEFT JOIN {$wpdb->users} u ON LOWER(u.user_email) = LOWER(c.email)";
		if ( $scoped && ! empty( $settings['auto_create_list_ids'] ) ) {
			$ids   = implode( ',', array_map( 'intval', $settings['auto_create_list_ids'] ) );
			$join .= " INNER JOIN (SELECT DISTINCT contact_id FROM {$wpdb->prefix}doublescale_contact_taxonomy_relationship WHERE taxonomy_type = 'list' AND taxonomy_id IN ({$ids})) r ON r.contact_id = c.id";
		}
		return array(
			'sql'  => "FROM {$wpdb->prefix}doublescale_contacts c {$join} WHERE {$where}",
			'args' => array(),
		);
	}

	/**
	 * Counts for the settings screen.
	 *
	 * @return array{contacts_with_email:int,with_account:int,missing_account:int,missing_in_scope:int,scoped:bool}
	 */
	public static function status(): array {
		global $wpdb;
		$settings = self::settings();
		$all      = self::missing_users_query( false );
		$scoped   = self::missing_users_query( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- fragments built above from int ids.
		$missing = (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $all['sql'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$missing_scoped = (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $scoped['sql'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$with_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}doublescale_contacts WHERE email IS NOT NULL AND email <> ''" );

		return array(
			'contacts_with_email' => $with_email,
			'with_account'        => max( 0, $with_email - $missing ),
			'missing_account'     => $missing,
			'missing_in_scope'    => $missing_scoped,
			'scoped'              => ! empty( $settings['auto_create_list_ids'] ),
		);
	}

	/**
	 * Provision one chunk of the backlog, resumable by id.
	 *
	 * @param int  $after_id Last processed contact id.
	 * @param int  $limit    Contacts per call.
	 * @param bool $scoped   Restrict to the configured lists.
	 * @param bool $send_welcome Send the set-password email.
	 * @return array{created:int,linked:int,skipped:int,errors:int,next_after_id:int,done:bool}
	 */
	public static function sync_chunk( int $after_id = 0, int $limit = 100, bool $scoped = true, bool $send_welcome = true ): array {
		global $wpdb;
		$q     = self::missing_users_query( $scoped );
		$limit = max( 1, min( 500, $limit ) );

		// wp_insert_user() fans out to every `user_register` listener on the
		// site (WooCommerce customer setup, welcome mails, our own triggers…),
		// so a "chunk" is bounded by time, not just rows: stop well inside
		// the PHP execution limit and let the caller continue from next_after_id.
		$started = microtime( true );
		$budget  = (float) apply_filters( 'doublescale_portal_sync_time_budget', min( 10, max( 3, (int) ini_get( 'max_execution_time' ) * 0.4 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT c.id ' . $q['sql'] . ' AND c.id > %d ORDER BY c.id ASC LIMIT %d', $after_id, $limit ) );

		$out = array(
			'created'       => 0,
			'linked'        => 0,
			'skipped'       => 0,
			'errors'        => 0,
			'next_after_id' => $after_id,
			'done'          => empty( $ids ),
		);

		$processed = 0;
		foreach ( $ids as $id ) {
			if ( $processed > 0 && ( microtime( true ) - $started ) > $budget ) {
				break; // Out of time: next call resumes after the last processed id.
			}
			++$processed;
			$out['next_after_id'] = (int) $id;
			$contact              = ContactModel::find( (int) $id );
			if ( ! $contact ) {
				++$out['skipped'];
				continue;
			}
			$r = self::provision( $contact, $send_welcome );
			switch ( $r['status'] ) {
				case 'created':
					++$out['created'];
					break;
				case 'linked':
					++$out['linked'];
					break;
				case 'error':
					++$out['errors'];
					doublescale_get_logger()->warning(
						'Portal account could not be created',
						array(
							'code'       => 'portal_user_provision_failed',
							'contact_id' => (int) $id,
							'error'      => $r['message'],
						)
					);
					break;
				default:
					++$out['skipped'];
			}
		}

		// Done only when the query returned fewer rows than asked AND we got
		// through all of them; a time-budget break always means "call again".
		$out['done'] = ( count( $ids ) < $limit ) && ( $processed === count( $ids ) );
		return $out;
	}
}
