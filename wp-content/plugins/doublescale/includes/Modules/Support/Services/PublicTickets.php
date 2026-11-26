<?php
/**
 * Tickets from the help center by visitors with no account.
 *
 * The help center's "Open a ticket" sends a signed-in customer to the support
 * portal. A visitor who isn't signed in either gets a short form (name, email,
 * subject, message) or the portal's sign-in, as the admin chooses in Helpdesk
 * → Settings → Help center tickets (Zendesk's "Anybody can submit tickets",
 * Freshdesk's "Who can submit a new ticket"):
 *
 *   who      anyone     the form; the ticket and contact are made as for an
 *                       email to the support mailbox, and the "We received your
 *                       request" email carries the ticket's private link
 *            signed_in  the portal's sign-in (how it always was)
 *   confirm  true       the ticket is made only once the visitor opens the link
 *                       emailed to them (proves the address is theirs)
 *
 * New sites start with anyone; sites that ran an earlier version keep
 * signed_in until the admin changes it.
 *
 * Always on against spam: a field people never see (bots fill it in), a
 * form sent faster than a person can type (a bot), and limits per visitor,
 * per email address and per day for the whole site. Caught bots are told it
 * worked and nothing is kept.
 *
 * Someone else's address: the ticket's private link is only ever emailed
 * (never shown to whoever typed the address), and a ticket for an address
 * that already belongs to a customer, not confirmed, carries a team-only
 * note before anyone shares that customer's details.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\ModuleNews;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Support\Models\TicketModel;
use WP_Error;

/**
 * PublicTickets service.
 */
final class PublicTickets {

	/** Settings key. */
	public const SETTING = 'support_public_tickets';

	public const ANYONE    = 'anyone';
	public const SIGNED_IN = 'signed_in';

	/** The first version that offers the form (new sites start with it on). */
	private const SINCE = '1.9.11';

	/** The emailed confirmation link's argument (any page of the site). */
	public const CONFIRM_ARG = 'ds_ticket_confirm';

	/** Where a confirmation link that no longer works leads (help center page). */
	public const EXPIRED_ARG = 'ds_ticket_link';

	/**
	 * The field people never see; bots fill it in. A name no browser autofill
	 * matches (an autofilled "website" would drop a real person's request).
	 */
	public const TRAP_FIELD = 'ds_hc_extra';

	/** Milliseconds the form was open (sent by the help center's script). */
	public const ELAPSED_FIELD = 'ds_hc_elapsed';

	/** Faster than this, nobody typed it. */
	private const MIN_ELAPSED_MS = 3000;

	/** How long a confirmation link works. */
	private const CONFIRM_TTL = 2 * DAY_IN_SECONDS;

	/** Requests per visitor per hour, per email address per hour, per day for the site. */
	private const PER_VISITOR = 5;
	private const PER_EMAIL   = 3;
	private const PER_DAY     = 50;

	/** Longest subject, name and message. */
	private const MAX_TITLE   = 191;
	private const MAX_NAME    = 100;
	private const MAX_MESSAGE = 10000;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'handle_link' ), 1 );
		// Signing in with the address proves it is theirs (a reply by email
		// does not: anyone can write "from" an address).
		add_action( 'set_logged_in_cookie', array( self::class, 'prove_address_on_sign_in' ), 10, 4 );
	}

	/**
	 * A contact signed in with its address: its tickets are no longer unverified.
	 *
	 * @param string $logged_in_cookie Unused.
	 * @param int    $expire           Unused.
	 * @param int    $expiration       Unused.
	 * @param int    $user_id          Who signed in.
	 * @return void
	 */
	public static function prove_address_on_sign_in( $logged_in_cookie, $expire, $expiration, $user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( (string) $user->user_email ) ) {
			return;
		}
		try {
			$contact = ContactModel::where( 'email', strtolower( (string) $user->user_email ) )->first();
			if ( $contact ) {
				TicketService::clear_unverified_for_contact( (int) $contact->id );
			}
		} catch ( \Throwable $e ) {
			return; // Never get in the way of signing in.
		}
	}

	/**
	 * Who may open a ticket from the help center, and whether visitors confirm
	 * their email first.
	 *
	 * @return array{who: string, confirm: bool}
	 */
	public static function settings(): array {
		$stored = Settings::get( self::SETTING, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$who    = (string) ( $stored['who'] ?? '' );
		if ( ! in_array( $who, array( self::ANYONE, self::SIGNED_IN ), true ) ) {
			$who = self::fresh_site() ? self::ANYONE : self::SIGNED_IN;
		}

		return array(
			'who'     => $who,
			'confirm' => ! empty( $stored['confirm'] ),
		);
	}

	/**
	 * @param array<string, mixed> $input who, confirm (either).
	 * @return array{who: string, confirm: bool}
	 */
	public static function update( array $input ): array {
		$settings = self::settings();
		if ( isset( $input['who'] ) && in_array( $input['who'], array( self::ANYONE, self::SIGNED_IN ), true ) ) {
			$settings['who'] = (string) $input['who'];
		}
		if ( array_key_exists( 'confirm', $input ) ) {
			$settings['confirm'] = (bool) $input['confirm'];
		}
		Settings::update( self::SETTING, $settings );

		return self::settings();
	}

	/**
	 * Visitors who aren't signed in get the form.
	 *
	 * @return bool
	 */
	public static function open_to_visitors(): bool {
		return self::ANYONE === self::settings()['who'];
	}

	/**
	 * A visitor's request from the help center form.
	 *
	 * @param array<string, mixed> $input name, email, title, content, the trap
	 *                                    field and how long the form was open.
	 * @return array{status: string, email: string}|WP_Error status `created`
	 *         (the ticket's private link went to `email`) or `confirm` (the
	 *         link to confirm went to `email`). Never the ticket's link: only
	 *         the owner of the address may follow the ticket.
	 */
	public static function submit( array $input ) {
		if ( ! self::open_to_visitors() ) {
			return new WP_Error( 'public_tickets_off', __( 'Please sign in to open a ticket.', 'doublescale' ), array( 'status' => 403 ) );
		}

		$fields = self::validate( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$settings = self::settings();

		// A bot: it filled in the field people never see, or sent the form
		// faster than anyone types (or without the help center's script).
		// Answer as if it worked, so it learns nothing, and keep nothing.
		if ( self::is_bot( $input ) ) {
			return array(
				'status' => $settings['confirm'] ? 'confirm' : 'created',
				'email'  => $fields['email'],
			);
		}

		$limited = self::limit( $fields['email'] );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( $settings['confirm'] ) {
			$sent = self::send_confirmation( $fields );
			if ( is_wp_error( $sent ) ) {
				return $sent;
			}
			return array(
				'status' => 'confirm',
				'email'  => $fields['email'],
			);
		}

		$ticket = self::create( $fields, false );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		return array(
			'status' => 'created',
			'email'  => $fields['email'],
		);
	}

	/**
	 * The emailed link. Opening it (GET) only shows a "Confirm my request"
	 * button: mail scanners and link previews open links, and must not make the
	 * ticket. Pressing the button (POST, the token is the proof) makes it once,
	 * then shows it.
	 *
	 * @return void
	 */
	public static function handle_link(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- anonymous visitor: the secret token is the proof.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$source = 'POST' === $method ? $_POST : $_GET; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read through sanitize_text_field below.
		if ( empty( $source[ self::CONFIRM_ARG ] ) || ! is_string( $source[ self::CONFIRM_ARG ] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $source[ self::CONFIRM_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification

		if ( 'POST' !== $method ) {
			if ( self::has_pending( $token ) ) {
				self::render_confirm_page( $token );
				exit;
			}
		} else {
			$ticket = self::confirm( $token );
			if ( $ticket instanceof TicketModel ) {
				$url = PortalUrl::get_public_ticket_url( $ticket );
				wp_safe_redirect( '' !== $url ? $url : home_url( '/' ) );
				exit;
			}
		}

		// Used or expired: back to where they asked, to send it again.
		$back = self::help_center_url();
		wp_safe_redirect( add_query_arg( self::EXPIRED_ARG, 'expired', '' !== $back ? $back : home_url( '/' ) ) );
		exit;
	}

	/**
	 * Is there a request waiting for this token? (Does not use it up.)
	 *
	 * @param string $token The link's token.
	 * @return bool
	 */
	public static function has_pending( string $token ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9]{32}$/', $token ) && is_array( get_transient( self::pending_key( $token ) ) );
	}

	/**
	 * The page behind the emailed link: one button. Nothing the visitor typed
	 * is shown; the page is not indexed or cached.
	 *
	 * @param string $token The link's token.
	 * @return void
	 */
	private static function render_confirm_page( string $token ): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $site ) . '</title>'
			. '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f7f9;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}'
			. '.c{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.12);padding:32px;max-width:420px;margin:16px;text-align:center}'
			. 'button{background:#2271b1;color:#fff;border:0;border-radius:6px;padding:12px 24px;font-size:16px;cursor:pointer}</style></head><body><div class="c">'
			. '<h1 style="font-size:20px;margin:0 0 12px">' . esc_html( $site ) . '</h1>'
			. '<p>' . esc_html__( 'Confirm that you sent us a request from our knowledge base. We open it as soon as you do.', 'doublescale' ) . '</p>'
			. '<form method="post" action="' . esc_url( home_url( '/' ) ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::CONFIRM_ARG ) . '" value="' . esc_attr( $token ) . '">'
			. '<button type="submit">' . esc_html__( 'Confirm my request', 'doublescale' ) . '</button></form></div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic part is escaped above.
	}

	/**
	 * Make the ticket a confirmation link stands for. A link works once.
	 *
	 * @param string $token The link's token.
	 * @return TicketModel|null
	 */
	public static function confirm( string $token ): ?TicketModel {
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return null;
		}
		$key     = self::pending_key( $token );
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) ) {
			return null;
		}
		// Gone before the ticket is made: a second click can't make another.
		delete_transient( $key );

		$ticket = self::create( $pending, true );

		return $ticket instanceof TicketModel ? $ticket : null;
	}

	/**
	 * Make the ticket and its contact, as for an email to the support mailbox.
	 * The "We received your request" email (Helpdesk email templates) sends
	 * the ticket's private link.
	 *
	 * @param array{name: string, email: string, title: string, content: string} $fields    Request.
	 * @param bool                                                               $confirmed The visitor opened the emailed link.
	 * @return TicketModel|WP_Error
	 */
	private static function create( array $fields, bool $confirmed ) {
		$parts = preg_split( '/\s+/u', trim( (string) $fields['name'] ), 2 );

		// Nobody proved the address is theirs, and it is a customer's already.
		$unproven = ! $confirmed && null !== ContactModel::where( 'email', (string) $fields['email'] )->first();

		/** This filter is documented in RestTicketController::service(). */
		$service = apply_filters( 'doublescale_support_ticket_service_instance', null );
		if ( ! $service instanceof TicketService ) {
			$service = new TicketService( new ContactResolver() );
		}

		$ticket = $service->create_ticket(
			array(
				'email'      => (string) $fields['email'],
				'first_name' => '' !== (string) ( $parts[0] ?? '' ) ? (string) $parts[0] : null,
				'last_name'  => '' !== (string) ( $parts[1] ?? '' ) ? (string) $parts[1] : null,
				'title'      => (string) $fields['title'],
				'content'    => wpautop( esc_html( (string) $fields['content'] ) ),
				'source'     => 'web',
				// Nobody proved the address: the ticket is never merged into a
				// real customer's and its first email carries nothing typed.
				TicketService::UNVERIFIED_KEY => ! $confirmed,
			)
		);
		if ( is_wp_error( $ticket ) ) {
			// No mailbox yet and the like: the team's to fix, not the visitor's.
			doublescale_get_logger()->error(
				'Knowledge base ticket form: the ticket could not be created.',
				array(
					'source' => 'support-public-tickets',
					'code'   => $ticket->get_error_code(),
				)
			);
			return new WP_Error( 'public_ticket_failed', __( 'Your request could not be sent. Please try again later, or email us.', 'doublescale' ), array( 'status' => 500 ) );
		}

		if ( $unproven ) {
			$service->add_note(
				(int) $ticket->id,
				array(
					'content'        => wpautop( esc_html__( 'Email not verified: this request came from the knowledge base form, where anyone can type any address, and the address belongs to an existing contact. Make sure it is really them before sharing account or order details.', 'doublescale' ) ),
					'author_user_id' => null,
				)
			);
		}

		return $ticket;
	}

	/**
	 * The trap field filled in, or the form sent faster than anyone types
	 * (or without the help center's script, which sends the time).
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return bool
	 */
	private static function is_bot( array $input ): bool {
		if ( '' !== trim( (string) ( $input[ self::TRAP_FIELD ] ?? '' ) ) ) {
			return true;
		}
		$elapsed = $input[ self::ELAPSED_FIELD ] ?? null;

		return ! is_numeric( $elapsed ) || (int) $elapsed < self::MIN_ELAPSED_MS;
	}

	/**
	 * @param array<string, mixed> $input Raw input.
	 * @return array{name: string, email: string, title: string, content: string}|WP_Error
	 */
	private static function validate( array $input ) {
		$email   = strtolower( trim( sanitize_email( (string) ( $input['email'] ?? '' ) ) ) );
		$name    = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		$title   = trim( sanitize_text_field( (string) ( $input['title'] ?? '' ) ) );
		$content = trim( sanitize_textarea_field( (string) ( $input['content'] ?? '' ) ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return self::invalid( 'email', __( 'Please enter a valid email address, so we can reply.', 'doublescale' ) );
		}
		if ( '' === $title ) {
			return self::invalid( 'title', __( 'Please add a short subject.', 'doublescale' ) );
		}
		if ( '' === $content ) {
			return self::invalid( 'content', __( 'Please tell us how we can help.', 'doublescale' ) );
		}

		return array(
			'name'    => mb_substr( $name, 0, self::MAX_NAME, 'UTF-8' ),
			'email'   => $email,
			'title'   => mb_substr( $title, 0, self::MAX_TITLE, 'UTF-8' ),
			'content' => mb_substr( $content, 0, self::MAX_MESSAGE, 'UTF-8' ),
		);
	}

	/**
	 * @param string $field   Field.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function invalid( string $field, string $message ): WP_Error {
		return new WP_Error(
			'public_ticket_invalid',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * Per visitor, per address and per day for the whole site. A count that
	 * could not be made (0) lets the request through.
	 *
	 * @param string $email Address.
	 * @return true|WP_Error
	 */
	private static function limit( string $email ) {
		$busy = new WP_Error( 'public_ticket_limit', __( 'You have sent several requests in a short time. Please wait a while and try again.', 'doublescale' ), array( 'status' => 429 ) );

		if ( RateLimiter::hit( 'support_public_ticket_ip_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > self::PER_VISITOR ) {
			return $busy;
		}
		if ( RateLimiter::hit( 'support_public_ticket_email_' . $email, HOUR_IN_SECONDS ) > self::PER_EMAIL ) {
			return $busy;
		}
		if ( RateLimiter::hit( 'support_public_ticket_site', DAY_IN_SECONDS ) > self::daily_cap() ) {
			return new WP_Error( 'public_ticket_limit', __( 'We can’t take more requests from this form today. Please email us instead.', 'doublescale' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Requests the form takes per day for the whole site.
	 *
	 * @return int
	 */
	public static function daily_cap(): int {
		/**
		 * Filters how many help center form requests the whole site takes a day.
		 *
		 * @param int $cap Default 50.
		 */
		return max( 1, (int) apply_filters( 'doublescale_public_ticket_daily_cap', self::PER_DAY ) );
	}

	/**
	 * Keep the request and email the link that sends it.
	 *
	 * @param array{name: string, email: string, title: string, content: string} $fields Request.
	 * @return true|WP_Error
	 */
	private static function send_confirmation( array $fields ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::pending_key( $token ), $fields, self::CONFIRM_TTL );

		$link = add_query_arg( self::CONFIRM_ARG, $token, home_url( '/' ) );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: site name */
		$subject = sprintf( __( 'Confirm your request to %s', 'doublescale' ), $site );
		$body    = '<p>' . esc_html__( 'Hello,', 'doublescale' ) . '</p>'
			// Nothing the visitor typed goes into this email: the form must not
			// carry a stranger's text to any address from this site.
			. '<p>' . esc_html__( 'Please confirm that you sent us a request from our knowledge base. We open it as soon as you do.', 'doublescale' ) . '</p>'
			. '<p><a href="' . esc_url( $link ) . '"><strong>' . esc_html__( 'Confirm my request', 'doublescale' ) . '</strong></a></p>'
			. '<p>' . esc_html__( 'This link works for 48 hours. If you didn’t send this, you can ignore this email.', 'doublescale' ) . '</p>';

		try {
			$sent = (bool) ( new Emails() )->send( $fields['email'], $subject, $body );
		} catch ( \Throwable $e ) {
			$sent = false;
		}
		if ( ! $sent ) {
			delete_transient( self::pending_key( $token ) );
			return new WP_Error( 'public_ticket_email_failed', __( 'We couldn’t send the confirmation email. Please try again later, or email us.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * @param string $token Link token.
	 * @return string Transient key (the token itself is never stored).
	 */
	private static function pending_key( string $token ): string {
		return 'ds_pt_' . substr( wp_hash( $token ), 0, 32 );
	}

	/**
	 * The help center page, or ''.
	 *
	 * @return string
	 */
	private static function help_center_url(): string {
		$class = '\\DoubleScale\\Modules\\KnowledgeBase\\Services\\KbPublicArticles';

		return class_exists( $class ) ? (string) $class::page_url() : '';
	}

	/**
	 * A site that started on this version or later.
	 *
	 * @return bool
	 */
	private static function fresh_site(): bool {
		$first = (string) get_option( ModuleNews::FIRST_VERSION_OPTION, '0' );

		return version_compare( $first, self::SINCE, '>=' );
	}
}
