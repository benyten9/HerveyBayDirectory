<?php
/**
 * Meta WhatsApp Error Codes
 *
 * Constants for Meta WhatsApp Business Api error codes.
 * Used for detecting opt-out scenarios and handling delivery failures.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

defined( 'ABSPATH' ) || exit;

/*
 * Pro's copy of free's Meta error-code constants. It moved with the
 * WhatsApp engine; free keeps its own, frozen, for sites whose Pro predates this.
 */

/**
 * MetaWhatsappErrorCodes class
 *
 * Provides constants for Meta WhatsApp Business Api error codes.
 * These codes are used by the MetaWhatsappProvider to detect
 * when a user has opted out or blocked the business.
 */
class MetaWhatsappErrorCodes {

	/**
	 * Message blocked by "Offers & Announcements" experiment
	 * User has opted out of promotional messages via WhatsApp settings.
	 */
	const OFFERS_ANNOUNCEMENTS_BLOCKED = 130472;

	/**
	 * User has blocked the business
	 * The recipient has blocked your WhatsApp Business Account.
	 */
	const USER_BLOCKED = 131031;

	/**
	 * Spam rate limit reached
	 * Meta has restricted how many messages the SENDING number may send,
	 * because too many earlier messages were blocked or flagged as spam. It is
	 * a verdict on the business account, not on the recipient.
	 */
	const SPAM_RATE_LIMIT = 131048;

	/**
	 * Per-user marketing message limit.
	 *
	 * Meta caps how many marketing template messages a person receives from
	 * ALL businesses in a period, and drops the ones over that cap with
	 * "This message was not delivered to maintain healthy ecosystem
	 * engagement." It is a verdict on the recipient's recent marketing volume,
	 * not on the sending number, and has nothing to do with the account's
	 * messaging tier: a 100,000-recipient account sending to 90 people can see
	 * it. The same person can be reached again once their own window moves, so
	 * resending immediately fails again and only burns quality.
	 */
	const MARKETING_FREQUENCY_CAP = 131049;

	/**
	 * Message undeliverable
	 * The message could not be delivered. User may have blocked or number is invalid.
	 */
	const MESSAGE_UNDELIVERABLE = 131026;

	/**
	 * Re-engagement message required
	 * 24-hour window expired; must use a template message.
	 */
	const REENGAGEMENT_REQUIRED = 131047;

	/**
	 * Template not found or not approved
	 * The template doesn't exist or hasn't been approved yet.
	 */
	const TEMPLATE_NOT_APPROVED = 132000;

	/**
	 * Account not registered
	 * The business sending number has not been registered with the Cloud Api.
	 * A number added to a WhatsApp Business Account stays "Pending" until it is
	 * explicitly registered; sending before that fails with this code.
	 */
	const ACCOUNT_NOT_REGISTERED = 133010;

	/**
	 * Invalid parameter
	 * A parameter in the request is invalid.
	 */
	const INVALID_PARAMETER = 100;

	/**
	 * Rate limit hit
	 * Too many Api calls in a short period.
	 */
	const RATE_LIMIT = 130429;

	/**
	 * Error codes that indicate user has opted out or blocked the business.
	 * These should trigger auto-unsubscribe in Plugin.
	 *
	 * @var array
	 */
	const OPT_OUT_CODES = array(
		self::OFFERS_ANNOUNCEMENTS_BLOCKED,
		self::USER_BLOCKED,
		self::MESSAGE_UNDELIVERABLE,
	);

	/**
	 * Error codes that mean Meta restricted the sending number itself.
	 *
	 * Not opt-outs: the recipient did nothing. Sending on after one of these is
	 * what gets a number blocked, so a campaign must stop at the first.
	 *
	 * @var array
	 */
	const ACCOUNT_RESTRICTION_CODES = array(
		self::SPAM_RATE_LIMIT,
	);

	/**
	 * Error codes that indicate a temporary/retriable error.
	 *
	 * @var array
	 */
	const RETRIABLE_CODES = array(
		self::RATE_LIMIT,
	);

	/**
	 * Codes where the recipient — not the account — must be left alone for a
	 * while. Retriable in a day, never in a minute.
	 *
	 * @var array
	 */
	const RECIPIENT_COOLDOWN_CODES = array(
		self::MARKETING_FREQUENCY_CAP,
	);

	/** How long a recipient is left alone after a cooldown code. */
	const RECIPIENT_COOLDOWN = DAY_IN_SECONDS;

	/**
	 * Check if an error code indicates user opt-out
	 *
	 * @param int $error_code Meta Api error code.
	 * @return bool True if this is an opt-out error.
	 */
	public static function is_opt_out_error( int $error_code ): bool {
		return in_array( $error_code, self::OPT_OUT_CODES, true );
	}

	/**
	 * Whether Meta restricted the sending number (not the recipient).
	 *
	 * @param int|string $error_code Meta Api error code.
	 * @return bool
	 */
	public static function is_account_restriction( $error_code ): bool {
		return in_array( (int) $error_code, self::ACCOUNT_RESTRICTION_CODES, true );
	}

	/**
	 * Whether this failure means "not this person, not yet".
	 *
	 * @param int|string $error_code Meta Api error code.
	 * @return bool
	 */
	public static function is_recipient_cooldown( $error_code ): bool {
		return in_array( (int) $error_code, self::RECIPIENT_COOLDOWN_CODES, true );
	}

	/**
	 * Seconds a recipient is left alone after a cooldown code.
	 *
	 * @return int
	 */
	public static function recipient_cooldown_seconds(): int {
		/**
		 * Filters how long a recipient is skipped after Meta's per-user
		 * marketing cap.
		 *
		 * @param int $seconds Cooldown length.
		 */
		$seconds = (int) apply_filters( 'doublescale_whatsapp_recipient_cooldown', self::RECIPIENT_COOLDOWN );

		return max( 0, $seconds );
	}

	/**
	 * Check if an error code is retriable
	 *
	 * @param int $error_code Meta Api error code.
	 * @return bool True if the error is retriable.
	 */
	public static function is_retriable_error( int $error_code ): bool {
		return in_array( $error_code, self::RETRIABLE_CODES, true );
	}

	/**
	 * Check if an error code means the sending number is not registered
	 *
	 * Distinct from opt-out/retriable: this is a one-time setup gap that the
	 * admin must fix by registering the phone number with the Cloud Api.
	 *
	 * @param int $error_code Meta Api error code.
	 * @return bool True if this is the "account not registered" error.
	 */
	public static function is_registration_error( int $error_code ): bool {
		return self::ACCOUNT_NOT_REGISTERED === $error_code;
	}

	/**
	 * Get human-readable opt-out reason from Meta error code
	 *
	 * @param int $error_code Meta error code.
	 * @return string Opt-out reason identifier.
	 */
	public static function get_opt_out_reason( int $error_code ): string {
		$reasons = array(
			self::OFFERS_ANNOUNCEMENTS_BLOCKED => 'meta_offers_announcements',
			self::USER_BLOCKED                 => 'meta_user_blocked',
			self::SPAM_RATE_LIMIT              => 'meta_spam_limit',
			self::MESSAGE_UNDELIVERABLE        => 'meta_undeliverable',
		);

		return $reasons[ $error_code ] ?? 'meta_unknown';
	}

	/**
	 * Get human-readable error message for display
	 *
	 * @param int $error_code Meta error code.
	 * @return string Human-readable error message.
	 */
	public static function get_error_message( int $error_code ): string {
		$messages = array(
			self::OFFERS_ANNOUNCEMENTS_BLOCKED => __( 'User has opted out of promotional messages.', 'doublescale' ),
			self::USER_BLOCKED                 => __( 'User has blocked this business.', 'doublescale' ),
			self::SPAM_RATE_LIMIT              => __( 'Too many messages flagged as spam.', 'doublescale' ),
			self::MESSAGE_UNDELIVERABLE        => __( 'Message could not be delivered.', 'doublescale' ),
			self::REENGAGEMENT_REQUIRED        => __( 'Conversation window expired. Use a template message.', 'doublescale' ),
			self::TEMPLATE_NOT_APPROVED        => __( 'Template not found or not approved.', 'doublescale' ),
			self::ACCOUNT_NOT_REGISTERED       => __( 'Your WhatsApp business number is not registered with Meta yet. Open Settings → Integrations → Meta WhatsApp and click "Register Number" to activate it.', 'doublescale' ),
			self::INVALID_PARAMETER            => __( 'Invalid parameter in request.', 'doublescale' ),
			self::RATE_LIMIT                   => __( 'Rate limit exceeded. Try again later.', 'doublescale' ),
			self::MARKETING_FREQUENCY_CAP      => __( 'Meta held this marketing message back because of how many marketing messages this person has already received — from any business. Your number and your daily limit are fine. They can be messaged again later.', 'doublescale' ),
		);

		return $messages[ $error_code ] ?? __( 'Unknown error occurred.', 'doublescale' );
	}

	/**
	 * Label for grouped failure causes in the campaign Messages tab.
	 *
	 * @param string|int $error_code Meta code, or a synthetic fallback.
	 * @return string
	 */
	public static function get_label( $error_code ): string {
		$code = (string) $error_code;
		if ( '' === $code ) {
			return __( 'Unknown reason', 'doublescale' );
		}

		if ( '130429_exhausted' === $code ) {
			return __( 'Rate limit exceeded after retries.', 'doublescale' );
		}

		if ( ctype_digit( $code ) ) {
			return self::get_error_message( (int) $code );
		}

		return __( 'Unknown reason', 'doublescale' );
	}
}
