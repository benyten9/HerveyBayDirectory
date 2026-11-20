<?php
/**
 * Meta's WhatsApp error codes, as the engine that owns WhatsApp knows them.
 *
 * The codes live with the WhatsApp engine: free's copy in
 * Core\Constants\MetaWhatsappErrorCodes, Pro's in its own Campaigns\Whatsapp.
 * Free's copy is deleted once every site runs a Pro that has its own, so
 * free's shared campaign code (failure causes, the recipient cooldown, the
 * resend gate) never names it: it asks the engine that owns WhatsApp which
 * class to read, and falls back to safe answers when nothing owns it.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;

/**
 * WhatsappErrorCodes.
 */
final class WhatsappErrorCodes {

	/**
	 * The error-codes class of the engine that owns WhatsApp.
	 *
	 * @return string Class name with MetaWhatsappErrorCodes' static API.
	 */
	public static function source(): string {
		$engine = ChannelEngineRegistry::get( CampaignChannel::STR_WHATSAPP );

		if ( $engine && method_exists( $engine, 'error_codes' ) ) {
			$class = $engine->error_codes();
			if ( is_string( $class ) && class_exists( $class ) ) {
				return $class;
			}
		}

		return NoWhatsappErrorCodes::class;
	}

	/**
	 * Label for grouped failure causes.
	 *
	 * @param string|int $code Error code.
	 * @return string
	 */
	public static function label( $code ): string {
		$class = self::source();

		return (string) $class::get_label( $code );
	}

	/**
	 * Whether a code means the recipient opted out (never worth retrying).
	 *
	 * @param int $code Error code.
	 * @return bool
	 */
	public static function is_opt_out( int $code ): bool {
		$class = self::source();

		return (bool) $class::is_opt_out_error( $code );
	}

	/**
	 * Whether a code is Meta's per-recipient marketing cap.
	 *
	 * @param string|int $code Error code.
	 * @return bool
	 */
	public static function is_recipient_cooldown( $code ): bool {
		$class = self::source();

		return (bool) $class::is_recipient_cooldown( $code );
	}

	/**
	 * How long a capped recipient must wait, in seconds.
	 *
	 * @return int
	 */
	public static function recipient_cooldown_seconds(): int {
		$class = self::source();

		return (int) $class::recipient_cooldown_seconds();
	}

	/**
	 * The codes that put a recipient in cooldown.
	 *
	 * @return array<int, int>
	 */
	public static function recipient_cooldown_codes(): array {
		$class = self::source();

		return (array) $class::RECIPIENT_COOLDOWN_CODES;
	}

	/**
	 * Whether a code means Meta restricted the sending account.
	 *
	 * @param string|int $code Error code.
	 * @return bool
	 */
	public static function is_account_restriction( $code ): bool {
		$class = self::source();

		return (bool) $class::is_account_restriction( $code );
	}
}
