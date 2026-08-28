<?php
/**
 * Opt-in contract for modules that expose WordPress Abilities.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Modules implement this alongside ModuleInterface to publish abilities.
 *
 * Deliberately NOT folded into ModuleInterface: 34 modules implement that
 * contract and only a handful will ever ship abilities. Mirrors the existing
 * restControllers() convention on AbstractModule.
 */
interface ProvidesAbilities {

	/**
	 * Ability definitions owned by this module.
	 *
	 * Keys are FULL ability names ('doublescale/list-contacts'). WP core
	 * validates them against /^[a-z0-9-]+\/[a-z0-9-]+$/ — exactly one forward
	 * slash, lowercase alphanumerics and dashes only, no underscores. A name
	 * that fails triggers _doing_it_wrong() and the ability is silently
	 * dropped (wp-includes/abilities-api/class-wp-abilities-registry.php:81).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> Ability name => definition.
	 */
	public function abilities(): array;
}
