<?php
/**
 * Whether the connected AI provider actually works.
 *
 * "Configured" (Settings → AI has a provider and a key) is not enough: a key
 * that was revoked or mistyped, a model the account can't use, or an account
 * out of credit makes every answer fail. When the provider refuses a request
 * for one of those reasons, the setup is marked as failing:
 *
 *   - the AI assistant page shows the "AI isn't working" warning with the
 *     provider's reason, the same place as "AI is not set up yet";
 *   - customers are not sent to an AI that can't answer: the chat goes to
 *     the team (live chat) or offers a ticket, as with no AI at all.
 *
 * Timeouts, provider outages (5xx) and ordinary rate limits are not setup
 * problems and never mark it. The mark clears when a request succeeds (for
 * example Settings → AI loading the model list with the key), when the AI
 * settings change, or after RETRY_AFTER, when the next question tries again.
 *
 * @package DoubleScale\Pro\Modules\Ai
 */

namespace DoubleScale\Pro\Modules\Ai;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * AiHealth.
 */
final class AiHealth {

	/** The last setup failure: code, message, at, and which settings it was for. */
	public const OPTION = 'doublescale_ai_health';

	/** A failing setup is tried again after this long. */
	public const RETRY_AFTER = 30 * MINUTE_IN_SECONDS;

	/** HTTP statuses that mean the setup itself is wrong. */
	private const SETUP_STATUSES = array( 401, 402, 403, 404 );

	/** Words in a 400 / 429 answer that point at the setup, not the request. */
	private const SETUP_WORDS = '/api[ _-]?key|invalid.{0,20}key|incorrect.{0,20}key|unauthori[sz]ed|permission|quota|billing|credit|payment|insufficient|model.{0,40}(not|does not|doesn.t) (found|exist|support)|no such model|unknown model/i';

	/**
	 * Look at an AI result: a setup failure marks the setup as failing, a
	 * success clears the mark. Anything else (timeouts, outages) leaves it.
	 *
	 * @param mixed $result What the provider call returned.
	 * @return mixed The same result.
	 */
	public static function observe( $result ) {
		if ( $result instanceof WP_Error ) {
			if ( self::is_setup_error( $result ) ) {
				self::failed( $result );
			}
			return $result;
		}

		self::ok();
		return $result;
	}

	/**
	 * Whether an error comes from the setup (key, model, credit), not the moment.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public static function is_setup_error( WP_Error $error ): bool {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'invalid_provider', 'missing_base_url' ), true ) ) {
			return true;
		}
		// A provider's answer with an HTTP status: chat calls (ai_api_error)
		// and the model list (openai_error, anthropic_error…).
		if ( 'ai_api_error' !== $code && ! preg_match( '/^(openai|anthropic|gemini|custom|compatible)_error$/', (string) $code ) ) {
			return false;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		if ( in_array( $status, self::SETUP_STATUSES, true ) ) {
			return true;
		}

		return in_array( $status, array( 400, 429 ), true ) && 1 === preg_match( self::SETUP_WORDS, $error->get_error_message() );
	}

	/**
	 * @param WP_Error $error The provider's refusal.
	 * @return void
	 */
	public static function failed( WP_Error $error ): void {
		update_option(
			self::OPTION,
			array(
				'message' => mb_substr( self::redact( $error->get_error_message() ), 0, 300, 'UTF-8' ),
				'at'      => time(),
				'for'     => self::fingerprint(),
			),
			false
		);
	}

	/**
	 * A request worked: the setup is fine.
	 *
	 * @return void
	 */
	public static function ok(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Whether the current setup is known to fail (and not due for a retry).
	 *
	 * @return bool
	 */
	public static function failing(): bool {
		return '' !== self::problem();
	}

	/**
	 * The provider's reason the current setup fails, or ''.
	 *
	 * @return string
	 */
	public static function problem(): string {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['at'] ) ) {
			return '';
		}
		// Other settings since, or time to try again.
		if ( ( $state['for'] ?? '' ) !== self::fingerprint() || time() - (int) $state['at'] >= self::RETRY_AFTER ) {
			return '';
		}

		// Redacted again on the way out: older records kept the raw text.
		$message = self::redact( (string) ( $state['message'] ?? '' ) );

		return '' !== $message ? $message : __( 'The AI provider refused the request.', 'doublescale' );
	}

	/**
	 * A provider's message without anything that looks like a key or token:
	 * an invalid-key answer quotes the key's first and last characters, and
	 * the text is shown in the admin and written to logs.
	 *
	 * @param string $message Provider message.
	 * @return string Plain text, key-like parts replaced by [key].
	 */
	public static function redact( string $message ): string {
		$message = wp_strip_all_tags( $message );
		$message = (string) preg_replace( '/\bBearer\s+\S+/i', 'Bearer [key]', $message );
		// Provider key shapes (sk-..., AIza..., key-..., pk_/rk_...), and masked
		// ones such as sk-abc***xyz.
		$message = (string) preg_replace( '/\b(?:sk|AIza|key|pk|rk)[-_A-Za-z0-9*.]{6,}/', '[key]', $message );
		// Any other long unbroken token with digits and letters.
		$message = (string) preg_replace( '/\b(?=[A-Za-z_-]*\d)(?=[0-9_-]*[A-Za-z])[A-Za-z0-9_-]{24,}\b/', '[key]', $message );

		return trim( $message );
	}

	/**
	 * The AI settings a failure was recorded for (never the key itself).
	 *
	 * @return string
	 */
	private static function fingerprint(): string {
		$ai = class_exists( '\\DoubleScale\\Core\\Settings\\Settings' ) ? \DoubleScale\Core\Settings\Settings::get( 'ai', array() ) : array();
		$ai = is_array( $ai ) ? $ai : array();

		return md5( (string) ( $ai['provider'] ?? '' ) . '|' . (string) ( $ai['model'] ?? '' ) . '|' . (string) ( $ai['base_url'] ?? '' ) . '|' . (string) ( $ai['api_key'] ?? '' ) );
	}
}
