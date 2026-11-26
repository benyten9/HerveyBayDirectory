<?php
/**
 * Who may fill in a form, and when (Pro): open and close dates, an entry limit,
 * one entry per person, logged-in visitors only and a password.
 *
 * Checked when the form is shown (a message takes its place) and again when it is sent,
 * so a copy of the page left open, or a request made by hand, cannot get round it.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;

defined( 'ABSPATH' ) || exit;

/**
 * Access rules.
 */
final class Access {

	/** Posted field names of the password box. */
	public const PASSWORD_FIELD = 'dsf_password';
	public const FORM_FIELD     = 'dsf_unlock';

	/** How long a correct password is remembered. */
	private const UNLOCK_TTL = 12 * HOUR_IN_SECONDS;

	/** Wrong passwords allowed per visitor and form every 15 minutes. */
	private const PASSWORD_TRIES = 10;

	/**
	 * Settings only bite with Pro; without it every form is open.
	 *
	 * @param FormModel  $form     Form.
	 * @param array|null $settings Normalized settings (looked up when null).
	 * @return array{open: bool, reason: string, message: string}
	 */
	public static function state( FormModel $form, ?array $settings = null ): array {
		$open = array(
			'open'    => true,
			'reason'  => '',
			'message' => '',
		);
		if ( ! Schema::pro_active() ) {
			return $open;
		}
		$settings = $settings ?? Schema::normalize_settings( $form->settings );
		$access   = $settings['access'];
		$now      = current_datetime()->format( 'Y-m-d\TH:i' );

		if ( '' !== $access['opens_at'] && $now < $access['opens_at'] ) {
			return self::closed( 'early', $access['early_message'] );
		}
		if ( '' !== $access['closes_at'] && $now >= $access['closes_at'] ) {
			return self::closed( 'closed', $access['closed_message'] );
		}
		if ( $access['max_entries'] > 0 && self::entries( $form ) >= $access['max_entries'] ) {
			return self::closed( 'full', $access['closed_message'] );
		}
		if ( $access['logged_in'] && ! is_user_logged_in() ) {
			return self::closed( 'login', $access['login_message'] );
		}
		if ( '' !== $access['password'] && ! self::unlocked( $form, $access['password'] ) ) {
			return self::closed( 'password', '' );
		}
		return $open;
	}

	/**
	 * Complete entries of a form (spam and partial ones do not count towards the limit).
	 *
	 * @param FormModel $form Form.
	 * @return int
	 */
	public static function entries( FormModel $form ): int {
		return (int) FormSubmissionModel::query()
			->where( 'form_id', (int) $form->id )
			->where( 'status', FormSubmissionModel::STATUS_COMPLETE )
			->count();
	}

	/**
	 * Whether this contact already sent the form (for "one entry per person").
	 *
	 * @param FormModel $form       Form.
	 * @param int       $contact_id Contact id.
	 * @return bool
	 */
	public static function already_sent( FormModel $form, int $contact_id ): bool {
		if ( $contact_id <= 0 ) {
			return false;
		}
		return FormSubmissionModel::query()
			->where( 'form_id', (int) $form->id )
			->where( 'contact_id', $contact_id )
			->where( 'status', FormSubmissionModel::STATUS_COMPLETE )
			->exists();
	}

	/**
	 * Handle a posted password (any front-end page: the form may be in a shortcode).
	 * A correct one is remembered in a signed cookie and the page reloads (no resubmit prompt).
	 *
	 * @return void
	 */
	public static function maybe_unlock(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- A shared form password, not a logged-in action; rate limited.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! isset( $_POST[ self::FORM_FIELD ], $_POST[ self::PASSWORD_FIELD ] ) ) {
			return;
		}
		if ( ! doublescale_module_data_ready( 'forms' ) || ! Schema::pro_active() ) {
			return;
		}
		$form = FormModel::find_native_by_hash( sanitize_key( wp_unslash( $_POST[ self::FORM_FIELD ] ) ) );
		if ( ! $form || 'active' !== $form->status ) {
			return;
		}
		$password = Schema::normalize_settings( $form->settings )['access']['password'];
		$given    = (string) wp_unslash( $_POST[ self::PASSWORD_FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared, never stored or printed.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $password ) {
			return;
		}

		$key = 'forms_password_' . $form->id . '_' . ClientIp::bucket();
		if ( RateLimiter::ready() && RateLimiter::count( $key ) >= self::PASSWORD_TRIES ) {
			self::$error[ (int) $form->id ] = __( 'Too many tries. Please wait a few minutes.', 'doublescale' );
			return;
		}

		if ( ! hash_equals( $password, $given ) ) {
			if ( RateLimiter::ready() ) {
				RateLimiter::hit( $key, 15 * MINUTE_IN_SECONDS );
			}
			self::$error[ (int) $form->id ] = __( 'That password is not right.', 'doublescale' );
			return;
		}

		$expires = time() + self::UNLOCK_TTL;
		if ( ! headers_sent() ) {
			setcookie(
				self::cookie( $form ),
				$expires . '.' . self::signature( $form, $password, $expires ),
				array(
					'expires'  => $expires,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		wp_safe_redirect( self::current_url(), 303 );
		exit;
	}

	/**
	 * The error of the last password try on this page, per form.
	 *
	 * @var array<int, string>
	 */
	private static $error = array();

	/**
	 * The box shown in place of a closed form.
	 *
	 * @param FormModel $form  Form.
	 * @param array     $state From state().
	 * @return string
	 */
	public static function markup( FormModel $form, array $state ): string {
		$style = Schema::normalize_style( $form->style );
		$vars  = sprintf(
			'--dsf-primary:%1$s;--dsf-text:%2$s;--dsf-bg:%3$s;--dsf-field-bg:%4$s;--dsf-border:%5$s;--dsf-radius:%6$dpx;--dsf-font-size:%7$dpx;--dsf-btn-text:%8$s;--dsf-pad:%9$dpx',
			$style['primary'],
			$style['text'],
			$style['background'],
			$style['field_background'],
			$style['border'],
			(int) $style['radius'],
			(int) $style['font_size'],
			$style['button_text_color'],
			max( 24, (int) $style['padding'] )
		);
		$theme = 'inherit' === $style['theme'] ? 'dsf-theme-inherit' : 'dsf-theme-default';
		$open  = '<div class="dsf-root dsf-gate" data-dsf-gate="' . esc_attr( $state['reason'] ) . '">'
			. '<div class="dsf-form ' . esc_attr( $theme ) . '" style="' . esc_attr( $vars ) . '">';
		$close = '</div></div>';

		if ( 'password' === $state['reason'] ) {
			$error = self::$error[ (int) $form->id ] ?? '';
			$id    = 'dsf-pw-' . $form->hash;
			return $open
				. '<form class="dsf-gate__body" method="post">'
				. '<span class="dsf-gate__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg></span>'
				. '<h2 class="dsf-gate__title">' . esc_html( (string) $form->name ) . '</h2>'
				. '<label class="dsf-label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Enter the password to open this form', 'doublescale' ) . '</label>'
				. '<div class="dsf-gate__row">'
				. '<input class="dsf-input" type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::PASSWORD_FIELD ) . '" autocomplete="current-password" required' . ( '' !== $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '' ) . '>'
				. '<input type="hidden" name="' . esc_attr( self::FORM_FIELD ) . '" value="' . esc_attr( (string) $form->hash ) . '">'
				. '<button class="dsf-button dsf-button--primary" type="submit">' . esc_html__( 'Open form', 'doublescale' ) . '</button>'
				. '</div>'
				. ( '' !== $error ? '<p class="dsf-error" id="' . esc_attr( $id ) . '-error" role="alert">' . esc_html( $error ) . '</p>' : '' )
				. '</form>'
				. $close;
		}

		$action = '';
		if ( 'login' === $state['reason'] ) {
			$action = '<p class="dsf-gate__actions"><a class="dsf-button dsf-button--primary" href="' . esc_url( wp_login_url( self::current_url() ) ) . '">' . esc_html__( 'Log in', 'doublescale' ) . '</a></p>';
		}
		$glyphs = array(
			'early'  => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4.2l2.8 1.8"/>',
			'closed' => '<rect x="4.5" y="5.5" width="15" height="14" rx="2.5"/><path d="M4.5 9.5h15M9 3.5v3M15 3.5v3M10 13l4 4M14 13l-4 4"/>',
			'full'   => '<rect x="4.5" y="5.5" width="15" height="14" rx="2.5"/><path d="M4.5 9.5h15M9 3.5v3M15 3.5v3M10 13l4 4M14 13l-4 4"/>',
			'login'  => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5.5 19.5a6.5 6.5 0 0 1 13 0"/>',
		);
		$icon = isset( $glyphs[ $state['reason'] ] )
			? '<span class="dsf-gate__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $glyphs[ $state['reason'] ] . '</svg></span>'
			: '';
		return $open
			. '<div class="dsf-gate__body" role="status">' . $icon . '<div class="dsf-gate__message">' . wp_kses_post( $state['message'] ) . '</div>' . $action . '</div>'
			. $close;
	}

	/**
	 * The error the submit endpoint returns for a closed form.
	 *
	 * @param array $state From state().
	 * @return array{status: int, body: array}
	 */
	public static function reply( array $state ): array {
		$messages = array(
			'early'    => __( 'This form is not open yet.', 'doublescale' ),
			'closed'   => __( 'This form is closed.', 'doublescale' ),
			'full'     => __( 'This form is closed.', 'doublescale' ),
			'login'    => __( 'Please log in to fill in this form.', 'doublescale' ),
			'password' => __( 'Please reload the page and enter the password.', 'doublescale' ),
		);
		$text = wp_strip_all_tags( $state['message'] );
		return array(
			'status' => 'login' === $state['reason'] || 'password' === $state['reason'] ? 403 : 410,
			'body'   => array(
				'code'    => 'form_' . $state['reason'],
				'message' => '' !== trim( $text ) ? $text : ( $messages[ $state['reason'] ] ?? $messages['closed'] ),
			),
		);
	}

	/**
	 * Whether this browser entered the form's current password.
	 *
	 * @param FormModel $form     Form.
	 * @param string    $password Current password.
	 * @return bool
	 */
	private static function unlocked( FormModel $form, string $password ): bool {
		// Signed value (expiry + HMAC of form, expiry and password), checked with hash_equals below.
		$value = isset( $_COOKIE[ self::cookie( $form ) ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::cookie( $form ) ] ) ) : '';
		$parts = explode( '.', $value, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() ) {
			return false;
		}
		return hash_equals( self::signature( $form, $password, (int) $parts[0] ), $parts[1] );
	}

	/**
	 * The address of this request (works when WordPress sits in a sub-folder).
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return '' !== $host ? set_url_scheme( 'http://' . $host . $path ) : home_url( '/' );
	}

	/**
	 * Cookie name for a form.
	 *
	 * @param FormModel $form Form.
	 * @return string
	 */
	private static function cookie( FormModel $form ): string {
		return 'dsf_unlock_' . (int) $form->id;
	}

	/**
	 * Signature binding form, password and expiry: changing the password locks everyone out.
	 *
	 * @param FormModel $form     Form.
	 * @param string    $password Password.
	 * @param int       $expires  Expiry.
	 * @return string
	 */
	private static function signature( FormModel $form, string $password, int $expires ): string {
		return hash_hmac( 'sha256', 'unlock|' . (int) $form->id . '|' . $expires . '|' . $password, wp_salt( 'auth' ) . 'doublescale-forms' );
	}

	/**
	 * A closed state.
	 *
	 * @param string $reason  Why.
	 * @param string $message Manager's message.
	 * @return array{open: bool, reason: string, message: string}
	 */
	private static function closed( string $reason, string $message ): array {
		return array(
			'open'    => false,
			'reason'  => $reason,
			'message' => $message,
		);
	}
}
