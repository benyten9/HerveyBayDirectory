<?php
/**
 * MCP surface for DoubleScale.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\Mcp\Endpoint;

/**
 * Owns the on/off state and route wiring for the MCP endpoint.
 *
 * The protocol itself lives in {@see Endpoint} and is implemented in this
 * plugin. DoubleScale deliberately depends on NO third-party MCP adapter: a
 * vendor plugin being deactivated, updated, or dropped must never take this
 * integration down with it.
 */
final class McpServer {

	/**
	 * Operator switch for the MCP endpoint.
	 *
	 * Separate from the abilities kill switch: turning MCP off must not
	 * disable the in-site abilities, which the admin and REST callers use.
	 */
	public const ENABLE_OPTION = 'doublescale_mcp_enabled';

	/**
	 * Wire the endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action(
			'rest_api_init',
			static function () {
				if ( self::is_enabled() ) {
					Endpoint::register_routes();
				}
			}
		);
	}

	/**
	 * Whether the MCP endpoint is switched on. Defaults to OFF.
	 *
	 * Publishing CRM data to external agents is opt-in: an admin turns it on
	 * deliberately rather than discovering after the fact that their contacts
	 * became reachable.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLE_OPTION, false );
	}

	/**
	 * Public endpoint URL clients connect to.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function endpoint_url(): string {
		return Endpoint::url();
	}

	/**
	 * Ability names this endpoint publishes.
	 *
	 * Reads the live registry, so a module switched off (whose abilities were
	 * never registered) contributes nothing here either.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	public static function tool_names(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = is_object( $ability ) && method_exists( $ability, 'get_name' )
				? (string) $ability->get_name()
				: '';

			if ( '' !== $name && 0 === strpos( $name, AbilityRegistrar::NAMESPACE_PREFIX ) ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}
}
