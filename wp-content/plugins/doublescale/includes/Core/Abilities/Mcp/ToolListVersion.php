<?php
/**
 * Tracks changes to the published tool set.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities\Mcp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\McpServer;

/**
 * Detects when the published tool list has changed since a client last saw it.
 *
 * MCP clients fetch `tools/list` once at connect time and cache it. That cache
 * goes stale whenever the published set changes — a module toggled off, a
 * plugin update adding abilities — and the failure is silent and confusing: the
 * client reports the tool as non-existent without ever sending the request, so
 * server logs show nothing at all.
 *
 * This was observed in practice: a session connected while 13 tools were
 * published, 21 more were added, and the session reported all 21 as unavailable
 * and concluded the underlying modules were disabled. They were not.
 *
 * Our transport is stateless HTTP, so we cannot push a notification to an idle
 * client. What we CAN do is recognise a stale view on the next request the
 * client makes and tell it to refresh.
 */
final class ToolListVersion {

	/**
	 * Header a client may send with the version it last saw.
	 */
	public const REQUEST_HEADER = 'x_doublescale_tools_version';

	/**
	 * Header carrying the current version on every response.
	 */
	public const RESPONSE_HEADER = 'X-DoubleScale-Tools-Version';

	/**
	 * A short stable fingerprint of the published tool set.
	 *
	 * Derived from the names rather than stored, so it cannot drift out of sync
	 * with reality — the thing being described IS the source of the value.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function current(): string {
		$names = McpServer::tool_names();
		sort( $names );

		return substr( md5( implode( '|', $names ) ), 0, 12 );
	}

	/**
	 * Whether the caller is working from an out-of-date tool list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $presented Version the client last saw, if any.
	 * @return bool
	 */
	public static function is_stale( string $presented ): bool {
		$presented = trim( $presented );
		if ( '' === $presented ) {
			return false;
		}

		return $presented !== self::current();
	}
}
