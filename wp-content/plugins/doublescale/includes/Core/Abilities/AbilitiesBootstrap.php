<?php
/**
 * Wires the abilities layer into WordPress.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The only file in the abilities layer that touches add_action().
 *
 * Keeping the hooks in one place makes the guard rails auditable: the
 * function_exists() check for WP < 6.9, the operator kill switch, and the
 * mandatory categories-before-abilities ordering.
 */
final class AbilitiesBootstrap {

	/**
	 * Operator kill switch. Absent/falsey means the layer is enabled.
	 */
	public const DISABLE_OPTION = 'doublescale_disable_abilities';

	/**
	 * Called from CoreModule::boot(), which runs at plugins_loaded:5.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'maybe_hook' ), 4 );
	}

	/**
	 * Attach the registration hooks unless something says otherwise.
	 *
	 * Runs at init:4 so the listeners exist before WP core lazily builds the
	 * ability registry and fires wp_abilities_api_init during init:10. Priority
	 * 4 rather than 5 leaves room for a plugin that wants to filter our
	 * definitions in between.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_hook(): void {
		// WP < 6.9, or a site where the Abilities API is unavailable.
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// Option rather than a constant so it stays flippable from the admin
		// without a deploy — one call disables the whole surface.
		if ( get_option( self::DISABLE_OPTION ) ) {
			return;
		}

		/**
		 * Filter whether the DoubleScale abilities layer registers at all.
		 *
		 * Last-resort switch for hosts that must block registration before any
		 * work happens.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether to register abilities.
		 */
		if ( ! apply_filters( 'doublescale_abilities_enabled', true ) ) {
			return;
		}

		// Categories MUST be registered on their own earlier hook: an ability
		// naming an unregistered category is dropped silently by WP core.
		add_action( 'wp_abilities_api_categories_init', array( AbilityCategories::class, 'register' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

		// Publish the same abilities over MCP when an adapter plugin is present
		// AND an admin has opted in. Silent no-op otherwise — the abilities
		// stay reachable over plain REST either way.
		McpServer::init();
	}

	/**
	 * Register free abilities, then let Pro add its own.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		AbilityRegistrar::register_all();

		/**
		 * Fires after the free abilities are registered.
		 *
		 * Pro registers additional abilities under the same `doublescale/`
		 * namespace so an agent never needs to know which plugin owns which
		 * tool. Pro module classes that implement ProvidesAbilities are already
		 * collected automatically — this hook is for abilities with no owning
		 * module. Use AbilityRegistrar::register_definitions() so the gate
		 * stack is applied; never call wp_register_ability() directly.
		 *
		 * @since 1.0.0
		 */
		do_action( 'doublescale_abilities_registered' );
	}
}
