<?php
/**
 * Merges extra data into the next SMTP email log row (context column).
 *
 * Call {@see self::push()} before `wp_mail` / provider send and {@see self::pop()} after.
 * Used by campaign sending so the log can link back to the campaign or email sequence in admin.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\EmailLog;

defined( 'ABSPATH' ) || exit;

/**
 * Email log context stack.
 */
class EmailLogContext {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private static $stack = array();

	/**
	 * @param array<string, mixed> $context Partial context merged with {@see array_replace_recursive()}.
	 */
	public static function push( array $context ) {
		self::$stack[] = $context;
	}

	/**
	 * @return void
	 */
	public static function pop() {
		array_pop( self::$stack );
	}

	/**
	 * @param array<string, mixed> $base Base context.
	 * @return array<string, mixed>
	 */
	public static function merge_stack( array $base ) {
		foreach ( self::$stack as $layer ) {
			$base = array_replace_recursive( $base, $layer );
		}
		return $base;
	}
}
