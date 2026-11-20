<?php
/**
 * The portal invite, and where a client lands after signing in.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Emails\Emails;
use WP_Error;

/**
 * A new portal account used to get WordPress's own "Login Details" email: no
 * portal name, no portal address, and after choosing a password the client
 * landed on their WordPress profile. The invite names the site, links to the
 * portal and to choosing a password; the team can send it again from the
 * contact's Client portal card (the password link lasts a day); a client who
 * signs in on wp-login.php goes to the portal.
 *
 * Never for a team member's account: they sign in to the dashboard.
 */
final class PortalInvite {

	/** When the last invite went (user meta, Unix time). */
	public const META_SENT_AT = 'doublescale_portal_invited_at';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 1000, 3 );
	}

	/**
	 * Email the contact an invite to the portal.
	 *
	 * @param ContactModel $contact Client.
	 * @return true|WP_Error
	 */
	public static function send( ContactModel $contact ) {
		$user = PortalIdentity::user_for_contact( $contact );
		if ( ! $user ) {
			return new WP_Error( 'portal_no_account', __( 'This contact has no portal account yet.', 'doublescale' ), array( 'status' => 409 ) );
		}
		if ( ! self::is_client_login( $user ) ) {
			return new WP_Error(
				'portal_team_account',
				__( 'This email belongs to a team member\'s account: they sign in to the dashboard, not the client portal.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}
		$portal = PortalUrl::get_portal_page_url();
		if ( '' === $portal ) {
			return new WP_Error( 'portal_no_page', __( 'There is no client portal page yet.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$site     = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$password = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$name     = trim( (string) $contact->first_name );

		/* translators: %s: site name */
		$subject = sprintf( __( 'Your client portal at %s', 'doublescale' ), $site );
		$body    = '<p>' . esc_html(
			'' !== $name
				/* translators: %s: client's first name */
				? sprintf( __( 'Hi %s,', 'doublescale' ), $name )
				: __( 'Hello,', 'doublescale' )
		) . '</p>'
			. '<p>' . esc_html(
				/* translators: %s: site name */
				sprintf( __( 'You now have your own client portal at %s: your documents, payments and requests with us, in one place.', 'doublescale' ), $site )
			) . '</p>'
			. '<p><a href="' . esc_url( $password ) . '"><strong>' . esc_html__( 'Choose your password', 'doublescale' ) . '</strong></a></p>'
			. '<p>' . esc_html__( 'This link works for 24 hours. After that, ask us for a new invite or use "Lost your password?" on the sign-in page.', 'doublescale' ) . '</p>'
			. '<p>' . esc_html__( 'Your portal:', 'doublescale' ) . ' <a href="' . esc_url( $portal ) . '">' . esc_html( $portal ) . '</a><br />'
			/* translators: %s: email address */
			. esc_html( sprintf( __( 'You sign in with %s.', 'doublescale' ), (string) $user->user_email ) ) . '</p>';

		try {
			$sent = (bool) ( new Emails() )->send( (string) $user->user_email, $subject, $body );
		} catch ( \Throwable $e ) {
			$sent = false;
		}
		if ( ! $sent ) {
			return new WP_Error( 'portal_invite_failed', __( 'The invite could not be sent. Check the site\'s email settings.', 'doublescale' ), array( 'status' => 500 ) );
		}

		update_user_meta( $user->ID, self::META_SENT_AT, time() );
		return true;
	}

	/**
	 * A client signing in on wp-login.php goes to the portal, unless they
	 * asked for a page of their own.
	 *
	 * @param string            $redirect_to Where WordPress sends them.
	 * @param string            $requested   What the sign-in link asked for.
	 * @param \WP_User|\WP_Error $user        Who signed in.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof \WP_User || ! self::is_client_login( $user ) ) {
			return $redirect_to;
		}
		$requested = (string) $requested;
		if ( '' !== $requested && admin_url() !== $requested && ! self::is_profile( $requested ) ) {
			return $redirect_to;
		}
		$portal = PortalUrl::get_portal_page_url();
		if ( '' === $portal || ! self::has_contact( $user ) ) {
			return $redirect_to;
		}
		return $portal;
	}

	/**
	 * A customer's own login: no team access, no site editing.
	 *
	 * @param \WP_User $user Account.
	 * @return bool
	 */
	public static function is_client_login( \WP_User $user ): bool {
		foreach ( array( 'manage_options', 'edit_posts', 'doublescale_access' ) as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param \WP_User $user Account.
	 * @return bool
	 */
	private static function has_contact( \WP_User $user ): bool {
		$email = strtolower( trim( (string) $user->user_email ) );
		return '' !== $email && ContactModel::where( 'email', $email )->exists();
	}

	/**
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_profile( string $url ): bool {
		return 0 === strpos( $url, admin_url( 'profile.php' ) );
	}
}
