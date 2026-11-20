<?php
/**
 * Give every failed workflow step a reason.
 *
 * Many actions still return a bare `false` and explain themselves only in a
 * log line. While a step runs, this remembers the last error it logged — even
 * when the site's log level would not store it — so the engine can record it
 * on the step instead of an empty message.
 *
 * @package DoubleScale\Modules\Automations
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

/**
 * StepFailureReason helper.
 */
final class StepFailureReason {

	/** Levels worth showing as a step's failure reason. */
	const LEVELS = array( 'emergency', 'alert', 'critical', 'error', 'warning' );

	/** @var int Nested captures (a step can run another step inline). */
	private static $depth = 0;

	/** @var array<int, string> Last logged problem per capture level. */
	private static $last = array();

	/**
	 * Start remembering what the running step logs.
	 *
	 * @return void
	 */
	public static function begin(): void {
		if ( 0 === self::$depth ) {
			add_action( 'doublescale_log_entry', array( self::class, 'listen' ), 10, 3 );
		}
		++self::$depth;
		self::$last[ self::$depth ] = '';
	}

	/**
	 * Stop and return the last problem the step logged ('' when none).
	 *
	 * @return string
	 */
	public static function end(): string {
		if ( self::$depth <= 0 ) {
			return '';
		}
		$reason = self::$last[ self::$depth ] ?? '';
		unset( self::$last[ self::$depth ] );
		--self::$depth;
		if ( 0 === self::$depth ) {
			remove_action( 'doublescale_log_entry', array( self::class, 'listen' ), 10 );
		}
		return $reason;
	}

	/**
	 * Logger hook: keep the latest error-level message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 * @return void
	 */
	public static function listen( $level, $message, $context = array() ): void {
		if ( self::$depth <= 0 || ! in_array( strtolower( (string) $level ), self::LEVELS, true ) ) {
			return;
		}
		self::$last[ self::$depth ] = self::describe( (string) $message, is_array( $context ) ? $context : array() );
	}

	/**
	 * The reason to store when a step failed without giving one.
	 *
	 * @param string $logged    What the step logged ('' when nothing).
	 * @param string $step_name The action's name, e.g. "Send Email Sequence".
	 * @return string
	 */
	public static function fallback( string $logged, string $step_name ): string {
		if ( '' !== $logged ) {
			return $logged;
		}
		return sprintf(
			/* translators: %s: workflow step name, e.g. "Send Email Sequence". */
			__( 'The "%s" step failed without saying why. Check Settings → System → Debugging → Logs, or turn logging on there to see more next time.', 'doublescale' ),
			$step_name
		);
	}

	/**
	 * A log line plus its error detail, when the context carries one.
	 *
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 * @return string
	 */
	private static function describe( string $message, array $context ): string {
		$detail = $context['error'] ?? $context['message'] ?? '';
		if ( is_array( $detail ) ) {
			$detail = $detail['message'] ?? '';
		}
		$detail  = is_scalar( $detail ) ? trim( (string) $detail ) : '';
		$message = trim( $message );

		if ( '' !== $detail && false === strpos( $message, $detail ) ) {
			$message = '' === $message ? $detail : rtrim( $message, '.: ' ) . ': ' . $detail;
		}

		return mb_substr( $message, 0, 500 );
	}
}
