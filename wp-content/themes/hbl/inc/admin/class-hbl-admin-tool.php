<?php
/**
 * Shared helper for the HBL admin tools (Place ID Manager, Email Finder, …).
 *
 * Gives every AJAX handler the same safety net:
 *   - nonce and capability checks that answer with a clear, structured JSON error
 *     (instead of WordPress's bare "-1" / "0"),
 *   - exceptions caught and logged, then returned as a readable message,
 *   - PHP fatals and stray notices turned into valid JSON, so the browser never
 *     fails with "Unexpected token < in JSON",
 *   - one error shape: { success: false, data: { message, code, … } }.
 *
 * The browser half lives in js/hbl-admin-notify.js (toasts + request wrapper).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Admin_Tool {

	/** @var bool Output buffering + shutdown guard installed for this request. */
	private static $started = false;

	/** @var int Output-buffer level before we started ours. */
	private static $level = 0;

	/** @var bool A JSON response has already been sent. */
	private static $responded = false;

	/**
	 * Loads the shared notification script and styles. Call from the tool's
	 * admin_enqueue_scripts callback. The script goes in <head> so it exists
	 * before the page's inline script runs.
	 */
	public static function enqueue_assets(): void {
		self::register_assets();
		wp_enqueue_style( 'hbl-admin-notify' );
		wp_enqueue_script( 'hbl-admin-notify' );
	}

	/**
	 * Registers (does not load) the shared assets on every admin page, so a tool's
	 * own script can simply list 'hbl-admin-notify' as a dependency.
	 */
	public static function register_assets(): void {
		if ( ! wp_style_is( 'hbl-admin-notify', 'registered' ) ) {
			wp_register_style( 'hbl-admin-notify', HBL_THEME_URI . '/inc/admin/css/hbl-admin-notify.css', array(), HBL_VERSION );
		}
		if ( ! wp_script_is( 'hbl-admin-notify', 'registered' ) ) {
			wp_register_script( 'hbl-admin-notify', HBL_THEME_URI . '/inc/admin/js/hbl-admin-notify.js', array(), HBL_VERSION, false );
		}
	}

	/**
	 * Verifies the nonce and capability. Fails with a structured JSON error
	 * (HTTP 403) instead of dying with "-1".
	 */
	public static function guard( string $nonce_action, string $capability = 'manage_options' ): void {
		self::start();

		if ( ! is_user_logged_in() ) {
			self::fail( 'You are logged out. Log in again, then reload this page.', 'logged_out', 403 );
		}
		if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
			self::fail( 'Your session has expired or the security token is no longer valid. Reload this page and try again.', 'bad_nonce', 403 );
		}
		if ( ! current_user_can( $capability ) ) {
			self::fail( 'You do not have permission to use this tool.', 'forbidden', 403 );
		}
	}

	/**
	 * Runs a handler body, converting any exception into a logged, readable
	 * JSON error.
	 *
	 * @param callable $fn    The handler body. Must end by calling ok() or fail().
	 * @param string   $label Short name used in the PHP error log.
	 */
	public static function run( callable $fn, string $label ): void {
		self::start();
		try {
			call_user_func( $fn );
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[HBL %s] %s in %s:%d', $label, $e->getMessage(), $e->getFile(), $e->getLine() ) );
			self::fail(
				'Something went wrong on the server: ' . $e->getMessage() . '. The details were written to the PHP error log.',
				'exception',
				500
			);
		}
		// A handler that returns without answering is a bug — say so rather than send an empty 200.
		self::fail( 'The server finished without returning a result.', 'no_response', 500 );
	}

	/**
	 * Sends a success response. Discards any stray output first.
	 *
	 * @param mixed $data
	 */
	public static function ok( $data = null ): void {
		self::clean();
		self::$responded = true;
		wp_send_json_success( $data );
	}

	/**
	 * Sends an error response in the standard shape.
	 *
	 * @param string|array|\WP_Error $message Human-readable, safe to show to the admin.
	 * @param string $code    Machine-readable reason (the browser can act on it, e.g. "rate_limited").
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Extra fields merged into data.
	 */
	public static function fail( $message, string $code = 'error', int $status = 400, array $extra = array() ): void {
		// Older handlers pass array( 'message' => … ) or a WP_Error; accept both.
		if ( is_wp_error( $message ) ) {
			$code    = $message->get_error_code() ?: $code;
			$message = $message->get_error_message();
		} elseif ( is_array( $message ) ) {
			$extra   = array_merge( $message, $extra );
			$message = (string) ( $message['message'] ?? 'Something went wrong.' );
			unset( $extra['message'] );
			if ( isset( $extra['code'] ) ) {
				$code = (string) $extra['code'];
				unset( $extra['code'] );
			}
		}
		$message = (string) $message;
		if ( '' === $message ) {
			$message = 'Something went wrong.';
		}

		self::clean();
		self::$responded = true;
		wp_send_json_error( array_merge( array( 'message' => $message, 'code' => $code ), $extra ), $status );
	}

	/**
	 * Maps a failed wp_remote_* call to a short, plain-English reason.
	 *
	 * @param \WP_Error|array $response
	 * @return array{message:string,code:string}
	 */
	public static function describe_http_failure( $response ): array {
		if ( is_wp_error( $response ) ) {
			$raw = $response->get_error_message();
			if ( false !== stripos( $raw, 'timed out' ) || false !== stripos( $raw, 'timeout' ) ) {
				return array( 'message' => 'The site took too long to respond (timed out).', 'code' => 'timeout' );
			}
			if ( false !== stripos( $raw, 'ssl' ) || false !== stripos( $raw, 'certificate' ) ) {
				return array( 'message' => 'The site has an invalid or expired SSL certificate.', 'code' => 'ssl' );
			}
			if ( false !== stripos( $raw, 'resolve host' ) || false !== stripos( $raw, 'could not resolve' ) ) {
				return array( 'message' => 'The website address could not be found (DNS lookup failed).', 'code' => 'dns' );
			}
			return array( 'message' => 'Could not connect: ' . $raw, 'code' => 'connect' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		switch ( true ) {
			case 401 === $status:
			case 403 === $status:
				return array( 'message' => "The site refused automated access (HTTP {$status}).", 'code' => 'blocked' );
			case 404 === $status:
			case 410 === $status:
				return array( 'message' => "The page was not found (HTTP {$status}).", 'code' => 'not_found' );
			case 429 === $status:
				return array( 'message' => 'The site is rate-limiting this server (HTTP 429). Wait a few minutes and try again.', 'code' => 'rate_limited' );
			case $status >= 500:
				return array( 'message' => "The site had a server error (HTTP {$status}).", 'code' => 'remote_error' );
			default:
				return array( 'message' => "Unexpected response (HTTP {$status}).", 'code' => 'bad_status' );
		}
	}

	/* ── Internals ───────────────────────────────────────────────────────── */

	private static function start(): void {
		if ( self::$started ) {
			return;
		}
		self::$started = true;
		// Never let PHP print notices into the JSON reply (they are logged instead).
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		self::$level   = ob_get_level();
		ob_start();
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	private static function clean(): void {
		if ( ! self::$started ) {
			return;
		}
		while ( ob_get_level() > self::$level ) {
			ob_end_clean();
		}
	}

	/**
	 * Turns a PHP fatal (memory, timeout, parse error) into a JSON reply.
	 */
	public static function on_shutdown(): void {
		if ( self::$responded ) {
			return;
		}
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}

		error_log( sprintf( '[HBL Admin Tool] Fatal: %s in %s:%d', $error['message'], $error['file'], $error['line'] ) );

		while ( ob_get_level() > self::$level ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			status_header( 500 );
			header( 'Content-Type: application/json; charset=utf-8' );
		}

		$hint = ( false !== stripos( $error['message'], 'Maximum execution time' ) )
			? 'The request ran out of time on the server. Try a smaller batch.'
			: ( ( false !== stripos( $error['message'], 'memory' ) )
				? 'The server ran out of memory. Try a smaller batch or file.'
				: 'The server hit a fatal PHP error. The details were written to the PHP error log.' );

		echo wp_json_encode( array(
			'success' => false,
			'data'    => array( 'message' => $hint, 'code' => 'fatal' ),
		) );
	}
}

add_action( 'admin_enqueue_scripts', array( 'HBL_Admin_Tool', 'register_assets' ), 1 );
