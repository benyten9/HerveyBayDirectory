<?php
/**
 * The answers free gives about Meta error codes when no engine owns WhatsApp:
 * no Meta wording, no cooldown, nothing treated as an opt-out or restriction.
 * Same static API as MetaWhatsappErrorCodes, so WhatsappErrorCodes can hand
 * it out in its place.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

/**
 * NoWhatsappErrorCodes.
 */
final class NoWhatsappErrorCodes {

	const RECIPIENT_COOLDOWN_CODES = array();

	const ACCOUNT_RESTRICTION_CODES = array();

	/**
	 * @param string|int $error_code Error code.
	 * @return string
	 */
	public static function get_label( $error_code ): string {
		return __( 'Unknown reason', 'doublescale' );
	}

	/**
	 * @param int $error_code Error code.
	 * @return bool
	 */
	public static function is_opt_out_error( int $error_code ): bool {
		return false;
	}

	/**
	 * @param string|int $error_code Error code.
	 * @return bool
	 */
	public static function is_recipient_cooldown( $error_code ): bool {
		return false;
	}

	/**
	 * @return int
	 */
	public static function recipient_cooldown_seconds(): int {
		return 0;
	}

	/**
	 * @param string|int $error_code Error code.
	 * @return bool
	 */
	public static function is_account_restriction( $error_code ): bool {
		return false;
	}

	/**
	 * @param int $error_code Error code.
	 * @return bool
	 */
	public static function is_retriable_error( int $error_code ): bool {
		return false;
	}
}
