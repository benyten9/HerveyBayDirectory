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

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

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
	 * Too many messages have been blocked or flagged as spam.
	 */
	const SPAM_RATE_LIMIT = 131048;

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
		self::SPAM_RATE_LIMIT,
		self::MESSAGE_UNDELIVERABLE,
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
	 * Check if an error code indicates user opt-out
	 *
	 * @param int $error_code Meta Api error code.
	 * @return bool True if this is an opt-out error.
	 */
	public static function is_opt_out_error( int $error_code ): bool {
		return in_array( $error_code, self::OPT_OUT_CODES, true );
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
		);

		return $messages[ $error_code ] ?? __( 'Unknown error occurred.', 'doublescale' );
	}
}
