<?php
/**
 * Spam gate for form submissions: honeypot, time trap, rate limit, CAPTCHA and email domains.
 *
 * Bots that trip the honeypot or the time trap get a normal "thank you", so they learn
 * nothing; their submission is kept as spam (no contact) and deleted after 30 days.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;

/**
 * Spam checks.
 */
final class Spam {

	public const HONEYPOT = '_dsf_hp';
	public const ELAPSED  = '_dsf_t';
	public const ISSUED   = '_dsf_issued';
	public const CAPTCHA  = '_dsf_captcha';

	public const OK      = 'ok';
	public const SPAM    = 'spam';

	/** The page was handed out too long ago (a cached page): send again with a fresh token. */
	public const EXPIRED = 'expired';
	public const LIMITED = 'limited';
	public const CAPTCHA_FAILED = 'captcha';
	public const BLOCKED = 'blocked';

	/**
	 * Disposable email domains blocked when "Block disposable emails" is on.
	 */
	private const DISPOSABLE = array(
		'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', '10minutemail.net',
		'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'yopmail.com', 'yopmail.net', 'trashmail.com', 'trashmail.de',
		'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'mailnesia.com', 'mintemail.com', 'throwawaymail.com',
		'fakeinbox.com', 'mohmal.com', 'emailondeck.com', 'tempinbox.com', 'mailcatch.com', 'spamgourmet.com', 'moakt.com',
		'tempr.email', 'discard.email', 'burnermail.io', 'mail.tm', 'linshiyouxiang.net', 'trbvm.com', 'byom.de', 'mytemp.email',
		'tmpmail.org', 'tmpmail.net', 'emailfake.com', 'fakemail.net', 'mailpoof.com', 'inboxkitten.com', 'spam4.me',
		'grr.la', 'guerrillamailblock.com', 'pokemail.net', 'getairmail.com', 'dropmail.me', 'tempail.com', 'mail7.io',
	);

	/**
	 * Run the checks.
	 *
	 * @param int    $form_id  Form row id.
	 * @param array  $settings The form's spam settings.
	 * @param array  $input    The request body.
	 * @param string $email    Email given in the answers ('' if none).
	 * @return array{verdict: string, reason: string}
	 */
	public static function check( int $form_id, array $settings, array $input, string $email ): array {
		// Every request counts, bots included, before any other verdict.
		$max = max( 1, (int) ( $settings['max_per_hour'] ?? 20 ) );
		if ( RateLimiter::ready() && RateLimiter::hit( 'forms_submit_' . $form_id . '_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > $max ) {
			return self::verdict( self::LIMITED, 'rate_limit' );
		}

		if ( ! empty( $settings['honeypot'] ) && '' !== trim( (string) ( $input[ self::HONEYPOT ] ?? '' ) ) ) {
			return self::verdict( self::SPAM, 'honeypot' );
		}

		$min = (int) ( $settings['min_seconds'] ?? 0 );
		if ( $min > 0 ) {
			// The server signed when it handed the form out; the browser cannot shorten that.
			$age = Tokens::issued_age( (string) ( $input[ self::ISSUED ] ?? '' ), $form_id );
			if ( null === $age ) {
				return self::verdict( self::SPAM, 'no_token' );
			}
			if ( $age < $min ) {
				return self::verdict( self::SPAM, 'too_fast' );
			}
			if ( $age > Tokens::ISSUED_TTL ) {
				return self::verdict( self::EXPIRED, 'expired' );
			}
		}

		$blocked = self::blocked_email( $settings, $email );
		if ( '' !== $blocked ) {
			return self::verdict( self::BLOCKED, $blocked );
		}

		$provider = (string) ( $settings['captcha'] ?? 'none' );
		if ( 'none' !== $provider && '' !== GlobalSettings::site_key( $provider ) ) {
			if ( ! self::verify_captcha( $provider, (string) ( $input[ self::CAPTCHA ] ?? '' ) ) ) {
				return self::verdict( self::CAPTCHA_FAILED, 'captcha' );
			}
		}

		return self::verdict( self::OK, '' );
	}

	/**
	 * Ask the CAPTCHA provider whether the visitor's token is good.
	 *
	 * @param string $provider turnstile, recaptcha or hcaptcha.
	 * @param string $token    Token from the widget.
	 * @return bool
	 */
	private static function verify_captcha( string $provider, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		$settings = GlobalSettings::get();
		$secret   = (string) ( $settings['captcha'][ $provider ]['secret_key'] ?? '' );
		$urls     = array(
			'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
			'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
		);
		if ( '' === $secret || ! isset( $urls[ $provider ] ) ) {
			return false;
		}

		$response = wp_remote_post(
			$urls[ $provider ],
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => ClientIp::get(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			// The provider is down: let the visitor through rather than lose the lead.
			doublescale_get_logger()->warning( 'Form CAPTCHA check failed to connect', array( 'provider' => $provider, 'error' => $response->get_error_message() ) );
			return true;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['success'] ) ) {
			return false;
		}
		// A token solved on another site is no good here.
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ! empty( $body['hostname'] ) && '' !== $host && strtolower( (string) $body['hostname'] ) !== $host ) {
			return false;
		}
		if ( 'recaptcha' === $provider ) {
			if ( isset( $body['action'] ) && 'form_submit' !== $body['action'] ) {
				return false;
			}
			if ( isset( $body['score'] ) ) {
				return (float) $body['score'] >= (float) $settings['captcha']['recaptcha_threshold'];
			}
		}
		return true;
	}

	/**
	 * Whether a spam submission should still be kept for review. A few per visitor an hour
	 * and a few hundred per form a day are plenty to spot a false positive; a flood isn't stored.
	 *
	 * @param int $form_id Form row id.
	 * @return bool
	 */
	/**
	 * Why an email address is refused by the form's spam settings ('' when it is fine).
	 *
	 * @param array  $settings The form's spam settings.
	 * @param string $email    Address (may be empty).
	 * @return string 'disposable', 'blocked_domain' or ''.
	 */
	public static function blocked_email( array $settings, string $email ): string {
		$domain = '' !== $email && false !== strpos( $email, '@' ) ? strtolower( (string) substr( strrchr( $email, '@' ), 1 ) ) : '';
		if ( '' === $domain ) {
			return '';
		}
		if ( ! empty( $settings['block_disposable'] ) && in_array( $domain, self::DISPOSABLE, true ) ) {
			return 'disposable';
		}
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) ( $settings['blocked_domains'] ?? '' ) ) ) as $blocked ) {
			$blocked = ltrim( trim( $blocked ), '@' );
			if ( '' !== $blocked && ( $domain === $blocked || substr( $domain, -strlen( '.' . $blocked ) ) === '.' . $blocked ) ) {
				return 'blocked_domain';
			}
		}
		return '';
	}

	public static function keep_spam( int $form_id ): bool {
		if ( ! RateLimiter::ready() ) {
			return true;
		}
		if ( RateLimiter::hit( 'forms_spam_' . ClientIp::bucket(), HOUR_IN_SECONDS ) > 5 ) {
			return false;
		}
		return RateLimiter::hit( 'forms_spam_form_' . $form_id, DAY_IN_SECONDS ) <= 500;
	}

	/**
	 * A verdict.
	 *
	 * @param string $verdict Verdict.
	 * @param string $reason  Reason.
	 * @return array{verdict: string, reason: string}
	 */
	private static function verdict( string $verdict, string $reason ): array {
		return array(
			'verdict' => $verdict,
			'reason'  => $reason,
		);
	}
}
