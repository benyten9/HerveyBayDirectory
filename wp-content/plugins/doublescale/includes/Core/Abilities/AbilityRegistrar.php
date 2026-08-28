<?php
/**
 * Walks the module registry and registers every declared ability.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\ModuleManager;

/**
 * Collects ability definitions from modules and hands them to WP core.
 *
 * Module authors write plain definition arrays; this class owns every
 * cross-cutting concern — the gates, the exception wrapper, REST visibility,
 * and the read-only annotation.
 */
final class AbilityRegistrar {

	/**
	 * Ability names must match this, per WP core
	 * (wp-includes/abilities-api/class-wp-abilities-registry.php:81).
	 * Exactly one forward slash; lowercase alphanumerics and dashes only.
	 */
	public const NAME_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

	/**
	 * Namespace every DoubleScale ability name carries.
	 *
	 * Free and Pro abilities share it deliberately — an agent should not need
	 * to know which plugin owns which tool.
	 */
	public const NAMESPACE_PREFIX = 'doublescale/';

	/**
	 * Register everything the enabled modules declare.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_all(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_definitions( self::collect() );
	}

	/**
	 * Find which module owns an ability, even when that module is switched off.
	 *
	 * Registration skips inactive modules so their tools vanish from tools/list,
	 * but MCP clients often keep a cached list. Resolving ownership here lets
	 * tools/call refuse with "module switched off" instead of "unknown tool".
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Full ability name (slash form).
	 * @return array{module_slug: string, module_label: string}|null
	 */
	public static function find_owner( string $name ): ?array {
		return self::find_owner_among( $name, ModuleManager::all() );
	}

	/**
	 * Same as {@see find_owner()} against an explicit module map (for tests).
	 *
	 * @since 1.0.0
	 *
	 * @param string                                  $name    Full ability name.
	 * @param array<string, ProvidesAbilities|object> $modules Slug => module.
	 * @return array{module_slug: string, module_label: string}|null
	 */
	public static function find_owner_among( string $name, array $modules ): ?array {
		foreach ( AbilityContext::definitions() as $ability_name => $definition ) {
			if ( $ability_name !== $name || ! is_array( $definition ) ) {
				continue;
			}

			$slug = isset( $definition['module_slug'] ) ? (string) $definition['module_slug'] : 'core';

			return array(
				'module_slug'  => $slug,
				'module_label' => AbilityGuard::module_label( $slug ),
			);
		}

		foreach ( $modules as $slug => $module ) {
			if ( ! self::provides_abilities( $module ) ) {
				continue;
			}

			$abilities = $module->abilities();
			if ( ! isset( $abilities[ $name ] ) || ! is_array( $abilities[ $name ] ) ) {
				continue;
			}

			$definition  = $abilities[ $name ];
			$module_slug = isset( $definition['module_slug'] ) && '' !== (string) $definition['module_slug']
				? (string) $definition['module_slug']
				: (string) $slug;

			$label = method_exists( $module, 'label' ) ? (string) $module->label() : AbilityGuard::module_label( $module_slug );

			return array(
				'module_slug'  => $module_slug,
				'module_label' => $label,
			);
		}

		return null;
	}

	/**
	 * Register a set of definitions with the full gate stack applied.
	 *
	 * Public so DoubleScale Pro can push abilities that have no owning module
	 * class and still get the same wrapping. Pro must never call
	 * wp_register_ability() directly — that would bypass every gate.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, mixed>> $definitions Name => definition.
	 * @return void
	 */
	public static function register_definitions( array $definitions ): void {
		foreach ( $definitions as $name => $definition ) {
			if ( is_string( $name ) && is_array( $definition ) ) {
				self::register_one( $name, $definition );
			}
		}
	}

	/**
	 * Gather definitions from every enabled module implementing ProvidesAbilities.
	 *
	 * Walks the live kernel registry, which already holds free and Pro modules
	 * in one map (Pro discovery runs on doublescale_register_modules at
	 * plugins_loaded:5, long before wp_abilities_api_init inside init:10), so
	 * no class_exists() juggling is needed for the Pro-inactive case.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	/**
	 * Whether a module contributes abilities.
	 *
	 * Tests for the METHOD, not the interface. The Pro plugin ships separately
	 * and can be updated first, so `implements ProvidesAbilities` in a Pro
	 * module class would be a hard parse-time dependency on this file: on a
	 * site running new Pro against old free, PHP fatals while loading the class
	 * — before any version guard could run. Duck typing keeps that boundary
	 * one-directional, which is the whole point of {@see ProvidesAbilities}
	 * being opt-in.
	 *
	 * Implementing the interface still works and remains the documented way for
	 * free modules; it simply is not required.
	 *
	 * @since 1.0.0
	 *
	 * @param object $module Module instance.
	 * @return bool
	 */
	public static function provides_abilities( $module ): bool {
		return is_object( $module ) && method_exists( $module, 'abilities' );
	}

	public static function collect(): array {
		// Core's discovery ability has no owning feature module.
		$definitions = AbilityContext::definitions();

		foreach ( ModuleManager::all() as $slug => $module ) {
			if ( ! self::provides_abilities( $module ) ) {
				continue;
			}

			// Gate 1, registration half: a disabled module contributes nothing,
			// so its abilities are invisible to tools/list rather than merely
			// uncallable. Keeps the agent from reading a listed tool as
			// evidence the feature exists on this site.
			if ( ! AbilityGuard::module_active( (string) $slug ) ) {
				continue;
			}

			foreach ( $module->abilities() as $name => $definition ) {
				if ( isset( $definitions[ $name ] ) ) {
					self::warn(
						sprintf( 'Ability "%s" declared more than once; the later definition wins.', $name )
					);
				}
				$definitions[ $name ] = $definition;
			}
		}

		/**
		 * Filter the collected ability definitions before registration.
		 *
		 * Prefer implementing ProvidesAbilities on a module class — those are
		 * collected automatically and get Gate 1 for free. This filter is the
		 * escape hatch for abilities with no owning module.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $definitions Name => definition.
		 */
		return (array) apply_filters( 'doublescale_ability_definitions', $definitions );
	}

	/**
	 * Normalise one definition and register it.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $name       Full ability name.
	 * @param array<string, mixed> $definition Definition array.
	 * @return void
	 */
	private static function register_one( string $name, array $definition ): void {
		if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
			self::warn(
				sprintf( 'Ability name "%s" is invalid and was skipped. Expected "namespace/ability-name".', $name )
			);
			return;
		}

		$module_slug = isset( $definition['module_slug'] ) ? (string) $definition['module_slug'] : '';
		$execute     = $definition['execute_callback'] ?? null;

		if ( '' === $module_slug || ! is_callable( $execute ) ) {
			self::warn(
				sprintf( 'Ability "%s" needs a module_slug and a callable execute_callback; it was skipped.', $name )
			);
			return;
		}

		// A definition supplying its own permission_callback would bypass the
		// AI-access and module gates, so it is never honoured.
		if ( isset( $definition['permission_callback'] ) ) {
			self::warn(
				sprintf( 'Ability "%s" must not define its own permission_callback; use the "permission" key.', $name )
			);
		}

		$args = array(
			'label'               => $definition['label'] ?? $name,
			'description'         => $definition['description'] ?? '',
			'category'            => $definition['category'] ?? AbilityCategories::slug_for_module( $module_slug ),
			'execute_callback'    => AbilityGuard::wrap_execute( $name, $execute ),
			'permission_callback' => AbilityGuard::compose_permission(
				$name,
				$module_slug,
				$definition['permission'] ?? null
			),
			'meta'                => array(
				// Read-only is the DEFAULT, not a ceiling: an author who omits
				// annotations gets the safe value, and a write ability opts out
				// deliberately. Order matters — the definition comes second so
				// it wins. These annotations are what tell an agent whether a
				// call is safe to make and safe to retry, so a mutating ability
				// that inherited `readonly: true` would be actively dangerous.
				'annotations'  => array_merge(
					array(
						'readonly'    => true,
						'destructive' => false,
					),
					(array) ( $definition['meta']['annotations'] ?? array() )
				),
				// Required. WP 7.0.3 defaults show_in_rest to false
				// (class-wp-ability.php:29) and has no `public` meta key, so
				// omitting this registers an ability unreachable over REST.
				'show_in_rest' => true,
			),
		);

		// WP treats the presence of an input schema as "input is expected", so
		// an empty properties map would fail validation on a no-argument call.
		if ( ! empty( $definition['input_schema']['properties'] ) ) {
			$args['input_schema'] = $definition['input_schema'];
		}

		if ( ! empty( $definition['output_schema'] ) ) {
			$args['output_schema'] = $definition['output_schema'];
		}

		wp_register_ability( $name, $args );
	}

	/**
	 * Surface an authoring mistake without breaking the request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function warn( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( function_exists( 'doublescale_get_logger' ) ) {
			$logger = doublescale_get_logger();
			if ( is_object( $logger ) && method_exists( $logger, 'warning' ) ) {
				$logger->warning( '[Abilities] ' . $message );
			}
		}
	}
}
