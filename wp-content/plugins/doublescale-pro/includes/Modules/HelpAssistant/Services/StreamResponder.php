<?php
/**
 * Server-Sent Events output for the help assistant's chat routes.
 *
 * The route sends its own headers, writes each event and flushes it, then
 * exits (WordPress's REST response would buffer everything). Compression is
 * switched off so proxies do not hold the stream, and a reader whose server
 * buffers the whole reply still parses it when it arrives at once.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

/**
 * StreamResponder class.
 */
final class StreamResponder {

	/**
	 * Headers for an unbuffered, uncached event stream.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// A reader who leaves stops the script at the next write; shutdown
		// handlers still run (they store what was written and free the slot).
		ignore_user_abort( false );
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
			header( 'X-Accel-Buffering: no' );
		}
	}

	/**
	 * One event.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data  Payload.
	 * @return void
	 */
	public static function emit( string $event, array $data ): void {
		echo 'event: ' . $event . "\n" . 'data: ' . wp_json_encode( array_merge( array( 'type' => $event ), $data ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON event stream.
		flush();
	}
}
