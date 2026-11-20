<?php
/**
 * A portal login counts once it has proved it owns its email.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Emails;
use WP_Error;

/**
 * The portal finds a client by their login's email ({@see PortalIdentity}).
 * A site that lets visitors make an account with a password they choose (a
 * shop's checkout or My Account, a membership sign-up) let anyone register
 * with a client's address and read their portal. A login now counts once its
 * email is confirmed:
 *
 *  - accounts the portal makes, and existing accounts the team links;
 *  - a password chosen from an emailed reset link;
 *  - the link the portal sends from its "Confirm your email" screen;
 *  - every login that matched a client when this arrived (no one locked out);
 *  - a contact email the team changes ({@see PortalLoginEmail}).
 *
 * Team accounts are the team's and need none of it.
 */
final class PortalEmailConfirmation {

	/** The email the login confirmed (user meta, lowercased). */
	public const META_CONFIRMED = 'doublescale_portal_email_confirmed';

	/** The pending link: hash, expiry, when it went, to which address. */
	public const META_TOKEN = 'doublescale_portal_confirm_token';

	/** Set once existing clients' logins were trusted. */
	public const BACKFILL_OPTION = 'doublescale_portal_email_confirmation_backfill_v1';

	/** The link's arguments, on the portal page. */
	public const TOKEN_ARG = 'ds_portal_confirm';
	public const USER_ARG  = 'ds_portal_user';

	/** How long a link works. */
	private const TOKEN_TTL = 2 * DAY_IN_SECONDS;

	/** Seconds before another link can be asked for. */
	private const RESEND_GAP = 60;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'user_register', array( self::class, 'on_user_register' ), 5, 1 );
		add_action( 'after_password_reset', array( self::class, 'on_password_reset' ), 10, 1 );
		add_action( 'template_redirect', array( self::class, 'handle_link' ), 1 );
	}

	/**
	 * An account a team member makes (Users → Add New, a shop order for a
	 * customer) or the site's owner makes from the command line is trusted. One
	 * a visitor makes on the site (a shop or membership sign-up) is not, until
	 * it confirms its email.
	 *
	 * @param int $user_id New login.
	 * @return void
	 */
	public static function on_user_register( $user_id ): void {
		$trusted = 'cli' === PHP_SAPI || current_user_can( 'create_users' );

		/**
		 * Whether a new login counts as its email's owner from the start.
		 *
		 * @param bool $trusted A team member or the command line made it.
		 * @param int  $user_id New login.
		 */
		if ( apply_filters( 'doublescale_portal_trust_new_login', $trusted, (int) $user_id ) ) {
			self::confirm( (int) $user_id );
		}
	}

	/**
	 * Whether the login may see a client's portal.
	 *
	 * @param \WP_User $user Login.
	 * @return bool
	 */
	public static function is_confirmed( \WP_User $user ): bool {
		if ( ! PortalInvite::is_client_login( $user ) ) {
			return true;
		}
		$email = strtolower( trim( (string) $user->user_email ) );
		return '' !== $email && $email === (string) get_user_meta( $user->ID, self::META_CONFIRMED, true );
	}

	/**
	 * Trust the login's current email.
	 *
	 * @param int $user_id Login.
	 * @return void
	 */
	public static function confirm( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		update_user_meta( $user_id, self::META_CONFIRMED, strtolower( trim( (string) $user->user_email ) ) );
		delete_user_meta( $user_id, self::META_TOKEN );
	}

	/**
	 * A password chosen from an emailed reset link proves the address.
	 *
	 * @param \WP_User|mixed $user Login.
	 * @return void
	 */
	public static function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			self::confirm( (int) $user->ID );
		}
	}

	/**
	 * Email the login a link that confirms its address.
	 *
	 * @param \WP_User $user Login.
	 * @return true|WP_Error
	 */
	public static function send( \WP_User $user ) {
		$email = strtolower( trim( (string) $user->user_email ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'portal_no_email', __( 'This account has no email address to confirm.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$portal = PortalUrl::get_portal_page_url();
		if ( '' === $portal ) {
			return new WP_Error( 'portal_no_page', __( 'There is no client portal page yet.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$pending = get_user_meta( $user->ID, self::META_TOKEN, true );
		if ( is_array( $pending ) && time() - (int) ( $pending['sent'] ?? 0 ) < self::RESEND_GAP ) {
			return new WP_Error( 'portal_confirm_wait', __( 'We just sent you a link. Please wait a minute before asking for another one.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$token = wp_generate_password( 32, false, false );
		update_user_meta(
			$user->ID,
			self::META_TOKEN,
			array(
				'hash'    => wp_hash( $token ),
				'expires' => time() + self::TOKEN_TTL,
				'sent'    => time(),
				'email'   => $email,
			)
		);

		$link = add_query_arg(
			array(
				self::TOKEN_ARG => $token,
				self::USER_ARG  => (int) $user->ID,
			),
			$portal
		);
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: site name */
		$subject = sprintf( __( 'Confirm your email for %s', 'doublescale' ), $site );
		$body    = '<p>' . esc_html__( 'Hello,', 'doublescale' ) . '</p>'
			. '<p>' . esc_html__( 'To see your account in our client portal, please confirm that this email address is yours.', 'doublescale' ) . '</p>'
			. '<p><a href="' . esc_url( $link ) . '"><strong>' . esc_html__( 'Confirm my email', 'doublescale' ) . '</strong></a></p>'
			. '<p>' . esc_html__( 'This link works for 48 hours. If you did not ask for it, you can ignore this email.', 'doublescale' ) . '</p>';

		try {
			$sent = (bool) ( new Emails() )->send( $email, $subject, $body );
		} catch ( \Throwable $e ) {
			$sent = false;
		}
		if ( ! $sent ) {
			return new WP_Error( 'portal_confirm_failed', __( 'The email could not be sent. Please try again later.', 'doublescale' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Check a link and confirm the login it was sent to. A link works once,
	 * for the address it went to.
	 *
	 * @param int    $user_id Login.
	 * @param string $token   The link's token.
	 * @return bool
	 */
	public static function verify( int $user_id, string $token ): bool {
		$pending = $user_id > 0 ? get_user_meta( $user_id, self::META_TOKEN, true ) : null;
		if ( '' === $token || ! is_array( $pending ) || (int) ( $pending['expires'] ?? 0 ) < time() ) {
			return false;
		}
		if ( ! hash_equals( (string) ( $pending['hash'] ?? '' ), wp_hash( $token ) ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || strtolower( trim( (string) $user->user_email ) ) !== (string) ( $pending['email'] ?? '' ) ) {
			return false;
		}
		self::confirm( $user_id );
		return true;
	}

	/**
	 * The emailed link opens the portal page: confirm, then show the portal
	 * without the link's arguments.
	 *
	 * @return void
	 */
	public static function handle_link(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the proof.
		if ( empty( $_GET[ self::TOKEN_ARG ] ) || empty( $_GET[ self::USER_ARG ] ) ) {
			return;
		}
		$token   = sanitize_text_field( wp_unslash( (string) $_GET[ self::TOKEN_ARG ] ) );
		$user_id = absint( wp_unslash( $_GET[ self::USER_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		self::verify( $user_id, $token );

		$portal = PortalUrl::get_portal_page_url();
		wp_safe_redirect( '' !== $portal ? $portal : home_url( '/' ) );
		exit;
	}

	/**
	 * Trust, once, the logins that already matched a client: the check is
	 * for accounts made from now on, not a step for every existing client.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		update_option( self::BACKFILL_OPTION, time(), false );

		global $wpdb;
		$contacts = $wpdb->prefix . 'doublescale_contacts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $contacts !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $contacts ) ) ) ) {
			return;
		}

		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$users = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_email FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT 500", $after ) );
			if ( empty( $users ) ) {
				break;
			}
			$by_email = array();
			foreach ( $users as $row ) {
				$after = (int) $row->ID;
				$email = strtolower( trim( (string) $row->user_email ) );
				if ( '' !== $email ) {
					$by_email[ $email ] = (int) $row->ID;
				}
			}
			if ( $by_email ) {
				$in = implode( ',', array_fill( 0, count( $by_email ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT email FROM `{$contacts}` WHERE email IN ({$in})", array_keys( $by_email ) ) );
				foreach ( (array) $found as $email ) {
					$key = strtolower( trim( (string) $email ) );
					if ( isset( $by_email[ $key ] ) ) {
						update_user_meta( $by_email[ $key ], self::META_CONFIRMED, $key );
					}
				}
			}
		} while ( count( $users ) === 500 );
	}
}
